<?php

declare(strict_types=1);

namespace WP2Git\Update;

defined( 'ABSPATH' ) || exit;

use WP2Git\Logger;
use WP2Git\Plugin;
use WP2Git\State;
use WP2Git\Sync\Paths;
use WP_Error;

/**
 * Applies an incoming commit to the live site. Each file goes through a
 * three-way comparison (base = manifest, local = disk, remote = incoming) so a
 * local edit is never silently lost: on conflict the remote wins but the local
 * copy is preserved under wp-content/.wp2git/conflicts. Writes are atomic
 * (temp file in the target dir, then rename).
 */
final class Applier {

	public function __construct( private readonly Plugin $plugin ) {
	}

	/**
	 * @return array{applied:int,conflicts:int,skipped:int,deleted:int}|WP_Error
	 */
	/**
	 * @param bool $force Re-apply the entire branch head, ignoring the synced
	 *                    cursor — used to forcibly bring the site back in line
	 *                    with GitHub (local copies are still preserved on conflict).
	 */
	public function apply( string $headSha, bool $force = false ) {
		if ( ! $this->plugin->isEnabled() ) {
			return new WP_Error( 'wp2git_disabled', __( 'Sync is not enabled.', 'wp2git' ) );
		}
		// Backup-only mode: never write anything from GitHub onto the live site.
		// This is the single authoritative gate, so no apply path (webhook,
		// manual button, or a stale queued job) can bypass it.
		if ( ! $this->plugin->settings->autoApply() ) {
			return new WP_Error( 'wp2git_backup_only', __( 'Backup-only mode is on; incoming updates are not applied.', 'wp2git' ) );
		}
		if ( ! $this->plugin->state->acquire( State::PULLING ) ) {
			return new WP_Error( 'wp2git_busy', __( 'Another sync is in progress.', 'wp2git' ) );
		}

		try {
			return $this->run( $headSha, $force );
		} finally {
			$this->plugin->state->release();
		}
	}

	/**
	 * @return array{applied:int,conflicts:int,skipped:int,deleted:int}|WP_Error
	 */
	private function run( string $headSha, bool $force = false ) {
		// A forced re-apply diffs against the full tree (base = null) so every
		// file is re-evaluated against disk, not just what changed since the cursor.
		$base    = $force ? null : $this->plugin->state->lastSyncedSha();
		$changes = $this->plugin->fetcher->changes( $base, $headSha );
		if ( is_wp_error( $changes ) ) {
			return $changes;
		}

		$fs = null;

		$stats = array(
			'applied'   => 0,
			'conflicts' => 0,
			'skipped'   => 0,
			'deleted'   => 0,
		);

		foreach ( $changes as $change ) {
			$path   = $change['path'];
			$status = $change['status'];

			// Exported database content (posts/pages) goes to the importer, not
			// the filesystem. It has its own validation, so it bypasses the file
			// safety gate (which would reject it as out-of-scope anyway).
			if ( $this->plugin->exporter->owns( $path ) ) {
				$this->applyContentChange( $change, $stats, $headSha );
				continue;
			}

			// Structured site-configuration records are intentionally one-way
			// snapshots. Never turn a Git edit into a database write (particularly
			// for executable snippets); invalidate the manifest so the next backup
			// restores WordPress's authoritative version in the repository.
			if ( $this->plugin->databaseExporter->owns( $path ) ) {
				$this->keepDatabaseSnapshot( $change, $stats, $headSha );
				continue;
			}

			// Database-only pulls do not need filesystem credentials. Initialize
			// lazily when the first actual wp-content change is encountered.
			if ( $fs === null ) {
				$fs = $this->filesystem();
				if ( is_wp_error( $fs ) ) {
					return $fs;
				}
			}

			// Renames: drop the old path, then add the new one.
			if ( $status === 'renamed' && is_string( $change['previous'] ) ) {
				$this->deleteFile( $fs, $change['previous'], $stats );
			}

			if ( ! $this->plugin->gate->allows( $path ) ) {
				++$stats['skipped'];
				$this->plugin->logger->log( Logger::PULL, array( 'skipped_unsafe' => $path ), $headSha );
				continue;
			}

			if ( $status === 'removed' ) {
				$this->deleteFile( $fs, $path, $stats );
				continue;
			}

			if ( ! is_string( $change['sha'] ) || $change['sha'] === '' ) {
				++$stats['skipped'];
				continue;
			}

			$content = $this->plugin->gitData->blobContent( $change['sha'] );
			if ( is_wp_error( $content ) ) {
				return $content;
			}
			$this->writeFile( $fs, $path, $content, $change['sha'], $headSha, $stats );
		}

		$this->plugin->state->setLastSyncedSha( $headSha );
		$this->plugin->logger->log( Logger::PULL, $stats, $headSha );

		return $stats;
	}

	/**
	 * Apply an incoming change to an exported content file back onto the
	 * database. Never deletes a post: a removed file just drops the manifest
	 * entry so the post re-exports, keeping GitHub consistent without data loss.
	 *
	 * @param array{path:string,status:string,sha:?string,previous:?string} $change
	 * @param array{applied:int,conflicts:int,skipped:int,deleted:int}       $stats
	 */
	private function applyContentChange( array $change, array &$stats, string $headSha ): void {
		$path = $change['path'];

		if ( $change['status'] === 'renamed' && is_string( $change['previous'] ) && $this->plugin->exporter->owns( $change['previous'] ) ) {
			$this->plugin->manifest->delete( $change['previous'] );
		}

		if ( $change['status'] === 'removed' ) {
			$this->plugin->manifest->delete( $path );
			$this->plugin->logger->log( Logger::PULL, array( 'content_kept' => $path ), $headSha );
			++$stats['skipped'];
			return;
		}

		if ( ! is_string( $change['sha'] ) || $change['sha'] === '' ) {
			++$stats['skipped'];
			return;
		}

		$content = $this->plugin->gitData->blobContent( $change['sha'] );
		if ( is_wp_error( $content ) ) {
			$this->plugin->logger->error( 'Content fetch failed', array( 'path' => $path ) );
			++$stats['skipped'];
			return;
		}

		$result = $this->plugin->importer->apply( $path, $content );
		if ( 'updated' === $result || 'created' === $result ) {
			$sha = (string) $change['sha'];
			$this->plugin->manifest->upsert( $path, $this->plugin->exporter->contentType( $path ), $sha, $sha, $headSha );
			++$stats['applied'];
			$this->plugin->logger->log( Logger::PULL, array( $result => $path ), $headSha );
		} else {
			++$stats['skipped'];
		}
	}

	/**
	 * Keep a structured database snapshot one-way and non-destructive. Remote
	 * writes, removals and renames all cause the local record to be re-exported
	 * on the next push; none are applied to WordPress.
	 *
	 * @param array{path:string,status:string,sha:?string,previous:?string} $change
	 * @param array{applied:int,conflicts:int,skipped:int,deleted:int}       $stats
	 */
	private function keepDatabaseSnapshot( array $change, array &$stats, string $headSha ): void {
		$path = $change['path'];
		if ( $change['status'] === 'renamed'
			&& is_string( $change['previous'] )
			&& $this->plugin->databaseExporter->owns( $change['previous'] ) ) {
			$this->plugin->manifest->delete( $change['previous'] );
		}

		$localSnapshot = $this->plugin->databaseExporter->render( $path );
		if ( $localSnapshot !== null || $change['status'] === 'removed' ) {
			// A real local record must be written back; a removed remote record that
			// is also absent locally is already in the desired state.
			$this->plugin->manifest->delete( $path );
		} elseif ( is_string( $change['sha'] ) && $change['sha'] !== '' ) {
			// Track a remote-only artifact just long enough for the next authoritative
			// scan to delete it. This also cleans up the destination of a Git rename.
			$sha = $change['sha'];
			$this->plugin->manifest->upsert(
				$path,
				$this->plugin->databaseExporter->contentType( $path ),
				$sha,
				$sha,
				$headSha
			);
		} else {
			$this->plugin->manifest->delete( $path );
		}
		$this->plugin->logger->log(
			Logger::PULL,
			array(
				'database_snapshot_kept' => $path,
				'remote_status'          => $change['status'],
			),
			$headSha
		);
		++$stats['skipped'];
	}

	/**
	 * @param array{applied:int,conflicts:int,skipped:int,deleted:int} $stats
	 */
	private function writeFile( $fs, string $repoPath, string $content, string $remoteSha, string $headSha, array &$stats ): void {
		$local   = $this->plugin->paths->toLocal( $repoPath );
		$known   = $this->plugin->manifest->get( $repoPath );
		$baseSha = $known['blob_sha'] ?? null;
		// Local file read to hash on-disk content; @ tolerates a vanished file.
		$currentSha = file_exists( $local ) ? Paths::gitBlobSha( (string) @file_get_contents( $local ) ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$decision = Resolution::forWrite( $baseSha, $currentSha, $remoteSha );

		// Already identical to the incoming content — just reconcile the manifest.
		if ( $decision === Resolution::NOOP ) {
			$this->plugin->manifest->upsert( $repoPath, $this->plugin->scope->contentType( $repoPath ), $remoteSha, $remoteSha, $headSha );
			return;
		}

		if ( $decision === Resolution::CONFLICT ) {
			$this->backupConflict( $fs, $repoPath, (string) @file_get_contents( $local ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read.
			++$stats['conflicts'];
			$this->plugin->logger->log(
				Logger::CONFLICT,
				array(
					'path'       => $repoPath,
					'resolution' => 'remote_wins',
				),
				$headSha
			);
		}

		if ( ! $this->atomicWrite( $fs, $local, $content ) ) {
			$this->plugin->logger->error( 'Failed to write file', array( 'path' => $repoPath ) );
			++$stats['skipped'];
			return;
		}

		$this->plugin->manifest->upsert( $repoPath, $this->plugin->scope->contentType( $repoPath ), $remoteSha, $remoteSha, $headSha );
		if ( $decision === Resolution::CLEAN ) {
			++$stats['applied'];
		}
	}

	/**
	 * @param array{applied:int,conflicts:int,skipped:int,deleted:int} $stats
	 */
	private function deleteFile( $fs, string $repoPath, array &$stats ): void {
		if ( ! $this->plugin->gate->allows( $repoPath ) ) {
			++$stats['skipped'];
			return;
		}
		$local   = $this->plugin->paths->toLocal( $repoPath );
		$known   = $this->plugin->manifest->get( $repoPath );
		$baseSha = $known['blob_sha'] ?? null;
		// Local file read to hash on-disk content; @ tolerates a vanished file.
		$currentSha = file_exists( $local ) ? Paths::gitBlobSha( (string) @file_get_contents( $local ) ) : null; // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$decision = Resolution::forDelete( $baseSha, $currentSha );

		// Remote deleted but local was modified since last sync — preserve it.
		if ( $decision === Resolution::DELETE_CONFLICT ) {
			$this->backupConflict( $fs, $repoPath, (string) @file_get_contents( $local ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read.
			$this->plugin->logger->log(
				Logger::CONFLICT,
				array(
					'path'       => $repoPath,
					'resolution' => 'remote_delete',
				)
			);
		}
		if ( $currentSha !== null ) {
			$fs->delete( $local );
		}
		$this->plugin->manifest->delete( $repoPath );
		++$stats['deleted'];
	}

	/** Atomic-ish write: temp file in the destination dir, then move into place. */
	private function atomicWrite( $fs, string $local, string $content ): bool {
		$dir = dirname( $local );
		if ( ! wp_mkdir_p( $dir ) ) {
			return false;
		}
		$tmp = $dir . '/.wp2git-tmp-' . wp_generate_password( 10, false );
		if ( ! $fs->put_contents( $tmp, $content, FS_CHMOD_FILE ) ) {
			return false;
		}
		if ( ! $fs->move( $tmp, $local, true ) ) {
			$fs->delete( $tmp );
			return false;
		}
		return true;
	}

	private function backupConflict( $fs, string $repoPath, string $content ): void {
		$dir = $this->plugin->paths->contentDir() . '/.wp2git/conflicts';
		$this->ensureProtected( $fs, $this->plugin->paths->contentDir() . '/.wp2git' );
		$target = $dir . '/' . $repoPath . '.' . time();
		wp_mkdir_p( dirname( $target ) );
		$fs->put_contents( $target, $content, FS_CHMOD_FILE );
	}

	/** Keep our working dir off the public web. */
	private function ensureProtected( $fs, string $dir ): void {
		wp_mkdir_p( $dir );
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			$fs->put_contents( $dir . '/.htaccess', "Require all denied\n", FS_CHMOD_FILE );
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			$fs->put_contents( $dir . '/index.php', "<?php // Silence is golden.\n", FS_CHMOD_FILE );
		}
	}

	/** @return \WP_Filesystem_Base|WP_Error */
	private function filesystem() {
		return \WP2Git\Sync\Filesystem::get();
	}
}
