<?php

declare(strict_types=1);

namespace WP2Git;

defined( 'ABSPATH' ) || exit;

/**
 * Append-only structured audit log. Never write secrets here.
 */
final class Logger {

	public const PUSH     = 'push';
	public const PULL     = 'pull';
	public const CONNECT  = 'connect';
	public const CONFLICT = 'conflict';
	public const ERROR    = 'error';

	/*
	 * Data-access layer for our own custom wp2git_log table. Direct $wpdb queries
	 * are required; the interpolated table name is internal, never user input; and
	 * values are parameterised. An audit log must not be cached.
	 */
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	private function table(): string {
		return Database::table( 'log' );
	}

	/**
	 * @param array<string,mixed> $detail
	 */
	public function log( string $event, array $detail = array(), ?string $commitSha = null, ?string $actor = null ): void {
		global $wpdb;

		$wpdb->insert(
			$this->table(),
			array(
				'event'      => $event,
				'commit_sha' => $commitSha,
				'actor'      => $actor ?? $this->currentActor(),
				'detail'     => wp_json_encode( $detail ),
				'created_at' => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s', '%s' )
		);
	}

	public function error( string $message, array $detail = array() ): void {
		$this->log( self::ERROR, array_merge( array( 'message' => $message ), $detail ) );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	public function recent( int $limit = 50 ): array {
		global $wpdb;
		$limit = max( 1, min( 500, $limit ) );
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$this->table()} ORDER BY id DESC LIMIT %d",
				$limit
			),
			ARRAY_A
		);
		return is_array( $rows ) ? $rows : array();
	}

	// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	private function currentActor(): string {
		if ( function_exists( 'wp_get_current_user' ) && is_user_logged_in() ) {
			$u = wp_get_current_user();
			return $u->user_login ?: 'system';
		}
		return 'system';
	}
}
