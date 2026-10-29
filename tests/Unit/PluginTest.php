<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Cronheart\WP\Api\Client;
use Cronheart\WP\Api\PingsDisabledReason;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Hooks\PerEventInstrumentation;
use Cronheart\WP\Log\DebugLogLogger;
use Cronheart\WP\Plugin;
use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Monkey\setUp();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
        parent::tearDown();
    }

    public function test_plugin_class_is_constructible_without_wp_runtime(): void
    {
        // Bootstrap class itself is side-effect free at construction —
        // hook registration happens in `boot()`, which the runtime
        // call in `cronheart.php` invokes after WordPress functions
        // are available. The integration smoke test that exercises
        // the full `boot()` chain lives in the v0.1.0 release
        // verification (commit 5) against a real WP install.
        $plugin = new Plugin();

        self::assertInstanceOf(Plugin::class, $plugin);
    }

    public function test_a_plain_http_endpoint_keeps_pings_but_refuses_the_account_token(): void
    {
        $resolver = new Resolver(
            constantReader: static fn (string $name) => match ($name) {
                Resolver::ENDPOINT_CONSTANT => 'http://cronheart.example.test',
                Resolver::ALLOW_INSECURE_CONSTANT => true,
                default => null,
            },
            optionReader: static fn (string $name) => null,
            filterApplier: static fn (string $name, array $value) => $value,
        );

        $pingConfiguration = self::invokePrivateStatic('buildSdkConfiguration', $resolver);
        self::assertInstanceOf(Configuration::class, $pingConfiguration);
        self::assertSame('http://cronheart.example.test', $pingConfiguration->endpoint);

        $factory = self::invokePrivateStatic('buildManagementClientFactory', $resolver);
        self::assertInstanceOf(\Closure::class, $factory);

        $this->expectException(\RuntimeException::class);
        $factory('cmk_'.str_repeat('a', 43));
    }

    /**
     * @return array<string, array{0: ?string, 1: bool, 2: string}>
     */
    public static function pingEndpoints(): array
    {
        return [
            'nothing configured' => [null, false, Configuration::DEFAULT_ENDPOINT],
            'plain http with opt-in is kept' => ['http://cronheart.example.test', true, 'http://cronheart.example.test'],
        ];
    }

    #[DataProvider('pingEndpoints')]
    public function test_the_ping_configuration_is_tokenless_with_a_short_timeout_and_two_retries(?string $endpoint, bool $allowInsecure, string $expectedEndpoint): void
    {
        $resolver = new Resolver(
            constantReader: static fn (string $name) => match ($name) {
                Resolver::ENDPOINT_CONSTANT => $endpoint,
                Resolver::ALLOW_INSECURE_CONSTANT => $allowInsecure,
                default => null,
            },
            optionReader: static fn (string $name) => null,
            filterApplier: static fn (string $name, array $value) => $value,
        );

        $configuration = self::invokePrivateStatic('buildSdkConfiguration', $resolver);

        self::assertInstanceOf(Configuration::class, $configuration);
        self::assertSame($expectedEndpoint, $configuration->endpoint);
        self::assertSame(3.0, $configuration->timeoutSeconds);
        self::assertSame(2, $configuration->retries);
        self::assertNull($configuration->apiKey);
        self::assertSame($allowInsecure && null !== $endpoint, $configuration->allowInsecureEndpoint);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: array<string, mixed>}>
     */
    public static function rejectedEndpoints(): array
    {
        return [
            'plain http without opt-in in the constant' => [[Resolver::ENDPOINT_CONSTANT => 'http://cronheart.example.test', Resolver::ALLOW_INSECURE_CONSTANT => false], []],
            'no scheme in the constant' => [[Resolver::ENDPOINT_CONSTANT => 'cronheart.example.test', Resolver::ALLOW_INSECURE_CONSTANT => true], []],
            'whitespace only in the constant' => [[Resolver::ENDPOINT_CONSTANT => '   '], []],
            'plain http in the option without opt-in' => [[], [Resolver::ENDPOINT_OPTION => 'http://cronheart.example.test']],
            'plain http in the option with the opt-in option stored as false' => [[], [Resolver::ENDPOINT_OPTION => 'http://cronheart.example.test', Resolver::ALLOW_INSECURE_OPTION => 'false']],
        ];
    }

    /**
     * @param array<string, mixed> $constants
     * @param array<string, mixed> $options
     */
    #[DataProvider('rejectedEndpoints')]
    public function test_a_rejected_endpoint_sends_no_pings_instead_of_falling_back_to_production(array $constants, array $options): void
    {
        $resolver = self::resolverWith($constants, $options);
        $created = false;

        self::assertNull(self::invokePrivateStatic('buildSdkConfiguration', $resolver));

        [$client, $reason] = self::buildClient($resolver, static function () use (&$created): Client {
            $created = true;

            return Client::disabled();
        });

        self::assertFalse($created);
        self::assertSame(PingsDisabledReason::EndpointRejected, $reason);
        $result = $client->heartbeat('00000000-0000-0000-0000-000000000001');
        self::assertFalse($result->delivered);
        self::assertSame(0, $result->attempts);
        self::assertSame(Client::DISABLED_MESSAGE, $result->errorMessage);
    }

    /**
     * @return array<string, array{0: bool, 1: PingsDisabledReason}>
     */
    public static function clientBuildFailures(): array
    {
        return [
            'curl missing' => [false, PingsDisabledReason::CurlMissing],
            'curl loaded' => [true, PingsDisabledReason::ClientUnavailable],
        ];
    }

    #[DataProvider('clientBuildFailures')]
    public function test_a_throwing_client_build_falls_back_to_a_client_that_sends_nothing(bool $curlLoaded, PingsDisabledReason $expected): void
    {
        [$client, $reason] = self::buildClient(
            self::resolverWith([]),
            static fn (Configuration $configuration): Client => throw new \RuntimeException('CurlPsr18Client requires the curl PHP extension.'),
            static fn (): bool => $curlLoaded,
        );

        self::assertSame($expected, $reason);
        $result = $client->start('00000000-0000-0000-0000-000000000001');
        self::assertFalse($result->delivered);
        self::assertSame(0, $result->attempts);
    }

    public function test_the_default_curl_probe_reports_a_loaded_extension(): void
    {
        self::assertTrue(\extension_loaded('curl'));

        [, $reason] = self::buildClient(
            self::resolverWith([]),
            static fn (Configuration $configuration): Client => throw new \RuntimeException('transport broke'),
        );

        self::assertSame(PingsDisabledReason::ClientUnavailable, $reason);
    }

    public function test_an_invalid_argument_thrown_by_the_resolver_is_not_reported_as_a_rejected_endpoint(): void
    {
        $resolver = new Resolver(
            constantReader: static fn (string $name) => null,
            optionReader: static fn (string $name) => throw new \InvalidArgumentException('third-party option filter broke'),
            filterApplier: static fn (string $name, array $value) => $value,
        );

        [$client, $reason] = self::buildClient($resolver, null, static fn (): bool => true);

        self::assertSame(PingsDisabledReason::ClientUnavailable, $reason);
        self::assertSame(Client::DISABLED_MESSAGE, $client->fail('00000000-0000-0000-0000-000000000001')->errorMessage);
    }

    public function test_a_valid_endpoint_returns_the_client_it_built_and_no_reason(): void
    {
        $live = Client::disabled();

        [$client, $reason] = self::buildClient(self::resolverWith([]), static fn (Configuration $configuration): Client => $live);

        self::assertSame($live, $client);
        self::assertNull($reason);
    }

    public function test_boot_registers_the_notice_only_when_pings_are_disabled_and_writes_nothing(): void
    {
        foreach (['update_option', 'add_option', 'set_transient', 'set_site_transient'] as $write) {
            Functions\expect($write)->never();
        }
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => Resolver::ENDPOINT_OPTION === $name ? 'http://cronheart.example.test' : $default);

        (new Plugin())->boot();

        self::assertTrue(has_action('admin_notices'));
    }

    public function test_boot_registers_no_notice_for_a_valid_endpoint(): void
    {
        Functions\when('get_option')->alias(static fn (string $name, mixed $default = false): mixed => $default);

        (new Plugin())->boot();

        self::assertFalse(has_action('admin_notices'));
    }

    /**
     * @return array<string, array{0: \Closure(): Resolver}>
     */
    public static function brokenMonitorMaps(): array
    {
        return [
            'a filter callback that throws' => [static fn (): Resolver => new Resolver(
                constantReader: static fn (string $name) => null,
                optionReader: static fn (string $name) => null,
                filterApplier: static fn (string $name, array $value) => throw new \RuntimeException('third-party monitor map filter broke'),
            )],
            'an earlier callback returning null into the cronheart_monitor() helper' => [static function (): Resolver {
                $helper = null;
                Functions\when('add_filter')->alias(static function (string $hook, callable $callback) use (&$helper): bool {
                    $helper = $callback;

                    return true;
                });
                cronheart_monitor('app_nightly_report');
                self::assertIsCallable($helper);

                return new Resolver(
                    constantReader: static fn (string $name) => null,
                    optionReader: static fn (string $name) => null,
                    filterApplier: static fn (string $name, array $value) => $helper(null),
                );
            }],
        ];
    }

    /**
     * @param \Closure(): Resolver $resolver
     */
    #[DataProvider('brokenMonitorMaps')]
    public function test_a_throwing_hook_enumeration_lets_plugins_loaded_complete_and_monitors_nothing(\Closure $resolver): void
    {
        $added = [];
        $perEvent = new PerEventInstrumentation(
            $resolver(),
            $this->createMock(Client::class),
            actionAdder: static function (string $hook) use (&$added): bool {
                $added[] = $hook;

                return true;
            },
            filterAdder: static function (string $hook) use (&$added): bool {
                $added[] = $hook;

                return true;
            },
            currentHookName: static fn (): ?string => null,
            shutdownRegistrar: static function (callable $callback) use (&$added): void {
                $added[] = 'shutdown';
            },
            lastErrorReader: static fn (): ?array => null,
        );

        (new \ReflectionMethod(Plugin::class, 'registerPerEventHooks'))->invoke(null, $perEvent, $resolver());

        self::assertSame([], $added);
    }

    public function test_the_default_client_and_its_sdk_share_the_debug_log_logger(): void
    {
        [$client] = self::buildClient(self::resolverWith([]));

        $logger = (new \ReflectionProperty(Client::class, 'logger'))->getValue($client);
        $sdk = (new \ReflectionProperty(Client::class, 'sdk'))->getValue($client);
        self::assertInstanceOf(DebugLogLogger::class, $logger);
        self::assertInstanceOf(CronMonitorClient::class, $sdk);
        self::assertSame($logger, (new \ReflectionProperty(CronMonitorClient::class, 'logger'))->getValue($sdk));
    }

    public function test_the_debug_log_logger_stays_off_without_wp_debug_log(): void
    {
        self::assertFalse(\defined('WP_DEBUG_LOG'));
        $logger = (new \ReflectionMethod(Plugin::class, 'buildDebugLogLogger'))->invoke(null);
        self::assertInstanceOf(DebugLogLogger::class, $logger);

        $enabled = (new \ReflectionProperty(DebugLogLogger::class, 'enabled'))->getValue($logger);

        self::assertInstanceOf(\Closure::class, $enabled);
        self::assertFalse($enabled());
    }

    public function test_the_account_configuration_keeps_one_retry_and_the_default_timeout(): void
    {
        $resolver = new Resolver(
            constantReader: static fn (string $name) => null,
            optionReader: static fn (string $name) => null,
            filterApplier: static fn (string $name, array $value) => $value,
        );

        $configuration = (new \ReflectionMethod(Plugin::class, 'buildApiConfiguration'))
            ->invoke(null, $resolver, 'cmk_'.str_repeat('a', 43));

        self::assertInstanceOf(Configuration::class, $configuration);
        self::assertSame(1, $configuration->retries);
        self::assertSame(Configuration::DEFAULT_TIMEOUT_SECONDS, $configuration->timeoutSeconds);
    }

    public function test_the_per_event_filter_adder_forwards_to_add_filter_in_order(): void
    {
        $resolver = new Resolver(
            constantReader: static fn (string $name) => null,
            optionReader: static fn (string $name) => null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
        $instrumentation = (new \ReflectionMethod(Plugin::class, 'buildPerEventInstrumentation'))
            ->invoke(null, $resolver, $this->createMock(Client::class));
        self::assertInstanceOf(PerEventInstrumentation::class, $instrumentation);
        $callback = [$instrumentation, 'sweep_before_fatal_error_page'];
        Functions\expect('add_filter')->once()->with('wp_php_error_args', $callback, \PHP_INT_MIN, 1)->andReturn(true);

        $filterAdder = (new \ReflectionProperty(PerEventInstrumentation::class, 'filterAdder'))->getValue($instrumentation);
        self::assertInstanceOf(\Closure::class, $filterAdder);
        self::assertTrue($filterAdder('wp_php_error_args', $callback, \PHP_INT_MIN, 1));
    }

    /**
     * @return array{Client, ?PingsDisabledReason}
     */
    private static function buildClient(Resolver $resolver, ?\Closure $createClient = null, ?\Closure $curlLoaded = null): array
    {
        $built = (new \ReflectionMethod(Plugin::class, 'buildClient'))->invoke(null, $resolver, $createClient, $curlLoaded);
        self::assertIsArray($built);
        self::assertInstanceOf(Client::class, $built[0]);

        return [$built[0], $built[1]];
    }

    /**
     * @param array<string, mixed> $constants
     * @param array<string, mixed> $options
     */
    private static function resolverWith(array $constants, array $options = []): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name) => $constants[$name] ?? null,
            optionReader: static fn (string $name) => $options[$name] ?? null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }

    private static function invokePrivateStatic(string $method, Resolver $resolver): mixed
    {
        return (new \ReflectionMethod(Plugin::class, $method))->invoke(null, $resolver);
    }
}
