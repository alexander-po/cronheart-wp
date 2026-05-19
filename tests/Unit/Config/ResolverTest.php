<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Config;

use Cronheart\WP\Config\Resolver;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    private const HEARTBEAT_UUID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    private const EVENT_UUID = 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb';

    public function test_heartbeat_constant_wins_over_option(): void
    {
        $resolver = $this->buildResolver(
            constants: [Resolver::HEARTBEAT_CONSTANT => self::HEARTBEAT_UUID],
            options: [Resolver::HEARTBEAT_OPTION => 'cccccccc-cccc-4ccc-8ccc-cccccccccccc'],
        );

        self::assertSame(self::HEARTBEAT_UUID, $resolver->heartbeatUuid());
    }

    public function test_heartbeat_falls_back_to_option_when_constant_undefined(): void
    {
        $resolver = $this->buildResolver(
            constants: [],
            options: [Resolver::HEARTBEAT_OPTION => self::HEARTBEAT_UUID],
        );

        self::assertSame(self::HEARTBEAT_UUID, $resolver->heartbeatUuid());
    }

    public function test_heartbeat_returns_null_when_no_source_supplies_a_value(): void
    {
        $resolver = $this->buildResolver(constants: [], options: []);

        self::assertNull($resolver->heartbeatUuid());
    }

    public function test_heartbeat_empty_constant_is_treated_as_explicit_suppression(): void
    {
        // Mirrors the SDK's `#[Monitor(uuid: '')]` policy: an empty
        // string is a deliberate "do not monitor in this environment"
        // signal that must shadow the lower-precedence option value.
        $resolver = $this->buildResolver(
            constants: [Resolver::HEARTBEAT_CONSTANT => ''],
            options: [Resolver::HEARTBEAT_OPTION => self::HEARTBEAT_UUID],
        );

        self::assertNull($resolver->heartbeatUuid());
    }

    public function test_heartbeat_empty_option_does_not_fall_through(): void
    {
        $resolver = $this->buildResolver(
            constants: [],
            options: [Resolver::HEARTBEAT_OPTION => ''],
        );

        self::assertNull($resolver->heartbeatUuid());
    }

    public function test_event_uuid_constant_uses_uppercase_underscore_normalised_hook_name(): void
    {
        $resolver = $this->buildResolver(
            constants: ['CRONHEART_EVENT_APP_REPORTS_NIGHTLY_UUID' => self::EVENT_UUID],
            options: [],
        );

        self::assertSame(self::EVENT_UUID, $resolver->eventUuid('app:reports-nightly'));
    }

    public function test_event_uuid_falls_back_through_option_map_then_filter(): void
    {
        $resolver = $this->buildResolver(
            constants: [],
            options: [Resolver::EVENT_MAP_OPTION => ['app:reports:nightly' => self::EVENT_UUID]],
        );

        self::assertSame(self::EVENT_UUID, $resolver->eventUuid('app:reports:nightly'));
    }

    public function test_event_uuid_filter_is_lowest_precedence(): void
    {
        $resolver = $this->buildResolver(
            constants: [],
            options: [],
            filterMap: ['app:reports:nightly' => self::EVENT_UUID],
        );

        self::assertSame(self::EVENT_UUID, $resolver->eventUuid('app:reports:nightly'));
    }

    public function test_event_uuid_higher_precedence_source_shadows_filter(): void
    {
        $resolver = $this->buildResolver(
            constants: [],
            options: [Resolver::EVENT_MAP_OPTION => ['app:reports:nightly' => 'from-option']],
            filterMap: ['app:reports:nightly' => 'from-filter'],
        );

        self::assertSame('from-option', $resolver->eventUuid('app:reports:nightly'));
    }

    public function test_event_uuid_empty_at_any_level_suppresses_the_event(): void
    {
        $resolver = $this->buildResolver(
            constants: [],
            options: [Resolver::EVENT_MAP_OPTION => ['app:reports:nightly' => '']],
            filterMap: ['app:reports:nightly' => self::EVENT_UUID],
        );

        // Explicit empty in the option map suppresses, even though
        // the lower-precedence filter has a real UUID — keeps the
        // semantics symmetric with the heartbeat path and with the
        // SDK's #[Monitor(uuid: '')] form.
        self::assertNull($resolver->eventUuid('app:reports:nightly'));
    }

    public function test_event_hook_names_unions_option_keys_and_filter_keys(): void
    {
        $resolver = $this->buildResolver(
            constants: [],
            options: [Resolver::EVENT_MAP_OPTION => [
                'a:nightly' => 'uuid-a',
                'b:weekly' => '',
            ]],
            filterMap: [
                'b:weekly' => 'uuid-b-filter',
                'c:hourly' => 'uuid-c',
            ],
        );

        $names = $resolver->eventHookNames();

        sort($names);
        self::assertSame(['a:nightly', 'b:weekly', 'c:hourly'], $names);
    }

    public function test_event_hook_names_returns_empty_list_when_no_source_registers_anything(): void
    {
        self::assertSame([], $this->buildResolver(constants: [], options: [])->eventHookNames());
    }

    public function test_event_hook_names_skips_empty_string_keys(): void
    {
        // Defensive: `''` as a key has no meaningful hook semantics
        // and would short-circuit PerEventInstrumentation's loop.
        $resolver = $this->buildResolver(
            constants: [],
            options: [Resolver::EVENT_MAP_OPTION => ['' => 'should-not-leak']],
            filterMap: ['' => 'should-not-leak-either'],
        );

        self::assertSame([], $resolver->eventHookNames());
    }

    /**
     * @param array<string, string> $constants
     * @param array<string, mixed>  $options
     * @param array<string, string> $filterMap
     */
    private function buildResolver(array $constants, array $options, array $filterMap = []): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): ?string => \array_key_exists($name, $constants) ? $constants[$name] : null,
            optionReader: static fn (string $name) => $options[$name] ?? null,
            filterApplier: static fn (string $name, array $value) => Resolver::EVENT_MAP_FILTER === $name ? $filterMap : $value,
        );
    }
}
