<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Cron\EventDiscovery;
use Cronheart\WP\Cron\IntervalMonitorBlueprint;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\MonitorStatus;
use CronMonitor\Api\Dto\SnoozeDuration;
use CronMonitor\Api\Exception\ApiException;
use CronMonitor\Api\Exception\ConflictException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;
use CronMonitor\Api\Exception\ValidationException;

// Direct-access guard. See `Plugin.php` for the rationale —
// same canonical pattern, same Plugin-Check-imposed shape.
\defined('ABSPATH') || exit;

/**
 * The plugin's authenticated admin-AJAX surface. Three actions, all on the
 * same security contract:
 *   1. nonce ({@see check_ajax_referer}) — a stale nonce returns a
 *      "reload and try again" JSON error, never a dead -1 / 403 page;
 *   2. capability ({@see current_user_can} `manage_options`);
 *   3. boundary validation — a monitor UUID against the canonical v4
 *      pattern, an `op` against a fixed allow-list, a snooze duration
 *      against the closed {@see SnoozeDuration} enum, and a cron **hook
 *      against the discovered event set** (never a trusted client string).
 * A thrown SDK {@see ApiException} is mapped to a {@see wp_send_json_error}
 * envelope — never an uncaught 500 — so the host admin page degrades to a
 * readable message rather than a fatal.
 *
 * The actions:
 *   - {@see ACTION} (monitor lifecycle: pause / resume / snooze / unsnooze)
 *     — routed through the throwing {@see ManagementClient};
 *   - {@see ACTION_MAP_EVENT} (assign a monitor UUID to a WP-Cron hook, or
 *     suppress it) — a pure `cronheart_event_map` option write, no API call;
 *   - {@see ACTION_CREATE_EVENT} (auto-create an interval monitor for an
 *     unmapped recurring hook, then assign it) — the only event action that
 *     calls cronheart.com.
 *
 * Only the authenticated `wp_ajax_*` actions are registered. There is
 * deliberately no `wp_ajax_nopriv_*` companion: monitor management is an
 * administrator capability, and the absence of the public hooks is the
 * control (asserted by the test suite). The single nonce action ({@see ACTION})
 * salts every handler's nonce.
 */
final class Ajax
{
    /**
     * The monitor-lifecycle `action`, and the shared nonce action: every
     * handler's nonce is minted and verified against this one value.
     */
    public const ACTION = 'cronheart_monitor_action';

    /** Assign a monitor to a WP-Cron hook (or suppress it). */
    public const ACTION_MAP_EVENT = 'cronheart_map_event';

    /** Auto-create an interval monitor for an unmapped recurring hook. */
    public const ACTION_CREATE_EVENT = 'cronheart_create_event_monitor';

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
     *                                                                    API-backed
     *                                                                    actions
     */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly ?\Closure $managementClientFactory = null,
        private readonly ?EventDiscovery $eventDiscovery = null,
    ) {
    }

    /**
     * Register only the authenticated actions — never a `nopriv` companion.
     */
    public function register(): void
    {
        add_action('wp_ajax_'.self::ACTION, [$this, 'handle']);
        add_action('wp_ajax_'.self::ACTION_MAP_EVENT, [$this, 'handleMapEvent']);
        add_action('wp_ajax_'.self::ACTION_CREATE_EVENT, [$this, 'handleCreateEventMonitor']);
    }

    /**
     * Data localised to `assets/admin.js` on both admin screens: the AJAX
     * endpoint, one shared nonce, the action names, and pre-translated
     * strings (so the JS never carries an untranslated user-facing string).
     *
     * @return array<string, mixed>
     */
    public static function scriptData(): array
    {
        return [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce(self::ACTION),
            'actions' => [
                'monitor' => self::ACTION,
                'mapEvent' => self::ACTION_MAP_EVENT,
                'createEvent' => self::ACTION_CREATE_EVENT,
            ],
            'i18n' => [
                'working' => __('Working…', 'cronheart'),
                'saving' => __('Saving…', 'cronheart'),
                'creating' => __('Creating monitor…', 'cronheart'),
                'error' => __('Something went wrong. Please try again.', 'cronheart'),
            ],
        ];
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
     * Assign a monitor UUID to a WP-Cron hook (an empty UUID suppresses it),
     * persisting one entry of the `cronheart_event_map` option. No API call:
     * this only edits the local option, so it needs no token.
     */
    public function handleMapEvent(): void
    {
        if (false === check_ajax_referer(self::ACTION, 'nonce', false)) {
            $this->fail(__('Your session has expired. Reload the page and try again.', 'cronheart'), 403);

            return;
        }

        if (!current_user_can('manage_options')) {
            $this->fail(__('You do not have permission to manage monitors.', 'cronheart'), 403);

            return;
        }

        $rawHook = isset($_POST['hook']) && \is_string($_POST['hook'])
            ? sanitize_text_field(wp_unslash($_POST['hook']))
            : '';
        $rawUuid = isset($_POST['uuid']) && \is_string($_POST['uuid'])
            ? sanitize_text_field(wp_unslash($_POST['uuid']))
            : '';

        $hook = $this->discoveredHook($rawHook);
        if (null === $hook) {
            $this->fail(__('That cron event is not recognised on this site.', 'cronheart'), 400);

            return;
        }
        if ($this->resolver->eventUuidIsConstant($hook)) {
            $this->fail(__('This hook is set by a wp-config.php constant and cannot be changed here.', 'cronheart'), 409);

            return;
        }

        $uuid = $this->validUuid($rawUuid);
        if ('' !== $rawUuid && null === $uuid) {
            $this->fail(__('That is not a valid monitor.', 'cronheart'), 400);

            return;
        }

        $assigned = '' === $rawUuid ? '' : (string) $uuid;
        $this->writeEventMap($hook, $assigned);

        wp_send_json_success([
            'hook' => $hook,
            'uuid' => $assigned,
            'mapped' => '' !== $assigned,
            'label' => '' !== $assigned ? $assigned : __('Not monitored', 'cronheart'),
        ]);
    }

    /**
     * Auto-create an interval monitor for an unmapped recurring hook and
     * assign it. Refuses hooks that are constant-governed or already resolve
     * to a monitor (assign those from the dropdown instead). A thrown SDK
     * exception — including a `409` from a same-key create whose body changed
     * — is mapped to a JSON error, never a fatal.
     */
    public function handleCreateEventMonitor(): void
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
            $this->fail(__('Connect a cronheart.com API token to create monitors.', 'cronheart'), 400);

            return;
        }

        $rawHook = isset($_POST['hook']) && \is_string($_POST['hook'])
            ? sanitize_text_field(wp_unslash($_POST['hook']))
            : '';
        $event = $this->recurringEventFor($rawHook);
        if (null === $event) {
            $this->fail(__('That cron event is not recognised on this site.', 'cronheart'), 400);

            return;
        }
        $hook = $event['hook'];

        if ($this->resolver->eventUuidIsConstant($hook) || null !== $this->resolver->eventUuid($hook)) {
            $this->fail(__('This hook already has a monitor. Assign it from the dropdown instead.', 'cronheart'), 409);

            return;
        }

        $tz = null !== $this->eventDiscovery ? $this->eventDiscovery->timezone() : 'UTC';
        $blueprint = IntervalMonitorBlueprint::fromEvent($hook, $event['intervalSeconds'], $tz, site_url());
        if (null === $blueprint) {
            $this->fail(__('This event\'s schedule cannot be auto-created. Assign a monitor by hand instead.', 'cronheart'), 400);

            return;
        }

        try {
            $client = ($this->managementClientFactory)($token);
            $monitor = $client->createIntervalMonitor(
                $blueprint->name,
                $blueprint->intervalSeconds,
                $blueprint->tz,
                $blueprint->graceSeconds,
                $blueprint->idempotencyKey,
            );
        } catch (PlanRestrictionException) {
            $this->fail(__('Your cronheart.com plan does not include API access.', 'cronheart'), 402);

            return;
        } catch (RateLimitException) {
            $this->fail(__('cronheart.com is rate-limiting requests right now. Try again in a minute.', 'cronheart'), 429);

            return;
        } catch (ConflictException) {
            $this->fail(__('A monitor for this hook already exists on cronheart.com, or its schedule changed. Reload and assign it from the dropdown.', 'cronheart'), 409);

            return;
        } catch (ValidationException) {
            $this->fail(__('cronheart.com rejected the monitor details for this event. Assign a monitor by hand instead.', 'cronheart'), 422);

            return;
        } catch (ApiException) {
            $this->fail(__('cronheart.com could not create the monitor. Please try again.', 'cronheart'), 502);

            return;
        } catch (\Throwable) {
            $this->fail(__('Could not reach cronheart.com. Please try again.', 'cronheart'), 502);

            return;
        }

        $this->writeEventMap($hook, $monitor->uuid);

        wp_send_json_success([
            'hook' => $hook,
            'uuid' => $monitor->uuid,
            'name' => $monitor->name,
            'mapped' => true,
            'label' => $monitor->uuid,
        ]);
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

    /**
     * Read-modify-write a single entry of the `cronheart_event_map` option,
     * preserving every other mapping (and never clobbering entries from the
     * constant or filter sources, which do not live in this option). An empty
     * UUID stores the resolver's explicit "suppress" marker.
     */
    private function writeEventMap(string $hook, string $uuid): void
    {
        $map = get_option(Resolver::EVENT_MAP_OPTION, []);
        if (!\is_array($map)) {
            $map = [];
        }
        $map[$hook] = $uuid;

        update_option(Resolver::EVENT_MAP_OPTION, $map);
    }

    /**
     * The given hook, only if it exactly matches a discovered WP-Cron hook —
     * the request hook is never trusted as a map key on its own.
     */
    private function discoveredHook(string $hook): ?string
    {
        if ('' === $hook || null === $this->eventDiscovery) {
            return null;
        }
        foreach ($this->eventDiscovery->events() as $event) {
            if ($event['hook'] === $hook) {
                return $hook;
            }
        }

        return null;
    }

    /**
     * The discovered recurring event for the given hook, or null when the
     * hook is unknown or one-off (only recurring hooks are auto-creatable).
     *
     * @return array{hook: string, schedule: ?string, intervalSeconds: ?int, nextRun: ?int, isRecurring: bool}|null
     */
    private function recurringEventFor(string $hook): ?array
    {
        if ('' === $hook || null === $this->eventDiscovery) {
            return null;
        }
        foreach ($this->eventDiscovery->recurringEvents() as $event) {
            if ($event['hook'] === $hook) {
                return $event;
            }
        }

        return null;
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
