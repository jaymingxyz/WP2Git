<?php

declare(strict_types=1);

namespace WP2Git;

defined( 'ABSPATH' ) || exit;

use WP2Git\Admin\ConflictsPage;
use WP2Git\Admin\SettingsPage;
use WP2Git\Backup\ContentExporter;
use WP2Git\Backup\FileScanner;
use WP2Git\Backup\Manifest;
use WP2Git\Backup\Pusher;
use WP2Git\GitHub\AppCredential;
use WP2Git\GitHub\Client;
use WP2Git\GitHub\Credential;
use WP2Git\GitHub\GitData;
use WP2Git\GitHub\PatCredential;
use WP2Git\GitHub\Webhooks;
use WP2Git\Rest\Routes;
use WP2Git\Sync\ChangeWatcher;
use WP2Git\Sync\Paths;
use WP2Git\Sync\Scheduler;
use WP2Git\Sync\Scope;
use WP2Git\Update\Applier;
use WP2Git\Update\ChangeFetcher;
use WP2Git\Update\Conflicts;
use WP2Git\Update\SafetyGate;

/**
 * Central wiring / service container. Constructs services and registers hooks.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	// Core services.
	public readonly Settings $settings;
	public readonly Crypto $crypto;
	public readonly Logger $logger;
	public readonly State $state;
	public readonly Credential $credential;
	public readonly Client $github;
	public readonly Webhooks $webhooks;

	// Sync engine.
	public readonly Paths $paths;
	public readonly Scope $scope;
	public readonly Manifest $manifest;
	public readonly GitData $gitData;
	public readonly FileScanner $scanner;
	public readonly ContentExporter $exporter;
	public readonly Pusher $pusher;
	public readonly ChangeFetcher $fetcher;
	public readonly SafetyGate $gate;
	public readonly Applier $applier;
	public readonly Conflicts $conflicts;
	public readonly Scheduler $scheduler;
	public readonly ChangeWatcher $watcher;

	private bool $booted = false;

	private function __construct() {
		$this->settings   = new Settings();
		$this->crypto     = new Crypto();
		$this->logger     = new Logger();
		$this->state      = new State();
		$this->credential = $this->settings->get( 'auth_method', 'pat' ) === 'app'
			? AppCredential::fromSettings( $this->settings, $this->crypto )
			: new PatCredential( $this->settings, $this->crypto );
		$this->github     = new Client( $this->credential, $this->logger );
		$this->webhooks   = new Webhooks( $this->github, $this->settings );

		$this->paths     = new Paths();
		$this->scope     = new Scope( $this->settings );
		$this->manifest  = new Manifest();
		$this->gitData   = new GitData( $this->github, $this->settings );
		$this->scanner   = new FileScanner( $this->paths, $this->scope, $this->logger );
		$this->exporter  = new ContentExporter( $this->settings );
		$this->fetcher   = new ChangeFetcher( $this->gitData );
		$this->gate      = new SafetyGate( $this->paths, $this->scope );
		$this->pusher    = new Pusher( $this );
		$this->applier   = new Applier( $this );
		$this->conflicts = new Conflicts( $this );
		$this->scheduler = new Scheduler( $this );
		$this->watcher   = new ChangeWatcher( $this );
	}

	public static function instance(): Plugin {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		load_plugin_textdomain( 'wp2git', false, dirname( WP2GIT_BASENAME ) . '/languages' );

		// REST API (webhook receiver + status) is always registered.
		( new Routes( $this ) )->register();

		// Background jobs (push/pull) are always wired so queued actions run.
		$this->scheduler->register();

		// Fire prompt backups when the dashboard changes wp-content files.
		$this->watcher->register();

		if ( is_admin() ) {
			( new SettingsPage( $this ) )->register();
			( new ConflictsPage( $this ) )->register();
		}

		/**
		 * Fires once WP2Git has wired its core services.
		 *
		 * @param Plugin $plugin
		 */
		do_action( 'wp2git_booted', $this );
	}

	/** Whether sync is globally enabled (kill switch + completed connection). */
	public function isEnabled(): bool {
		return ! WP2GIT_DISABLE && $this->settings->isConnected();
	}
}
