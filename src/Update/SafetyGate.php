<?php

declare(strict_types=1);

namespace WP2Git\Update;

defined( 'ABSPATH' ) || exit;

use WP2Git\Sync\Paths;
use WP2Git\Sync\Scope;

/**
 * Validates every incoming path before it can be written to disk. This is the
 * primary machine-gate that contains the standing RCE exposure of auto-apply:
 * paths must stay inside the in-scope wp-content sub-dirs, with no traversal.
 */
final class SafetyGate {

	public function __construct(
		private readonly Paths $paths,
		private readonly Scope $scope,
	) {
	}

	public function allows( string $repoPath ): bool {
		$repoPath = ltrim( $repoPath, '/' );

		// Reject null bytes, traversal, backslashes and absolute paths outright.
		if (
			$repoPath === '' ||
			str_contains( $repoPath, "\0" ) ||
			str_contains( $repoPath, '\\' ) ||
			str_contains( $repoPath, '../' ) ||
			str_starts_with( $repoPath, '../' ) ||
			$repoPath === '..'
		) {
			return false;
		}

		if ( ! $this->scope->isInScope( $repoPath ) || $this->scope->isExcluded( $repoPath ) ) {
			return false;
		}

		// Final containment check: the resolved target must live under wp-content.
		$local   = wp_normalize_path( $this->paths->toLocal( $repoPath ) );
		$base    = $this->paths->contentDir() . '/';
		$allowed = str_starts_with( $local, $base );

		/**
		 * Filter the final allow decision for an incoming path.
		 *
		 * @param bool   $allowed
		 * @param string $repoPath
		 */
		return (bool) apply_filters( 'wp2git_safe_path', $allowed, $repoPath );
	}
}
