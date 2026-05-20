<?php

declare(strict_types=1);

namespace Cronheart\WP\Tests\Unit\Hooks;

use Cronheart\WP\Api\Client;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Hooks\PerEventInstrumentation;
use CronMonitor\Client\PingResult;
use PHPUnit\Framework\TestCase;

final class PerEventInstrumentationTest extends TestCase
{
    private const HOOK = 'app:reports:nightly';
    private const UUID = 'eeeeeeee-eeee-4eee-8eee-eeeeeeeeeeee';

    public function test_register_installs_two_actions_per_hook_and_one_shutdown_handler(): void
    {
        $actionRecorder = new Recorder();
        $shutdownRecorder = new Recorder();
        $instrumentation = $this->buildInstrumentation(
            uuidFor: [self::HOOK => self::UUID],
            currentHook: null,
            actionRecorder: $actionRecorder,
            shutdownRecorder: $shutdownRecorder,
            lastError: null,
        );

        $instrumentation->register([self::HOOK]);

        // start + success listeners per hook → exactly 2 add_action
        // calls for one registered hook.
        self::assertCount(2, $actionRecorder->calls);
        self::assertSame(self::HOOK, $actionRecorder->calls[0][0]);
        self::assertSame(\PHP_INT_MIN, $actionRecorder->calls[0][2]);
        self::assertSame(self::HOOK, $actionRecorder->calls[1][0]);
        self::assertSame(\PHP_INT_MAX, $actionRecorder->calls[1][2]);

        // Exactly one shutdown handler registered when we have
        // hooks to monitor.
        self::assertCount(1, $shutdownRecorder->calls);
    }

    public function test_register_skips_shutdown_registration_when_no_hooks_to_monitor(): void
    {
        $shutdownRecorder = new Recorder();
        $instrumentation = $this->buildInstrumentation(
            uuidFor: [],
            currentHook: null,
            actionRecorder: new Recorder(),
            shutdownRecorder: $shutdownRecorder,
            lastError: null,
        );

        $instrumentation->register([]);

        self::assertCount(0, $shutdownRecorder->calls);
    }

    public function test_fire_start_dispatches_start_ping_for_resolved_uuid(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('start')
            ->with(self::UUID)
            ->willReturn(PingResult::delivered(200, 1));

        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([self::HOOK => self::UUID]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            currentHookName: static fn (): string => self::HOOK,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): ?array => null,
        );

        $instrumentation->fire_start();
    }

    public function test_fire_start_skips_when_resolver_returns_null(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('start');

        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            currentHookName: static fn (): string => self::HOOK,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): ?array => null,
        );

        $instrumentation->fire_start();
    }

    public function test_start_then_success_fires_both_pings_and_clears_in_flight(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('start')->with(self::UUID);
        $client->expects(self::once())->method('success')->with(self::UUID);

        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([self::HOOK => self::UUID]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            currentHookName: static fn (): string => self::HOOK,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): ?array => null,
        );

        $instrumentation->fire_start();
        $instrumentation->fire_success();

        // After a successful sweep, in_flight is empty, so the
        // shutdown sweep must fire zero additional pings.
        $instrumentation->sweep_in_flight();
    }

    public function test_sweep_in_flight_fires_fail_ping_when_success_callback_never_ran(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('start')->with(self::UUID);
        $client->expects(self::never())->method('success');
        $client->expects(self::once())
            ->method('fail')
            ->with(
                self::UUID,
                self::stringContains('did not complete'),
            );

        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([self::HOOK => self::UUID]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            currentHookName: static fn (): string => self::HOOK,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): ?array => null,
        );

        $instrumentation->fire_start();
        // No fire_success — simulating a user callback that fataled
        // or threw before our PHP_INT_MAX listener could run.
        $instrumentation->sweep_in_flight();
    }

    public function test_sweep_in_flight_includes_php_fatal_error_summary_in_body(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())
            ->method('fail')
            ->with(
                self::UUID,
                self::callback(
                    static fn (?string $body): bool => null !== $body
                        && str_contains($body, 'E_ERROR')
                        && str_contains($body, '/app/src/blew_up.php:42')
                        && str_contains($body, 'segfault-like message'),
                ),
            );

        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([self::HOOK => self::UUID]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            currentHookName: static fn (): string => self::HOOK,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): array => [
                'type' => \E_ERROR,
                'message' => 'segfault-like message',
                'file' => '/app/src/blew_up.php',
                'line' => 42,
            ],
        );

        $instrumentation->fire_start();
        $instrumentation->sweep_in_flight();
    }

    public function test_sweep_in_flight_is_a_noop_when_nothing_is_in_flight(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('fail');

        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([self::HOOK => self::UUID]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            currentHookName: static fn (): ?string => null,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): ?array => null,
        );

        $instrumentation->sweep_in_flight();
    }

    /**
     * @param array<string, string>                                           $uuidFor
     * @param array{type: int, message: string, file: string, line: int}|null $lastError
     */
    private function buildInstrumentation(
        array $uuidFor,
        ?string $currentHook,
        Recorder $actionRecorder,
        Recorder $shutdownRecorder,
        ?array $lastError,
    ): PerEventInstrumentation {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('start');
        $client->expects(self::never())->method('success');
        $client->expects(self::never())->method('fail');

        return new PerEventInstrumentation(
            resolver: $this->resolverWith($uuidFor),
            client: $client,
            actionAdder: static function (string $h, callable $c, int $p, int $a) use ($actionRecorder): bool {
                $actionRecorder->record([$h, $c, $p, $a]);

                return true;
            },
            currentHookName: static fn (): ?string => $currentHook,
            shutdownRegistrar: static function (callable $c) use ($shutdownRecorder): bool {
                $shutdownRecorder->record([$c]);

                return true;
            },
            lastErrorReader: static fn (): ?array => $lastError,
        );
    }

    /**
     * @param array<string, string> $byHook
     */
    private function resolverWith(array $byHook): Resolver
    {
        return new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static fn (string $name) => Resolver::EVENT_MAP_OPTION === $name ? $byHook : null,
            filterApplier: static fn (string $name, array $value) => $value,
        );
    }
}

/**
 * Test-only spy used by `PerEventInstrumentationTest::register*`
 * cases to assert which `add_action` / `register_shutdown_function`
 * call shapes the instrumentation produces, without standing up the
 * full WordPress runtime.
 */
final class Recorder
{
    /** @var list<array<int, mixed>> */
    public array $calls = [];

    /**
     * @param array<int, mixed> $args
     */
    public function record(array $args): void
    {
        $this->calls[] = $args;
    }
}
