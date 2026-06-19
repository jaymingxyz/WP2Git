<?php

declare(strict_types=1);

namespace WP2Git\Update;

defined( 'ABSPATH' ) || exit;

use WP2Git\Logger;
use WP2Git\Plugin;
use WP2Git\Sync\Filesystem;
use WP_Error;

/**
 * Read/act layer over the conflict store the Applier writes to
 * (wp-content/.wp2git/conflicts/<repoPath>.<unix-ts>). Each backup holds the
 * local version that "remote wins" overwrote or deleted, so nothing is ever
 * lost silently — this class lets an admin see those versions and either restore
 * (take ours, propagating back to GitHub) or discard (accept remote).
 */
final class Conflicts {

	public function __construct( private readonly Plugin $plugin ) {
	}

	private function dir(): string {
		return $this->plugin->paths->contentDir() . '/.wp2git/conflicts';
	}

	/**
	 * @return list<array{id:string,path:string,timestamp:int,size:int}>
	 */
	public function all(): array {
		$dir = $this->dir();
		if ( ! is_dir( $dir ) ) {
			return array();
		}

		$out      = array();
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);
		foreach ( $iterator as $file ) {
			/** @var \SplFileInfo $file */
			if ( ! $file->isFile() || $file->isLink() ) {
				continue;
			}
			$rel = ltrim( str_replace( '\\', '/', substr( $file->getPathname(), strlen( $dir ) ) ), '/' );
			if ( ! preg_match( '/^(.+)\.(\d{9,12})$/', $rel, $m ) ) {
				continue;
			}
			$out[] = array(
				'id'        => $rel,
				'path'      => $m[1],
				'timestamp' => (int) $m[2],
				'size'      => (int) $file->getSize(),
			);
		}

		usort( $out, static fn ( $a, $b ) => $b['timestamp'] <=> $a['timestamp'] );
		return $out;
	}

	public function count(): int {
		return count( $this->all() );
	}

	/** Backed-up (local) content for preview, or null if missing. */
	public function backupContent( string $id ): ?string {
		$abs = $this->resolveBackup( $id );
		if ( $abs === null || ! is_file( $abs ) ) {
			return null;
		}
		$content = @file_get_contents( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read.
		return $content === false ? null : $content;
	}

	/**
	 * Restore the local version over the live file (take "ours"). The manifest
	 * is intentionally left untouched so the scanner sees a local change and the
	 * next push propagates the restored version to GitHub.
	 *
	 * @return true|WP_Error
	 */
	public function restore( string $id ) {
		$abs      = $this->resolveBackup( $id );
		$repoPath = $this->repoPathFromId( $id );
		if ( $abs === null || $repoPath === null || ! is_file( $abs ) ) {
			return new WP_Error( 'wp2git_no_conflict', __( 'Conflict backup not found.', 'wp2git' ) );
		}

		// The restore target must stay within wp-content.
		$target = $this->plugin->paths->toLocal( $repoPath );
		if ( ! str_starts_with( wp_normalize_path( $target ), $this->plugin->paths->contentDir() . '/' ) ) {
			return new WP_Error( 'wp2git_bad_target', __( 'Refusing to restore outside wp-content.', 'wp2git' ) );
		}

		$content = @file_get_contents( $abs ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file read.
		if ( $content === false ) {
			return new WP_Error( 'wp2git_read_failed', __( 'Could not read the conflict backup.', 'wp2git' ) );
		}

		$fs = Filesystem::get();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		if ( ! wp_mkdir_p( dirname( $target ) ) ) {
			return new WP_Error( 'wp2git_mkdir', __( 'Could not prepare the target directory.', 'wp2git' ) );
		}

		// Atomic write: temp file in the target dir, then move into place.
		$tmp = dirname( $target ) . '/.wp2git-tmp-' . wp_generate_password( 10, false );
		if ( ! $fs->put_contents( $tmp, $content, FS_CHMOD_FILE ) || ! $fs->move( $tmp, $target, true ) ) {
			$fs->delete( $tmp );
			return new WP_Error( 'wp2git_write', __( 'Could not restore the file.', 'wp2git' ) );
		}

		$fs->delete( $abs );
		$this->plugin->logger->log(
			Logger::CONFLICT,
			array(
				'path'   => $repoPath,
				'action' => 'restored',
			)
		);
		$this->plugin->scheduler->enqueuePushDebounced();
		return true;
	}

	/** Discard the backup (accept remote, which is already live). @return true|WP_Error */
	public function discard( string $id ) {
		$abs = $this->resolveBackup( $id );
		if ( $abs === null ) {
			return new WP_Error( 'wp2git_bad_conflict', __( 'Invalid conflict reference.', 'wp2git' ) );
		}
		$fs = Filesystem::get();
		if ( is_wp_error( $fs ) ) {
			return $fs;
		}
		if ( is_file( $abs ) ) {
			$fs->delete( $abs );
		}
		$this->plugin->logger->log(
			Logger::CONFLICT,
			array(
				'path'   => $this->repoPathFromId( $id ),
				'action' => 'discarded',
			)
		);
		return true;
	}

	// --- Containment ------------------------------------------------------

	/** Resolve an id to an absolute path confined to the conflicts dir, or null. */
	private function resolveBackup( string $id ): ?string {
		$id = ltrim( str_replace( '\\', '/', $id ), '/' );
		if ( $id === '' || str_contains( $id, '../' ) || str_contains( $id, "\0" ) ) {
			return null;
		}
		$base = wp_normalize_path( $this->dir() );
		$abs  = wp_normalize_path( $base . '/' . $id );
		return str_starts_with( $abs, $base . '/' ) ? $abs : null;
	}

	private function repoPathFromId( string $id ): ?string {
		$id = ltrim( str_replace( '\\', '/', $id ), '/' );
		return preg_match( '/^(.+)\.(\d{9,12})$/', $id, $m ) ? $m[1] : null;
	}
}
