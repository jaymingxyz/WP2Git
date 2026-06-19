<?php

declare(strict_types=1);

namespace WP2Git;

defined( 'ABSPATH' ) || exit;

/**
 * Option accessor that routes to network options when WP2Git is network-scoped,
 * and to per-site options otherwise. All persistent config and sync state goes
 * through here so the single-site and multisite paths stay identical elsewhere.
 */
final class Options {

	public static function get( string $key, mixed $default = false ): mixed {
		return Context::networkScoped() ? get_site_option( $key, $default ) : get_option( $key, $default );
	}

	public static function set( string $key, mixed $value ): void {
		if ( Context::networkScoped() ) {
			update_site_option( $key, $value );
		} else {
			update_option( $key, $value, false );
		}
	}

	public static function delete( string $key ): void {
		if ( Context::networkScoped() ) {
			delete_site_option( $key );
		} else {
			delete_option( $key );
		}
	}
}
