<?php

declare(strict_types=1);

namespace WP2Git;

defined( 'ABSPATH' ) || exit;

/**
 * Activation / deactivation: schema and cleanup.
 *
 * On multisite, "Network Activate" sets a network flag so all storage routes to
 * network scope (see Context/Options/Database); wp-content is shared across the
 * network, so a single shared manifest/log and one connection are correct.
 */
final class Install {

	public const DB_VERSION = '1';

	public static function activate( bool $networkWide = false ): void {
		if ( is_multisite() && $networkWide ) {
			update_site_option( 'wp2git_network_active', 1 );
		}

		self::createTables();
		Options::set( 'wp2git_db_version', self::DB_VERSION );
	}

	public static function deactivate(): void {
		// Stop all background work: WP-Cron events and Action Scheduler actions.
		wp_clear_scheduled_hook( Sync\Scheduler::HOOK_PUSH );
		wp_clear_scheduled_hook( Sync\Scheduler::HOOK_PULL );
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( Sync\Scheduler::HOOK_PUSH );
			as_unschedule_all_actions( Sync\Scheduler::HOOK_PULL );
		}
	}

	public static function createTables(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset  = $wpdb->get_charset_collate();
		$manifest = Database::table( 'manifest' );
		$log      = Database::table( 'log' );

		// One row per tracked file — the basis for cheap diffs + loop prevention.
		$sql_manifest = "CREATE TABLE {$manifest} (
            path VARCHAR(512) NOT NULL,
            content_type VARCHAR(32) NOT NULL DEFAULT 'file',
            local_hash CHAR(40) DEFAULT NULL,
            blob_sha CHAR(40) DEFAULT NULL,
            last_sync_sha CHAR(40) DEFAULT NULL,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (path(191)),
            KEY content_type (content_type)
        ) {$charset};";

		// Append-only audit trail.
		$sql_log = "CREATE TABLE {$log} (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            event VARCHAR(32) NOT NULL,
            commit_sha CHAR(40) DEFAULT NULL,
            actor VARCHAR(191) DEFAULT NULL,
            detail LONGTEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY event (event),
            KEY created_at (created_at)
        ) {$charset};";

		dbDelta( $sql_manifest );
		dbDelta( $sql_log );
	}
}
