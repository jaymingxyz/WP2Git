<?php
/**
 * Runs when the plugin is deleted from the WordPress admin.
 * Removes all WP2Git options and custom tables (network-aware).
 *
 * Note: preserved local versions under wp-content/.wp2git/conflicts are NOT
 * deleted — those are the user's own files, kept deliberately to avoid the
 * silent data loss this plugin exists to prevent.
 *
 * @package WP2Git
 */

declare(strict_types=1);

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Remove all WP2Git data. Wrapped in a function so its locals don't leak into
 * the global scope during uninstall.
 */
function wp2git_uninstall_cleanup(): void {
	global $wpdb;

	$network = is_multisite() && get_site_option( 'wp2git_network_active' );
	$prefix  = $network ? $wpdb->base_prefix : $wpdb->prefix;

	$options = array(
		'wp2git_settings',
		'wp2git_state',
		'wp2git_lock',
		'wp2git_last_synced_sha',
		'wp2git_push_job',
		'wp2git_db_version',
	);
	foreach ( $options as $option ) {
		if ( $network ) {
			delete_site_option( $option );
		} else {
			delete_option( $option );
		}
	}
	if ( $network ) {
		delete_site_option( 'wp2git_network_active' );
	}

	// Short-lived GitHub App installation token cache.
	delete_transient( 'wp2git_app_token' );
	delete_site_transient( 'wp2git_app_token' );

	foreach ( array( 'wp2git_manifest', 'wp2git_log' ) as $table ) {
		$name = $prefix . $table;
		// One-time teardown of our own custom tables; table name is internal, not user input.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$name}" );
	}
}

wp2git_uninstall_cleanup();
