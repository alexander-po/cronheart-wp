<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Api;

use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Tests\Support\FakeHttpClient;
use CronMonitor\Api\Dto\SnoozeDuration;
use CronMonitor\Api\Exception\AuthenticationException;
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
}
