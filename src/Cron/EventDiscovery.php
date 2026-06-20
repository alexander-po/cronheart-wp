<?php

declare(strict_types=1);

namespace Cronheart\WP\Cron;

// Direct-access guard. See `Plugin.php` for the rationale —
// same canonical pattern, same Plugin-Check-imposed shape.
\defined('ABSPATH') || exit;

/**
 * Discovers this site's WP-Cron events for the per-event monitoring UI.
 *
 * Pure PHP, in the same spirit as {@see \Cronheart\WP\Config\Resolver}: the
 * three injected closures own the only contact with WordPress globals
 * (`_get_cron_array()`, `wp_get_schedules()`, `wp_timezone_string()`), so
 * the discovery logic is unit-testable without a WP runtime.
 *
 * The WP-Cron array is keyed by run timestamp, then hook name, then a
 * per-argument signature; a recurring hook carries a `schedule` name and an
 * `interval` (seconds). This service tolerates a missing / non-array / empty
 * cron option (including the stray integer `version` key WordPress stores
 * alongside the timestamp entries), de-duplicates by hook name (a hook with
 * several argument signatures, or scheduled at several timestamps, collapses
 * to one row), and exposes a recurring-only view — the only kind the UI can
 * map to an interval monitor.
 */
final class EventDiscovery
{
    /**
     * @param \Closure(): mixed $cronArrayReader returns `_get_cron_array()` (array, or false when WP-Cron is empty)
     * @param \Closure(): mixed $schedulesReader returns `wp_get_schedules()` (name => {interval, display})
     * @param \Closure(): mixed $timezoneReader  returns `wp_timezone_string()`
     */
    public function __construct(
        private readonly \Closure $cronArrayReader,
        private readonly \Closure $schedulesReader,
        private readonly \Closure $timezoneReader,
    ) {
    }

    /**
     * Every discovered hook, de-duplicated by hook name. Recurring hooks
     * carry their schedule name + interval seconds; one-off hooks
     * (`schedule === false`) carry null for both and `isRecurring => false`.
     *
     * @return list<array{hook: string, schedule: ?string, intervalSeconds: ?int, nextRun: ?int, isRecurring: bool}>
     */
    public function events(): array
    {
        $cron = ($this->cronArrayReader)();
        if (!\is_array($cron)) {
            return [];
        }

        $scheduleIntervals = $this->scheduleIntervals();

        $byHook = [];
        foreach ($cron as $timestamp => $hooks) {
            // Skips the integer `version` entry and any malformed slot.
            if (!\is_array($hooks)) {
                continue;
            }
            $nextRun = \is_int($timestamp) ? $timestamp : (is_numeric($timestamp) ? (int) $timestamp : null);

            foreach ($hooks as $hook => $signatures) {
                if (!\is_string($hook) || '' === $hook || !\is_array($signatures)) {
                    continue;
                }

                [$schedule, $interval] = $this->recurrenceOf($signatures, $scheduleIntervals);

                if (!isset($byHook[$hook])) {
                    $byHook[$hook] = [
                        'hook' => $hook,
                        'schedule' => $schedule,
                        'intervalSeconds' => $interval,
                        'nextRun' => $nextRun,
                        'isRecurring' => null !== $schedule,
                    ];

                    continue;
                }

                // Already seen this hook at another timestamp / signature:
                // keep the earliest next run, and upgrade to recurring if any
                // occurrence is recurring.
                if (null !== $nextRun && (null === $byHook[$hook]['nextRun'] || $nextRun < $byHook[$hook]['nextRun'])) {
                    $byHook[$hook]['nextRun'] = $nextRun;
                }
                if (null !== $schedule && !$byHook[$hook]['isRecurring']) {
                    $byHook[$hook]['schedule'] = $schedule;
                    $byHook[$hook]['intervalSeconds'] = $interval;
                    $byHook[$hook]['isRecurring'] = true;
                }
            }
        }

        return array_values($byHook);
    }

    /**
     * The recurring events only, sorted by hook name — the default the UI
     * shows (a one-off cannot be mapped to an interval monitor).
     *
     * @return list<array{hook: string, schedule: ?string, intervalSeconds: ?int, nextRun: ?int, isRecurring: bool}>
     */
    public function recurringEvents(): array
    {
        $events = array_values(array_filter($this->events(), static fn (array $event): bool => $event['isRecurring']));
        usort($events, static fn (array $a, array $b): int => strcmp($a['hook'], $b['hook']));

        return $events;
    }

    /**
     * Site timezone for monitor creation: the IANA name from
     * `wp_timezone_string()`, or `'UTC'` when WordPress returns a fixed
     * numeric offset (e.g. `"+03:00"`, which it does when the site is set to
     * a manual UTC offset rather than a city). A frozen offset does not track
     * DST and is not an IANA zone name, so UTC is the unambiguous, DST-safe
     * choice for a monitor's schedule when no real zone name is available.
     */
    public function timezone(): string
    {
        $tz = ($this->timezoneReader)();
        if (!\is_string($tz) || '' === trim($tz)) {
            return 'UTC';
        }
        if (1 === preg_match('/^[+-]/', $tz)) {
            return 'UTC';
        }

        return $tz;
    }

    /**
     * @return array<string, int> schedule name => interval seconds
     */
    private function scheduleIntervals(): array
    {
        $schedules = ($this->schedulesReader)();
        if (!\is_array($schedules)) {
            return [];
        }

        $intervals = [];
        foreach ($schedules as $name => $definition) {
            if (\is_string($name) && \is_array($definition) && isset($definition['interval']) && \is_int($definition['interval'])) {
                $intervals[$name] = $definition['interval'];
            }
        }

        return $intervals;
    }

    /**
     * Resolve a hook's recurrence from its argument signatures: the first
     * signature carrying a non-empty `schedule` wins. The interval comes
     * from the signature's own `interval` when present, else the
     * `wp_get_schedules()` lookup for that schedule name.
     *
     * @param array<array-key, mixed> $signatures
     * @param array<string, int>      $scheduleIntervals
     *
     * @return array{0: ?string, 1: ?int} [schedule name, interval seconds]
     */
    private function recurrenceOf(array $signatures, array $scheduleIntervals): array
    {
        foreach ($signatures as $signature) {
            if (!\is_array($signature)) {
                continue;
            }
            $schedule = $signature['schedule'] ?? false;
            if (!\is_string($schedule) || '' === $schedule) {
                continue;
            }

            $interval = null;
            if (isset($signature['interval']) && \is_int($signature['interval'])) {
                $interval = $signature['interval'];
            } elseif (isset($scheduleIntervals[$schedule])) {
                $interval = $scheduleIntervals[$schedule];
            }

            return [$schedule, $interval];
        }

        return [null, null];
    }
}
