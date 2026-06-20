<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Cronheart\WP\Admin\CronEventsScreen;
use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Cron\EventDiscovery;
use Cronheart\WP\Tests\Support\FakeHttpClient;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class CronEventsScreenTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_attr__')->returnArg();
        Functions\when('esc_url')->returnArg();
        Functions\when('site_url')->justReturn('https://example.test');
        Functions\when('number_format_i18n')->alias(static fn ($n): string => (string) $n);
        Functions\when('selected')->alias(
            static fn ($a, $b = true, $echo = true): string => (string) $a === (string) $b ? " selected='selected'" : ''
        );
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_render_lists_recurring_events_with_assign_and_create_controls(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $monitors = [$this->monitorWire('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports')];
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWithMonitors($monitors));

        self::assertStringContainsString('cronheart-events', $html);
        self::assertStringContainsString('data-cronheart-hook="wp_version_check"', $html);
        self::assertStringContainsString('cronheart-event-monitor', $html, 'the assign dropdown is present');
        self::assertStringContainsString('cronheart-event-create', $html, 'an unmapped, auto-creatable hook offers create');
        self::assertStringContainsString('Nightly reports', $html, 'account monitors populate the dropdown');
        // The one-off hook is not listed (recurring-only).
        self::assertStringNotContainsString('my_one_off', $html);
    }

    public function test_render_without_a_token_is_read_only(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $html = $this->renderScreen($this->resolverWithoutToken(), null);

        self::assertStringContainsString('data-cronheart-hook="wp_version_check"', $html, 'events still listed read-only');
        self::assertStringNotContainsString('cronheart-event-monitor', $html, 'no live controls without a token');
        self::assertStringNotContainsString('cronheart-event-create', $html);
    }

    public function test_render_marks_a_constant_governed_hook_read_only(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $monitors = [$this->monitorWire('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports')];
        $html = $this->renderScreen($this->resolverWithConstantEvent('wp_version_check'), $this->factoryWithMonitors($monitors));

        self::assertStringContainsString('wp-config.php constant', $html);
        self::assertStringNotContainsString('cronheart-event-monitor', $html, 'a constant-governed hook has no dropdown');
    }

    public function test_render_aborts_without_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_die')->alias(static function (string $msg): void {
            throw new \RuntimeException($msg);
        });

        $this->expectException(\RuntimeException::class);

        // Call render() directly: the capability check fails before any
        // output, so there is no buffer to manage (and wp_die throws here).
        $screen = new CronEventsScreen($this->eventDiscovery(), $this->resolverWithToken(), null, '/plugins/cronheart/cronheart.php');
        $screen->render();
    }

    private function renderScreen(Resolver $resolver, ?\Closure $factory): string
    {
        $screen = new CronEventsScreen($this->eventDiscovery(), $resolver, $factory, '/plugins/cronheart/cronheart.php');

        ob_start();
        $screen->render();

        return (string) ob_get_clean();
    }

    private function eventDiscovery(): EventDiscovery
    {
        $cron = [
            1_000_000_000 => [
                'wp_version_check' => [['schedule' => 'twicedaily', 'args' => [], 'interval' => 43200]],
                'my_one_off' => [['schedule' => false, 'args' => []]],
            ],
        ];

        return new EventDiscovery(
            cronArrayReader: static fn () => $cron,
            schedulesReader: static fn (): array => [],
            timezoneReader: static fn (): string => 'UTC',
        );
    }

    /**
     * @param list<array<string, mixed>> $monitorsWire
     *
     * @return \Closure(string): ManagementClient
     */
    private function factoryWithMonitors(array $monitorsWire): \Closure
    {
        $page = (string) json_encode(['data' => $monitorsWire, 'total' => \count($monitorsWire), 'limit' => 100, 'offset' => 0]);

        return static function (string $token) use ($page): ManagementClient {
            $factory = new Psr17Factory();
            $configuration = new Configuration('https://cronheart.com', apiKey: 'cmk_test_token');
            $http = new FakeHttpClient([new Response(200, ['Content-Type' => 'application/json'], $page)]);

            return new ManagementClient($configuration, new MonitorApiClient($configuration, $http, $factory, $factory));
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorWire(string $uuid, string $name): array
    {
        return [
            'uuid' => $uuid,
            'name' => $name,
            'schedule_kind' => 'interval',
            'schedule_expr' => '300',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => 'up',
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/'.$uuid,
            'badge_url' => 'https://cronheart.com/badge/'.$uuid.'.svg',
            'snoozed_until' => null,
        ];
    }

    private function resolverWithToken(): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static fn (string $name) => Resolver::API_TOKEN_OPTION === $name ? 'cmk_'.str_repeat('a', 43) : null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }

    private function resolverWithoutToken(): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static fn (string $name) => null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }

    private function resolverWithConstantEvent(string $hook): Resolver
    {
        $constant = Resolver::EVENT_CONSTANT_PREFIX.strtoupper(str_replace('-', '_', $hook)).Resolver::EVENT_CONSTANT_SUFFIX;

        return new Resolver(
            constantReader: static fn (string $name): ?string => $name === $constant ? 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa' : null,
            optionReader: static fn (string $name) => Resolver::API_TOKEN_OPTION === $name ? 'cmk_'.str_repeat('a', 43) : null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }
}
