<?php

declare(strict_types=1);

namespace Cronheart\WP\Hooks;

use Cronheart\WP\Api\Client;
use Cronheart\WP\Config\Resolver;

/**
 * Tick handler for the site-level heartbeat WP-Cron event.
 *
 * Hooked to `HeartbeatScheduler::TICK_HOOK` by `Plugin::boot()`. On
 * each tick:
 *   - Resolve the site UUID through `Resolver::heartbeatUuid()`
 *     (constants > options > null).
 *   - If unset (or explicitly suppressed via the empty-string
 *     signal), do nothing — the user opted out for this environment.
 *   - Otherwise, dispatch a `heartbeat` ping. The host job receives
 *     the failure outcome through `PingResult` but we intentionally
 *     do not act on it: heartbeat is fire-and-forget. The SDK
 *     already logs swallowed transport errors at warning level.
 *
 * The whole method is wrapped in `try/catch \Throwable` defensively —
 * the SDK's no-throw contract should hold, but a pathological
 * Configuration override (custom PSR-18 client, malformed endpoint)
 * could in theory break it. The cron run must complete regardless.
 */
final class HeartbeatHandler
{
    public function __construct(
        private readonly Resolver $resolver,
        private readonly Client $client,
    ) {
    }

    public function tick(): void
    {
        try {
            $uuid = $this->resolver->heartbeatUuid();
            if (null === $uuid) {
                return;
            }

            $this->client->heartbeat($uuid);
        } catch (\Throwable) {
            // Defensive belt-and-suspenders: SDK contract is no-throw
            // and `Client::safely()` re-catches at its layer too, but
            // a third unconditional catch here means even an exotic
            // failure mode (e.g. autoloader race on a hot-reloaded
            // worker) cannot punish the WP-Cron run.
        }
    }
}
