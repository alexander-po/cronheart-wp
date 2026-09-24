<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Api;

use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Tests\Support\FakeHttpClient;
use CronMonitor\Api\Dto\AlertKind;
use CronMonitor\Api\Dto\PingKind;
use CronMonitor\Api\Dto\SnoozeDuration;
use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\Exception\ChannelDeliveryException;
use CronMonitor\Api\Exception\ValidationException;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use PHPUnit\Framework\TestCase;

final class ManagementClientTest extends TestCase
{
    public function test_list_monitors_sends_bearer_and_returns_the_account_monitors(): void
    {
        [$management, $http] = $this->managementClient([
            $this->monitorsPage([
                $this->monitorWire('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly reports'),
                $this->monitorWire('bbbbbbbb-bbbb-4bbb-8bbb-bbbbbbbbbbbb', 'Hourly sync'),
            ], total: 2),
        ]);

        $monitors = $management->listMonitors();

        self::assertCount(2, $monitors);
        self::assertSame('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', $monitors[0]->uuid);
        self::assertSame('Hourly sync', $monitors[1]->name);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors?offset=0&limit=100', (string) $request->getUri());
        self::assertSame('Bearer cmk_test_token', $request->getHeaderLine('Authorization'));
    }

    public function test_list_monitors_caps_at_200_and_stops_paginating(): void
    {
        // Three full pages of 100 are queued (300 monitors available), but
        // the picker cap is 200 — the third page must never be requested.
        // This is the behaviour the v0.2.x closure guaranteed and the Part-A
        // swap to ManagementClient must preserve.
        [$management, $http] = $this->managementClient([
            $this->monitorsPage($this->wireBatch(0, 100), total: 300),
            $this->monitorsPage($this->wireBatch(100, 100), total: 300),
            $this->monitorsPage($this->wireBatch(200, 100), total: 300),
        ]);

        $monitors = $management->listMonitors();

        self::assertCount(ManagementClient::MONITOR_LIST_CAP, $monitors);
        self::assertSame(200, ManagementClient::MONITOR_LIST_CAP);
        self::assertCount(2, $http->requests, 'the page that would exceed the cap must not be requested');
    }

    public function test_list_monitors_propagates_a_thrown_sdk_exception(): void
    {
        // The admin-context counterpart to the ping Client's never-throw
        // contract: a 401 must surface as a typed exception so the settings
        // page can map it to a notice and fall back to manual UUID entry.
        [$management] = $this->managementClient([
            new Response(401, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                'title' => 'Unauthorized',
                'status' => 401,
                'detail' => 'Bad token.',
            ])),
        ]);

        $this->expectException(AuthenticationException::class);
        $management->listMonitors();
    }

    public function test_account_reads_the_account_endpoint(): void
    {
        [$management, $http] = $this->managementClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'plan' => ['key' => 'starter', 'label' => 'Starter', 'monitor_limit' => 50],
                'monitor_budget' => ['used' => 10, 'limit' => 50, 'remaining' => 40],
                'api_rate_limit' => ['limit' => 120, 'remaining' => 119],
            ])),
        ]);

        $account = $management->account();

        self::assertSame('Starter', $account->plan->label);
        self::assertSame(40, $account->monitorBudget->remaining);
        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/account', (string) $request->getUri());
    }

    public function test_pause_posts_to_the_pause_subresource(): void
    {
        [$management, $http] = $this->managementClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->monitorWire('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly'))),
        ]);

        $management->pause('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/pause', (string) $request->getUri());
    }

    public function test_snooze_posts_the_bounded_duration(): void
    {
        [$management, $http] = $this->managementClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->monitorWire('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly'))),
        ]);

        $management->snooze('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', SnoozeDuration::OneDay);

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/snooze', (string) $request->getUri());
        self::assertSame(['duration' => '1d'], json_decode($http->bodies[0], true));
    }

    public function test_unsnooze_posts_to_the_unsnooze_subresource(): void
    {
        [$management, $http] = $this->managementClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($this->monitorWire('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Nightly'))),
        ]);

        $management->unsnooze('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/unsnooze', (string) $request->getUri());
    }

    public function test_create_interval_monitor_posts_bare_digit_schedule_and_idempotency_key(): void
    {
        [$management, $http] = $this->managementClient([
            new Response(201, ['Content-Type' => 'application/json'], (string) json_encode($this->monitorWire('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'wp_version_check'))),
        ]);

        $management->createIntervalMonitor('wp_version_check', 43200, 'UTC', 4320, 'wp-abc123');

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors', (string) $request->getUri());
        self::assertSame('wp-abc123', $request->getHeaderLine('Idempotency-Key'));

        $body = (array) json_decode($http->bodies[0], true);
        self::assertSame('wp_version_check', $body['name']);
        self::assertSame('interval', $body['schedule_kind']);
        self::assertSame('43200', $body['schedule_expr'], 'bare interval seconds, no unit suffix');
        self::assertSame('UTC', $body['tz']);
        self::assertSame(4320, $body['grace_seconds']);
    }

    public function test_list_channels_reads_the_channels_endpoint(): void
    {
        [$management, $http] = $this->managementClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'data' => [
                    $this->channelWire('7', 'webhook', 'Ops webhook', true),
                    $this->channelWire('42', 'email', 'On-call email', false),
                ],
                'total' => 2,
            ])),
        ]);

        $channels = $management->listChannels();

        self::assertCount(2, $channels);
        self::assertSame('7', $channels[0]->id, 'channel ids stay strings (BIGINT carried verbatim)');
        self::assertSame('webhook', $channels[0]->kind);
        self::assertTrue($channels[0]->verified);
        self::assertSame('On-call email', $channels[1]->label);
        self::assertFalse($channels[1]->verified);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/channels', (string) $request->getUri());
        self::assertSame('Bearer cmk_test_token', $request->getHeaderLine('Authorization'));
    }

    public function test_list_channels_propagates_a_thrown_sdk_exception(): void
    {
        [$management] = $this->managementClient([
            new Response(401, ['Content-Type' => 'application/problem+json'], '{"title":"Unauthorized","status":401}'),
        ]);

        $this->expectException(AuthenticationException::class);
        $management->listChannels();
    }

    public function test_test_channel_posts_to_the_test_subresource_and_returns_the_result(): void
    {
        [$management, $http] = $this->managementClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'delivered' => true,
                'newly_verified' => true,
                'channel' => $this->channelWire('7', 'email', 'On-call email', true),
            ])),
        ]);

        $result = $management->testChannel('7');

        self::assertTrue($result->delivered);
        self::assertTrue($result->newlyVerified);
        self::assertSame('7', $result->channel->id);

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/channels/7/test', (string) $request->getUri());
    }

    public function test_test_channel_surfaces_a_delivery_failure_as_channel_delivery_exception(): void
    {
        [$management] = $this->managementClient([
            new Response(502, ['Content-Type' => 'application/problem+json'], '{"title":"Bad Gateway","status":502}'),
        ]);

        $this->expectException(ChannelDeliveryException::class);
        $management->testChannel('7');
    }

    public function test_test_channel_surfaces_an_unverified_channel_as_validation_exception(): void
    {
        [$management] = $this->managementClient([
            new Response(422, ['Content-Type' => 'application/problem+json'], (string) json_encode([
                'title' => 'Unprocessable Entity',
                'status' => 422,
                'errors' => ['channel' => 'Channel is not verified.'],
            ])),
        ]);

        $this->expectException(ValidationException::class);
        $management->testChannel('7');
    }

    public function test_rotate_channel_secret_posts_and_returns_the_once_only_plaintext(): void
    {
        $wire = $this->channelWire('7', 'webhook', 'Ops webhook', true);
        $wire['secret'] = 'whsec_rotated_plaintext';

        [$management, $http] = $this->managementClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode($wire)),
        ]);

        $secret = $management->rotateChannelSecret('7');

        self::assertSame('whsec_rotated_plaintext', $secret->secret);
        self::assertSame('7', $secret->channel->id);

        $request = $http->requests[0];
        self::assertSame('POST', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/channels/7/rotate-secret', (string) $request->getUri());
    }

    public function test_list_pings_reads_the_first_page_only(): void
    {
        [$management, $http] = $this->managementClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'data' => [
                    ['id' => '101', 'kind' => 'success', 'received_at' => '2026-01-01T00:05:00+00:00', 'runtime_ms' => 1234],
                    ['id' => '100', 'kind' => 'start', 'received_at' => '2026-01-01T00:05:00+00:00', 'runtime_ms' => null],
                ],
                'next_cursor' => 'eyJpZCI6MTAwfQ==',
            ])),
        ]);

        $page = $management->listPings('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        self::assertCount(2, $page->data);
        self::assertSame(PingKind::Success, $page->data[0]->kind);
        self::assertSame(1234, $page->data[0]->runtimeMs);
        self::assertNull($page->data[1]->runtimeMs);
        self::assertTrue($page->hasMore());

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/pings?limit=50', (string) $request->getUri());
        self::assertCount(1, $http->requests, 'the dashboard reads only the first page — no cursor walk');
    }

    public function test_list_alerts_reads_the_first_page_only(): void
    {
        [$management, $http] = $this->managementClient([
            new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
                'data' => [
                    ['id' => '9', 'kind' => 'late', 'created_at' => '2026-01-01T00:10:00+00:00', 'dispatched_to' => ['7' => '2026-01-01T00:10:01+00:00']],
                    ['id' => '8', 'kind' => 'recovered', 'created_at' => '2026-01-01T00:12:00+00:00', 'dispatched_to' => null],
                ],
                'total' => 2,
                'limit' => 50,
                'offset' => 0,
            ])),
        ]);

        $page = $management->listAlerts('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa');

        self::assertCount(2, $page->data);
        self::assertSame(AlertKind::Late, $page->data[0]->kind);
        self::assertSame(['7' => '2026-01-01T00:10:01+00:00'], $page->data[0]->dispatchedTo);
        self::assertNull($page->data[1]->dispatchedTo);
        self::assertSame(2, $page->total);

        $request = $http->requests[0];
        self::assertSame('GET', $request->getMethod());
        self::assertSame('https://cronheart.com/api/v1/monitors/aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa/alerts?offset=0&limit=50', (string) $request->getUri());
        self::assertCount(1, $http->requests, 'the dashboard reads only the first page');
    }

    /**
     * @param list<\Psr\Http\Message\ResponseInterface|\Throwable> $queue
     *
     * @return array{0: ManagementClient, 1: FakeHttpClient}
     */
    private function managementClient(array $queue): array
    {
        $http = new FakeHttpClient($queue);
        $factory = new Psr17Factory();
        $configuration = new Configuration('https://cronheart.com', apiKey: 'cmk_test_token');
        $api = new MonitorApiClient($configuration, $http, $factory, $factory);

        return [new ManagementClient($configuration, $api), $http];
    }

    /**
     * @param list<array<string, mixed>> $monitors
     */
    private function monitorsPage(array $monitors, int $total): Response
    {
        return new Response(200, ['Content-Type' => 'application/json'], (string) json_encode([
            'data' => $monitors,
            'total' => $total,
            'limit' => 100,
            'offset' => 0,
        ]));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function wireBatch(int $start, int $count): array
    {
        $batch = [];
        for ($i = $start; $i < $start + $count; ++$i) {
            $batch[] = $this->monitorWire(\sprintf('%08d-0000-4000-8000-000000000000', $i), 'Monitor '.$i);
        }

        return $batch;
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
}
