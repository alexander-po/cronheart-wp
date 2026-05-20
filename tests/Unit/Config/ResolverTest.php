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

    public function test_endpoint_returns_null_when_no_source_supplies_a_value(): void
    {
        // Null tells the plugin "use the SDK's default (cronheart.com)".
        $resolver = $this->buildResolver(constants: [], options: []);

        self::assertNull($resolver->endpoint());
    }

    public function test_endpoint_constant_overrides_option_and_defaults(): void
    {
        $resolver = $this->buildResolver(
            constants: [Resolver::ENDPOINT_CONSTANT => 'http://host.docker.internal:8081'],
            options: [Resolver::ENDPOINT_OPTION => 'https://staging.cronheart.com'],
        );

        self::assertSame('http://host.docker.internal:8081', $resolver->endpoint());
    }

    public function test_endpoint_falls_back_to_option_when_constant_unset(): void
    {
        $resolver = $this->buildResolver(
            constants: [],
            options: [Resolver::ENDPOINT_OPTION => 'https://staging.cronheart.com'],
        );

        self::assertSame('https://staging.cronheart.com', $resolver->endpoint());
    }

    public function test_endpoint_empty_string_at_either_level_is_treated_as_unset(): void
    {
        // The SDK would reject a literally empty endpoint anyway; we
        // collapse it to null here so the plugin uses the default,
        // matching the empty-string-as-suppression policy we apply
        // elsewhere (UUIDs).
        self::assertNull($this->buildResolver(
            constants: [Resolver::ENDPOINT_CONSTANT => ''],
            options: [Resolver::ENDPOINT_OPTION => 'https://other.example.com'],
        )->endpoint());

        self::assertNull($this->buildResolver(
            constants: [],
            options: [Resolver::ENDPOINT_OPTION => ''],
        )->endpoint());
    }

    public function test_allow_insecure_endpoint_defaults_to_false(): void
    {
        $resolver = $this->buildResolver(constants: [], options: []);

        self::assertFalse($resolver->allowInsecureEndpoint());
    }

    public function test_allow_insecure_endpoint_accepts_native_boolean_constants(): void
    {
        // `define('CRONHEART_ALLOW_INSECURE_ENDPOINT', true);` is the
        // most natural form for wp-config.php — confirm both true and
        // false flow through.
        self::assertTrue($this->buildResolver(
            constants: [Resolver::ALLOW_INSECURE_CONSTANT => true],
            options: [],
        )->allowInsecureEndpoint());

        self::assertFalse($this->buildResolver(
            constants: [Resolver::ALLOW_INSECURE_CONSTANT => false],
            options: [],
        )->allowInsecureEndpoint());
    }

    public function test_allow_insecure_endpoint_accepts_truthy_falsy_string_constants(): void
    {
        // When the constant value flows through env-var expansion the
        // PHP boolean ends up as a string. We accept the canonical
        // forms — useful for hosts that source secrets from env files.
        foreach (['true', '1', 'yes', 'on'] as $truthy) {
            self::assertTrue(
                $this->buildResolver(constants: [Resolver::ALLOW_INSECURE_CONSTANT => $truthy], options: [])
                    ->allowInsecureEndpoint(),
                "Expected truthy interpretation of '{$truthy}'"
            );
        }
        foreach (['false', '0', 'no', 'off'] as $falsy) {
            self::assertFalse(
                $this->buildResolver(constants: [Resolver::ALLOW_INSECURE_CONSTANT => $falsy], options: [])
                    ->allowInsecureEndpoint(),
                "Expected falsy interpretation of '{$falsy}'"
            );
        }
    }

    public function test_allow_insecure_endpoint_falls_back_to_option_when_constant_unset(): void
    {
        self::assertTrue($this->buildResolver(
            constants: [],
            options: [Resolver::ALLOW_INSECURE_OPTION => '1'],
        )->allowInsecureEndpoint());

        self::assertFalse($this->buildResolver(
            constants: [],
            options: [Resolver::ALLOW_INSECURE_OPTION => ''],
        )->allowInsecureEndpoint());
    }

    /**
     * @param array<string, mixed>  $constants accepts native PHP types — strings for UUID / endpoint, booleans for allow-insecure — matching what `constant()` would return in production
     * @param array<string, mixed>  $options
     * @param array<string, string> $filterMap
     */
    private function buildResolver(array $constants, array $options, array $filterMap = []): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name) => \array_key_exists($name, $constants) ? $constants[$name] : null,
            optionReader: static fn (string $name) => $options[$name] ?? null,
            filterApplier: static fn (string $name, array $value) => Resolver::EVENT_MAP_FILTER === $name ? $filterMap : $value,
        );
    }
}
