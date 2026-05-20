<?php

declare(strict_types=1);

namespace Cronheart\WP;

use Cronheart\WP\Admin\EventList;
use Cronheart\WP\Admin\SettingsPage;
use Cronheart\WP\Api\Client;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Hooks\HeartbeatHandler;
use Cronheart\WP\Hooks\HeartbeatScheduler;
use Cronheart\WP\Hooks\PerEventInstrumentation;
use CronMonitor\Client\Configuration;

// Direct-access guard. WP.org's Plugin Check flags every PHP file
// that is reachable through a stray HTTP request without an
// `ABSPATH` probe — even though PSR-4 means this class is never
// loaded as a top-level script in practice, the static check fires
// on the missing constant regardless. The check's regex is strict
// about the canonical `defined('ABSPATH') || exit;` shape, so we
// stick to it. `tests/bootstrap.php` predefines `ABSPATH` to a
// sentinel before autoload runs, which keeps the unit suite happy.
\defined('ABSPATH') || exit;

/**
 * Plugin entry point, called from `cronheart.php`.
 *
 * Responsibilities:
 *   - Build the small object graph (`Resolver`, `Client`,
 *     `HeartbeatHandler`).
 *   - Wire the WordPress hooks the plugin owns:
 *       * `cron_schedules` filter — adds the 5-minute custom schedule.
 *       * `cronheart_heartbeat_tick` action — drives the heartbeat
 *         ping.
 *   - Stay otherwise side-effect free at construction time so the
 *     class is unit-testable.
 *
 * Activation / deactivation hooks (which `wp_schedule_event` /
 * `wp_clear_scheduled_hook` ride) live in `cronheart.php` itself
 * because `register_activation_hook` requires `__FILE__` to be the
 * main plugin file — they call into `HeartbeatScheduler::activate()`
 * and `::deactivate()`.
 */
final class Plugin
{
    public function boot(): void
    {
        $resolver = self::buildResolver();
        $client = new Client(self::buildSdkConfiguration($resolver));
        $heartbeat = new HeartbeatHandler($resolver, $client);
        $perEvent = self::buildPerEventInstrumentation($resolver, $client);

        // Make the 5-minute schedule known to WP-Cron on every
        // request — idempotent thanks to `HeartbeatScheduler`'s own
        // collision guard.
        add_filter('cron_schedules', [HeartbeatScheduler::class, 'add_cronheart_schedule']);

        // Drive the heartbeat tick. Priority 10 (default) is fine —
        // no other plugin should be wiring this hook, and if they
        // do, our handler still runs regardless of order.
        add_action(HeartbeatScheduler::TICK_HOOK, [$heartbeat, 'tick']);

        // Per-event monitoring: wrap every hook the resolver knows
        // about with start / success / fail pings. Hook enumeration
        // happens on `plugins_loaded` (priority `PHP_INT_MAX`) so
        // every other plugin's own `cronheart_monitor()` calls have
        // landed by the time we read the filter map.
        add_action('plugins_loaded', static function () use ($perEvent, $resolver): void {
            $perEvent->register($resolver->eventHookNames());
        }, \PHP_INT_MAX);

        // Admin Settings → Cronheart. Registering the menu and
        // settings hooks outside an admin request is harmless — the
        // hooks only fire on admin page loads — so we skip the
        // `is_admin()` guard.
        (new SettingsPage(new EventList($resolver)))->register();
    }

    /**
     * Wire the `Resolver` to the real WordPress runtime. The closures
     * bind the WP-side primitives (`defined`/`constant`,
     * `get_option`, `apply_filters`) the resolver depends on without
     * making the resolver itself WP-aware — tests construct it with
     * pure-PHP closures (see `tests/Unit/Config/ResolverTest.php`).
     */
    private static function buildResolver(): Resolver
    {
        return new Resolver(
            // Return PHP's native constant value (string / bool / int /
            // …) rather than coercing to string — the resolver needs
            // the native type for `allowInsecureEndpoint()` which
            // accepts `define('…', true)` directly.
            constantReader: static fn (string $name) => \defined($name) ? \constant($name) : null,
            optionReader: static fn (string $name) => get_option($name, null),
            filterApplier: static fn (string $name, array $value) => apply_filters($name, $value),
        );
    }

    /**
     * Build the SDK `Configuration` from the resolver's endpoint and
     * allow-insecure values. Falls back to `null` when the resolver
     * has nothing to say — `Client::__construct(null)` then uses
     * `CronMonitorClient::create()` which applies the SDK's defaults
     * (production `https://cronheart.com`).
     *
     * If the user misconfigures the endpoint (plain `http://` without
     * allow-insecure, malformed URL), the SDK's Configuration
     * constructor throws `\InvalidArgumentException`. We catch and
     * fall back to defaults so the plugin keeps booting — the WP-Cron
     * run must complete even if monitoring is unreachable. The
     * misconfiguration surfaces through the admin notice path in
     * v0.1.1+; for v0.1.0, the silent fallback keeps the host site
     * functional.
     */
    private static function buildSdkConfiguration(Resolver $resolver): ?Configuration
    {
        $endpoint = $resolver->endpoint();
        $allowInsecure = $resolver->allowInsecureEndpoint();
        if (null === $endpoint && !$allowInsecure) {
            return null;
        }

        try {
            return new Configuration(
                endpoint: $endpoint ?? Configuration::DEFAULT_ENDPOINT,
                allowInsecureEndpoint: $allowInsecure,
            );
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Wire the per-event instrumentation to the real WordPress
     * runtime. As with `buildResolver`, the closures are the only
     * point of contact with WP globals so the class itself stays
     * unit-testable.
     */
    private static function buildPerEventInstrumentation(Resolver $resolver, Client $client): PerEventInstrumentation
    {
        return new PerEventInstrumentation(
            $resolver,
            $client,
            actionAdder: static fn (string $hook, callable $cb, int $priority, int $args) => add_action($hook, $cb, $priority, $args),
            currentHookName: static function (): ?string {
                $hook = current_action();

                return \is_string($hook) && '' !== $hook ? $hook : null;
            },
            shutdownRegistrar: static fn (callable $cb) => register_shutdown_function($cb),
            lastErrorReader: static fn () => error_get_last(),
        );
    }
}
