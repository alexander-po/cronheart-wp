<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit;

use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Plugin;
use CronMonitor\Client\Configuration;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
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

    private static function invokePrivateStatic(string $method, Resolver $resolver): mixed
    {
        return (new \ReflectionMethod(Plugin::class, $method))->invoke(null, $resolver);
    }
}
