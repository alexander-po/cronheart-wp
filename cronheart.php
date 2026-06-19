<?php
/**
 * Plugin Name:       Cronheart
 * Plugin URI:        https://github.com/alexander-po/cronheart-wp
 * Description:       Monitors WP-Cron with cronheart.com. Detects when scheduled events stop firing — heartbeat for the whole site plus per-event start/success/fail pings.
 * Version:           0.2.1
 * Requires at least: 6.0
 * Requires PHP:      8.2
 * Author:            Aliaksandr Palazok
 * Author URI:        https://github.com/alexander-po
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       cronheart
 *
 * @package Cronheart\WP
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

/*
 * Plugin version and main-file path, used to enqueue and cache-bust the
 * admin assets. CRONHEART_VERSION must match the `Version:` header above;
 * the release checklist bumps both in the same edit.
 */
define( 'CRONHEART_VERSION', '0.2.1' );
define( 'CRONHEART_PLUGIN_FILE', __FILE__ );

/*
 * The plugin's runtime code lives under `src/` and is loaded through
 * Composer's PSR-4 autoloader.
 *
 * Vendor namespace prefixing (via Strauss / php-scoper) is intentionally
 * deferred — see README and CHANGELOG. We ship the SDK under its
 * canonical `CronMonitor\…` namespace; conflict risk is minimal in
 * practice because `cron-monitor/php-sdk` is not yet bundled by any
 * other WordPress plugin, so the namespace is effectively unique to
 * this integration. The prefixing step will be revisited if a
 * collision is reported in the wild.
 */
$cronheart_vendor_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $cronheart_vendor_autoload ) ) {
	require_once $cronheart_vendor_autoload;
}
unset( $cronheart_vendor_autoload );

/*
 * The `cronheart_monitor()` global helper used to be wired through
 * Composer's `autoload.files` directive. That made the function
 * available on every `require vendor/autoload.php`, including from
 * test bootstraps that have not yet defined `ABSPATH` — at which
 * point the file's own `defined('ABSPATH') || exit;` guard would
 * silently terminate the test runner. Loading the helper explicitly
 * from this plugin entry point keeps the function's availability
 * scoped to a real WordPress runtime (where `ABSPATH` is defined
 * by `wp-load.php` long before plugins load) and lets the same
 * guard live unmodified in the source file.
 */
$cronheart_helper = __DIR__ . '/src/Helpers/monitor.php';
if ( file_exists( $cronheart_helper ) ) {
	require_once $cronheart_helper;
}
unset( $cronheart_helper );

if ( ! class_exists( \Cronheart\WP\Plugin::class ) ) {
	// Plugin source missing — typically a misconfigured install
	// (someone uploaded the repo zip from GitHub's "Code → Download
	// ZIP" instead of the release `cronheart.zip`, or git-cloned
	// without `composer install`). Stay silent at runtime (do NOT
	// `wp_die()` — that would lock the operator out of site-wide
	// admin) but surface a notice in wp-admin so the cause is
	// diagnosable rather than mysterious.
	add_action(
		'admin_notices',
		static function (): void {
			if ( ! current_user_can( 'manage_options' ) ) {
				return;
			}
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'Cronheart: plugin dependencies are missing (vendor/ directory not found). The plugin will not run until you install the release zip from the GitHub releases page, or run "composer install" in the plugin directory.',
				'cronheart'
			);
			echo '</p></div>';
		}
	);

	return;
}

// Activation / deactivation hooks must reference the plugin's main
// file (`__FILE__` resolves to cronheart.php here), so the calls live
// at the top level rather than inside the bootstrap class. They
// schedule / unschedule the heartbeat WP-Cron event.
register_activation_hook( __FILE__, array( \Cronheart\WP\Hooks\HeartbeatScheduler::class, 'activate' ) );
register_deactivation_hook( __FILE__, array( \Cronheart\WP\Hooks\HeartbeatScheduler::class, 'deactivate' ) );

( new \Cronheart\WP\Plugin() )->boot();
