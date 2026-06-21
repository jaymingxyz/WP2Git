<?php
/**
 * Plugin Name:       WP2Git
 * Plugin URI:        https://github.com/jaymingxyz/WP2Git
 * Description:        Two-way sync between wp-content and a GitHub repository: back up to GitHub and apply commits back to the site.
 * Version:           1.3.0
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            Tungsten Digital
 * Author URI:        https://github.com/jaymingxyz
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       wp2git
 * Domain Path:       /languages
 *
 * @package WP2Git
 */

declare(strict_types=1);

namespace WP2Git;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // No direct access.
}

// ---------------------------------------------------------------------------
// Constants
// ---------------------------------------------------------------------------
define( 'WP2GIT_VERSION', '1.3.0' );
define( 'WP2GIT_FILE', __FILE__ );
define( 'WP2GIT_DIR', plugin_dir_path( __FILE__ ) );
define( 'WP2GIT_URL', plugin_dir_url( __FILE__ ) );
define( 'WP2GIT_BASENAME', plugin_basename( __FILE__ ) );

// Emergency kill switch: define WP2GIT_DISABLE in wp-config.php to halt all sync.
if ( ! defined( 'WP2GIT_DISABLE' ) ) {
	define( 'WP2GIT_DISABLE', false );
}

// ---------------------------------------------------------------------------
// Autoloading: prefer Composer, fall back to a minimal PSR-4 loader so the
// plugin runs even before `composer install` (Action Scheduler features that
// need vendor/ degrade gracefully until then).
// ---------------------------------------------------------------------------
if ( is_readable( WP2GIT_DIR . 'vendor/autoload.php' ) ) {
	require_once WP2GIT_DIR . 'vendor/autoload.php';
} else {
	spl_autoload_register(
		static function ( string $class ): void {
			$prefix = 'WP2Git\\';
			if ( ! str_starts_with( $class, $prefix ) ) {
				return;
			}
			$relative = substr( $class, strlen( $prefix ) );
			$path     = WP2GIT_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	);
}

// ---------------------------------------------------------------------------
// Lifecycle hooks
// ---------------------------------------------------------------------------
// $network_wide is passed by WordPress when the plugin is network-activated.
register_activation_hook( __FILE__, array( Install::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( Install::class, 'deactivate' ) );

// ---------------------------------------------------------------------------
// Boot
// ---------------------------------------------------------------------------
add_action(
	'plugins_loaded',
	static function (): void {
		Plugin::instance()->boot();
	}
);
