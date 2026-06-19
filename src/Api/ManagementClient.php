<?php

declare(strict_types=1);

namespace Cronheart\WP\Api;

use CronMonitor\Api\Dto\Account;
use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Dto\SnoozeDuration;
use CronMonitor\Api\MonitorApiClient;
use CronMonitor\Client\Configuration;

// Direct-access guard. See `Plugin.php` for the rationale —
// same canonical pattern, same Plugin-Check-imposed shape.
\defined('ABSPATH') || exit;

/**
 * Admin-only, throwing façade over the bundled SDK's management API
 * client ({@see MonitorApiClient}).
 *
 * The deliberate counterpart to the ping {@see Client}: where the ping
 * client never throws — it runs inside WP-Cron, where a broken backend
 * must never break the host job — this client lets the SDK's typed
 * {@see \CronMonitor\Api\Exception\ApiException} subclasses propagate.
 * It runs only in wp-admin, on an authenticated administrator's
 * request, where the caller wants to know (and surface) when a call
 * fails. The admin / AJAX layer owns the exception → notice / JSON
 * ladder.
 *
 * It is built lazily, only when an account token is present and only
 * from a wp-admin code path (the settings-page render in v0.3.0). The
 * account token is write-capable, so keeping the only construction site
 * in wp-admin is what preserves the plugin's least-privilege boundary:
 * the high-frequency runtime ping path keeps its tokenless {@see Client}
 * and never carries the credential.
 *
 * The underlying SDK {@see MonitorApiClient} is itself created lazily on
 * first use, so merely building the façade costs nothing until a call is
 * actually made. Tests inject a pre-built client over a fake PSR-18
 * transport; production passes only the {@see Configuration} and the
 * façade builds the real cURL-backed client on demand.
 */
final class ManagementClient
{
    /**
     * Upper bound on monitors materialised for the admin heartbeat
     * picker. Mirrors the v0.2.x picker cap exactly: the dropdown lists
     * at most this many; a heartbeat UUID already saved stays selectable
     * whether or not it appears in the listed subset, so the cap never
     * silently drops a saved selection. The picker is an ergonomic
     * shortcut, not an exhaustive browser.
     */
    public const MONITOR_LIST_CAP = 200;

    private ?MonitorApiClient $client;

    public function __construct(
        private readonly Configuration $configuration,
        ?MonitorApiClient $client = null,
    ) {
        $this->client = $client;
    }

    /**
     * List the account's monitors for the heartbeat picker, capped at
     * {@see MONITOR_LIST_CAP}. Walks the SDK's lazy pager and stops as
     * soon as the cap is reached, so an account with more monitors than
     * the cap never triggers the page request that would exceed it.
     *
     * @return list<Monitor>
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function listMonitors(): array
    {
        $monitors = [];
        foreach ($this->client()->allMonitors() as $monitor) {
            $monitors[] = $monitor;
            if (\count($monitors) >= self::MONITOR_LIST_CAP) {
                break;
            }
        }

        return $monitors;
    }

    /**
     * The account snapshot — plan, monitor budget, and live API
     * rate-limit standing — for the settings-page account card. Sends
     * nothing beyond the bearer token.
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function account(): Account
    {
        return $this->client()->getAccount();
    }

    /**
     * Pause a monitor (no alerts while paused) and return its refreshed
     * snapshot. Sends only the monitor UUID and the action.
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function pause(string $uuid): Monitor
    {
        return $this->client()->pauseMonitor($uuid);
    }

    /**
     * Resume a paused monitor and return its refreshed snapshot.
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function resume(string $uuid): Monitor
    {
        return $this->client()->resumeMonitor($uuid);
    }

    /**
     * Snooze a monitor for a bounded duration and return its refreshed
     * snapshot. The duration is a closed enum (1h / 4h / 1d / 1w).
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function snooze(string $uuid, SnoozeDuration $duration): Monitor
    {
        return $this->client()->snoozeMonitor($uuid, $duration);
    }

    /**
     * Clear an active snooze and return the monitor's refreshed snapshot.
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function unsnooze(string $uuid): Monitor
    {
        return $this->client()->unsnoozeMonitor($uuid);
    }

    /**
     * Create an interval monitor for an auto-discovered WP-Cron hook and
     * return it. The schedule expression is the bare interval in seconds
     * (the backend validates `ctype_digit`, 30..31,622,400); callers must
     * pass values already clamped to the backend's ranges (see
     * {@see \Cronheart\WP\Cron\IntervalMonitorBlueprint}). The idempotency
     * key makes a double-clicked create a safe replay within the backend's
     * 24h key TTL — but the real duplicate guard is only offering create on
     * an unmapped hook; a same-key create with a changed body is a `409`
     * {@see \CronMonitor\Api\Exception\ConflictException}.
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function createIntervalMonitor(string $name, int $intervalSeconds, string $tz, int $graceSeconds, string $idempotencyKey): Monitor
    {
        $request = new CreateMonitorRequest(
            $name,
            ScheduleKind::Interval,
            (string) $intervalSeconds,
            $tz,
            $graceSeconds,
        );

        return $this->client()->createMonitor($request, $idempotencyKey);
    }

    private function client(): MonitorApiClient
    {
        return $this->client ??= MonitorApiClient::create($this->configuration);
    }
}
