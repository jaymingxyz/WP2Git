<?php

declare(strict_types=1);

namespace WP2Git\Sync;

defined( 'ABSPATH' ) || exit;

use WP2Git\Settings;

/**
 * Defines what is in scope for sync (which wp-content sub-dirs) and what is
 * always excluded. Shared by the push scanner and the pull safety gate so both
 * directions agree on the file set.
 */
final class Scope {

	/** All sync-eligible top-level wp-content directories. */
	public const DIRS = array( 'themes', 'plugins', 'mu-plugins', 'uploads' );

	/** Default toggles: code on, uploads off (large/binary). */
	public const DEFAULTS = array(
		'themes'     => true,
		'plugins'    => true,
		'mu-plugins' => true,
		'uploads'    => false,
	);

	public function __construct( private readonly Settings $settings ) {
	}

	/** @return array<string,bool> */
	public function toggles(): array {
		$stored = $this->settings->get( 'scope', array() );
		return array_merge( self::DEFAULTS, is_array( $stored ) ? $stored : array() );
	}

	/** @return list<string> in-scope top-level dirs */
	public function dirs(): array {
		$toggles = $this->toggles();
		return array_values( array_filter( self::DIRS, static fn ( $d ) => ! empty( $toggles[ $d ] ) ) );
	}

	public function isInScope( string $repoPath ): bool {
		$repoPath = ltrim( $repoPath, '/' );
		foreach ( $this->dirs() as $dir ) {
			if ( $repoPath === $dir || str_starts_with( $repoPath, $dir . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	/** Top-level content type for a repo path, e.g. "themes". */
	public function contentType( string $repoPath ): string {
		$repoPath = ltrim( $repoPath, '/' );
		$slash    = strpos( $repoPath, '/' );
		return $slash === false ? $repoPath : substr( $repoPath, 0, $slash );
	}

	/**
	 * Paths we never sync in either direction: our own working dir, VCS
	 * metadata, logs, env and OS cruft. Extendable via the wp2git_excluded filter.
	 */
	public function isExcluded( string $repoPath ): bool {
		$repoPath = ltrim( $repoPath, '/' );
		$base     = strtolower( basename( $repoPath ) );

		$excluded =
			str_starts_with( $repoPath, '.wp2git' ) ||
			str_starts_with( $repoPath, '.git/' ) ||
			str_contains( $repoPath, '/.git/' ) ||
			str_ends_with( $base, '.log' ) ||
			in_array( $base, array( '.env', '.ds_store', 'thumbs.db' ), true );

		/**
		 * Filter whether a repo-relative path is excluded from sync.
		 *
		 * @param bool   $excluded
		 * @param string $repoPath
		 */
		return (bool) apply_filters( 'wp2git_excluded', $excluded, $repoPath );
	}
}
