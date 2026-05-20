<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Hooks;

use Cronheart\WP\Hooks\HeartbeatScheduler;
use PHPUnit\Framework\TestCase;

final class HeartbeatSchedulerTest extends TestCase
{
    public function test_add_cronheart_schedule_inserts_the_5_minute_slot_when_absent(): void
    {
        $schedules = HeartbeatScheduler::add_cronheart_schedule([]);

        self::assertArrayHasKey(HeartbeatScheduler::SCHEDULE_SLUG, $schedules);
        self::assertSame(300, $schedules[HeartbeatScheduler::SCHEDULE_SLUG]['interval']);
    }

    public function test_add_cronheart_schedule_does_not_clobber_a_pre_existing_slot(): void
    {
        // Another plugin (or a must-use file) might register an
        // identically-named schedule. Defensive policy: first writer
        // wins. We do not silently mutate it to our values, because
        // doing so would surprise the other plugin's developer.
        $existing = [
            HeartbeatScheduler::SCHEDULE_SLUG => [
                'interval' => 60,
                'display' => 'Some other plugin (1 minute)',
            ],
            'hourly' => [
                'interval' => 3600,
                'display' => 'Once Hourly',
            ],
        ];

        $schedules = HeartbeatScheduler::add_cronheart_schedule($existing);

        self::assertSame(60, $schedules[HeartbeatScheduler::SCHEDULE_SLUG]['interval']);
        self::assertSame('Some other plugin (1 minute)', $schedules[HeartbeatScheduler::SCHEDULE_SLUG]['display']);
        self::assertSame(3600, $schedules['hourly']['interval']);
    }

    public function test_add_cronheart_schedule_preserves_unrelated_slots(): void
    {
        $schedules = HeartbeatScheduler::add_cronheart_schedule([
            'twicedaily' => ['interval' => 43200, 'display' => 'Twice Daily'],
        ]);

        self::assertArrayHasKey(HeartbeatScheduler::SCHEDULE_SLUG, $schedules);
        self::assertArrayHasKey('twicedaily', $schedules);
        self::assertSame(43200, $schedules['twicedaily']['interval']);
    }

    public function test_constants_match_the_documented_runtime_contract(): void
    {
        // These are documented in the plugin README and referenced
        // by the future Admin\EventList view. Pinning them here keeps
        // accidental renames out of reach.
        self::assertSame('cronheart_5min', HeartbeatScheduler::SCHEDULE_SLUG);
        self::assertSame('cronheart_heartbeat_tick', HeartbeatScheduler::TICK_HOOK);
    }
}
