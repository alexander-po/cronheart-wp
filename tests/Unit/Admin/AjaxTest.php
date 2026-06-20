<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Admin;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Cronheart\WP\Admin\Ajax;
use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Cron\EventDiscovery;
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
        Functions\when('site_url')->justReturn('https://example.test');
    }

    protected function tearDown(): void
    {
        $_POST = [];
        Monkey\tearDown();
    }

    public function test_register_adds_the_authenticated_actions_and_never_nopriv(): void
    {
        Actions\expectAdded('wp_ajax_'.Ajax::ACTION)->once();
        Actions\expectAdded('wp_ajax_'.Ajax::ACTION_MAP_EVENT)->once();
        Actions\expectAdded('wp_ajax_'.Ajax::ACTION_CREATE_EVENT)->once();
        Actions\expectAdded('wp_ajax_nopriv_'.Ajax::ACTION)->never();
        Actions\expectAdded('wp_ajax_nopriv_'.Ajax::ACTION_MAP_EVENT)->never();
        Actions\expectAdded('wp_ajax_nopriv_'.Ajax::ACTION_CREATE_EVENT)->never();

        (new Ajax($this->resolverWithToken(), $this->throwingFactory()))->register();

        $this->addToAssertionCount(6);
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

    public function test_map_event_assigns_a_monitor_and_writes_the_event_map(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'wp_version_check', 'uuid' => self::UUID];
        $saved = null;
        Functions\when('get_option')->alias(static fn ($opt, $def = false) => Resolver::EVENT_MAP_OPTION === $opt ? [] : $def);
        Functions\when('update_option')->alias(static function ($opt, $val) use (&$saved) {
            $saved = [$opt, $val];

            return true;
        });

        $response = $this->captureCall(static fn (Ajax $ajax) => $ajax->handleMapEvent(), $this->resolverWithToken());

        self::assertTrue($response->success);
        self::assertNotNull($saved, 'update_option was called');
        self::assertSame(['wp_version_check' => self::UUID], $saved[1]);
        self::assertSame(Resolver::EVENT_MAP_OPTION, $saved[0]);
        self::assertIsArray($response->data);
        self::assertTrue($response->data['mapped']);
    }

    public function test_map_event_preserves_other_hooks_in_the_map(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'wp_version_check', 'uuid' => self::UUID];
        $saved = null;
        Functions\when('get_option')->alias(static fn ($opt, $def = false) => Resolver::EVENT_MAP_OPTION === $opt
            ? ['feed_refresh' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb']
            : $def);
        Functions\when('update_option')->alias(static function ($opt, $val) use (&$saved) {
            $saved = [$opt, $val];

            return true;
        });

        $response = $this->captureCall(static fn (Ajax $ajax) => $ajax->handleMapEvent(), $this->resolverWithToken());

        self::assertTrue($response->success);
        self::assertNotNull($saved, 'update_option was called');
        self::assertSame(
            [
                'feed_refresh' => 'bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb',
                'wp_version_check' => self::UUID,
            ],
            $saved[1],
            'a read-modify-write merge keeps every other hook entry'
        );
    }

    public function test_map_event_recovers_from_a_non_array_stored_option(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'wp_version_check', 'uuid' => self::UUID];
        $saved = null;
        Functions\when('get_option')->alias(static fn ($opt, $def = false) => Resolver::EVENT_MAP_OPTION === $opt ? 'corrupt-not-an-array' : $def);
        Functions\when('update_option')->alias(static function ($opt, $val) use (&$saved) {
            $saved = [$opt, $val];

            return true;
        });

        $response = $this->captureCall(static fn (Ajax $ajax) => $ajax->handleMapEvent(), $this->resolverWithToken());

        self::assertTrue($response->success);
        self::assertNotNull($saved, 'update_option was called');
        self::assertSame(['wp_version_check' => self::UUID], $saved[1], 'a non-array stored option resets to a fresh map without fataling');
    }

    public function test_map_event_suppresses_with_an_empty_uuid(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'wp_version_check', 'uuid' => ''];
        $saved = null;
        Functions\when('get_option')->alias(static fn ($opt, $def = false) => Resolver::EVENT_MAP_OPTION === $opt ? [] : $def);
        Functions\when('update_option')->alias(static function ($opt, $val) use (&$saved) {
            $saved = [$opt, $val];

            return true;
        });

        $response = $this->captureCall(static fn (Ajax $ajax) => $ajax->handleMapEvent(), $this->resolverWithToken());

        self::assertTrue($response->success);
        self::assertNotNull($saved, 'update_option was called');
        self::assertSame(['wp_version_check' => ''], $saved[1], 'empty UUID stores the suppress marker');
        self::assertFalse($response->data['mapped']);
    }

    public function test_map_event_rejects_an_unknown_hook_without_writing(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'not_a_real_hook', 'uuid' => self::UUID];
        Functions\expect('update_option')->never();

        $response = $this->captureCall(static fn (Ajax $ajax) => $ajax->handleMapEvent(), $this->resolverWithToken());

        self::assertFalse($response->success);
        self::assertSame(400, $response->statusCode);
    }

    public function test_map_event_rejects_a_constant_governed_hook(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'wp_version_check', 'uuid' => self::UUID];
        Functions\expect('update_option')->never();

        $response = $this->captureCall(static fn (Ajax $ajax) => $ajax->handleMapEvent(), $this->resolverWithConstantEvent('wp_version_check'));

        self::assertFalse($response->success);
        self::assertSame(409, $response->statusCode);
    }

    public function test_map_event_rejects_an_invalid_uuid(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'wp_version_check', 'uuid' => 'not-a-uuid'];
        Functions\expect('update_option')->never();

        $response = $this->captureCall(static fn (Ajax $ajax) => $ajax->handleMapEvent(), $this->resolverWithToken());

        self::assertFalse($response->success);
        self::assertSame(400, $response->statusCode);
    }

    public function test_create_event_monitor_creates_then_assigns(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'wp_version_check'];
        $saved = null;
        Functions\when('get_option')->alias(static fn ($opt, $def = false) => Resolver::EVENT_MAP_OPTION === $opt ? [] : $def);
        Functions\when('update_option')->alias(static function ($opt, $val) use (&$saved) {
            $saved = [$opt, $val];

            return true;
        });

        $factory = $this->factoryReturning($this->monitorWire('new', null));
        $response = $this->captureCall(static fn (Ajax $ajax) => $ajax->handleCreateEventMonitor(), $this->resolverWithToken(), $factory);

        self::assertTrue($response->success);
        self::assertSame(self::UUID, $response->data['uuid']);
        self::assertNotNull($saved, 'update_option was called');
        self::assertSame(['wp_version_check' => self::UUID], $saved[1], 'the created monitor is mapped to the hook');
    }

    public function test_create_event_monitor_rejects_an_already_mapped_hook(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'wp_version_check'];
        Functions\expect('update_option')->never();

        $response = $this->captureCall(
            static fn (Ajax $ajax) => $ajax->handleCreateEventMonitor(),
            $this->resolverWithMappedEvent('wp_version_check', self::UUID),
            $this->throwingFactory(),
        );

        self::assertFalse($response->success);
        self::assertSame(409, $response->statusCode);
    }

    public function test_create_event_monitor_rejects_a_one_off_hook(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'my_one_off'];
        Functions\expect('update_option')->never();

        $response = $this->captureCall(
            static fn (Ajax $ajax) => $ajax->handleCreateEventMonitor(),
            $this->resolverWithToken(),
            $this->throwingFactory(),
        );

        self::assertFalse($response->success);
        self::assertSame(400, $response->statusCode);
    }

    public function test_create_event_monitor_maps_a_conflict_to_a_json_error(): void
    {
        $this->authorised();
        $_POST = ['hook' => 'wp_version_check'];
        Functions\expect('update_option')->never();

        // A 409 (same idempotency key, changed body) must surface as a JSON
        // error, never a fatal, and must not write the map.
        $factory = $this->factoryFailingWith(new Response(409, ['Content-Type' => 'application/problem+json'], '{"title":"Conflict","status":409}'));
        $response = $this->captureCall(static fn (Ajax $ajax) => $ajax->handleCreateEventMonitor(), $this->resolverWithToken(), $factory);

        self::assertFalse($response->success);
        self::assertSame(409, $response->statusCode);
    }

    private function authorised(): void
    {
        Functions\when('check_ajax_referer')->justReturn(1);
        Functions\when('current_user_can')->justReturn(true);
    }

    /**
     * @param \Closure(Ajax): void $invoke
     */
    private function captureCall(\Closure $invoke, Resolver $resolver, ?\Closure $factory = null): WpJsonResponse
    {
        $ajax = new Ajax($resolver, $factory, $this->eventDiscovery());

        try {
            $invoke($ajax);
        } catch (WpJsonResponse $response) {
            return $response;
        }

        self::fail('the handler did not emit a JSON response');
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

    private function resolverWithConstantEvent(string $hook): Resolver
    {
        $constant = Resolver::EVENT_CONSTANT_PREFIX.strtoupper(str_replace('-', '_', $hook)).Resolver::EVENT_CONSTANT_SUFFIX;

        return new Resolver(
            constantReader: static fn (string $name): ?string => $name === $constant ? self::UUID : null,
            optionReader: static fn (string $name) => Resolver::API_TOKEN_OPTION === $name ? 'cmk_'.str_repeat('a', 43) : null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }

    private function resolverWithMappedEvent(string $hook, string $uuid): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static fn (string $name) => match ($name) {
                Resolver::API_TOKEN_OPTION => 'cmk_'.str_repeat('a', 43),
                Resolver::EVENT_MAP_OPTION => [$hook => $uuid],
                default => null,
            },
            filterApplier: static fn (string $name, array $value) => $value,
        );
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
