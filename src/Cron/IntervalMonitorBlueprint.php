<?php

declare(strict_types=1);

namespace Cronheart\WP\Cron;

// Direct-access guard. See `Plugin.php` for the rationale —
// same canonical pattern, same Plugin-Check-imposed shape.
\defined('ABSPATH') || exit;

/**
 * The create-request shape for auto-creating an interval monitor from a
 * discovered recurring WP-Cron hook — a pure value object that keeps every
 * field inside the ranges the monitor API accepts, so the create request is
 * never rejected as invalid.
 *
 * Mapping rules:
 *   - **Auto-creatable only when** the interval is present and within
 *     [30, 31,622,400] seconds; a one-off (no interval) or a sub-30s /
 *     over-a-year interval returns null and must be assigned by hand instead.
 *   - **schedule_expr** is the bare interval in whole seconds, digits only;
 *     this object carries the int and the API layer stringifies.
 *   - **name** is the hook name, trimmed, cut to 120 characters and padded
 *     to at least 2.
 *   - **grace** is `min(86400, max(60, interval / 10))` — a tenth of the
 *     interval, floored at a minute and capped at a day, the API's maximum.
 *   - **idempotency key** is derived from the site URL + hook, so a
 *     double-clicked "create" is a safe replay while the API remembers the
 *     key (the real duplicate guard is only offering create on an unmapped
 *     hook).
 */
final class IntervalMonitorBlueprint
{
    public const MIN_INTERVAL_SECONDS = 30;
    public const MAX_INTERVAL_SECONDS = 31_622_400;

    private const MIN_GRACE_SECONDS = 60;
    private const MAX_GRACE_SECONDS = 86_400;
    private const MIN_NAME_LENGTH = 2;
    private const MAX_NAME_LENGTH = 120;

    private function __construct(
        public readonly string $name,
        public readonly int $intervalSeconds,
        public readonly int $graceSeconds,
        public readonly string $tz,
        public readonly string $idempotencyKey,
    ) {
    }

    /**
     * Build the blueprint for a discovered hook, or null when the hook is not
     * auto-creatable as an interval monitor (one-off, or interval out of range).
     */
    public static function fromEvent(string $hook, ?int $intervalSeconds, string $tz, string $siteUrl): ?self
    {
        if (null === $intervalSeconds
            || $intervalSeconds < self::MIN_INTERVAL_SECONDS
            || $intervalSeconds > self::MAX_INTERVAL_SECONDS) {
            return null;
        }

        return new self(
            self::clampName($hook),
            $intervalSeconds,
            min(self::MAX_GRACE_SECONDS, max(self::MIN_GRACE_SECONDS, intdiv($intervalSeconds, 10))),
            '' !== trim($tz) ? $tz : 'UTC',
            'wp-'.hash('sha256', $siteUrl.'|'.$hook),
        );
    }

    private static function clampName(string $hook): string
    {
        $name = trim($hook);
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            $name = mb_substr($name, 0, self::MAX_NAME_LENGTH);
        }
        while (mb_strlen($name) < self::MIN_NAME_LENGTH) {
            $name .= '_';
        }

        return $name;
    }
}
