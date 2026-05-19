<?php

declare(strict_types=1);

namespace Cronheart\WP\Hooks;

use Cronheart\WP\Api\Client;
use Cronheart\WP\Config\Resolver;

/**
 * Per-event WP-Cron instrumentation.
 *
 * For each hook name we are asked to monitor, two `add_action` hooks
 * sandwich the user's own callbacks:
 *
 *   - At priority **PHP_INT_MIN** (runs first): `fire_start()` —
 *     dispatches the `start` ping.
 *   - At priority **PHP_INT_MAX** (runs last):  `fire_success()` —
 *     dispatches the `success` ping.
 *
 * If the user's callback throws or fatals, the high-priority
 * `fire_success` callback never runs and the hook stays in
 * `$inFlight`. A shutdown handler then sweeps the still-in-flight
 * map and dispatches `fail` pings — that path also folds in the
 * `error_get_last()` capture so a fatal PHP error surfaces in the
 * fail-ping body on the cronheart dashboard.
 *
 * Hook-name enumeration: PerEventInstrumentation is asked at
 * `Plugin::boot()` time to instrument a fixed list of names. The
 * list comes from the union of `cronheart_event_map` (option) and
 * `cronheart_monitor_map` (filter); see `Resolver::eventHookNames()`.
 * Plain `CRONHEART_EVENT_<HOOK>_UUID` constants can supply the UUID
 * for those hooks, but they cannot register hook names on their own
 * (PHP cannot enumerate user constants by prefix without
 * `get_defined_constants()`, which is too heavy for a boot path);
 * users still call `cronheart_monitor( $hook_name )` (no UUID
 * argument) to register the name, and the resolver finds the
 * constant for the value.
 *
 * The shutdown registrar is injected through the constructor so
 * tests can pass a no-op closure (the real
 * `register_shutdown_function` would persist across tests and pollute
 * unrelated cases).
 */
final class PerEventInstrumentation
{
    /**
     * Hook name → marker. Set by `fire_start` when we ping `start`,
     * cleared by `fire_success` once the user's callbacks complete.
     * Anything left here at shutdown is interpreted as "the hook did
     * not complete normally" and gets a fail-ping.
     *
     * @var array<string, true>
     */
    private array $in_flight = [];

    /**
     * @param \Closure(string $hook, callable $callback, int $priority, int $accepted_args): mixed $actionAdder
     *                                                                                                                wraps `add_action`; tests pass a recorder spy
     * @param \Closure(): ?string                                                                  $currentHookName
     *                                                                                                                returns the name of the currently-firing WP action, or null at boot/shutdown
     *                                                                                                                (wraps `current_action`)
     * @param \Closure(callable): mixed                                                            $shutdownRegistrar
     *                                                                                                                wraps `register_shutdown_function` so tests can pass a no-op
     * @param \Closure(): (array{type: int, message: string, file: string, line: int}|null)        $lastErrorReader
     *                                                                                                                wraps `error_get_last`, returns null if no error
     */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly Client $client,
        private readonly \Closure $actionAdder,
        private readonly \Closure $currentHookName,
        private readonly \Closure $shutdownRegistrar,
        private readonly \Closure $lastErrorReader,
    ) {
    }

    /**
     * @param iterable<string> $hookNames
     */
    public function register(iterable $hookNames): void
    {
        $any = false;
        foreach ($hookNames as $hook) {
            if ('' === $hook) {
                continue;
            }
            ($this->actionAdder)($hook, [$this, 'fire_start'], \PHP_INT_MIN, 0);
            ($this->actionAdder)($hook, [$this, 'fire_success'], \PHP_INT_MAX, 0);
            $any = true;
        }

        // Skip the shutdown registration when there's nothing to
        // monitor — keeps the test suite tidy and saves an unneeded
        // PHP shutdown handler in dev/test runs.
        if ($any) {
            ($this->shutdownRegistrar)([$this, 'sweep_in_flight']);
        }
    }

    public function fire_start(): void
    {
        $hook = ($this->currentHookName)();
        if (null === $hook || '' === $hook) {
            return;
        }
        $uuid = $this->resolver->eventUuid($hook);
        if (null === $uuid) {
            return;
        }
        $this->in_flight[$hook] = true;
        try {
            $this->client->start($uuid);
        } catch (\Throwable) {
            // Belt-and-suspenders; Client::safely already catches.
        }
    }

    public function fire_success(): void
    {
        $hook = ($this->currentHookName)();
        if (null === $hook || '' === $hook) {
            return;
        }
        if (!isset($this->in_flight[$hook])) {
            return;
        }
        unset($this->in_flight[$hook]);
        $uuid = $this->resolver->eventUuid($hook);
        if (null === $uuid) {
            return;
        }
        try {
            $this->client->success($uuid);
        } catch (\Throwable) {
            // Belt-and-suspenders; Client::safely already catches.
        }
    }

    public function sweep_in_flight(): void
    {
        if ([] === $this->in_flight) {
            return;
        }
        $body = $this->build_fail_body();
        foreach (array_keys($this->in_flight) as $hook) {
            $uuid = $this->resolver->eventUuid($hook);
            if (null === $uuid) {
                continue;
            }
            try {
                $this->client->fail($uuid, $body);
            } catch (\Throwable) {
                // Belt-and-suspenders; Client::safely already catches.
            }
        }
        $this->in_flight = [];
    }

    /**
     * Build a fail-ping body, including the PHP fatal error summary
     * when one is available via `error_get_last`. Always returns a
     * string — at minimum, a generic "did not complete" sentinel,
     * since an empty body would hide the failure in the cronheart
     * dashboard's body column.
     */
    private function build_fail_body(): string
    {
        $last = ($this->lastErrorReader)();
        if (null === $last) {
            return 'WP-Cron event did not complete (no PHP error captured)';
        }
        $fatal_mask = \E_ERROR | \E_PARSE | \E_CORE_ERROR | \E_COMPILE_ERROR | \E_USER_ERROR;
        if (0 === ($last['type'] & $fatal_mask)) {
            return 'WP-Cron event did not complete (last PHP error was non-fatal)';
        }

        return \sprintf(
            "%s in %s:%d\n%s",
            self::php_error_type_name($last['type']),
            $last['file'],
            $last['line'],
            $last['message'],
        );
    }

    private static function php_error_type_name(int $type): string
    {
        return match ($type) {
            \E_ERROR => 'E_ERROR',
            \E_PARSE => 'E_PARSE',
            \E_CORE_ERROR => 'E_CORE_ERROR',
            \E_COMPILE_ERROR => 'E_COMPILE_ERROR',
            \E_USER_ERROR => 'E_USER_ERROR',
            default => 'PHP error '.$type,
        };
    }
}
