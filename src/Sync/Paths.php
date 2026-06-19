<?php

declare(strict_types=1);

namespace WP2Git\Sync;

defined( 'ABSPATH' ) || exit;

/**
 * Maps between repo-relative paths (e.g. "themes/x/style.css") and absolute
 * local paths under wp-content, and computes git-compatible blob hashes so a
 * local file's hash can be compared directly against a GitHub blob SHA.
 */
final class Paths {

	/** Absolute, normalized wp-content dir without a trailing slash. */
	public function contentDir(): string {
		return untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
	}

	public function toLocal( string $repoPath ): string {
		return $this->contentDir() . '/' . ltrim( $repoPath, '/' );
	}

	/** Repo-relative path for a local file, or null if it escapes wp-content. */
	public function toRepo( string $localPath ): ?string {
		$local = wp_normalize_path( $localPath );
		$base  = $this->contentDir() . '/';
		if ( ! str_starts_with( $local, $base ) ) {
			return null;
		}
		return substr( $local, strlen( $base ) );
	}

	/** git blob object id: sha1("blob <len>\0<content>"). */
	public static function gitBlobSha( string $content ): string {
		return sha1( 'blob ' . strlen( $content ) . "\0" . $content );
	}
}
