<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Cron;

use Cronheart\WP\Cron\IntervalMonitorBlueprint;
use PHPUnit\Framework\TestCase;

final class IntervalMonitorBlueprintTest extends TestCase
{
    private const SITE = 'https://example.test';

    public function test_maps_a_recurring_hook_to_a_clamped_create_request(): void
    {
        $bp = IntervalMonitorBlueprint::fromEvent('wp_version_check', 43200, 'UTC', self::SITE);

        self::assertNotNull($bp);
        self::assertSame('wp_version_check', $bp->name);
        self::assertSame(43200, $bp->intervalSeconds);
        self::assertSame(4320, $bp->graceSeconds, 'grace = interval / 10');
        self::assertSame('UTC', $bp->tz);
        self::assertSame('wp-'.hash('sha256', self::SITE.'|wp_version_check'), $bp->idempotencyKey);
    }

    public function test_not_auto_creatable_outside_the_backend_interval_range(): void
    {
        self::assertNull(IntervalMonitorBlueprint::fromEvent('h', null, 'UTC', self::SITE), 'one-off (no interval)');
        self::assertNull(IntervalMonitorBlueprint::fromEvent('h', 29, 'UTC', self::SITE), 'sub-30s');
        self::assertNull(IntervalMonitorBlueprint::fromEvent('h', 31_622_401, 'UTC', self::SITE), 'over a year');
        // Boundaries are inclusive.
        self::assertNotNull(IntervalMonitorBlueprint::fromEvent('h', 30, 'UTC', self::SITE));
        self::assertNotNull(IntervalMonitorBlueprint::fromEvent('h', 31_622_400, 'UTC', self::SITE));
    }

    /**
     * @dataProvider graceCases
     */
    public function test_grace_is_a_tenth_floored_at_a_minute_capped_at_a_day(int $interval, int $expectedGrace): void
    {
        $bp = IntervalMonitorBlueprint::fromEvent('hook', $interval, 'UTC', self::SITE);

        self::assertNotNull($bp);
        self::assertSame($expectedGrace, $bp->graceSeconds);
    }

    /**
     * @return iterable<string, array{0: int, 1: int}>
     */
    public static function graceCases(): iterable
    {
        yield '5 min -> floor 60' => [300, 60];
        yield '10 min -> exactly 60' => [600, 60];
        yield '20 min -> 120' => [1200, 120];
        yield '1 day -> 8640' => [86400, 8640];
        yield 'max interval -> capped 86400' => [31_622_400, 86400];
    }

    public function test_name_is_clamped_to_the_backend_length_bounds(): void
    {
        $long = str_repeat('a', 200);
        $bpLong = IntervalMonitorBlueprint::fromEvent($long, 3600, 'UTC', self::SITE);
        self::assertNotNull($bpLong);
        self::assertSame(120, mb_strlen($bpLong->name), 'truncated to max:120');

        $bpShort = IntervalMonitorBlueprint::fromEvent('x', 3600, 'UTC', self::SITE);
        self::assertNotNull($bpShort);
        self::assertSame('x_', $bpShort->name, 'padded to min:2');
    }

    public function test_offset_timezone_falls_back_to_utc(): void
    {
        $bp = IntervalMonitorBlueprint::fromEvent('hook', 3600, '', self::SITE);

        self::assertNotNull($bp);
        self::assertSame('UTC', $bp->tz);
    }
}
