<?php
/**
 * Plugin Name:       Cronheart
 * Plugin URI:        https://github.com/alexander-po/cronheart-wp
 * Description:       Monitors WP-Cron with cronheart.com. Detects when scheduled events stop firing — heartbeat for the whole site plus per-event start/success/fail pings.
 * Version:           0.1.0
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
 * The plugin's runtime code lives under `src/` and is loaded through
 * Composer's PSR-4 autoloader.
 *
 * Vendor namespace prefixing (via Strauss / php-scoper) is intentionally
 * deferred to v0.1.1+ when we submit to wordpress.org. For the v0.1.0
 * GitHub-only release we ship the SDK under its canonical
 * `CronMonitor\…` namespace. Conflict risk is minimal in practice
 * because `cron-monitor/php-sdk` is not yet bundled by any other
 * plugin — the namespace is unique to this integration.
 */
$cronheart_vendor_autoload = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $cronheart_vendor_autoload ) ) {
	require_once $cronheart_vendor_autoload;
}
unset( $cronheart_vendor_autoload );

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
