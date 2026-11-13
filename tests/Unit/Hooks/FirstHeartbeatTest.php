<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Hooks;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Cronheart\WP\Hooks\FirstHeartbeat;
use Cronheart\WP\Hooks\HeartbeatScheduler;
use PHPUnit\Framework\TestCase;

final class FirstHeartbeatTest extends TestCase
{
    private const UUID = '00000000-0000-0000-0000-000000000001';

    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_register_hooks_both_option_actions(): void
    {
        $handler = new FirstHeartbeat();
        $added = [];
        Functions\when('add_action')->alias(static function (string $hook, callable $callback, int $priority, int $args) use (&$added): bool {
            $added[] = [$hook, $callback, $priority, $args];

            return true;
        });

        $handler->register();

        self::assertSame([
            ['add_option_cronheart_heartbeat_uuid', [$handler, 'on_added'], 10, 2],
            ['update_option_cronheart_heartbeat_uuid', [$handler, 'on_updated'], 10, 2],
        ], $added);
    }

    public function test_a_first_saved_uuid_queues_one_single_tick_without_any_http_call(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_single_event')->once()->with(\Mockery::type('int'), HeartbeatScheduler::TICK_HOOK, ['first-heartbeat']);
        Functions\expect('wp_remote_get')->never();
        Functions\expect('wp_remote_post')->never();
        Functions\expect('spawn_cron')->never();

        (new FirstHeartbeat())->on_added('cronheart_heartbeat_uuid', self::UUID);
        $this->addToAssertionCount(1);
    }

    public function test_a_changed_uuid_queues_a_tick(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_single_event')->once();

        (new FirstHeartbeat())->on_updated('00000000-0000-0000-0000-000000000002', self::UUID);
        $this->addToAssertionCount(1);
    }

    public function test_changing_from_empty_queues_a_tick(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\expect('wp_schedule_single_event')->once();

        (new FirstHeartbeat())->on_updated('', self::UUID);
        $this->addToAssertionCount(1);
    }

    public function test_an_unchanged_uuid_queues_nothing(): void
    {
        Functions\expect('wp_schedule_single_event')->never();

        (new FirstHeartbeat())->on_updated(self::UUID, self::UUID);
        $this->addToAssertionCount(1);
    }

    public function test_clearing_the_uuid_queues_nothing(): void
    {
        Functions\expect('wp_schedule_single_event')->never();

        (new FirstHeartbeat())->on_updated(self::UUID, '');
        (new FirstHeartbeat())->on_added('cronheart_heartbeat_uuid', '');
        $this->addToAssertionCount(1);
    }

    public function test_a_tick_already_queued_is_not_duplicated(): void
    {
        Functions\expect('wp_next_scheduled')->once()->with(HeartbeatScheduler::TICK_HOOK, ['first-heartbeat'])->andReturn(1700000000);
        Functions\expect('wp_schedule_single_event')->never();

        (new FirstHeartbeat())->on_updated('', self::UUID);
        $this->addToAssertionCount(1);
    }

    public function test_a_throwing_scheduler_never_reaches_the_settings_save(): void
    {
        Functions\when('wp_next_scheduled')->justReturn(false);
        Functions\when('wp_schedule_single_event')->alias(static function (): never {
            throw new \RuntimeException('cron option is broken');
        });

        (new FirstHeartbeat())->on_updated('', self::UUID);
        (new FirstHeartbeat())->on_added('cronheart_heartbeat_uuid', self::UUID);

        $this->addToAssertionCount(1);
    }
}
