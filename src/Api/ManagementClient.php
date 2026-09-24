<?php

declare(strict_types=1);

namespace Cronheart\WP\Api;

use CronMonitor\Api\Dto\Account;
use CronMonitor\Api\Dto\AlertPage;
use CronMonitor\Api\Dto\Channel;
use CronMonitor\Api\Dto\ChannelSecret;
use CronMonitor\Api\Dto\CreateMonitorRequest;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\PingPage;
use CronMonitor\Api\Dto\ScheduleKind;
use CronMonitor\Api\Dto\SnoozeDuration;
use CronMonitor\Api\Dto\TestChannelResult;
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

    /**
     * The account's notification channels for the channels screen. The
     * channels endpoint returns the full set in one response (no pagination),
     * so this hands back the bare list. Channel ids are strings (the backend's
     * BIGINT carried verbatim) — pass them straight back to {@see testChannel}
     * / {@see rotateChannelSecret}, never cast to int. Sends only the token.
     *
     * @return list<Channel>
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function listChannels(): array
    {
        return $this->client()->listChannels()->data;
    }

    /**
     * Send a test alert through a channel — a real outbound delivery, and the
     * only channel call with a side effect off cronheart.com. Returns whether
     * it was delivered (and whether that delivery newly verified the channel).
     * A downstream destination that rejects the delivery answers `502` (the
     * SDK's {@see \CronMonitor\Api\Exception\ChannelDeliveryException}); an
     * unverified or transport-less channel answers `422`
     * ({@see \CronMonitor\Api\Exception\ValidationException}). Never retried.
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function testChannel(string $id): TestChannelResult
    {
        return $this->client()->testChannel($id);
    }

    /**
     * Rotate a webhook channel's signing secret, returning the channel plus
     * the freshly-minted plaintext secret the backend reveals **once**. The
     * caller must surface that plaintext immediately and never persist it. Only
     * webhook channels have a rotatable secret; any other kind answers `422`.
     * Never retried (a replay would mint a second secret and lose the first).
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function rotateChannelSecret(string $id): ChannelSecret
    {
        return $this->client()->rotateChannelSecret($id);
    }

    /**
     * The first page of a monitor's ping history (newest first), capped at
     * $limit. Pings are cursor-paginated; the admin history dashboard reads
     * only this first page and never walks the full history, so no cursor is
     * threaded through.
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function listPings(string $uuid, int $limit = 50): PingPage
    {
        return $this->client()->listPings($uuid, $limit);
    }

    /**
     * The first page of a monitor's alert history (newest first), capped at
     * $limit. Offset-paginated; like {@see listPings} the dashboard reads only
     * the first page (offset 0).
     *
     * @throws \CronMonitor\Api\Exception\ApiException
     */
    public function listAlerts(string $uuid, int $limit = 50): AlertPage
    {
        return $this->client()->listAlerts($uuid, 0, $limit);
    }

    private function client(): MonitorApiClient
    {
        return $this->client ??= MonitorApiClient::create($this->configuration);
    }
}
