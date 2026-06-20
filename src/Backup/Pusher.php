<?php

declare(strict_types=1);

namespace WP2Git\Backup;

defined( 'ABSPATH' ) || exit;

use WP2Git\Logger;
use WP2Git\Plugin;
use WP2Git\State;
use WP_Error;

/**
 * Builds an incremental commit from local changes and pushes it to GitHub via
 * the Git Data API: only changed/added/deleted files become blobs/tree entries,
 * layered on the current head tree.
 *
 * Large pushes (notably the first import) are chunked across background jobs: a
 * job is planned once, then blobs are created in bounded batches with the
 * progress persisted between runs, and finally a single commit is made. The
 * sync lock is released between batches so a queued pull can interleave; the
 * persisted job resumes on the next run.
 */
final class Pusher {

	/** Blobs created per background job — keeps each run well under time/rate limits. */
	private const BATCH_SIZE = 50;

	private const JOB_OPTION = 'wp2git_push_job';

	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * Process a single batch. This is the background-queue entry point: each
	 * async run does one bounded batch and re-enqueues the next, so a huge import
	 * never blocks a single request. Equivalent to draining with a zero budget.
	 *
	 * @return array{changed:int,commit?:string,in_progress?:bool,remaining?:int}|WP_Error
	 */
	public function push() {
		return $this->pushSync( 0.0 );
	}

	/**
	 * Drain the push job within one request, up to a wall-clock budget, instead
	 * of one batch per background run. Used by the "Back up now" button so a
	 * manual backup actually completes and commits in a single click — even on
	 * sites where WP-Cron / loopback isn't firing. Whatever doesn't fit in the
	 * budget is handed back to the async queue, so a very large first import
	 * still finishes without timing out the request.
	 *
	 * @param float $budget_seconds Soft wall-clock ceiling for this request.
	 * @return array{changed:int,commit?:string,in_progress?:bool,remaining?:int}|WP_Error
	 */
	public function pushSync( float $budget_seconds = 20.0 ) {
		if ( ! $this->plugin->isEnabled() ) {
			return new WP_Error( 'wp2git_disabled', __( 'Sync is not enabled.', 'wp2git' ) );
		}
		if ( ! $this->plugin->state->acquire( State::PUSHING ) ) {
			return new WP_Error( 'wp2git_busy', __( 'Another sync is in progress.', 'wp2git' ) );
		}

		$deadline = microtime( true ) + $budget_seconds;

		try {
			$job = $this->loadJob() ?? $this->planJob();
			if ( $job === null ) {
				$this->plugin->logger->log( Logger::PUSH, array( 'changed' => 0 ) );
				return array( 'changed' => 0 );
			}

			while ( true ) {
				// Reset PHP's execution timer each batch so a long drain isn't
				// killed mid-flight (best-effort; some hosts disable this).
				if ( function_exists( 'set_time_limit' ) ) {
					@set_time_limit( 60 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				}

				$result = $this->runBatch( $job );
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				$job = $result['job'];

				if ( $result['done'] ) {
					return $this->finalize( $job['tree'] );
				}

				// More work remains: persist progress so a crash/timeout resumes.
				$this->saveJob( $job );

				if ( microtime( true ) >= $deadline ) {
					// Out of budget — hand the remainder to the background queue.
					$this->plugin->scheduler->enqueuePush();
					return array(
						'changed'     => 0,
						'in_progress' => true,
						'remaining'   => count( $job['changes'] ),
					);
				}
			}
		} catch ( \Throwable $e ) {
			// Abandon the job on any hard failure so it can't wedge future pushes.
			$this->clearJob();
			$this->plugin->logger->error( 'Push failed', array( 'detail' => $e->getMessage() ) );
			return new WP_Error( 'wp2git_push_failed', $e->getMessage() );
		} finally {
			$this->plugin->state->release();
		}
	}

	// --- Plan -------------------------------------------------------------

	/**
	 * Scan + diff against the manifest into a resumable job, or null if nothing
	 * changed.
	 *
	 * @return array{changes:list<array{path:string,op:string}>,tree:list<array<string,mixed>>}|null
	 */
	private function planJob(): ?array {
		// Files under wp-content plus any exported database content (posts/pages).
		// Their repo paths never collide (exports live under a reserved prefix),
		// so the union diffs against the manifest exactly like files do.
		$current = $this->plugin->scanner->scan() + $this->plugin->exporter->scan();
		$known   = $this->plugin->manifest->all();

		$changes = array();
		foreach ( $current as $repoPath => $localSha ) {
			if ( ( $known[ $repoPath ]['blob_sha'] ?? null ) !== $localSha ) {
				$changes[] = array(
					'path' => $repoPath,
					'op'   => 'write',
				);
			}
		}
		foreach ( $known as $repoPath => $_row ) {
			if ( ! isset( $current[ $repoPath ] ) ) {
				$changes[] = array(
					'path' => $repoPath,
					'op'   => 'delete',
				);
			}
		}

		if ( $changes === array() ) {
			return null;
		}

		$job = array(
			'changes' => $changes,
			'tree'    => array(),
		);
		$this->saveJob( $job );
		return $job;
	}

	// --- Process ----------------------------------------------------------

	/**
	 * Turn up to BATCH_SIZE pending changes into blobs/tree entries. Pure step:
	 * it mutates and returns the job, leaving the enqueue/finalize decision to
	 * the caller so both the single-batch ({@see push}) and synchronous-drain
	 * ({@see pushSync}) paths can share it.
	 *
	 * @param array{changes:list<array{path:string,op:string}>,tree:list<array<string,mixed>>} $job
	 * @return array{job:array{changes:list<array{path:string,op:string}>,tree:list<array<string,mixed>>},done:bool}|WP_Error
	 */
	private function runBatch( array $job ) {
		$batch = array_splice( $job['changes'], 0, self::BATCH_SIZE );

		foreach ( $batch as $item ) {
			$repoPath = $item['path'];

			if ( $item['op'] === 'delete' ) {
				$job['tree'][] = array(
					'path' => $repoPath,
					'mode' => '100644',
					'type' => 'blob',
					'sha'  => null,
				);
				continue;
			}

			$content = $this->contentFor( $repoPath );
			if ( $content === null ) {
				continue; // Vanished since planning; skip.
			}
			$blobSha = $this->plugin->gitData->createBlob( $content );
			if ( is_wp_error( $blobSha ) ) {
				$this->clearJob();
				return $blobSha;
			}
			$job['tree'][] = array(
				'path' => $repoPath,
				'mode' => '100644',
				'type' => 'blob',
				'sha'  => $blobSha,
			);
		}

		return array(
			'job'  => $job,
			'done' => $job['changes'] === array(),
		);
	}

	/**
	 * Content for a path being pushed: exported database content is regenerated
	 * from the post, everything else is read from disk. Null means "skip".
	 */
	private function contentFor( string $repoPath ): ?string {
		if ( $this->plugin->exporter->owns( $repoPath ) ) {
			return $this->plugin->exporter->render( $repoPath );
		}
		$raw = @file_get_contents( $this->plugin->paths->toLocal( $repoPath ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		return $raw === false ? null : $raw;
	}

	// --- Finalize ---------------------------------------------------------

	/**
	 * @param list<array<string,mixed>> $treeEntries
	 * @return array{changed:int,commit?:string}|WP_Error
	 */
	private function finalize( array $treeEntries ) {
		if ( $treeEntries === array() ) {
			$this->clearJob();
			$this->plugin->logger->log( Logger::PUSH, array( 'changed' => 0 ) );
			return array( 'changed' => 0 );
		}

		$gitData = $this->plugin->gitData;
		$branch  = $this->plugin->settings->branch();

		$head = $gitData->branchHead( $branch );
		if ( is_wp_error( $head ) ) {
			return $head;
		}
		$baseTree = null;
		$parents  = array();
		if ( is_string( $head ) ) {
			$baseTree = $gitData->commitTree( $head );
			if ( is_wp_error( $baseTree ) ) {
				return $baseTree;
			}
			$parents = array( $head );
		}

		$treeSha = $gitData->createTree( $treeEntries, $baseTree );
		if ( is_wp_error( $treeSha ) ) {
			return $treeSha;
		}

		$count = count( $treeEntries );
		// The origin trailer is the loop guard: the webhook receiver drops it.
		$message = sprintf(
			/* translators: %d: number of changed files. */
			_n( 'WP2Git backup: %d change', 'WP2Git backup: %d changes', $count, 'wp2git' ),
			$count
		) . "\n\nX-WP2Git-Origin: push";

		$commitSha = $gitData->createCommit( $message, $treeSha, $parents );
		if ( is_wp_error( $commitSha ) ) {
			return $commitSha;
		}

		$set = $gitData->setBranchHead( $branch, $commitSha, ! is_string( $head ) );
		if ( is_wp_error( $set ) ) {
			return $set;
		}

		// Persist the manifest only after the ref moved successfully.
		foreach ( $treeEntries as $entry ) {
			$path = (string) $entry['path'];
			if ( $entry['sha'] === null ) {
				$this->plugin->manifest->delete( $path );
			} else {
				$sha  = (string) $entry['sha'];
				$type = $this->plugin->exporter->owns( $path )
					? $this->plugin->exporter->contentType( $path )
					: $this->plugin->scope->contentType( $path );
				$this->plugin->manifest->upsert( $path, $type, $sha, $sha, $commitSha );
			}
		}

		$this->clearJob();
		$this->plugin->state->setLastSyncedSha( $commitSha );
		$this->plugin->logger->log( Logger::PUSH, array( 'changed' => $count ), $commitSha );

		return array(
			'changed' => $count,
			'commit'  => $commitSha,
		);
	}

	// --- Job persistence --------------------------------------------------

	/** @return array{changes:list<array{path:string,op:string}>,tree:list<array<string,mixed>>}|null */
	private function loadJob(): ?array {
		$job = get_option( self::JOB_OPTION, null );
		return is_array( $job ) && isset( $job['changes'], $job['tree'] ) ? $job : null;
	}

	/** @param array<string,mixed> $job */
	private function saveJob( array $job ): void {
		update_option( self::JOB_OPTION, $job, false );
	}

	private function clearJob(): void {
		delete_option( self::JOB_OPTION );
	}
}
