<?php

declare(strict_types=1);

namespace WP2Git;

defined( 'ABSPATH' ) || exit;

/**
 * Sync state machine + advisory lock. Push and pull must never interleave;
 * webhooks arriving mid-push are queued by the caller, not dropped.
 */
final class State {

	public const IDLE    = 'idle';
	public const PUSHING = 'pushing';
	public const PULLING = 'pulling';

	private const OPTION_STATE = 'wp2git_state';
	private const OPTION_LOCK  = 'wp2git_lock';

	/** Lock auto-expires so a crashed job can't wedge sync permanently. */
	private const LOCK_TTL = 600; // Seconds.

	public function current(): string {
		return (string) Options::get( self::OPTION_STATE, self::IDLE );
	}

	/**
	 * Acquire the lock and transition. Returns false if another job holds a
	 * lock that has not yet expired.
	 */
	public function acquire( string $to ): bool {
		if ( ! in_array( $to, array( self::PUSHING, self::PULLING ), true ) ) {
			return false;
		}

		$lock = Options::get( self::OPTION_LOCK, 0 );
		$now  = time();
		if ( is_numeric( $lock ) && (int) $lock > $now ) {
			return false; // Still locked.
		}

		Options::set( self::OPTION_LOCK, $now + self::LOCK_TTL );
		Options::set( self::OPTION_STATE, $to );
		return true;
	}

	public function release(): void {
		Options::set( self::OPTION_STATE, self::IDLE );
		Options::set( self::OPTION_LOCK, 0 );
	}

	public function isLocked(): bool {
		$lock = Options::get( self::OPTION_LOCK, 0 );
		return is_numeric( $lock ) && (int) $lock > time();
	}

	/** Cursor: the last commit SHA we have fully reconciled in either direction. */
	public function lastSyncedSha(): ?string {
		$sha = Options::get( 'wp2git_last_synced_sha', '' );
		return is_string( $sha ) && $sha !== '' ? $sha : null;
	}

	public function setLastSyncedSha( string $sha ): void {
		Options::set( 'wp2git_last_synced_sha', $sha );
	}
}
