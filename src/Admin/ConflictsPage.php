<?php

declare(strict_types=1);

namespace WP2Git\Admin;

defined( 'ABSPATH' ) || exit;

use WP2Git\Plugin;

/**
 * Conflicts submenu: lists the preserved local versions from "remote wins"
 * resolutions and lets an admin restore (take ours) or discard (accept remote).
 */
final class ConflictsPage {
	/*
	 * State-changing handlers call guardAndGetId(), which runs
	 * current_user_can( 'manage_options' ) and check_admin_referer( self::NONCE );
	 * render() only reads display-only $_GET params. PHPCS can't trace the central
	 * guard, so the nonce sniff is disabled at file scope.
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

	public const SLUG   = 'wp2git-conflicts';
	private const NONCE = 'wp2git_conflicts';

	public function __construct( private readonly Plugin $plugin ) {
	}

	public function register(): void {
		add_action( \WP2Git\Context::networkScoped() ? 'network_admin_menu' : 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_post_wp2git_restore_conflict', array( $this, 'handleRestore' ) );
		add_action( 'admin_post_wp2git_discard_conflict', array( $this, 'handleDiscard' ) );
	}

	public function addMenu(): void {
		$count = $this->plugin->conflicts->count();
		$label = __( 'Conflicts', 'wp2git' );
		if ( $count > 0 ) {
			$label .= sprintf( ' <span class="awaiting-mod"><span class="pending-count">%d</span></span>', $count );
		}
		add_submenu_page(
			'wp2git',
			__( 'WP2Git Conflicts', 'wp2git' ),
			$label,
			'manage_options',
			self::SLUG,
			array( $this, 'render' )
		);
	}

	public function handleRestore(): void {
		$id     = $this->guardAndGetId();
		$result = $this->plugin->conflicts->restore( $id );
		$this->redirect( $result );
	}

	public function handleDiscard(): void {
		$id     = $this->guardAndGetId();
		$result = $this->plugin->conflicts->discard( $id );
		$this->redirect( $result );
	}

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$conflicts = $this->plugin->conflicts->all();
		$notice    = isset( $_GET['wp2git_msg'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['wp2git_msg'] ) ) : '';
		$type      = ( isset( $_GET['wp2git_status'] ) && $_GET['wp2git_status'] === 'error' ) ? 'error' : 'success';

		echo '<div class="wrap"><h1>' . esc_html__( 'Sync conflicts', 'wp2git' ) . '</h1>';

		if ( $notice !== '' ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $notice ) );
		}

		echo '<p>' . esc_html__( 'When a file changed both locally and on GitHub, the GitHub version was applied and your local version was saved here. Restore to keep yours (it will be pushed back to GitHub), or discard to accept the GitHub version.', 'wp2git' ) . '</p>';

		if ( $conflicts === array() ) {
			echo '<p><em>' . esc_html__( 'No conflicts. 🎉', 'wp2git' ) . '</em></p></div>';
			return;
		}

		echo '<table class="widefat striped"><thead><tr>';
		echo '<th>' . esc_html__( 'File', 'wp2git' ) . '</th>';
		echo '<th>' . esc_html__( 'Saved', 'wp2git' ) . '</th>';
		echo '<th>' . esc_html__( 'Size', 'wp2git' ) . '</th>';
		echo '<th>' . esc_html__( 'Actions', 'wp2git' ) . '</th>';
		echo '</tr></thead><tbody>';

		foreach ( $conflicts as $c ) {
			echo '<tr>';
			echo '<td><code>' . esc_html( $c['path'] ) . '</code></td>';
			echo '<td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $c['timestamp'] ) ) . '</td>';
			echo '<td>' . esc_html( size_format( $c['size'] ) ) . '</td>';
			// actionForm() returns markup escaped internally (esc_attr/esc_js/submit_button).
			// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '<td>' . $this->actionForm( 'wp2git_restore_conflict', __( 'Restore mine', 'wp2git' ), $c['id'], 'primary' )
				. ' ' . $this->actionForm( 'wp2git_discard_conflict', __( 'Discard', 'wp2git' ), $c['id'], 'delete', true ) . '</td>';
			// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
			echo '</tr>';
		}

		echo '</tbody></table></div>';
	}

	// --- Helpers ----------------------------------------------------------

	private function actionForm( string $action, string $label, string $id, string $buttonType, bool $confirm = false ): string {
		ob_start();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline"
			<?php
			if ( $confirm ) :
				?>
				onsubmit="return confirm('<?php echo esc_js( __( 'Discard your local version permanently?', 'wp2git' ) ); ?>');"<?php endif; ?>>
			<input type="hidden" name="action" value="<?php echo esc_attr( $action ); ?>">
			<input type="hidden" name="conflict_id" value="<?php echo esc_attr( $id ); ?>">
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php submit_button( $label, $buttonType, 'submit', false ); ?>
		</form>
		<?php
		return (string) ob_get_clean();
	}

	private function guardAndGetId(): string {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp2git' ) );
		}
		return isset( $_POST['conflict_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['conflict_id'] ) ) : '';
	}

	private function redirect( $result ): never {
		$isError = is_wp_error( $result );
		$message = $isError
			? $result->get_error_message()
			: __( 'Done.', 'wp2git' );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::SLUG,
					'wp2git_status' => $isError ? 'error' : 'ok',
					'wp2git_msg'    => rawurlencode( $message ),
				),
				SettingsPage::adminUrl()
			)
		);
		exit;
	}
}
