<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Config;

use Cronheart\WP\Config\Resolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResolverTest extends TestCase
{
    private const HEARTBEAT_UUID = '00000000-0000-0000-0000-000000000001';

    private const EVENT_UUID = '00000000-0000-0000-0000-000000000002';

    public function test_heartbeat_constant_wins_over_option(): void
    {
        $resolver = $this->buildResolver(
            constants: [Resolver::HEARTBEAT_CONSTANT => self::HEARTBEAT_UUID],
            options: [Resolver::HEARTBEAT_OPTION => '00000000-0000-0000-0000-000000000003'],
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
            constants: [Resolver::ENDPOINT_CONSTANT => 'http://host.docker.internal:8080'],
            options: [Resolver::ENDPOINT_OPTION => 'https://staging.cronheart.com'],
        );

        self::assertSame('http://host.docker.internal:8080', $resolver->endpoint());
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
     * @return array<string, array{0: mixed, 1: bool}>
     */
    public static function allowInsecureOptionValues(): array
    {
        return [
            'string false' => ['false', false],
            'string no' => ['no', false],
            'string off' => ['off', false],
            'zero with a trailing space' => ['0 ', false],
            'string zero' => ['0', false],
            'unparseable string' => ['garbage', false],
            'empty string' => ['', false],
            'non-empty array' => [['1'], false],
            'native false' => [false, false],
            'string one' => ['1', true],
            'string true' => ['true', true],
            'string yes' => ['yes', true],
            'string on' => ['on', true],
            'native true' => [true, true],
        ];
    }

    #[DataProvider('allowInsecureOptionValues')]
    public function test_allow_insecure_option_is_parsed_like_the_constant(mixed $stored, bool $expected): void
    {
        self::assertSame($expected, $this->buildResolver(
            constants: [],
            options: [Resolver::ALLOW_INSECURE_OPTION => $stored],
        )->allowInsecureEndpoint());
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function allowInsecureConstantValues(): array
    {
        $values = [true, false, 'true', 'false', '1', '0', 'yes', 'no', 'on', 'off', '', ' 1', '0 ', 'TRUE', 'garbage', 0, 1, 2, -1, 1.0, 0.0, null, []];
        $cases = [];
        foreach ($values as $value) {
            $cases[var_export($value, true)] = [$value];
        }

        return $cases;
    }

    #[DataProvider('allowInsecureConstantValues')]
    public function test_every_constant_value_keeps_the_meaning_it_had_before_the_option_parser_changed(mixed $constant): void
    {
        $before = static function (mixed $fromConstant, mixed $fromOption): bool {
            if (\is_bool($fromConstant)) {
                return $fromConstant;
            }
            if (\is_string($fromConstant) && '' !== $fromConstant) {
                return (bool) filter_var($fromConstant, \FILTER_VALIDATE_BOOLEAN);
            }
            if (\is_int($fromConstant)) {
                return 0 !== $fromConstant;
            }

            return (bool) $fromOption;
        };

        foreach (['1', '', null] as $option) {
            self::assertSame(
                $before($constant, $option),
                $this->buildResolver(
                    constants: [Resolver::ALLOW_INSECURE_CONSTANT => $constant],
                    options: [Resolver::ALLOW_INSECURE_OPTION => $option],
                )->allowInsecureEndpoint(),
                'with the option '.var_export($option, true),
            );
        }
    }

    public function test_endpoint_is_constant_only_when_the_constant_is_a_string(): void
    {
        self::assertTrue($this->buildResolver(
            constants: [Resolver::ENDPOINT_CONSTANT => 'https://cronheart.example.test'],
            options: [],
        )->endpointIsConstant());
        self::assertFalse($this->buildResolver(
            constants: [],
            options: [Resolver::ENDPOINT_OPTION => 'https://cronheart.example.test'],
        )->endpointIsConstant());

        $nonStringConstant = $this->buildResolver(
            constants: [Resolver::ENDPOINT_CONSTANT => false],
            options: [Resolver::ENDPOINT_OPTION => 'http://cronheart.example.test'],
        );
        self::assertFalse($nonStringConstant->endpointIsConstant());
        self::assertSame('http://cronheart.example.test', $nonStringConstant->endpoint());
    }

    public function test_api_token_constant_wins_over_option(): void
    {
        $resolver = $this->buildResolver(
            constants: [Resolver::API_TOKEN_CONSTANT => 'cmk_from_constant'],
            options: [Resolver::API_TOKEN_OPTION => 'cmk_from_option'],
        );

        self::assertSame('cmk_from_constant', $resolver->apiToken());
        self::assertTrue($resolver->apiTokenIsConstant());
    }

    public function test_api_token_falls_back_to_option_when_constant_undefined(): void
    {
        $resolver = $this->buildResolver(
            constants: [],
            options: [Resolver::API_TOKEN_OPTION => 'cmk_from_option'],
        );

        self::assertSame('cmk_from_option', $resolver->apiToken());
        self::assertFalse($resolver->apiTokenIsConstant());
    }

    public function test_api_token_returns_null_when_no_source_supplies_a_value(): void
    {
        self::assertNull($this->buildResolver(constants: [], options: [])->apiToken());
    }

    public function test_api_token_empty_constant_is_treated_as_suppression(): void
    {
        // A blanked constant disables the API features and shadows the
        // option — same empty-string-as-suppression policy as the UUIDs.
        // `apiTokenIsConstant()` still reports true so the settings page
        // shows the "set via wp-config" notice rather than an editable
        // field.
        $resolver = $this->buildResolver(
            constants: [Resolver::API_TOKEN_CONSTANT => ''],
            options: [Resolver::API_TOKEN_OPTION => 'cmk_from_option'],
        );

        self::assertNull($resolver->apiToken());
        self::assertTrue($resolver->apiTokenIsConstant());
    }

    public function test_api_token_empty_option_does_not_fall_through(): void
    {
        self::assertNull($this->buildResolver(
            constants: [],
            options: [Resolver::API_TOKEN_OPTION => ''],
        )->apiToken());
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
