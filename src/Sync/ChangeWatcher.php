<?php

declare(strict_types=1);

namespace WP2Git\Sync;

defined( 'ABSPATH' ) || exit;

use WP2Git\Plugin;

/**
 * Best-effort fast path: fires wp2git_local_change when a dashboard action is
 * known to have modified wp-content files, so a backup is queued promptly
 * instead of waiting for the next scheduled run.
 *
 * This is a latency optimization, not the source of truth. Out-of-band edits
 * (SFTP, WP-CLI, the built-in file editor — which has no reliable post-write
 * hook) are still caught by the scheduled content scan. The push diffs anyway,
 * so an over-eager trigger that finds nothing changed is cheap.
 */
final class ChangeWatcher {

	public function __construct( private readonly Plugin $plugin ) {
	}

	public function register(): void {
		if ( ! $this->plugin->isEnabled() ) {
			return;
		}

		// Install / update of plugins, themes, core, translations.
		add_action( 'upgrader_process_complete', array( $this, 'onChange' ), 10, 0 );

		// Deletions via the dashboard.
		add_action( 'deleted_plugin', array( $this, 'onChange' ), 10, 0 );
		add_action( 'deleted_theme', array( $this, 'onChange' ), 10, 0 );

		// Media library — only meaningful when uploads is in scope.
		add_action( 'add_attachment', array( $this, 'onAttachment' ), 10, 0 );
		add_action( 'edit_attachment', array( $this, 'onAttachment' ), 10, 0 );
		add_action( 'delete_attachment', array( $this, 'onAttachment' ), 10, 0 );
	}

	public function onChange(): void {
		do_action( 'wp2git_local_change' );
	}

	public function onAttachment(): void {
		if ( in_array( 'uploads', $this->plugin->scope->dirs(), true ) ) {
			do_action( 'wp2git_local_change' );
		}
	}
}
