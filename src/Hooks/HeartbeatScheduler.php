<?php

declare(strict_types=1);

namespace Cronheart\WP\Hooks;

/**
 * Registers and maintains the site-level heartbeat WP-Cron event that
 * the plugin uses to prove "WP-Cron is still firing on this site".
 *
 * Lifecycle:
 *
 *   - Plugin activation: install a 5-minute custom schedule and
 *     register `cronheart_heartbeat_tick` on it. Wired up from
 *     `cronheart.php` via `register_activation_hook`.
 *   - Plugin deactivation: clear the scheduled event so a removed
 *     plugin does not leave an orphan entry rattling around in the
 *     `cron` option. Wired up via `register_deactivation_hook`.
 *   - Each request: `Plugin::boot()` re-installs the custom-schedule
 *     filter (idempotent) so the schedule is known to WP-Cron even
 *     if the option got reset between activation and the next run.
 *
 * The 5-minute interval is a deliberate compromise: shorter would
 * pressure the cronheart Free tier rate limit and waste billable
 * pings; longer would delay detection of a stalled WP-Cron beyond
 * what most operators expect from a "did my site run?" alert.
 */
final class HeartbeatScheduler
{
    public const SCHEDULE_SLUG = 'cronheart_5min';

    public const TICK_HOOK = 'cronheart_heartbeat_tick';

    private const INTERVAL_SECONDS = 300;

    /**
     * @param array<string, array{interval: int, display: string}> $schedules
     *
     * @return array<string, array{interval: int, display: string}>
     */
    public static function add_cronheart_schedule(array $schedules): array
    {
        // Defensive: another plugin (or a custom must-use file) could
        // register an identically-named schedule and we should not
        // clobber it. The interval and display are static; if the
        // slot is already populated, accept whoever got there first.
        if (!\array_key_exists(self::SCHEDULE_SLUG, $schedules)) {
            $schedules[self::SCHEDULE_SLUG] = [
                'interval' => self::INTERVAL_SECONDS,
                'display' => 'Every 5 minutes (cronheart)',
            ];
        }

        return $schedules;
    }

    /**
     * Schedule the heartbeat tick if it isn't already scheduled.
     * Called from `register_activation_hook` in cronheart.php.
     */
    public static function activate(): void
    {
        if (false === wp_next_scheduled(self::TICK_HOOK)) {
            wp_schedule_event(time(), self::SCHEDULE_SLUG, self::TICK_HOOK);
        }
    }

    /**
     * Remove the scheduled tick on plugin deactivation so a
     * deactivated plugin does not leave a phantom event in the
     * `cron` option. Called from `register_deactivation_hook` in
     * cronheart.php.
     */
    public static function deactivate(): void
    {
        wp_clear_scheduled_hook(self::TICK_HOOK);
    }
}
