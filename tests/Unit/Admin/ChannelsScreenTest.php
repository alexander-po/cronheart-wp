<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Cronheart\WP\Admin\ChannelsScreen;
use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Tests\Support\FakeHttpClient;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ChannelsScreenTest extends TestCase
{
    protected function setUp(): void
    {
        Monkey\setUp();
        Functions\when('__')->returnArg();
        Functions\when('esc_html__')->returnArg();
        Functions\when('esc_html')->returnArg();
        Functions\when('esc_attr')->returnArg();
        Functions\when('esc_attr__')->returnArg();
    }

    protected function tearDown(): void
    {
        Monkey\tearDown();
    }

    public function test_render_lists_channels_with_test_and_webhook_only_rotate_controls(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $html = $this->renderScreen(
            $this->resolverWithToken(),
            $this->factoryWithChannels([
                $this->channelWire('7', 'webhook', 'Ops webhook', true),
                $this->channelWire('42', 'email', 'On-call email', false),
            ])
        );

        self::assertStringContainsString('cronheart-channels', $html);
        self::assertStringContainsString('data-cronheart-channel-id="7"', $html);
        self::assertStringContainsString('data-cronheart-channel-id="42"', $html);
        self::assertStringContainsString('Ops webhook', $html);
        self::assertStringContainsString('On-call email', $html);
        self::assertSame(2, substr_count($html, 'cronheart-channel-test'), 'every channel offers a test send');
        self::assertSame(1, substr_count($html, 'cronheart-channel-rotate'), 'only the webhook channel offers rotate-secret');
        self::assertStringContainsString('<tr data-cronheart-channel-id="7" data-cronheart-channel-verified="1">', $html);
        self::assertStringContainsString('<tr data-cronheart-channel-id="42" data-cronheart-channel-verified="0">', $html);
        self::assertSame(1, substr_count($html, '<td class="cronheart-channel-verified">Verified</td>'));
        self::assertSame(1, substr_count($html, '<td class="cronheart-channel-verified">Not verified</td>'));
    }

    public function test_render_without_a_token_degrades_to_a_notice(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $html = $this->renderScreen($this->resolverWithoutToken(), null);

        self::assertStringContainsString('notice', $html, 'a degradation notice is shown');
        self::assertStringNotContainsString('cronheart-channel-test', $html, 'no live controls without a token');
        self::assertStringNotContainsString('cronheart-channel-rotate', $html);
    }

    public function test_render_with_no_channels_shows_an_empty_state(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryWithChannels([]));

        self::assertStringContainsString('no notification channels yet', $html);
        self::assertStringNotContainsString('cronheart-channel-test', $html);
    }

    public function test_render_degrades_when_the_listing_fails_without_fataling(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryFailingWith(401));

        self::assertStringContainsString('notice', $html);
        self::assertStringNotContainsString('cronheart-channels', $html, 'no table when the listing failed');
    }

    public function test_render_a_plan_refusal_notice_does_not_tie_the_api_to_a_paid_plan(): void
    {
        Functions\when('current_user_can')->justReturn(true);

        $html = $this->renderScreen($this->resolverWithToken(), $this->factoryFailingWith(402));

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

        $screen = new ChannelsScreen($this->resolverWithToken(), null, '/plugins/cronheart/cronheart.php');
        $screen->render();
    }

    private function renderScreen(Resolver $resolver, ?\Closure $factory): string
    {
        $screen = new ChannelsScreen($resolver, $factory, '/plugins/cronheart/cronheart.php');

        ob_start();
        $screen->render();

        return (string) ob_get_clean();
    }

    /**
     * @param list<array<string, mixed>> $channelsWire
     *
     * @return \Closure(string): ManagementClient
     */
    private function factoryWithChannels(array $channelsWire): \Closure
    {
        $page = (string) json_encode(['data' => $channelsWire, 'total' => \count($channelsWire)]);

        return static function (string $token) use ($page): ManagementClient {
            $factory = new Psr17Factory();
            $configuration = new Configuration('https://cronheart.com', apiKey: 'cmk_test_token');
            $http = new FakeHttpClient([new Response(200, ['Content-Type' => 'application/json'], $page)]);

            return new ManagementClient($configuration, new MonitorApiClient($configuration, $http, $factory, $factory));
        };
    }

    /**
     * @return \Closure(string): ManagementClient
     */
    private function factoryFailingWith(int $status): \Closure
    {
        return static function (string $token) use ($status): ManagementClient {
            $factory = new Psr17Factory();
            $configuration = new Configuration('https://cronheart.com', apiKey: 'cmk_test_token', retries: 0);
            $http = new FakeHttpClient([new Response($status, ['Content-Type' => 'application/problem+json'], '{"title":"Error","status":'.$status.'}')]);

            return new ManagementClient($configuration, new MonitorApiClient($configuration, $http, $factory, $factory));
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function channelWire(string $id, string $kind, string $label, bool $verified): array
    {
        return [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'verified' => $verified,
            'config' => [],
            'created_at' => '2026-01-01T00:00:00+00:00',
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
}
