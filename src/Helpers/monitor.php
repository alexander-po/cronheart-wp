<?php

declare(strict_types=1);

/*
 * Global helper for plugin developers wiring per-event cron-monitor
 * coverage from PHP. Loaded through Composer `autoload.files` so it
 * is available the moment vendor/autoload.php is required, with no
 * `add_action('plugins_loaded', ...)` ceremony at the call site.
 */

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
     * call's UUID wins (Composer's `files` autoload guarantees the
     * function is defined once, but each call adds a new filter
     * closure — later closures see earlier values and overwrite
     * deterministically).
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
