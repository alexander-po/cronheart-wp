<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Helpers;

use Brain\Monkey;
use Brain\Monkey\Filters;
use PHPUnit\Framework\TestCase;

final class MonitorHelperTest extends TestCase
{
    private const HOOK = 'app:reports:nightly';
    private const UUID = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    protected function setUp(): void
    {
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_helper_is_globally_defined_and_registers_one_filter_per_call(): void
    {
        // Smoke-tests two contracts at once: the Composer
        // `autoload.files` directive loaded the helper into the
        // global namespace (no add_action(plugins_loaded, …)
        // ceremony required), and the helper registers exactly one
        // `cronheart_monitor_map` filter per call.
        self::assertTrue(\function_exists('cronheart_monitor'));

        Filters\expectAdded('cronheart_monitor_map')->once();
        cronheart_monitor(self::HOOK, self::UUID);
    }

    public function test_helper_with_null_uuid_inserts_empty_string_into_map(): void
    {
        // The empty string is the SDK / heartbeat layer's "do not
        // monitor in this environment" signal. The Resolver's
        // precedence chain still consults a higher-priority source
        // (constant, option) — the filter just registers the hook
        // name for enumeration.
        Filters\expectAdded('cronheart_monitor_map')
            ->once()
            ->whenHappen(static function (\Closure $closure): void {
                $result = $closure([]);
                self::assertSame(['app:reports:nightly' => ''], $result);
            });

        cronheart_monitor(self::HOOK);
    }

    public function test_helper_overwrites_existing_entry_for_same_hook(): void
    {
        Filters\expectAdded('cronheart_monitor_map')
            ->once()
            ->whenHappen(static function (\Closure $closure): void {
                $result = $closure(['app:reports:nightly' => 'older-uuid']);
                self::assertSame(['app:reports:nightly' => self::UUID], $result);
            });

        cronheart_monitor(self::HOOK, self::UUID);
    }

    public function test_helper_preserves_unrelated_entries(): void
    {
        Filters\expectAdded('cronheart_monitor_map')
            ->once()
            ->whenHappen(static function (\Closure $closure): void {
                $result = $closure(['other:hook' => 'other-uuid']);
                self::assertSame([
                    'other:hook' => 'other-uuid',
                    'app:reports:nightly' => self::UUID,
                ], $result);
            });

        cronheart_monitor(self::HOOK, self::UUID);
    }
}
