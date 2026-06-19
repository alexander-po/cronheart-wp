<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\MonitorStatus;
use CronMonitor\Api\Dto\SnoozeDuration;
use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;

// Direct-access guard. See `Plugin.php` for the rationale —
// same canonical pattern, same Plugin-Check-imposed shape.
\defined('ABSPATH') || exit;

/**
 * The plugin's first authenticated admin-AJAX surface: monitor lifecycle
 * actions (pause / resume / snooze / unsnooze) issued from Settings →
 * Cronheart and routed through the throwing {@see ManagementClient}.
 *
 * One handler, one allow-listed `op`. The security contract, applied to
 * every request before any cronheart.com call:
 *   1. nonce ({@see check_ajax_referer}) — a stale nonce returns a
 *      "reload and try again" JSON error, never a dead -1 / 403 page;
 *   2. capability ({@see current_user_can} `manage_options`);
 *   3. boundary validation — the monitor UUID against the canonical v4
 *      pattern, the `op` against a fixed allow-list, the snooze duration
 *      against the closed {@see SnoozeDuration} enum. Nothing from the
 *      request is trusted beyond these checks.
 * A thrown SDK {@see ApiException} is mapped to a {@see wp_send_json_error}
 * envelope — never an uncaught 500 — so the host admin page degrades to a
 * readable message rather than a fatal.
 *
 * Only the authenticated `wp_ajax_*` action is registered. There is
 * deliberately no `wp_ajax_nopriv_*` companion: monitor management is an
 * administrator capability, and the absence of the public hook is the
 * control (asserted by the test suite).
 */
final class Ajax
{
    /**
     * The admin-AJAX `action` and the nonce action share one name. The
     * settings page mints the nonce with {@see wp_create_nonce} against this
     * value and {@see check_ajax_referer} verifies it here.
     */
    public const ACTION = 'cronheart_monitor_action';

    /**
     * Allow-listed lifecycle operations. A request `op` outside this set is
     * rejected at the boundary before any client is built.
     */
    private const OPERATIONS = ['pause', 'resume', 'snooze', 'unsnooze'];

    /**
     * Canonical UUID v4 shape — the same pattern the settings page's
     * {@see SettingsPage::sanitize_uuid()} accepts.
     */
    private const UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';

    /**
     * @param \Closure(string): ManagementClient $managementClientFactory builds the
     *                                                                    admin-only
     *                                                                    management
     *                                                                    client for a
     *                                                                    token; null
     *                                                                    disables the
     *                                                                    AJAX surface
     */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly ?\Closure $managementClientFactory = null,
    ) {
    }

    /**
     * Register only the authenticated action — never the `nopriv` companion.
     */
    public function register(): void
    {
        add_action('wp_ajax_'.self::ACTION, [$this, 'handle']);
    }

    public function handle(): void
    {
        if (false === check_ajax_referer(self::ACTION, 'nonce', false)) {
            $this->fail(__('Your session has expired. Reload the page and try again.', 'cronheart'), 403);

            return;
        }

        if (!current_user_can('manage_options')) {
            $this->fail(__('You do not have permission to manage monitors.', 'cronheart'), 403);

            return;
        }

        $token = $this->resolver->apiToken();
        if (null === $this->managementClientFactory || null === $token) {
            $this->fail(__('Connect a cronheart.com API token to manage monitors.', 'cronheart'), 400);

            return;
        }

        // From here every request value is sanitised and validated at the
        // boundary. The reads live in this method (not helpers) so the nonce
        // check above is in the same scope WPCS inspects for verification.
        $rawUuid = isset($_POST['uuid']) && \is_string($_POST['uuid'])
            ? sanitize_text_field(wp_unslash($_POST['uuid']))
            : '';
        $uuid = $this->validUuid($rawUuid);
        if (null === $uuid) {
            $this->fail(__('That is not a valid monitor.', 'cronheart'), 400);

            return;
        }

        $rawOp = isset($_POST['op']) && \is_string($_POST['op'])
            ? sanitize_key(wp_unslash($_POST['op']))
            : '';
        $op = \in_array($rawOp, self::OPERATIONS, true) ? $rawOp : null;
        if (null === $op) {
            $this->fail(__('That monitor action is not supported.', 'cronheart'), 400);

            return;
        }

        $duration = null;
        if ('snooze' === $op) {
            $rawDuration = isset($_POST['duration']) && \is_string($_POST['duration'])
                ? sanitize_text_field(wp_unslash($_POST['duration']))
                : '';
            $duration = SnoozeDuration::tryFrom($rawDuration);
            if (null === $duration) {
                $this->fail(__('Choose a snooze duration of 1 hour, 4 hours, 1 day, or 1 week.', 'cronheart'), 400);

                return;
            }
        }

        try {
            $client = ($this->managementClientFactory)($token);
            $monitor = $this->dispatch($client, $op, $uuid, $duration);
        } catch (PlanRestrictionException) {
            $this->fail(__('Your cronheart.com plan does not include API access.', 'cronheart'), 402);

            return;
        } catch (RateLimitException) {
            $this->fail(__('cronheart.com is rate-limiting requests right now. Try again in a minute.', 'cronheart'), 429);

            return;
        } catch (ApiException) {
            $this->fail(__('cronheart.com could not complete that action. Please try again.', 'cronheart'), 502);

            return;
        } catch (\Throwable) {
            $this->fail(__('Could not reach cronheart.com. Please try again.', 'cronheart'), 502);

            return;
        }

        wp_send_json_success($this->monitorPayload($monitor));
    }

    /**
     * Map a monitor status to its translated label. Public + static so the
     * settings-page table and this AJAX payload share one mapping; all five
     * cases are handled so the match is exhaustive.
     */
    public static function statusLabel(MonitorStatus $status): string
    {
        return match ($status) {
            MonitorStatus::New => __('New', 'cronheart'),
            MonitorStatus::Up => __('Up', 'cronheart'),
            MonitorStatus::Late => __('Late', 'cronheart'),
            MonitorStatus::Down => __('Down', 'cronheart'),
            MonitorStatus::Paused => __('Paused', 'cronheart'),
        };
    }

    /**
     * Format a snooze deadline as a stable UTC label, or an empty string
     * when the monitor is not snoozed. Shared by the settings-page table
     * (initial render) and this AJAX payload (live update) so both show the
     * same string.
     */
    public static function snoozeUntilLabel(?\DateTimeImmutable $snoozedUntil): string
    {
        if (null === $snoozedUntil) {
            return '';
        }

        return $snoozedUntil->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i T');
    }

    private function dispatch(ManagementClient $client, string $op, string $uuid, ?SnoozeDuration $duration): Monitor
    {
        return match ($op) {
            'pause' => $client->pause($uuid),
            'resume' => $client->resume($uuid),
            'unsnooze' => $client->unsnooze($uuid),
            // 'snooze' is the only remaining allow-listed op, and $duration
            // is guaranteed non-null for it by the handler's validation.
            default => $client->snooze($uuid, $duration ?? SnoozeDuration::OneHour),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function monitorPayload(Monitor $monitor): array
    {
        return [
            'uuid' => $monitor->uuid,
            'status' => $monitor->status->value,
            'status_label' => self::statusLabel($monitor->status),
            'snoozed' => null !== $monitor->snoozedUntil,
            'snoozed_until' => self::snoozeUntilLabel($monitor->snoozedUntil),
        ];
    }

    private function validUuid(string $raw): ?string
    {
        return 1 === preg_match(self::UUID_PATTERN, $raw) ? strtolower($raw) : null;
    }

    private function fail(string $message, int $statusCode): void
    {
        wp_send_json_error(['message' => $message], $statusCode);
    }
}
