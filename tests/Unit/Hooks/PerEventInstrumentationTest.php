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
    private const UUID = '00000000-0000-0000-0000-000000000000';

    public function test_register_installs_two_actions_per_hook_one_fatal_error_filter_and_one_shutdown_handler(): void
    {
        $actionRecorder = new Recorder();
        $filterRecorder = new Recorder();
        $shutdownRecorder = new Recorder();
        $instrumentation = $this->buildInstrumentation(
            uuidFor: [self::HOOK => self::UUID],
            currentHook: null,
            actionRecorder: $actionRecorder,
            filterRecorder: $filterRecorder,
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

        self::assertCount(1, $filterRecorder->calls);
        self::assertSame('wp_php_error_args', $filterRecorder->calls[0][0]);
        self::assertSame([$instrumentation, 'sweep_before_fatal_error_page'], $filterRecorder->calls[0][1]);
        self::assertSame(\PHP_INT_MIN, $filterRecorder->calls[0][2]);
        self::assertSame(1, $filterRecorder->calls[0][3]);
    }

    public function test_register_skips_filter_and_shutdown_registration_when_no_hooks_to_monitor(): void
    {
        $filterRecorder = new Recorder();
        $shutdownRecorder = new Recorder();
        $instrumentation = $this->buildInstrumentation(
            uuidFor: [],
            currentHook: null,
            actionRecorder: new Recorder(),
            filterRecorder: $filterRecorder,
            shutdownRecorder: $shutdownRecorder,
            lastError: null,
        );

        $instrumentation->register([]);

        self::assertCount(0, $filterRecorder->calls);
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
            filterAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
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
            filterAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
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
            filterAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
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
            filterAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
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
            filterAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
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

    public function test_fatal_error_page_filter_sends_one_fail_before_the_shutdown_sweep(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('start')->with(self::UUID);
        $client->expects(self::never())->method('success');
        $failPings = new Recorder();
        $client->method('fail')->willReturnCallback(
            static function (string $uuid, ?string $body) use ($failPings): PingResult {
                $failPings->record([$uuid, $body]);

                return PingResult::delivered(200, 1);
            },
        );

        $filterRecorder = new Recorder();
        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([self::HOOK => self::UUID]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            filterAdder: static function (string $h, callable $c, int $p, int $a) use ($filterRecorder): bool {
                $filterRecorder->record([$h, $c, $p, $a]);

                return true;
            },
            currentHookName: static fn (): string => self::HOOK,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): array => [
                'type' => \E_ERROR,
                'message' => 'Uncaught RuntimeException: boom',
                'file' => '/app/src/job.php',
                'line' => 7,
            ],
        );
        $instrumentation->register([self::HOOK]);
        $fatalErrorPageFilter = $filterRecorder->calls[0][1];
        self::assertIsCallable($fatalErrorPageFilter);

        $instrumentation->fire_start();
        // WordPress's fatal-error handler applies the filter, then calls
        // wp_die(), which exits under WP-CLI before our shutdown sweep.
        $args = ['response' => 500, 'exit' => false];
        self::assertSame($args, $fatalErrorPageFilter($args));
        self::assertCount(1, $failPings->calls);
        self::assertSame(self::UUID, $failPings->calls[0][0]);
        self::assertStringContainsString('E_ERROR', (string) $failPings->calls[0][1]);

        $instrumentation->sweep_in_flight();
        self::assertCount(1, $failPings->calls);
    }

    public function test_fatal_error_page_filter_returns_its_args_untouched_when_nothing_is_in_flight(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('fail');
        $filterRecorder = new Recorder();
        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([self::HOOK => self::UUID]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            filterAdder: static function (string $h, callable $c, int $p, int $a) use ($filterRecorder): bool {
                $filterRecorder->record([$h, $c, $p, $a]);

                return true;
            },
            currentHookName: static fn (): string => self::HOOK,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): ?array => null,
        );
        $instrumentation->register([self::HOOK]);
        $fatalErrorPageFilter = $filterRecorder->calls[0][1];
        self::assertIsCallable($fatalErrorPageFilter);

        $args = ['response' => 500, 'exit' => false];
        self::assertSame($args, $fatalErrorPageFilter($args));
    }

    public function test_a_throwing_resolver_inside_the_fatal_error_page_filter_is_swallowed_per_hook(): void
    {
        $throwingHook = 'app:reports:weekly';
        $failPings = new Recorder();
        $client = $this->createMock(Client::class);
        $client->method('fail')->willReturnCallback(
            static function (string $uuid, ?string $body) use ($failPings): PingResult {
                $failPings->record([$uuid]);

                return PingResult::delivered(200, 1);
            },
        );
        $optionReads = 0;
        $resolver = new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static function (string $name) use (&$optionReads, $throwingHook): array {
                ++$optionReads;
                if (3 === $optionReads) {
                    throw new \TypeError('third-party option filter broke');
                }

                return [$throwingHook => '00000000-0000-0000-0000-000000000002', self::HOOK => self::UUID];
            },
            filterApplier: static fn (string $name, array $value) => $value,
        );
        $currentHook = $throwingHook;
        $filterRecorder = new Recorder();
        $instrumentation = new PerEventInstrumentation(
            resolver: $resolver,
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            filterAdder: static function (string $h, callable $c, int $p, int $a) use ($filterRecorder): bool {
                $filterRecorder->record([$h, $c, $p, $a]);

                return true;
            },
            currentHookName: static function () use (&$currentHook): string {
                return $currentHook;
            },
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): array => self::uncaughtException(),
        );
        $instrumentation->register([$throwingHook, self::HOOK]);
        $fatalErrorPageFilter = $filterRecorder->calls[0][1];
        self::assertIsCallable($fatalErrorPageFilter);
        $instrumentation->fire_start();
        $currentHook = self::HOOK;
        $instrumentation->fire_start();

        $args = ['response' => 500, 'exit' => false];
        self::assertSame($args, $fatalErrorPageFilter($args));
        self::assertSame([[self::UUID]], $failPings->calls);

        $instrumentation->sweep_in_flight();
        self::assertSame([[self::UUID]], $failPings->calls);
    }

    public function test_fatal_error_page_filter_applied_mid_run_without_a_fatal_error_leaves_the_run_alone(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::once())->method('start')->with(self::UUID);
        $client->expects(self::never())->method('fail');
        $client->expects(self::once())->method('success')->with(self::UUID);
        $filterRecorder = new Recorder();
        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([self::HOOK => self::UUID]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            filterAdder: static function (string $h, callable $c, int $p, int $a) use ($filterRecorder): bool {
                $filterRecorder->record([$h, $c, $p, $a]);

                return true;
            },
            currentHookName: static fn (): string => self::HOOK,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): array => [
                'type' => \E_WARNING,
                'message' => 'Undefined variable',
                'file' => '/app/src/job.php',
                'line' => 3,
            ],
        );
        $instrumentation->register([self::HOOK]);
        $fatalErrorPageFilter = $filterRecorder->calls[0][1];
        self::assertIsCallable($fatalErrorPageFilter);

        $instrumentation->fire_start();
        $args = ['response' => 500, 'exit' => false];
        self::assertSame($args, $fatalErrorPageFilter($args));
        $instrumentation->fire_success();
    }

    public function test_a_warning_raised_inside_the_fatal_error_page_filter_does_not_replace_the_last_error(): void
    {
        $client = $this->createMock(Client::class);
        $optionReads = 0;
        $resolver = new Resolver(
            constantReader: static fn (string $name): ?string => null,
            optionReader: static function (string $name) use (&$optionReads): array {
                if (2 === ++$optionReads) {
                    trigger_error('third-party option filter warned', \E_USER_WARNING);
                }

                return [self::HOOK => self::UUID];
            },
            filterApplier: static fn (string $name, array $value) => $value,
        );
        $filterRecorder = new Recorder();
        $instrumentation = new PerEventInstrumentation(
            resolver: $resolver,
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            filterAdder: static function (string $h, callable $c, int $p, int $a) use ($filterRecorder): bool {
                $filterRecorder->record([$h, $c, $p, $a]);

                return true;
            },
            currentHookName: static fn (): string => self::HOOK,
            shutdownRegistrar: static fn (callable $c): mixed => null,
            lastErrorReader: static fn (): array => self::uncaughtException(),
        );
        $instrumentation->register([self::HOOK]);
        $fatalErrorPageFilter = $filterRecorder->calls[0][1];
        self::assertIsCallable($fatalErrorPageFilter);
        $instrumentation->fire_start();

        $handlerBefore = self::currentErrorHandler();
        error_clear_last();
        $fatalErrorPageFilter(['response' => 500, 'exit' => false]);

        self::assertSame(2, $optionReads);
        self::assertNull(error_get_last());
        self::assertSame($handlerBefore, self::currentErrorHandler());
    }

    private static function currentErrorHandler(): mixed
    {
        $current = set_error_handler(static fn (): bool => false);
        restore_error_handler();

        return $current;
    }

    public function test_sweep_in_flight_is_a_noop_when_nothing_is_in_flight(): void
    {
        $client = $this->createMock(Client::class);
        $client->expects(self::never())->method('fail');

        $instrumentation = new PerEventInstrumentation(
            resolver: $this->resolverWith([self::HOOK => self::UUID]),
            client: $client,
            actionAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
            filterAdder: static fn (string $h, callable $c, int $p, int $a): bool => true,
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
        Recorder $filterRecorder,
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
            filterAdder: static function (string $h, callable $c, int $p, int $a) use ($filterRecorder): bool {
                $filterRecorder->record([$h, $c, $p, $a]);

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
     * @return array{type: int, message: string, file: string, line: int}
     */
    private static function uncaughtException(): array
    {
        return [
            'type' => \E_ERROR,
            'message' => 'Uncaught RuntimeException: boom',
            'file' => '/app/src/job.php',
            'line' => 7,
        ];
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
 * cases to assert which `add_action` / `add_filter` / `register_shutdown_function`
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
