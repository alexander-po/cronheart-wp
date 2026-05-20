<?php

declare(strict_types=1);

/*
 * Global helper for plugin developers wiring per-event cron-monitor
 * coverage from PHP.
 *
 * Loaded via explicit `require_once` from `cronheart.php` in the
 * WordPress runtime, and from `tests/bootstrap.php` for the unit
 * suite. Earlier iterations wired the file through Composer's
 * `autoload.files` directive, which made the function available the
 * instant `vendor/autoload.php` ran — but that path triggered the
 * ABSPATH guard below before PHPUnit could process its own
 * bootstrap, and Plugin Check's strict regex on the guard ruled out
 * a `'cli' === PHP_SAPI` escape hatch. The explicit-require path is
 * the simpler resolution: the helper is available the moment the
 * plugin boots in production, and tests opt in deliberately.
 */

// Direct-access guard. WP.org's Plugin Check expects the canonical
// `defined('ABSPATH') || exit;` shape on every PHP file in the
// plugin tree, including this one. The check's regex match is
// strict — see comments in `Plugin.php` / the v0.1.3 CHANGELOG
// entry for the full diagnosis.
\defined('ABSPATH') || exit;

if (!\function_exists('cronheart_monitor')) {
    /**
     * Register a WP-Cron hook with cronheart for start / success /
     * fail pings.
     *
     *     cronheart_monitor( 'my_nightly_report', 'xxxxxxxx-…' );
     *     cronheart_monitor( 'my_nightly_report' );  // UUID from
     *                                                // CRONHEART_EVENT_…
     *                                                // constant
     *
     * The first argument is the WP action hook name passed to
     * `wp_schedule_event()`. The second argument is the cronheart
     * monitor UUID; pass `null` (or omit) when the UUID is supplied
     * out-of-band through `CRONHEART_EVENT_<HOOK>_UUID` in
     * `wp-config.php` — the function still needs to be called so the
     * plugin learns the hook name to instrument.
     *
     * Empty-string UUIDs are treated as explicit "do not monitor in
     * this environment" — parallels the SDK's `#[Monitor(uuid: '')]`
     * semantics and the heartbeat layer's empty-constant policy.
     *
     * Calling multiple times for the same hook is harmless; the last
     * call's UUID wins. The `function_exists()` guard around the
     * definition keeps the symbol unique across explicit `require_once`
     * calls from `cronheart.php` and `tests/bootstrap.php`; each
     * `cronheart_monitor()` invocation then adds a new filter closure,
     * and later closures see earlier values and overwrite
     * deterministically.
     */
    function cronheart_monitor(string $hook_name, ?string $monitor_uuid = null): void
    {
        add_filter(
            'cronheart_monitor_map',
            static function (array $map) use ($hook_name, $monitor_uuid): array {
                $map[$hook_name] = $monitor_uuid ?? '';

                return $map;
            }
        );
    }
}
