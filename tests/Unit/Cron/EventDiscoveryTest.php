<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Cron;

use Cronheart\WP\Cron\EventDiscovery;
use PHPUnit\Framework\TestCase;

final class EventDiscoveryTest extends TestCase
{
    private const SCHEDULES = [
        'hourly' => ['interval' => 3600, 'display' => 'Once Hourly'],
        'twicedaily' => ['interval' => 43200, 'display' => 'Twice Daily'],
    ];

    public function test_discovers_recurring_and_one_off_hooks_deduped_by_hook(): void
    {
        $cron = [
            'version' => 2, // the stray integer key WP stores — must be skipped
            1_000_000_000 => [
                'wp_version_check' => [
                    'sigA' => ['schedule' => 'twicedaily', 'args' => [], 'interval' => 43200],
                ],
                'my_one_off' => [
                    'sigB' => ['schedule' => false, 'args' => []],
                ],
            ],
            1_000_000_300 => [
                // Same hook at a later timestamp — collapses to one row.
                'wp_version_check' => [
                    'sigC' => ['schedule' => 'twicedaily', 'args' => [], 'interval' => 43200],
                ],
                // Recurring but no per-signature interval — resolved via wp_get_schedules().
                'feed_refresh' => [
                    'sigD' => ['schedule' => 'hourly', 'args' => []],
                ],
            ],
        ];

        $events = $this->discovery($cron)->events();
        $byHook = [];
        foreach ($events as $event) {
            $byHook[$event['hook']] = $event;
        }

        self::assertCount(3, $events, 'three distinct hooks, deduped');
        self::assertSame('twicedaily', $byHook['wp_version_check']['schedule']);
        self::assertSame(43200, $byHook['wp_version_check']['intervalSeconds']);
        self::assertTrue($byHook['wp_version_check']['isRecurring']);
        self::assertSame(1_000_000_000, $byHook['wp_version_check']['nextRun'], 'earliest next run wins');

        self::assertFalse($byHook['my_one_off']['isRecurring']);
        self::assertNull($byHook['my_one_off']['intervalSeconds']);
        self::assertNull($byHook['my_one_off']['schedule']);

        self::assertTrue($byHook['feed_refresh']['isRecurring']);
        self::assertSame(3600, $byHook['feed_refresh']['intervalSeconds'], 'interval filled from wp_get_schedules()');
    }

    public function test_dedupe_upgrades_a_hook_to_recurring_if_any_occurrence_is_recurring(): void
    {
        // Insertion order = iteration order: the one-off occurrence is seen
        // first, then the recurring one at an earlier timestamp.
        $cron = [
            1_000_000_500 => [
                'mixed_hook' => [['schedule' => false, 'args' => []]],
            ],
            1_000_000_000 => [
                'mixed_hook' => [['schedule' => 'hourly', 'args' => [], 'interval' => 3600]],
            ],
        ];

        $events = $this->discovery($cron)->events();

        self::assertCount(1, $events);
        self::assertTrue($events[0]['isRecurring'], 'recurring at any occurrence => recurring');
        self::assertSame(3600, $events[0]['intervalSeconds']);
        self::assertSame(1_000_000_000, $events[0]['nextRun'], 'earliest next run across occurrences');
    }

    public function test_recurring_events_excludes_one_off_and_sorts_by_hook(): void
    {
        $cron = [
            1_000_000_000 => [
                'zeta_recurring' => [['schedule' => 'hourly', 'interval' => 3600]],
                'alpha_recurring' => [['schedule' => 'twicedaily', 'interval' => 43200]],
                'mid_one_off' => [['schedule' => false]],
            ],
        ];

        $recurring = $this->discovery($cron)->recurringEvents();

        self::assertSame(['alpha_recurring', 'zeta_recurring'], array_map(static fn (array $e): string => $e['hook'], $recurring));
    }

    public function test_tolerates_missing_or_malformed_cron_array(): void
    {
        self::assertSame([], $this->discovery(false)->events());
        self::assertSame([], $this->discovery(null)->events());
        self::assertSame([], $this->discovery([])->events());
        // A cron array that is only the version key produces no events.
        self::assertSame([], $this->discovery(['version' => 2])->events());
        // Malformed hook slots are skipped without error.
        self::assertSame([], $this->discovery([1_000 => ['' => [['schedule' => 'hourly']]]])->events());
    }

    public function test_timezone_passes_iana_through_and_falls_back_to_utc_on_offset(): void
    {
        self::assertSame('America/New_York', $this->discovery([], 'America/New_York')->timezone());
        self::assertSame('UTC', $this->discovery([], 'UTC')->timezone());
        self::assertSame('UTC', $this->discovery([], '+03:00')->timezone());
        self::assertSame('UTC', $this->discovery([], '-0500')->timezone());
        self::assertSame('UTC', $this->discovery([], '')->timezone());
    }

    private function discovery(mixed $cron, string $tz = 'UTC'): EventDiscovery
    {
        return new EventDiscovery(
            cronArrayReader: static fn () => $cron,
            schedulesReader: static fn (): array => self::SCHEDULES,
            timezoneReader: static fn (): string => $tz,
        );
    }
}
