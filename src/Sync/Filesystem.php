<?php

declare(strict_types=1);

namespace WP2Git\Sync;

defined( 'ABSPATH' ) || exit;

use WP_Error;

/**
 * Lazily initializes and returns the WordPress filesystem abstraction. Shared by
 * the pull applier and the conflict store so all writes go through WP_Filesystem
 * (which honours the configured direct/FTP/SSH method).
 */
final class Filesystem {

	/** @return \WP_Filesystem_Base|WP_Error */
	public static function get() {
		global $wp_filesystem;
		if ( ! $wp_filesystem ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			if ( ! WP_Filesystem() ) {
				return new WP_Error( 'wp2git_fs', __( 'Could not initialize the WordPress filesystem.', 'wp2git' ) );
			}
		}
		return $wp_filesystem;
	}
}
