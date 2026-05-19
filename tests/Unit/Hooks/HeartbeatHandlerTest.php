<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Hooks;

use Cronheart\WP\Api\Client;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Hooks\HeartbeatHandler;
use CronMonitor\Client\PingResult;
use PHPUnit\Framework\TestCase;

final class HeartbeatHandlerTest extends TestCase
{
    private const UUID = 'dddddddd-dddd-4ddd-8ddd-dddddddddddd';

    public function test_tick_skips_silently_when_resolver_returns_null(): void
    {
        // Resolver returns null when no UUID is configured. The
        // handler must not call the client.
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('heartbeat');

        $handler = new HeartbeatHandler($this->resolverReturning(null), $client);
        $handler->tick();
    }

    public function test_tick_dispatches_heartbeat_when_resolver_supplies_uuid(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('heartbeat')
            ->with(self::UUID)
            ->willReturn(PingResult::delivered(200, 1));

        $handler = new HeartbeatHandler($this->resolverReturning(self::UUID), $client);
        $handler->tick();
    }

    public function test_tick_swallows_throwables_so_wp_cron_run_continues(): void
    {
        // Defence in depth: even if the client violates its no-throw
        // contract (custom transport bound through Configuration),
        // the WP-Cron runner must keep going. We deliberately throw
        // from the mocked client to prove the outer try/catch holds.
        $client = $this->createMock(Client::class);
        $client->method('heartbeat')->willThrowException(new \RuntimeException('boom'));

        $handler = new HeartbeatHandler($this->resolverReturning(self::UUID), $client);
        $handler->tick();

        $this->expectNotToPerformAssertions();
    }

    private function resolverReturning(?string $uuid): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): ?string => Resolver::HEARTBEAT_CONSTANT === $name ? $uuid : null,
            optionReader: static fn (string $name) => null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }
}
