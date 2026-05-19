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
 * The plugin's runtime code lives under `src/` and is loaded through two
 * Composer-generated autoloaders:
 *
 *   - vendor-prefixed/autoload.php — the cron-monitor PHP SDK and
 *     nyholm/psr7, namespace-prefixed by Strauss into
 *     `Cronheart\WP\Vendor\…` to avoid clashes with other plugins that
 *     ship the same packages under their canonical namespaces.
 *
 *   - vendor/autoload.php — Composer's main autoloader, which also
 *     resolves this plugin's own `Cronheart\WP\…` namespace via the
 *     PSR-4 mapping in composer.json.
 *
 * We try vendor-prefixed first because release builds delete the
 * original `vendor/cron-monitor` and `vendor/nyholm` directories after
 * Strauss has copied them with prefixes; falling through to vendor/
 * alone lets a developer working from the source tree boot the plugin
 * before they have run Strauss.
 */
$cronheart_prefixed_autoload = __DIR__ . '/vendor-prefixed/autoload.php';
$cronheart_vendor_autoload   = __DIR__ . '/vendor/autoload.php';
if ( file_exists( $cronheart_prefixed_autoload ) ) {
	require_once $cronheart_prefixed_autoload;
}
if ( file_exists( $cronheart_vendor_autoload ) ) {
	require_once $cronheart_vendor_autoload;
}
unset( $cronheart_prefixed_autoload, $cronheart_vendor_autoload );

if ( ! class_exists( \Cronheart\WP\Plugin::class ) ) {
	// Plugin source missing — typically a misconfigured install (plugin
	// uploaded without `vendor/`). Stay silent rather than `wp_die()`
	// so site-wide admin remains reachable; later commits surface a
	// notice in wp-admin instead.
	return;
}

( new \Cronheart\WP\Plugin() )->boot();
