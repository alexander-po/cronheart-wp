<?php

declare(strict_types=1);

namespace Cronheart\WP;

use Cronheart\WP\Api\Client;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Hooks\HeartbeatHandler;
use Cronheart\WP\Hooks\HeartbeatScheduler;

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
        $client = new Client();
        $handler = new HeartbeatHandler($resolver, $client);

        // Make the 5-minute schedule known to WP-Cron on every
        // request — idempotent thanks to `HeartbeatScheduler`'s own
        // collision guard.
        add_filter('cron_schedules', [HeartbeatScheduler::class, 'add_cronheart_schedule']);

        // Drive the heartbeat tick. Priority 10 (default) is fine —
        // no other plugin should be wiring this hook, and if they
        // do, our handler still runs regardless of order.
        add_action(HeartbeatScheduler::TICK_HOOK, [$handler, 'tick']);
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
            constantReader: static fn (string $name): ?string => \defined($name) ? (string) \constant($name) : null,
            optionReader: static fn (string $name) => get_option($name, null),
            filterApplier: static fn (string $name, array $value) => apply_filters($name, $value),
        );
    }
}
