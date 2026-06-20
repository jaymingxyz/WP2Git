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

		$fs = $this->filesystem();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}

		$stats = array(
			'applied'   => 0,
			'conflicts' => 0,
			'skipped'   => 0,
			'deleted'   => 0,
		);

		foreach ( $changes as $change ) {
			$path   = $change['path'];
			$status = $change['status'];

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
