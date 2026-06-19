<?php

declare(strict_types=1);

namespace WP2Git\Sync;

defined( 'ABSPATH' ) || exit;

use WP2Git\Plugin;

/**
 * Background job wiring. Prefers Action Scheduler (chunked, retryable); falls
 * back to WP-Cron when the vendor library is not installed so the plugin still
 * functions after a plain (composer-less) install.
 *
 * Lock contention is handled here: if a push and a pull collide, the loser is
 * re-enqueued with a short delay (bounded) rather than dropped, so an incoming
 * commit is never lost just because a backup happened to be running.
 */
final class Scheduler {

	public const HOOK_PUSH = 'wp2git_run_push';
	public const HOOK_PULL = 'wp2git_apply_push';
	private const GROUP    = 'wp2git';

	/** Re-enqueue delay (seconds) and attempt cap when the sync lock is held. */
	private const RETRY_DELAY = 30;
	private const MAX_RETRIES = 20; // ~10 min, covers the State lock TTL.

	/** Debounce window for on-change backups, so a burst collapses into one push. */
	private const DEBOUNCE = 120;

	public function __construct( private readonly Plugin $plugin ) {
	}

	public function register(): void {
		add_action( self::HOOK_PUSH, array( $this, 'runPush' ), 10, 1 );
		add_action( self::HOOK_PULL, array( $this, 'runPull' ), 10, 2 );

		if ( $this->plugin->isEnabled() ) {
			$this->ensureRecurringPush();
		}

		// Debounced push on local content changes (theme/plugin edits, uploads).
		// NB: this action passes a context arg, so it must NOT bind directly to
		// enqueuePush(int) — strict_types would reject the string.
		add_action( 'wp2git_local_change', array( $this, 'enqueuePushDebounced' ) );

		// Make the chosen cadence available to the WP-Cron fallback. The interval
		// is admin-selected (15 minutes or longer, see the schedule dropdown), not
		// a hardcoded sub-minute value.
		// phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_filter( 'cron_schedules', array( $this, 'registerCronInterval' ) );
	}

	/**
	 * Expose the configured backup interval as a named WP-Cron schedule, so the
	 * fallback path (used only when Action Scheduler isn't available) honors the
	 * chosen cadence — including Weekly / Every two weeks — instead of always
	 * running hourly.
	 *
	 * @param array<string,array{interval:int,display:string}> $schedules
	 * @return array<string,array{interval:int,display:string}>
	 */
	public function registerCronInterval( array $schedules ): array {
		$interval = (int) $this->plugin->settings->get( 'schedule_interval', HOUR_IN_SECONDS );
		if ( $interval > 0 ) {
			$schedules['wp2git_backup'] = array(
				'interval' => $interval,
				'display'  => __( 'WP2Git backup interval', 'wp2git' ),
			);
		}
		return $schedules;
	}

	// --- Enqueue ----------------------------------------------------------

	/** Queue an apply for an incoming commit (called by the webhook receiver). */
	public function enqueuePull( string $sha, int $attempt = 0 ): void {
		$args = array( $sha, $attempt );
		if ( $attempt > 0 ) {
			$this->scheduleDelayed( self::HOOK_PULL, $args );
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( self::HOOK_PULL, $args, self::GROUP );
		} else {
			wp_schedule_single_event( time() + 5, self::HOOK_PULL, $args );
		}
	}

	/** Queue a backup push (attempt > 0 is a delayed retry after lock contention). */
	public function enqueuePush( int $attempt = 0 ): void {
		if ( $attempt > 0 ) {
			$this->scheduleDelayed( self::HOOK_PUSH, array( $attempt ) );
			return;
		}
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			// Coalesce: don't stack duplicate pending pushes.
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK_PUSH, array(), self::GROUP ) ) {
				return;
			}
			as_enqueue_async_action( self::HOOK_PUSH, array(), self::GROUP );
		} elseif ( ! wp_next_scheduled( self::HOOK_PUSH ) ) {
			wp_schedule_single_event( time() + 30, self::HOOK_PUSH );
		}
	}

	/**
	 * Schedule a single delayed, coalesced push in response to a local change.
	 * Ignores any action context arg passed by do_action('wp2git_local_change').
	 */
	public function enqueuePushDebounced(): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			if ( function_exists( 'as_next_scheduled_action' )
				&& as_next_scheduled_action( self::HOOK_PUSH, array(), self::GROUP ) !== false ) {
				return; // A push is already pending within the window.
			}
			as_schedule_single_action( time() + self::DEBOUNCE, self::HOOK_PUSH, array(), self::GROUP );
		} elseif ( ! wp_next_scheduled( self::HOOK_PUSH ) ) {
			wp_schedule_single_event( time() + self::DEBOUNCE, self::HOOK_PUSH );
		}
	}

	/**
	 * Re-create the recurring backup action from the current interval setting.
	 * Call after the schedule changes so a new cadence (e.g. weekly) takes effect
	 * immediately instead of waiting for the old recurring action to lapse.
	 */
	public function rescheduleRecurringPush(): void {
		if ( function_exists( 'as_unschedule_all_actions' ) ) {
			as_unschedule_all_actions( self::HOOK_PUSH, array(), self::GROUP );
		} else {
			$ts = wp_next_scheduled( self::HOOK_PUSH );
			while ( $ts ) {
				wp_unschedule_event( $ts, self::HOOK_PUSH );
				$ts = wp_next_scheduled( self::HOOK_PUSH );
			}
		}
		if ( $this->plugin->isEnabled() ) {
			$this->ensureRecurringPush();
		}
	}

	private function ensureRecurringPush(): void {
		$interval = (int) $this->plugin->settings->get( 'schedule_interval', HOUR_IN_SECONDS );
		if ( $interval <= 0 ) {
			return; // Scheduled backups disabled; manual/on-change only.
		}

		if ( function_exists( 'as_schedule_recurring_action' ) ) {
			if ( ! as_has_scheduled_action( self::HOOK_PUSH, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + $interval, $interval, self::HOOK_PUSH, array(), self::GROUP );
			}
		} elseif ( ! wp_next_scheduled( self::HOOK_PUSH ) ) {
			wp_schedule_event( time() + $interval, 'wp2git_backup', self::HOOK_PUSH );
		}
	}

	private function scheduleDelayed( string $hook, array $args ): void {
		if ( function_exists( 'as_schedule_single_action' ) ) {
			as_schedule_single_action( time() + self::RETRY_DELAY, $hook, $args, self::GROUP );
		} else {
			wp_schedule_single_event( time() + self::RETRY_DELAY, $hook, $args );
		}
	}

	// --- Run --------------------------------------------------------------

	/** @param mixed $attempt Positional arg from AS / WP-Cron. */
	public function runPush( $attempt = 0 ): void {
		$attempt = is_array( $attempt ) ? (int) ( $attempt[0] ?? 0 ) : (int) $attempt;
		$result  = $this->plugin->pusher->push();
		if ( $this->isBusy( $result ) ) {
			$this->retryOrGiveUp( 'push', $attempt, fn ( int $next ) => $this->enqueuePush( $next ) );
		}
	}

	/**
	 * @param mixed $sha     Positional arg from AS / WP-Cron.
	 * @param mixed $attempt Positional arg from AS / WP-Cron.
	 */
	public function runPull( $sha = '', $attempt = 0 ): void {
		if ( is_array( $sha ) ) {
			$attempt = (int) ( $sha[1] ?? 0 );
			$sha     = $sha[0] ?? '';
		}
		$sha     = (string) $sha;
		$attempt = (int) $attempt;
		if ( $sha === '' ) {
			return;
		}
		$result = $this->plugin->applier->apply( $sha );
		if ( $this->isBusy( $result ) ) {
			$this->retryOrGiveUp( 'pull', $attempt, fn ( int $next ) => $this->enqueuePull( $sha, $next ), $sha );
		}
	}

	// --- Helpers ----------------------------------------------------------

	private function isBusy( mixed $result ): bool {
		return is_wp_error( $result ) && $result->get_error_code() === 'wp2git_busy';
	}

	private function retryOrGiveUp( string $kind, int $attempt, callable $reenqueue, string $context = '' ): void {
		$next = $attempt + 1;
		if ( $next >= self::MAX_RETRIES ) {
			$this->plugin->logger->error(
				"Gave up {$kind} after lock contention",
				array(
					'attempts' => $next,
					'context'  => $context,
				)
			);
			return;
		}
		$reenqueue( $next );
	}
}
