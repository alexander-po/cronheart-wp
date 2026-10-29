<?php

declare(strict_types=1);

namespace Cronheart\WP\Api;

use CronMonitor\Client\Configuration;
use CronMonitor\Client\CronMonitorClient;
use CronMonitor\Client\PingResult;

/**
 * Thin façade over the bundled cron-monitor PHP SDK.
 *
 * The SDK already promises "never throws on network or HTTP errors"
 * — every transport failure is folded into a `PingResult::failed(...)`
 * return value. The wrapper exists so the plugin code refers to a
 * single type name (`Cronheart\WP\Api\Client`) instead of threading
 * the SDK's `CronMonitor\…` namespace through every call site, AND
 * so any future site-wide instrumentation (logging, metrics, request
 * tagging) can be added in one place without touching the SDK
 * consumers.
 *
 * **Vendor namespace prefixing** (Strauss / php-scoper) is deferred
 * pending a first reported collision. The SDK ships under its
 * canonical `CronMonitor\…` namespace; if a future plugin bundles
 * the same SDK without prefixing, PHP's autoloader resolves to
 * whichever was registered first. Risk is low because no other
 * plugin currently ships `cron-monitor/php-sdk`.
 *
 * Belt-and-suspenders try/catch wraps every call. The SDK contract
 * says it does not throw, but a custom PSR-18 transport bound through
 * the SDK's optional `Configuration` could violate that contract; we
 * defend the boundary so the host WP-Cron run never breaks.
 *
 * Not marked `final` so that tests can build a thin mock (via PHPUnit's
 * `createMock`) without standing up a real HTTP transport. Plugin code
 * should still treat this class as effectively final — there is no
 * extension point that the SDK would recognise.
 */
class Client
{
    public const DISABLED_MESSAGE = 'Cronheart pings are disabled on this site';

    /**
     * A null SDK client makes every call a no-op that reports a failed
     * ping without sending anything; see {@see disabled()}.
     */
    private function __construct(private readonly ?CronMonitorClient $sdk)
    {
    }

    /**
     * Throws when the SDK cannot build its transport, for example when the
     * curl extension is not loaded; `Plugin::boot()` catches that and falls
     * back to {@see disabled()}.
     */
    public static function create(Configuration $configuration): self
    {
        return new self(CronMonitorClient::create($configuration));
    }

    public static function disabled(): self
    {
        return new self(null);
    }

    public function heartbeat(string $uuid, ?string $body = null): PingResult
    {
        return $this->safely(static fn (CronMonitorClient $sdk): PingResult => $sdk->heartbeat($uuid, $body));
    }

    public function start(string $uuid): PingResult
    {
        return $this->safely(static fn (CronMonitorClient $sdk): PingResult => $sdk->start($uuid));
    }

    public function success(string $uuid, ?string $body = null): PingResult
    {
        return $this->safely(static fn (CronMonitorClient $sdk): PingResult => $sdk->success($uuid, $body));
    }

    public function fail(string $uuid, ?string $body = null): PingResult
    {
        return $this->safely(static fn (CronMonitorClient $sdk): PingResult => $sdk->fail($uuid, $body));
    }

    /**
     * @param \Closure(CronMonitorClient): PingResult $call
     */
    private function safely(\Closure $call): PingResult
    {
        if (null === $this->sdk) {
            return PingResult::failed(null, self::DISABLED_MESSAGE, 0);
        }

        try {
            return $call($this->sdk);
        } catch (\Throwable $error) {
            // The SDK swallows network / HTTP errors already; this
            // catch handles the pathological case where a custom
            // transport bound through `Configuration` violates the
            // no-throw contract. Returning a synthetic failed
            // PingResult keeps the host WP-Cron run intact.
            return PingResult::failed(null, $error->getMessage(), 0);
        }
    }
}
