<?php

declare(strict_types=1);

namespace Cronheart\WP;

use Cronheart\WP\Admin\Ajax;
use Cronheart\WP\Admin\ChannelsScreen;
use Cronheart\WP\Admin\CronEventsScreen;
use Cronheart\WP\Admin\EventList;
use Cronheart\WP\Admin\HistoryScreen;
use Cronheart\WP\Admin\SettingsPage;
use Cronheart\WP\Api\Client;
use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Cron\EventDiscovery;
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
    private const PING_TIMEOUT_SECONDS = 3.0;
    private const PING_RETRIES = 2;
    private const API_RETRIES = 1;

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

        // Admin Settings → Cronheart, plus the monitor-lifecycle AJAX
        // surface. Registering these outside an admin request is harmless —
        // the menu/settings hooks only fire on admin page loads, and the
        // `wp_ajax_*` action only fires for an authenticated admin-ajax
        // request — so we skip the `is_admin()` guard. Both share one
        // management-client factory; the AJAX layer is admin-only by
        // construction (no `nopriv` companion).
        $managementClientFactory = self::buildManagementClientFactory($resolver);
        $pluginFile = \defined('CRONHEART_PLUGIN_FILE') ? (string) \constant('CRONHEART_PLUGIN_FILE') : '';
        $eventDiscovery = self::buildEventDiscovery();

        (new SettingsPage(new EventList($resolver), $resolver, $managementClientFactory, $pluginFile))->register();
        (new CronEventsScreen($eventDiscovery, $resolver, $managementClientFactory, $pluginFile))->register();
        (new ChannelsScreen($resolver, $managementClientFactory, $pluginFile))->register();
        (new HistoryScreen($resolver, $managementClientFactory))->register();
        (new Ajax($resolver, $managementClientFactory, $eventDiscovery))->register();
    }

    /**
     * Wire {@see EventDiscovery} to the real WordPress runtime. As with the
     * resolver, the closures are the only point of contact with WP globals so
     * the service itself stays unit-testable. `_get_cron_array()` is a private
     * core function (no public wrapper), guarded with `function_exists` for
     * defensiveness; an absent / empty cron option yields no events.
     */
    private static function buildEventDiscovery(): EventDiscovery
    {
        return new EventDiscovery(
            cronArrayReader: static fn () => \function_exists('_get_cron_array') ? _get_cron_array() : false,
            schedulesReader: static fn () => wp_get_schedules(),
            timezoneReader: static fn () => wp_timezone_string(),
        );
    }

    /**
     * Build the factory the Cronheart admin screens and admin-AJAX handlers
     * call to obtain a {@see ManagementClient} for the account token. It is
     * invoked lazily, only from those wp-admin code paths and only when an
     * API token is configured — never on the front end, in WP-Cron, or during
     * the runtime ping path. That is what keeps the plugin's "External
     * services" disclosure accurate: the write-capable account token leaves
     * the site only from wp-admin.
     *
     * The factory throws a {@see \RuntimeException} if the endpoint is
     * misconfigured, which every screen and handler treats as a generic
     * "could not reach" failure; the {@see ManagementClient} it returns lets
     * the SDK's typed {@see \CronMonitor\Api\Exception\ApiException}
     * subclasses propagate so the admin layer can map each failure to the
     * right notice or JSON error.
     *
     * @return \Closure(string): ManagementClient
     */
    private static function buildManagementClientFactory(Resolver $resolver): \Closure
    {
        return static function (string $token) use ($resolver): ManagementClient {
            $configuration = self::buildApiConfiguration($resolver, $token);
            if (null === $configuration) {
                throw new \RuntimeException('The Cronheart API endpoint is misconfigured.');
            }

            return new ManagementClient($configuration);
        };
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
     * Build the SDK `Configuration` for the runtime ping path from the
     * resolver's endpoint and allow-insecure values.
     *
     * The per-attempt timeout is short because a ping that runs inline
     * (`ALTERNATE_WP_CRON`, or a host that runs cron on the page
     * request) holds the visitor's request; the SDK's retry waits
     * together stay within the same value.
     *
     * If the user misconfigures the endpoint (plain `http://` without
     * allow-insecure, malformed URL), the SDK's Configuration
     * constructor throws `\InvalidArgumentException`. We catch and
     * fall back to the production endpoint so the plugin keeps booting
     * — the WP-Cron run must complete even if monitoring is
     * unreachable. The fallback is silent: nothing tells the operator
     * that pings go to the production host instead of the configured one.
     */
    private static function buildSdkConfiguration(Resolver $resolver): Configuration
    {
        try {
            return new Configuration(
                endpoint: $resolver->endpoint() ?? Configuration::DEFAULT_ENDPOINT,
                timeoutSeconds: self::PING_TIMEOUT_SECONDS,
                retries: self::PING_RETRIES,
                allowInsecureEndpoint: $resolver->allowInsecureEndpoint(),
            );
        } catch (\InvalidArgumentException) {
            return new Configuration(
                endpoint: Configuration::DEFAULT_ENDPOINT,
                timeoutSeconds: self::PING_TIMEOUT_SECONDS,
                retries: self::PING_RETRIES,
            );
        }
    }

    /**
     * Build the SDK `Configuration` for the authenticated management API,
     * carrying the account token as `apiKey`. Distinct from
     * `buildSdkConfiguration()` (which stays tokenless): the high-frequency
     * runtime ping path must never carry a write-capable credential —
     * least privilege. This config is built only for admin requests (the
     * screens and the admin-AJAX actions), lazily, inside the factory.
     *
     * Same endpoint / allow-insecure resolution as the runtime config, but
     * a misconfigured endpoint returns null so the token never reaches the
     * production host and the factory refuses; the settings picker then falls
     * back to manual entry. Retries stay
     * at one, the count the admin screens had before SDK 1.9.
     */
    private static function buildApiConfiguration(Resolver $resolver, string $token): ?Configuration
    {
        try {
            return new Configuration(
                endpoint: $resolver->endpoint() ?? Configuration::DEFAULT_ENDPOINT,
                retries: self::API_RETRIES,
                apiKey: $token,
                allowInsecureEndpoint: $resolver->allowInsecureEndpoint(),
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
