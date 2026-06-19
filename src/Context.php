<?php

declare(strict_types=1);

namespace WP2Git;

defined( 'ABSPATH' ) || exit;

/**
 * Runtime context helpers. On multisite, wp-content (themes/plugins/mu-plugins)
 * is shared network-wide, so when the plugin is network-activated WP2Git keeps a
 * single network-level connection, manifest and log rather than per-site copies.
 */
final class Context {

	public static function networkScoped(): bool {
		return is_multisite() && (bool) get_site_option( 'wp2git_network_active', false );
	}
}
