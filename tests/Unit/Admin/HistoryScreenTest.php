<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Cronheart\WP\Admin\HistoryScreen;
use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Tests\Support\FakeHttpClient;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class HistoryScreenTest extends TestCase
{
    private const UUID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        Monkey\setUp();
        $_GET = [];

        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('sanitize_text_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('wp_unslash')->returnArg();
        Functions\when('number_format_i18n')->alias(static fn ($n): string => (string) $n);
        Functions\when('_n')->alias(static fn ($single, $plural, $number, $domain = 'default'): string => 1 === $number ? $single : $plural);
        Functions\when('selected')->alias(
            static fn ($a, $b = true, $echo = true): string => (string) $a === (string) $b ? " selected='selected'" : ''
        );
    }

    protected function tearDown(): void
    {
        $_GET = [];
        Monkey\tearDown();
    }

    public function test_render_without_a_token_degrades_to_a_notice(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $html = $this->renderScreen($this->resolverWithoutToken(), null);

        self::assertStringContainsString('notice', $html);
        self::assertStringNotContainsString('name="monitor"', $html, 'no picker without a token');
        self::assertStringNotContainsString('Recent pings', $html);
    }

    public function test_render_shows_the_picker_without_a_selection(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $http = new FakeHttpClient([$this->monitorsPage()]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http));

        self::assertStringContainsString('name="monitor"', $html, 'the monitor picker is shown');
        self::assertStringContainsString('Select a monitor above', $html, 'a hint prompts a selection');
        self::assertStringContainsString('The picker lists up to 200 of your monitors.', $html);
        self::assertStringNotContainsString('Recent pings', $html, 'no history table until a monitor is chosen');
        self::assertCount(1, $http->requests, 'only the monitor listing — no history fetched without a selection');
    }

    public function test_render_shows_first_page_pings_and_alerts_for_the_selected_monitor(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET = ['monitor' => self::handle()];

        $http = new FakeHttpClient([$this->monitorsPage(), $this->pingPage(), $this->alertPage()]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http));

        self::assertStringContainsString('Recent pings', $html);
        self::assertStringContainsString('Success', $html, 'a ping kind label renders');
        self::assertStringContainsString('1500', $html, 'the ping runtime renders');
        self::assertStringContainsString('Recent alerts', $html);
        self::assertStringContainsString('Late', $html, 'an alert kind label renders');
        self::assertStringContainsString('2 channels', $html, 'the delivered-to count renders');
        self::assertCount(3, $http->requests, 'one monitors list + one pings page + one alerts page — no pagination walk');
    }

    public function test_render_shows_unknown_ping_and_alert_kinds_verbatim(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET = ['monitor' => self::handle()];

        $http = new FakeHttpClient([$this->monitorsPage(), $this->pingPage('retry'), $this->alertPage('escalated')]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http));

        self::assertStringContainsString('<tr><td>retry</td>', $html);
        self::assertStringContainsString('<tr><td>escalated</td>', $html);
    }

    public function test_the_picker_submits_a_digest_never_the_monitor_uuid(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $http = new FakeHttpClient([$this->monitorsPage()]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http));

        self::assertStringContainsString('<option value="'.self::handle().'">', $html);
        self::assertStringNotContainsString('value="'.self::UUID.'"', $html);
    }

    public function test_render_ignores_a_raw_uuid_in_the_monitor_parameter(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET = ['monitor' => self::UUID];

        $http = new FakeHttpClient([$this->monitorsPage()]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http));

        self::assertStringContainsString('Select a monitor above', $html);
        self::assertCount(1, $http->requests, 'a malformed selection fetches no history');
    }

    public function test_render_reports_a_handle_that_matches_no_listed_monitor(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET = ['monitor' => str_repeat('b', 16)];

        $http = new FakeHttpClient([$this->monitorsPage()]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http));

        self::assertStringContainsString('That monitor is not in the list above', $html);
        self::assertCount(1, $http->requests, 'an unmatched selection fetches no history');
    }

    public function test_render_degrades_when_the_monitor_listing_fails(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $http = new FakeHttpClient([
            new Response(401, ['Content-Type' => 'application/problem+json'], '{"title":"Unauthorized","status":401}'),
        ]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http, 0));

        self::assertStringContainsString('Could not authenticate with cronheart.com', $html);
        self::assertStringNotContainsString('name="monitor"', $html, 'no picker without a monitor listing');
        self::assertSame(substr_count($html, '<div'), substr_count($html, '</div>'), 'every div, the wrap included, is closed on the failure path');
    }

    /**
     * @return array<string, array{array<array-key, string>|null}>
     */
    public static function unconfirmedDeliveries(): array
    {
        return ['null map' => [null], 'empty map' => [[]]];
    }

    /**
     * @param array<array-key, string>|null $dispatchedTo
     */
    #[DataProvider('unconfirmedDeliveries')]
    public function test_an_alert_without_a_confirmed_delivery_says_so(?array $dispatchedTo): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET = ['monitor' => self::handle()];

        $http = new FakeHttpClient([$this->monitorsPage(), $this->pingPage(), $this->alertPage('late', $dispatchedTo)]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http));

        self::assertStringContainsString('<th>Delivered to</th>', $html);
        self::assertStringContainsString('No delivery confirmed', $html);
    }

    public function test_render_degrades_when_the_history_load_fails(): void
    {
        Functions\when('current_user_can')->justReturn(true);
        $_GET = ['monitor' => self::handle()];

        $http = new FakeHttpClient([
            $this->monitorsPage(),
            new Response(404, ['Content-Type' => 'application/problem+json'], '{"title":"Not Found","status":404}'),
        ]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http, 0));

        self::assertStringContainsString('name="monitor"', $html, 'the picker still renders');
        self::assertStringContainsString('Could not load the history', $html);
        self::assertStringNotContainsString('Recent pings', $html, 'no table when the history failed to load');
    }

    public function test_render_a_plan_refusal_notice_does_not_tie_the_api_to_a_paid_plan(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $http = new FakeHttpClient([
            new Response(402, ['Content-Type' => 'application/problem+json'], '{"title":"Payment Required","status":402}'),
        ]);
        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWith($http, 0));

        self::assertStringContainsString('does not allow this request', $html);
        self::assertStringNotContainsString('API access', $html);
        self::assertStringNotContainsString('Starter', $html);
    }

    public function test_render_aborts_without_capability(): void
    {
        Functions\when('current_user_can')->justReturn(false);
        Functions\when('wp_die')->alias(static function (string $msg): void {
            throw new \RuntimeException($msg);
        });

        $this->expectException(\RuntimeException::class);

        $screen = new HistoryScreen($this->resolverWithToken(), null);
        $screen->render();
    }

    private static function handle(): string
    {
        return substr(hash('sha256', self::UUID), 0, 16);
    }

    private function renderScreen(Resolver $resolver, ?\Closure $factory): string
    {
        $screen = new HistoryScreen($resolver, $factory);

        ob_start();
        $screen->render();

        return (string) ob_get_clean();
    }

    /**
     * @return \Closure(string): ManagementClient
     */
    private function factoryWith(FakeHttpClient $http, int $retries = 1): \Closure
    {
        return static function (string $token) use ($http, $retries): ManagementClient {
            $factory = new Psr17Factory();
            $configuration = new Configuration('https://cronheart.com', apiKey: 'cmk_test_token', retries: $retries);

            return new ManagementClient($configuration, new MonitorApiClient($configuration, $http, $factory, $factory));
        };
    }

    private function monitorsPage(): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'data' => [[
                'uuid' => self::UUID,
                'name' => 'Nightly reports',
                'schedule_kind' => 'interval',
                'schedule_expr' => '300',
                'tz' => 'UTC',
                'grace_seconds' => 60,
                'status' => 'up',
                'next_expected_at' => null,
                'last_ping_at' => null,
                'created_at' => '2026-01-01T00:00:00+00:00',
                'ping_url' => 'https://cronheart.com/ping/'.self::UUID,
                'badge_url' => 'https://cronheart.com/badge/'.self::UUID.'.svg',
                'snoozed_until' => null,
            ]],
            'total' => 1,
            'limit' => 100,
            'offset' => 0,
        ]));
    }

    private function pingPage(string $firstKind = 'success'): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'data' => [
                ['id' => '101', 'kind' => $firstKind, 'received_at' => '2026-01-01T00:05:00+00:00', 'runtime_ms' => 1500],
                ['id' => '100', 'kind' => 'start', 'received_at' => '2026-01-01T00:05:00+00:00', 'runtime_ms' => null],
            ],
            'next_cursor' => null,
        ]));
    }

    /**
     * @param array<array-key, string>|null $dispatchedTo
     */
    private function alertPage(string $kind = 'late', ?array $dispatchedTo = ['7' => '2026-01-01T00:10:01+00:00', '42' => '2026-01-01T00:10:02+00:00']): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'data' => [
                ['id' => '9', 'kind' => $kind, 'created_at' => '2026-01-01T00:10:00+00:00', 'dispatched_to' => $dispatchedTo],
            ],
            'total' => 1,
            'limit' => 50,
            'offset' => 0,
        ]));
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
}
