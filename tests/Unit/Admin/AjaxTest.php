<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Cronheart\WP\Admin\Ajax;
use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Tests\Support\FakeHttpClient;
use Cronheart\WP\Tests\Support\WpJsonResponse;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class AjaxTest extends TestCase
{
    private const UUID = 'aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa';

    protected function setUp(): void
    {
        Monkey\setUp();
        $_POST = [];

        Functions\when('__')->returnArg();
        Functions\when('wp_unslash')->returnArg();
        Functions\when('sanitize_text_field')->alias(static fn (string $value): string => trim($value));
        Functions\when('sanitize_key')->alias(static fn (string $value): string => strtolower($value));
        Functions\when('wp_send_json_success')->alias(
            static fn ($data = null, $code = null) => throw new WpJsonResponse(true, $data, $code)
        );
        Functions\when('wp_send_json_error')->alias(
            static fn ($data = null, $code = null) => throw new WpJsonResponse(false, $data, $code)
        );
    }

    protected function tearDown(): void
    {
        $_POST = [];
        Monkey\tearDown();
    }

    public function test_register_adds_the_authenticated_action_and_never_nopriv(): void
    {
        Actions\expectAdded('wp_ajax_'.Ajax::ACTION)->once();
        Actions\expectAdded('wp_ajax_nopriv_'.Ajax::ACTION)->never();

        (new Ajax($this->resolverWithToken(), $this->throwingFactory()))->register();

        $this->addToAssertionCount(2);
    }

    public function test_rejects_a_stale_nonce_without_touching_the_api(): void
    {
        Functions\when('check_ajax_referer')->justReturn(false);

        $response = $this->capture($this->throwingFactory());

        self::assertFalse($response->success);
        self::assertSame(403, $response->statusCode);
    }

    public function test_rejects_a_non_administrator_without_touching_the_api(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(false);

        $response = $this->capture($this->throwingFactory());

        self::assertFalse($response->success);
        self::assertSame(403, $response->statusCode);
    }

    public function test_rejects_when_no_token_is_configured(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);

        $ajax = new Ajax($this->resolverWithoutToken(), $this->throwingFactory());
        $_POST = ['op' => 'pause', 'uuid' => self::UUID];

        $response = $this->captureHandle($ajax);

        self::assertFalse($response->success);
        self::assertSame(400, $response->statusCode);
    }

    public function test_rejects_an_invalid_uuid(): void
    {
        $this->authorised();
        $_POST = ['op' => 'pause', 'uuid' => 'not-a-uuid'];

        $response = $this->captureHandle(new Ajax($this->resolverWithToken(), $this->throwingFactory()));

        self::assertFalse($response->success);
        self::assertSame(400, $response->statusCode);
    }

    public function test_rejects_an_unknown_operation(): void
    {
        $this->authorised();
        $_POST = ['op' => 'delete', 'uuid' => self::UUID];

        $response = $this->captureHandle(new Ajax($this->resolverWithToken(), $this->throwingFactory()));

        self::assertFalse($response->success);
        self::assertSame(400, $response->statusCode);
    }

    public function test_rejects_an_invalid_snooze_duration(): void
    {
        $this->authorised();
        $_POST = ['op' => 'snooze', 'uuid' => self::UUID, 'duration' => '3h'];

        $response = $this->captureHandle(new Ajax($this->resolverWithToken(), $this->throwingFactory()));

        self::assertFalse($response->success);
        self::assertSame(400, $response->statusCode);
    }

    public function test_pause_returns_the_updated_status(): void
    {
        $this->authorised();
        $_POST = ['op' => 'pause', 'uuid' => self::UUID];

        $factory = $this->factoryReturning($this->monitorWire('paused', null));
        $response = $this->captureHandle(new Ajax($this->resolverWithToken(), $factory));

        self::assertTrue($response->success);
        self::assertIsArray($response->data);
        self::assertSame('paused', $response->data['status']);
        self::assertSame('Paused', $response->data['status_label']);
        self::assertFalse($response->data['snoozed']);
        self::assertSame('', $response->data['snoozed_until']);
    }

    public function test_snooze_returns_the_snooze_deadline(): void
    {
        $this->authorised();
        $_POST = ['op' => 'snooze', 'uuid' => self::UUID, 'duration' => '1d'];

        $factory = $this->factoryReturning($this->monitorWire('up', '2026-06-20T12:00:00+00:00'));
        $response = $this->captureHandle(new Ajax($this->resolverWithToken(), $factory));

        self::assertTrue($response->success);
        self::assertIsArray($response->data);
        self::assertTrue($response->data['snoozed']);
        self::assertSame('2026-06-20 12:00 UTC', $response->data['snoozed_until']);
    }

    public function test_maps_a_thrown_sdk_error_to_a_json_error_not_a_fatal(): void
    {
        $this->authorised();
        $_POST = ['op' => 'pause', 'uuid' => self::UUID];

        // A 404 surfaces as the SDK's NotFoundException (an ApiException);
        // the handler must answer with a JSON error envelope, never a 500.
        $factory = $this->factoryFailingWith(new Response(404, ['Content-Type' => 'application/problem+json'], '{"title":"Not Found","status":404}'));
        $response = $this->captureHandle(new Ajax($this->resolverWithToken(), $factory));

        self::assertFalse($response->success);
        self::assertSame(502, $response->statusCode);
        self::assertIsArray($response->data);
        self::assertArrayHasKey('message', $response->data);
    }

    private function authorised(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
    }

    private function capture(\Closure $factory): WpJsonResponse
    {
        return $this->captureHandle(new Ajax($this->resolverWithToken(), $factory));
    }

    private function captureHandle(Ajax $ajax): WpJsonResponse
    {
        try {
            $ajax->handle();
        } catch (WpJsonResponse $response) {
            return $response;
        }

        self::fail('handle() did not emit a JSON response');
    }

    /**
     * @param array<string, mixed> $monitorWire
     *
     * @return \Closure(string): ManagementClient
     */
    private function factoryReturning(array $monitorWire): \Closure
    {
        $json = (string) json_encode($monitorWire);

        return static function (string $token) use ($json): ManagementClient {
            $factory = new Psr17Factory();
            $configuration = new Configuration('https://cronheart.com', apiKey: 'cmk_test_token');
            $http = new FakeHttpClient([new Response(200, ['Content-Type' => 'application/json'], $json)]);

            return new ManagementClient($configuration, new MonitorApiClient($configuration, $http, $factory, $factory));
        };
    }

    /**
     * @return \Closure(string): ManagementClient
     */
    private function factoryFailingWith(Response $response): \Closure
    {
        return static function (string $token) use ($response): ManagementClient {
            $factory = new Psr17Factory();
            $configuration = new Configuration('https://cronheart.com', apiKey: 'cmk_test_token', retries: 0);
            $http = new FakeHttpClient([$response]);

            return new ManagementClient($configuration, new MonitorApiClient($configuration, $http, $factory, $factory));
        };
    }

    /**
     * A factory that fails loudly if invoked — proves a request rejected at
     * the boundary never reaches (and never mutates anything through) the API.
     *
     * @return \Closure(string): ManagementClient
     */
    private function throwingFactory(): \Closure
    {
        return static function (string $token): ManagementClient {
            throw new \LogicException('the management client must not be built for a rejected request');
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorWire(string $status, ?string $snoozedUntil): array
    {
        return [
            'uuid' => self::UUID,
            'name' => 'Nightly reports',
            'schedule_kind' => 'interval',
            'schedule_expr' => '300',
            'tz' => 'UTC',
            'grace_seconds' => 60,
            'status' => $status,
            'next_expected_at' => null,
            'last_ping_at' => null,
            'created_at' => '2026-01-01T00:00:00+00:00',
            'ping_url' => 'https://cronheart.com/ping/'.self::UUID,
            'badge_url' => 'https://cronheart.com/badge/'.self::UUID.'.svg',
            'snoozed_until' => $snoozedUntil,
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
