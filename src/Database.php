<?php

declare(strict_types=1);

namespace WP2Git;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves WP2Git table names. Network-scoped installs use the network-wide
 * base prefix so the manifest/log are shared (matching the shared wp-content);
 * single-site installs use the regular site prefix.
 */
final class Database {

	public static function prefix(): string {
		global $wpdb;
		return Context::networkScoped() ? $wpdb->base_prefix : $wpdb->prefix;
	}

	public static function table( string $name ): string {
		return self::prefix() . 'wp2git_' . $name;
	}
}
