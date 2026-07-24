<?php

declare(strict_types=1);

namespace WP2Git\Sync;

defined( 'ABSPATH' ) || exit;

use WP2Git\Plugin;
use WP2Git\State;

/**
 * Best-effort fast path: fires wp2git_local_change when a dashboard action is
 * known to have modified backed-up files or database records, so a backup is
 * queued promptly
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

		// Posts/pages and structured database post types.
		add_action( 'save_post', array( $this, 'onPostChange' ), 10, 2 );
		add_action( 'trashed_post', array( $this, 'onPostChange' ), 10, 1 );
		add_action( 'deleted_post', array( $this, 'onPostChange' ), 10, 2 );
		add_action( 'added_post_meta', array( $this, 'onPostMetaChange' ), 10, 2 );
		add_action( 'updated_post_meta', array( $this, 'onPostMetaChange' ), 10, 2 );
		add_action( 'deleted_post_meta', array( $this, 'onPostMetaChange' ), 10, 2 );
		add_action( 'set_object_terms', array( $this, 'onObjectTermsChange' ), 10, 1 );

		// Option-backed Customizer and widget state. The handler checks an exact
		// allowlist before queuing, so unrelated option churn remains cheap.
		add_action( 'added_option', array( $this, 'onOptionChange' ), 10, 1 );
		add_action( 'updated_option', array( $this, 'onOptionChange' ), 10, 1 );
		add_action( 'deleted_option', array( $this, 'onOptionChange' ), 10, 1 );
		add_action( 'customize_save_after', array( $this, 'onCustomizerChange' ), 10, 0 );

		// Empty menu creation/deletion may not save a nav_menu_item post.
		add_action( 'wp_create_nav_menu', array( $this, 'onMenuChange' ), 10, 0 );
		add_action( 'wp_update_nav_menu', array( $this, 'onMenuChange' ), 10, 0 );
		add_action( 'wp_delete_nav_menu', array( $this, 'onMenuChange' ), 10, 0 );
		add_action( 'created_nav_menu', array( $this, 'onMenuChange' ), 10, 0 );
		add_action( 'edited_nav_menu', array( $this, 'onMenuChange' ), 10, 0 );
	}

	public function onChange(): void {
		do_action( 'wp2git_local_change' );
	}

	public function onAttachment(): void {
		if ( in_array( 'uploads', $this->plugin->scope->dirs(), true ) ) {
			do_action( 'wp2git_local_change' );
		}
	}

	/**
	 * Queue a backup when a backed-up post type changes. Skips revisions and
	 * autosaves so routine editing doesn't thrash the queue (the push debounces).
	 *
	 * @param int          $postId Post ID (from save_post / trashed_post).
	 * @param mixed        $post   WP_Post for save_post; absent for trashed_post.
	 */
	public function onPostChange( $postId, $post = null ): void {
		// Don't echo: a save during a pull is the importer writing GitHub's content
		// back, not a user edit, so it must not queue a push back to GitHub.
		if ( $this->plugin->state->current() === State::PULLING ) {
			return;
		}
		if ( wp_is_post_revision( $postId ) || wp_is_post_autosave( $postId ) ) {
			return;
		}
		$post = $post instanceof \WP_Post ? $post : get_post( $postId );
		if ( ! $post instanceof \WP_Post ) {
			return;
		}
		$backsUpContent  = in_array( $post->post_type, $this->plugin->exporter->enabledTypes(), true );
		$backsUpSnapshot = $this->plugin->databaseExporter->watchesPostType( $post->post_type );
		if ( $backsUpContent || $backsUpSnapshot ) {
			do_action( 'wp2git_local_change' );
		}
	}

	/** Queue metadata-only changes, such as enabling or disabling a WPCode snippet. */
	public function onPostMetaChange( $metaId, $postId ): void {
		unset( $metaId );
		$this->onPostChange( $postId );
	}

	/** Queue taxonomy relationship changes for backed-up template/menu records. */
	public function onObjectTermsChange( $objectId ): void {
		$this->onPostChange( $objectId );
	}

	/** Queue when an exact, enabled snapshot option changes. */
	public function onOptionChange( string $option ): void {
		if ( $this->plugin->state->current() === State::PULLING ) {
			return;
		}
		if ( $this->plugin->databaseExporter->watchesOption( $option ) ) {
			do_action( 'wp2git_local_change' );
		}
	}

	/** Queue after a Customizer transaction, including saved theme mods. */
	public function onCustomizerChange(): void {
		if ( $this->plugin->state->current() !== State::PULLING
			&& in_array( 'customizer', $this->plugin->databaseExporter->enabledGroups(), true ) ) {
			do_action( 'wp2git_local_change' );
		}
	}

	/** Queue menu term changes that do not necessarily touch an item post. */
	public function onMenuChange(): void {
		if ( $this->plugin->state->current() !== State::PULLING
			&& in_array( 'menus', $this->plugin->databaseExporter->enabledGroups(), true ) ) {
			do_action( 'wp2git_local_change' );
		}
	}
}
