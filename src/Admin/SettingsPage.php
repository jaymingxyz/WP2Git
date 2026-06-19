<?php

declare(strict_types=1);

namespace WP2Git\Admin;

defined( 'ABSPATH' ) || exit;

use WP2Git\Logger;
use WP2Git\Plugin;

/**
 * Admin UI: the connect wizard (PAT → repo → branch → webhook) plus a status
 * dashboard. Rendered as a classic settings page; a React dashboard can replace
 * the body in a later phase without changing the routing here.
 */
final class SettingsPage {
	/*
	 * Every state-changing handler in this controller calls guard() as its first
	 * statement, which runs current_user_can( 'manage_options' ) and
	 * check_admin_referer( self::NONCE ). The only superglobal reads outside that
	 * are the display-only $_GET status/message params in render(). PHPCS can't
	 * trace the central guard, so the nonce sniff is disabled at file scope.
	 */
	// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended

	private const SLUG  = 'wp2git';
	private const NONCE = 'wp2git_admin';

	public function __construct( private readonly Plugin $plugin ) {
	}

	public function register(): void {
		add_action( \WP2Git\Context::networkScoped() ? 'network_admin_menu' : 'admin_menu', array( $this, 'addMenu' ) );
		add_action( 'admin_post_wp2git_connect', array( $this, 'handleConnect' ) );
		add_action( 'admin_post_wp2git_connect_app', array( $this, 'handleConnectApp' ) );
		add_action( 'admin_post_wp2git_save_repo', array( $this, 'handleSaveRepo' ) );
		add_action( 'admin_post_wp2git_disconnect', array( $this, 'handleDisconnect' ) );
		add_action( 'admin_post_wp2git_backup_now', array( $this, 'handleBackupNow' ) );
		add_action( 'admin_post_wp2git_pull_now', array( $this, 'handlePullNow' ) );
		add_action( 'admin_post_wp2git_save_backup', array( $this, 'handleSaveBackup' ) );
		add_action( 'admin_post_wp2git_save_security', array( $this, 'handleSaveSecurity' ) );
	}

	public function addMenu(): void {
		add_menu_page(
			__( 'WP2Git', 'wp2git' ),
			__( 'WP2Git', 'wp2git' ),
			'manage_options',
			self::SLUG,
			array( $this, 'render' ),
			'dashicons-randomize',
			81
		);
	}

	// --- Form handlers ----------------------------------------------------

	public function handleConnect(): void {
		$this->guard();
		$token = isset( $_POST['wp2git_token'] ) ? sanitize_text_field( wp_unslash( $_POST['wp2git_token'] ) ) : '';
		if ( $token === '' ) {
			$this->redirect( 'error', __( 'Token is required.', 'wp2git' ) );
		}

		$user = $this->plugin->github->validateToken( $token );
		if ( is_wp_error( $user ) ) {
			/* translators: %s: error message returned by GitHub. */
			$this->redirect( 'error', sprintf( __( 'Token rejected: %s', 'wp2git' ), $user->get_error_message() ) );
		}

		// Store encrypted. Repo/branch are chosen on the next screen.
		$this->plugin->settings->merge(
			array(
				'auth_method'  => 'pat',
				'token'        => $this->plugin->crypto->encrypt( $token ),
				'github_login' => (string) ( $user['login'] ?? '' ),
			)
		);
		$this->plugin->logger->log(
			Logger::CONNECT,
			array(
				'method' => 'pat',
				'login'  => $user['login'] ?? '',
			)
		);

		$this->redirect( 'ok', __( 'Connected to GitHub. Now pick a repository.', 'wp2git' ) );
	}

	public function handleConnectApp(): void {
		$this->guard();
		$appId     = isset( $_POST['wp2git_app_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['wp2git_app_id'] ) ) : '';
		$installId = isset( $_POST['wp2git_app_installation_id'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['wp2git_app_installation_id'] ) ) : '';
		// The PEM must keep its newlines — sanitize_text_field would destroy the
		// key. It is validated below by actually minting a token with it.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized
		$pem = isset( $_POST['wp2git_app_key'] ) ? trim( (string) wp_unslash( $_POST['wp2git_app_key'] ) ) : '';

		if ( $appId === '' || $installId === '' || $pem === '' ) {
			$this->redirect( 'error', __( 'App ID, Installation ID and private key are all required.', 'wp2git' ) );
		}

		// Validate by actually minting an installation token.
		$credential = new \WP2Git\GitHub\AppCredential( $appId, $installId, $pem );
		$token      = $credential->bearerToken();
		if ( is_wp_error( $token ) ) {
			/* translators: %s: error message returned by GitHub. */
			$this->redirect( 'error', sprintf( __( 'GitHub App rejected: %s', 'wp2git' ), $token->get_error_message() ) );
		}
		\WP2Git\GitHub\AppCredential::flushCache();

		$this->plugin->settings->merge(
			array(
				'auth_method'         => 'app',
				'app_id'              => $appId,
				'app_installation_id' => $installId,
				'app_key'             => $this->plugin->crypto->encrypt( $pem ),
			)
		);
		$this->plugin->logger->log(
			Logger::CONNECT,
			array(
				'method' => 'app',
				'app_id' => $appId,
			)
		);

		$this->redirect( 'ok', __( 'Connected via GitHub App. Now pick a repository.', 'wp2git' ) );
	}

	public function handleSaveRepo(): void {
		$this->guard();

		$full   = isset( $_POST['wp2git_repo'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['wp2git_repo'] ) ) : '';
		$branch = isset( $_POST['wp2git_branch'] ) ? sanitize_text_field( (string) wp_unslash( $_POST['wp2git_branch'] ) ) : 'main';
		if ( ! str_contains( $full, '/' ) ) {
			$this->redirect( 'error', __( 'Choose a repository.', 'wp2git' ) );
		}
		[$owner, $repo] = explode( '/', $full, 2 );

		// Enforce the private-repo policy: auto-applying code from a public repo
		// is not allowed.
		$meta = $this->plugin->github->getRepository( $owner, $repo );
		if ( is_wp_error( $meta ) ) {
			$this->redirect( 'error', $meta->get_error_message() );
		}
		if ( empty( $meta['private'] ) ) {
			$this->redirect( 'error', __( 'Refusing to connect: the repository is public. Use a private repository.', 'wp2git' ) );
		}

		$this->plugin->settings->merge(
			array(
				'owner'  => $owner,
				'repo'   => $repo,
				'branch' => $branch ?: 'main',
			)
		);

		// Provision the push webhook now that we have a repo.
		$hook = $this->plugin->webhooks->provision();
		if ( is_wp_error( $hook ) ) {
			/* translators: %s: error message returned by GitHub. */
			$this->redirect( 'error', sprintf( __( 'Saved, but webhook setup failed: %s', 'wp2git' ), $hook->get_error_message() ) );
		}

		$this->redirect( 'ok', __( 'Repository connected and webhook installed.', 'wp2git' ) );
	}

	public function handleBackupNow(): void {
		$this->guard();
		if ( ! $this->plugin->isEnabled() ) {
			$this->redirect( 'error', __( 'Connect a repository first.', 'wp2git' ) );
		}

		// Run the backup right now (draining batches within a request budget)
		// rather than only queuing it, so the user gets a real result — and so a
		// manual backup completes even when WP-Cron / loopback isn't firing.
		$result = $this->plugin->pusher->pushSync();

		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'error',
				/* translators: %s: error detail. */
				sprintf( __( 'Backup failed: %s', 'wp2git' ), $result->get_error_message() )
			);
		}

		if ( ! empty( $result['in_progress'] ) ) {
			$remaining = (int) ( $result['remaining'] ?? 0 );
			$this->redirect(
				'ok',
				sprintf(
					/* translators: %d: number of files still to upload. */
					_n(
						'Backup started — %d file still uploading in the background.',
						'Backup started — %d files still uploading in the background.',
						$remaining,
						'wp2git'
					),
					$remaining
				)
			);
		}

		$changed = (int) ( $result['changed'] ?? 0 );
		if ( $changed === 0 ) {
			$this->redirect( 'ok', __( 'Already up to date — nothing to back up.', 'wp2git' ) );
		}

		$commit = isset( $result['commit'] ) ? substr( (string) $result['commit'], 0, 7 ) : '';
		$this->redirect(
			'ok',
			sprintf(
				/* translators: 1: number of files backed up, 2: short commit SHA. */
				_n(
					'Backed up %1$d file to GitHub (commit %2$s).',
					'Backed up %1$d files to GitHub (commit %2$s).',
					$changed,
					'wp2git'
				),
				$changed,
				$commit
			)
		);
	}

	/**
	 * Manually pull the current branch head and apply it now, synchronously, so
	 * the inbound direction works (and is testable) without waiting on a webhook
	 * or the background queue.
	 */
	public function handlePullNow(): void {
		$this->guard();
		if ( ! $this->plugin->isEnabled() ) {
			$this->redirect( 'error', __( 'Connect a repository first.', 'wp2git' ) );
		}
		if ( ! $this->plugin->settings->autoApply() ) {
			$this->redirect( 'error', __( 'Backup-only mode is on. Turn on auto-apply to pull updates from GitHub.', 'wp2git' ) );
		}

		$head = $this->plugin->gitData->branchHead( $this->plugin->settings->branch() );
		if ( is_wp_error( $head ) ) {
			$this->redirect(
				'error',
				/* translators: %s: error detail. */
				sprintf( __( 'Could not reach GitHub: %s', 'wp2git' ), $head->get_error_message() )
			);
		}
		if ( $head === null ) {
			$this->redirect( 'ok', __( 'The branch has no commits yet — nothing to apply.', 'wp2git' ) );
		}
		if ( $head === $this->plugin->state->lastSyncedSha() ) {
			$this->redirect( 'ok', __( 'Already up to date with GitHub.', 'wp2git' ) );
		}

		$result = $this->plugin->applier->apply( $head );
		if ( is_wp_error( $result ) ) {
			$this->redirect(
				'error',
				/* translators: %s: error detail. */
				sprintf( __( 'Update failed: %s', 'wp2git' ), $result->get_error_message() )
			);
		}

		$applied   = (int) ( $result['applied'] ?? 0 );
		$deleted   = (int) ( $result['deleted'] ?? 0 );
		$conflicts = (int) ( $result['conflicts'] ?? 0 );

		if ( $applied === 0 && $deleted === 0 && $conflicts === 0 ) {
			$this->redirect( 'ok', __( 'Already up to date — no changes from GitHub.', 'wp2git' ) );
		}

		$parts = array();
		if ( $applied > 0 ) {
			/* translators: %d: number of files updated. */
			$parts[] = sprintf( _n( '%d file updated', '%d files updated', $applied, 'wp2git' ), $applied );
		}
		if ( $deleted > 0 ) {
			/* translators: %d: number of files deleted. */
			$parts[] = sprintf( _n( '%d file deleted', '%d files deleted', $deleted, 'wp2git' ), $deleted );
		}
		if ( $conflicts > 0 ) {
			$parts[] = sprintf(
				/* translators: %d: number of conflicting files whose local copy was preserved. */
				_n(
					'%d conflict (your copy saved to the Conflicts screen)',
					'%d conflicts (your copies saved to the Conflicts screen)',
					$conflicts,
					'wp2git'
				),
				$conflicts
			);
		}

		$this->redirect(
			'ok',
			sprintf(
				/* translators: %s: summary, e.g. "3 files updated, 1 file deleted". */
				__( 'Applied from GitHub: %s.', 'wp2git' ),
				implode( ', ', $parts )
			)
		);
	}

	public function handleSaveBackup(): void {
		$this->guard();

		$scope = array();
		foreach ( \WP2Git\Sync\Scope::DIRS as $dir ) {
			$scope[ $dir ] = ! empty( $_POST['scope'][ $dir ] );
		}
		$old_interval = (int) $this->plugin->settings->get( 'schedule_interval', HOUR_IN_SECONDS );
		$interval     = max( 0, isset( $_POST['wp2git_interval'] ) ? (int) $_POST['wp2git_interval'] : HOUR_IN_SECONDS );

		$this->plugin->settings->merge(
			array(
				'scope'             => $scope,
				'schedule_interval' => $interval,
			)
		);

		// Apply a changed cadence right away rather than waiting for the old
		// recurring action to lapse.
		if ( $interval !== $old_interval ) {
			$this->plugin->scheduler->rescheduleRecurringPush();
		}

		$this->redirect( 'ok', __( 'Backup settings saved.', 'wp2git' ) );
	}

	public function handleSaveSecurity(): void {
		$this->guard();

		$raw    = isset( $_POST['wp2git_allowlist'] ) ? sanitize_textarea_field( wp_unslash( $_POST['wp2git_allowlist'] ) ) : '';
		$logins = preg_split( '/[\s,]+/', $raw, -1, PREG_SPLIT_NO_EMPTY ) ?: array();
		$clean  = array();
		foreach ( $logins as $login ) {
			$login = strtolower( $login );
			// Valid GitHub usernames: alphanumeric and hyphens, up to 39 chars.
			if ( preg_match( '/^[a-z0-9-]{1,39}$/', $login ) ) {
				$clean[ $login ] = true;
			}
		}

		$this->plugin->settings->merge(
			array(
				'pusher_allowlist' => array_keys( $clean ),
				'auto_apply'       => ! empty( $_POST['wp2git_auto_apply'] ),
			)
		);
		$this->redirect( 'ok', __( 'Auto-apply security saved.', 'wp2git' ) );
	}

	public function handleDisconnect(): void {
		$this->guard();
		$this->plugin->webhooks->deprovision();
		foreach ( array(
			'token',
			'github_login',
			'auth_method',
			'app_id',
			'app_installation_id',
			'app_key',
			'owner',
			'repo',
			'branch',
			'webhook_secret',
			'webhook_id',
		) as $key ) {
			$this->plugin->settings->delete( $key );
		}
		\WP2Git\GitHub\AppCredential::flushCache();
		$this->redirect( 'ok', __( 'Disconnected.', 'wp2git' ) );
	}

	// --- Rendering --------------------------------------------------------

	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		$s      = $this->plugin->settings;
		$notice = isset( $_GET['wp2git_msg'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['wp2git_msg'] ) ) : '';
		$type   = ( isset( $_GET['wp2git_status'] ) && $_GET['wp2git_status'] === 'error' ) ? 'error' : 'success';

		echo '<div class="wrap"><h1>' . esc_html__( 'WP2Git', 'wp2git' ) . '</h1>';

		if ( $notice !== '' ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $notice ) );
		}

		if ( ! $s->hasCredentials() ) {
			$this->renderConnectStep();
		} elseif ( ! $s->isConnected() ) {
			$this->renderRepoStep();
		} else {
			$this->renderDashboard();
		}

		echo '</div>';
	}

	private function renderConnectStep(): void {
		?>
		<h2><?php esc_html_e( 'Step 1 — Connect to GitHub', 'wp2git' ); ?></h2>
		<p><?php esc_html_e( 'Choose one authentication method. Both must target a private repository.', 'wp2git' ); ?></p>

		<h3><?php esc_html_e( 'Option A — Fine-grained Personal Access Token', 'wp2git' ); ?></h3>
		<p><?php esc_html_e( 'Create a fine-grained PAT scoped to the repository with Contents (read/write), Metadata (read), and Webhooks (read/write).', 'wp2git' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp2git_connect">
			<?php wp_nonce_field( self::NONCE ); ?>
			<table class="form-table"><tr>
				<th><label for="wp2git_token"><?php esc_html_e( 'GitHub token', 'wp2git' ); ?></label></th>
				<td><input type="password" id="wp2git_token" name="wp2git_token" class="regular-text" autocomplete="off" required></td>
			</tr></table>
			<?php submit_button( __( 'Connect with token', 'wp2git' ) ); ?>
		</form>

		<hr>

		<h3><?php esc_html_e( 'Option B — GitHub App', 'wp2git' ); ?></h3>
		<p><?php esc_html_e( 'Recommended for ongoing use: short-lived tokens, per-repository install, no expiry to manage. Install your GitHub App on the repository, then enter its App ID, the Installation ID, and a private key (.pem).', 'wp2git' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp2git_connect_app">
			<?php wp_nonce_field( self::NONCE ); ?>
			<table class="form-table">
				<tr>
					<th><label for="wp2git_app_id"><?php esc_html_e( 'App ID', 'wp2git' ); ?></label></th>
					<td><input type="text" id="wp2git_app_id" name="wp2git_app_id" class="regular-text" autocomplete="off"></td>
				</tr>
				<tr>
					<th><label for="wp2git_app_installation_id"><?php esc_html_e( 'Installation ID', 'wp2git' ); ?></label></th>
					<td><input type="text" id="wp2git_app_installation_id" name="wp2git_app_installation_id" class="regular-text" autocomplete="off"></td>
				</tr>
				<tr>
					<th><label for="wp2git_app_key"><?php esc_html_e( 'Private key (PEM)', 'wp2git' ); ?></label></th>
					<td><textarea id="wp2git_app_key" name="wp2git_app_key" rows="6" class="large-text code" autocomplete="off" placeholder="-----BEGIN RSA PRIVATE KEY-----"></textarea></td>
				</tr>
			</table>
			<?php submit_button( __( 'Connect with GitHub App', 'wp2git' ) ); ?>
		</form>
		<?php
	}

	private function renderRepoStep(): void {
		$repos = $this->plugin->github->listRepositories();
		?>
		<h2><?php esc_html_e( 'Step 2 — Choose repository & branch', 'wp2git' ); ?></h2>
		<?php if ( is_wp_error( $repos ) ) : ?>
			<div class="notice notice-error"><p><?php echo esc_html( $repos->get_error_message() ); ?></p></div>
		<?php else : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp2git_save_repo">
			<?php wp_nonce_field( self::NONCE ); ?>
			<table class="form-table">
				<tr>
					<th><label for="wp2git_repo"><?php esc_html_e( 'Repository', 'wp2git' ); ?></label></th>
					<td><select id="wp2git_repo" name="wp2git_repo" class="regular-text">
						<?php foreach ( $repos as $r ) : ?>
							<option value="<?php echo esc_attr( $r['full_name'] ); ?>" data-default="<?php echo esc_attr( $r['default_branch'] ); ?>">
								<?php echo esc_html( $r['full_name'] . ( $r['private'] ? '' : '  (public — not allowed)' ) ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Only private repositories may be used.', 'wp2git' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="wp2git_branch"><?php esc_html_e( 'Branch', 'wp2git' ); ?></label></th>
					<td><input type="text" id="wp2git_branch" name="wp2git_branch" class="regular-text" value="main"></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save & install webhook', 'wp2git' ) ); ?>
		</form>
			<?php
		endif;
	}

	private function renderDashboard(): void {
		$s       = $this->plugin->settings;
		$repoUrl = sprintf( 'https://github.com/%s/%s', $s->repoOwner(), $s->repoName() );
		?>
		<h2><?php esc_html_e( 'Status', 'wp2git' ); ?></h2>
		<table class="widefat striped" style="max-width:680px">
			<tbody>
				<tr><td><strong><?php esc_html_e( 'Repository', 'wp2git' ); ?></strong></td>
					<td><a href="<?php echo esc_url( $repoUrl ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $s->repoOwner() . '/' . $s->repoName() ); ?></a></td></tr>
				<tr><td><strong><?php esc_html_e( 'Branch', 'wp2git' ); ?></strong></td><td><?php echo esc_html( $s->branch() ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Sync state', 'wp2git' ); ?></strong></td><td><?php echo esc_html( $this->plugin->state->current() ); ?></td></tr>
				<tr><td><strong><?php esc_html_e( 'Last synced commit', 'wp2git' ); ?></strong></td><td><code><?php echo esc_html( $this->plugin->state->lastSyncedSha() ?? '—' ); ?></code></td></tr>
				<tr><td><strong><?php esc_html_e( 'Webhook endpoint', 'wp2git' ); ?></strong></td><td><code><?php echo esc_html( \WP2Git\GitHub\Webhooks::receiverUrl() ); ?></code></td></tr>
				<?php $conflictCount = $this->plugin->conflicts->count(); ?>
				<tr><td><strong><?php esc_html_e( 'Unresolved conflicts', 'wp2git' ); ?></strong></td>
					<td><?php if ( $conflictCount > 0 ) : ?>
						<a href="<?php echo esc_url( add_query_arg( 'page', \WP2Git\Admin\ConflictsPage::SLUG, self::adminUrl() ) ); ?>">
						<?php
							/* translators: %d: number of unresolved conflicts. */
							echo esc_html( sprintf( _n( '%d conflict — review', '%d conflicts — review', $conflictCount, 'wp2git' ), $conflictCount ) );
						?>
						</a>
					<?php else : ?>
						<?php esc_html_e( 'None', 'wp2git' ); ?>
					<?php endif; ?></td></tr>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Recent activity', 'wp2git' ); ?></h2>
		<table class="widefat striped" style="max-width:900px">
			<thead><tr><th><?php esc_html_e( 'When', 'wp2git' ); ?></th><th><?php esc_html_e( 'Event', 'wp2git' ); ?></th><th><?php esc_html_e( 'Commit', 'wp2git' ); ?></th><th><?php esc_html_e( 'Actor', 'wp2git' ); ?></th></tr></thead>
			<tbody>
			<?php foreach ( $this->plugin->logger->recent( 20 ) as $row ) : ?>
				<tr>
					<td><?php echo esc_html( (string) $row['created_at'] ); ?></td>
					<td><?php echo esc_html( (string) $row['event'] ); ?></td>
					<td><code><?php echo esc_html( $row['commit_sha'] ? substr( (string) $row['commit_sha'], 0, 8 ) : '—' ); ?></code></td>
					<td><?php echo esc_html( (string) $row['actor'] ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<h2><?php esc_html_e( 'Backup', 'wp2git' ); ?></h2>
		<?php
		$toggles  = $this->plugin->scope->toggles();
		$interval = (int) $s->get( 'schedule_interval', HOUR_IN_SECONDS );
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp2git_save_backup">
			<?php wp_nonce_field( self::NONCE ); ?>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Folders to sync', 'wp2git' ); ?></th>
					<td><?php foreach ( \WP2Git\Sync\Scope::DIRS as $dir ) : ?>
						<label style="display:inline-block;margin-right:1.2em">
							<input type="checkbox" name="scope[<?php echo esc_attr( $dir ); ?>]" value="1" <?php checked( ! empty( $toggles[ $dir ] ) ); ?>>
							<?php echo esc_html( $dir ); ?>
						</label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'uploads can be large/binary; leave off unless you need media versioned.', 'wp2git' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="wp2git_interval"><?php esc_html_e( 'Scheduled backup', 'wp2git' ); ?></label></th>
					<td><select id="wp2git_interval" name="wp2git_interval">
						<option value="0" <?php selected( $interval, 0 ); ?>><?php esc_html_e( 'Manual / on-change only', 'wp2git' ); ?></option>
						<option value="900" <?php selected( $interval, 900 ); ?>><?php esc_html_e( 'Every 15 minutes', 'wp2git' ); ?></option>
						<option value="3600" <?php selected( $interval, 3600 ); ?>><?php esc_html_e( 'Hourly', 'wp2git' ); ?></option>
						<option value="86400" <?php selected( $interval, 86400 ); ?>><?php esc_html_e( 'Daily', 'wp2git' ); ?></option>
						<option value="604800" <?php selected( $interval, 604800 ); ?>><?php esc_html_e( 'Weekly', 'wp2git' ); ?></option>
						<option value="1209600" <?php selected( $interval, 1209600 ); ?>><?php esc_html_e( 'Every two weeks', 'wp2git' ); ?></option>
					</select></td>
				</tr>
			</table>
			<?php submit_button( __( 'Save backup settings', 'wp2git' ), 'secondary' ); ?>
		</form>

		<p>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block;margin-right:.5em">
				<input type="hidden" name="action" value="wp2git_backup_now">
				<?php wp_nonce_field( self::NONCE ); ?>
				<?php submit_button( __( 'Back up now', 'wp2git' ), 'primary', 'submit', false ); ?>
			</form>
			<?php if ( $s->autoApply() ) : ?>
				<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline-block">
					<input type="hidden" name="action" value="wp2git_pull_now">
					<?php wp_nonce_field( self::NONCE ); ?>
					<?php submit_button( __( 'Check GitHub for updates now', 'wp2git' ), 'secondary', 'submit', false ); ?>
				</form>
			<?php endif; ?>
		</p>
		<p class="description">
			<?php
			if ( $s->autoApply() ) {
				esc_html_e( 'Back up now pushes local changes to GitHub. Check for updates pulls the latest commit and applies it to this site — both run immediately.', 'wp2git' );
			} else {
				esc_html_e( 'Backup-only mode is on: Back up now pushes local changes to GitHub. Nothing is pulled from GitHub onto this site. Enable auto-apply under “Auto-apply security” to sync both ways.', 'wp2git' );
			}
			?>
		</p>
		<p style="margin-top:1em"><em>
			<?php
			if ( $s->autoApply() ) {
				esc_html_e( 'Auto-apply is enabled: commits pushed to this branch run on the live site. Enable branch protection on GitHub for human review.', 'wp2git' );
			} else {
				esc_html_e( 'Backup-only mode is on: commits from GitHub are never applied to this site — only backups to GitHub run.', 'wp2git' );
			}
			?>
		</em></p>

		<h2><?php esc_html_e( 'Auto-apply security', 'wp2git' ); ?></h2>
		<?php
		$allowlist  = (array) $s->get( 'pusher_allowlist', array() );
		$auto_apply = $s->autoApply();
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp2git_save_security">
			<?php wp_nonce_field( self::NONCE ); ?>
			<table class="form-table">
				<tr>
					<th><?php esc_html_e( 'Sync direction', 'wp2git' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="wp2git_auto_apply" value="1" <?php checked( $auto_apply ); ?>>
							<?php esc_html_e( 'Apply incoming commits from GitHub to this site (auto-apply)', 'wp2git' ); ?>
						</label>
						<p class="description">
							<?php esc_html_e( 'On: two-way sync — pushes to the connected branch are applied to this site automatically.', 'wp2git' ); ?><br>
							<?php esc_html_e( 'Off: backup-only — wp-content is still backed up to GitHub, but nothing is ever pulled from GitHub onto the live site.', 'wp2git' ); ?>
						</p>
					</td>
				</tr>
				<tr>
					<th><label for="wp2git_allowlist"><?php esc_html_e( 'Pusher allowlist', 'wp2git' ); ?></label></th>
					<td>
						<textarea id="wp2git_allowlist" name="wp2git_allowlist" rows="4" class="large-text code"
							placeholder="octocat&#10;another-dev"><?php echo esc_textarea( implode( "\n", $allowlist ) ); ?></textarea>
						<p class="description"><?php esc_html_e( 'One GitHub username per line (or comma-separated). Only pushes from — and commits authored by — these users will be auto-applied to this site. Leave empty to allow anyone who can push to the repository.', 'wp2git' ); ?></p>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save security settings', 'wp2git' ), 'secondary' ); ?>
		</form>

		<h2 style="margin-top:2em"><?php esc_html_e( 'Danger zone', 'wp2git' ); ?></h2>
		<p class="description" style="max-width:640px">
			<strong style="color:#b32d2e"><?php esc_html_e( 'Warning:', 'wp2git' ); ?></strong>
			<?php esc_html_e( 'Disconnecting removes the GitHub webhook and erases the stored token, repository, and sync state from this site. Your GitHub repository and its backups are not deleted, but syncing stops until you reconnect and re-authenticate.', 'wp2git' ); ?>
		</p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="margin-top:.5em"
				onsubmit="return confirm('<?php echo esc_js( __( 'Disconnect from GitHub? This removes the webhook and stored credentials from this site. Your repository and backups are not deleted.', 'wp2git' ) ); ?>');">
			<input type="hidden" name="action" value="wp2git_disconnect">
			<?php wp_nonce_field( self::NONCE ); ?>
			<?php
			submit_button(
				__( 'Disconnect', 'wp2git' ),
				'delete',
				'submit',
				true,
				array( 'style' => 'background:#b32d2e;border-color:#b32d2e;color:#fff;text-shadow:none;' )
			);
			?>
		</form>
		<?php
	}

	// --- Helpers ----------------------------------------------------------

	private function guard(): void {
		if ( ! current_user_can( 'manage_options' ) || ! check_admin_referer( self::NONCE ) ) {
			wp_die( esc_html__( 'Permission denied.', 'wp2git' ) );
		}
	}

	public static function adminUrl(): string {
		return \WP2Git\Context::networkScoped() ? network_admin_url( 'admin.php' ) : admin_url( 'admin.php' );
	}

	private function redirect( string $status, string $message ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::SLUG,
					'wp2git_status' => $status,
					'wp2git_msg'    => rawurlencode( $message ),
				),
				self::adminUrl()
			)
		);
		exit;
	}
}
