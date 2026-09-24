<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use CronMonitor\Api\Dto\Alert;
use CronMonitor\Api\Dto\AlertKind;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Dto\Ping;
use CronMonitor\Api\Dto\PingKind;
use CronMonitor\Api\Dto\Vocabulary;
use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;

// Direct-access guard. See `Plugin.php` for the rationale —
// same canonical pattern, same Plugin-Check-imposed shape.
\defined('ABSPATH') || exit;

/**
 * Settings → Cronheart History: a read-only dashboard of a monitor's recent
 * pings and alerts.
 *
 * A plain GET monitor picker (no JavaScript, no write action): selecting a
 * monitor reloads the page with `?monitor=<handle>` ({@see HANDLE_PATTERN}),
 * and the screen renders the **first page only** of that monitor's history —
 * the latest {@see HISTORY_LIMIT} pings and alerts. Pings are cursor-paginated
 * and alerts offset-paginated on the backend, but this screen never walks
 * beyond the first page: a dashboard load must be one bounded read of each,
 * never the SDK's `allPings()` / `allAlerts()` generators.
 *
 * Token-gated like the other screens; without a token (or if the monitor
 * listing fails) it degrades to a notice with no picker. Selecting a monitor
 * whose history then fails to load degrades to a notice under the picker so
 * another monitor can be tried.
 */
final class HistoryScreen
{
    public const MENU_SLUG = 'cronheart-history';

    /**
     * How many pings / alerts to show — one first page each. Matches the SDK's
     * default list limit; the dashboard deliberately never paginates further.
     */
    private const HISTORY_LIMIT = 50;

    /**
     * The picker submits a digest of the monitor UUID ({@see handleFor()}),
     * never the UUID itself: the UUID is the monitor's ping credential, and a
     * GET value lands in access logs, proxies and browser history.
     */
    private const HANDLE_PATTERN = '/^[0-9a-f]{16}$/';

    /**
     * @param \Closure(string): ManagementClient $managementClientFactory builds the
     *                                                                    admin-only
     *                                                                    management
     *                                                                    client for a
     *                                                                    token; null
     *                                                                    disables the
     *                                                                    dashboard
     */
    public function __construct(
        private readonly Resolver $resolver,
        private readonly ?\Closure $managementClientFactory = null,
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
    }

    public function add_menu(): void
    {
        add_options_page(
            __('Cronheart History', 'cronheart'),
            __('Cronheart History', 'cronheart'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'cronheart'));
        }

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Cronheart History', 'cronheart').'</h1>';
        echo '<p>'.esc_html(\sprintf(
            /* translators: %d: how many of the most recent pings and alerts are shown. */
            __('Review the recent pings and alerts cronheart.com recorded for a monitor. Pick a monitor to load its most recent history — the latest %d pings and alerts.', 'cronheart'),
            self::HISTORY_LIMIT
        )).'</p>';
        $this->render_body();
        echo '</div>';
    }

    private function render_body(): void
    {
        $token = $this->resolver->apiToken();
        if (null === $this->managementClientFactory || null === $token) {
            $this->renderNotice(__('Connect a cronheart.com API token on the Cronheart settings page to view ping and alert history here.', 'cronheart'));

            return;
        }

        try {
            $client = ($this->managementClientFactory)($token);
            $monitors = array_values($client->listMonitors());
        } catch (PlanRestrictionException) {
            $this->renderNotice(__('Your cronheart.com plan does not include API access, so history cannot be loaded here.', 'cronheart'));

            return;
        } catch (AuthenticationException) {
            $this->renderNotice(__('Could not authenticate with cronheart.com — check the API token on the Cronheart settings page.', 'cronheart'));

            return;
        } catch (RateLimitException) {
            $this->renderNotice(__('cronheart.com is rate-limiting requests right now. Reload this page in a minute.', 'cronheart'));

            return;
        } catch (\Throwable) {
            $this->renderNotice(__('Could not reach cronheart.com to load your monitors.', 'cronheart'));

            return;
        }

        if ([] === $monitors) {
            echo '<p>'.esc_html__('This account has no monitors yet. Create one at cronheart.com, then reload this page.', 'cronheart').'</p>';

            return;
        }

        $handle = $this->selectedHandle();
        $selected = null;
        foreach ($monitors as $monitor) {
            if (self::handleFor($monitor->uuid) === $handle) {
                $selected = $monitor;
            }
        }
        $this->render_picker($monitors, null === $selected ? '' : $handle);

        if (null === $selected) {
            if ('' !== $handle) {
                $this->renderNotice(__('That monitor is not in the list above. Pick one from the list.', 'cronheart'));

                return;
            }
            echo '<p>'.esc_html__('Select a monitor above and choose "View history" to load its recent pings and alerts.', 'cronheart').'</p>';

            return;
        }

        try {
            $pings = $client->listPings($selected->uuid, self::HISTORY_LIMIT);
            $alerts = $client->listAlerts($selected->uuid, self::HISTORY_LIMIT);
        } catch (\Throwable) {
            $this->renderNotice(__('Could not load the history for that monitor. Reload the page or pick another monitor.', 'cronheart'));

            return;
        }

        $this->render_pings_table($pings->data);
        $this->render_alerts_table($alerts->data);
    }

    /**
     * @param list<Monitor> $monitors
     */
    private function render_picker(array $monitors, string $selected): void
    {
        echo '<form method="get" action="">';
        printf('<input type="hidden" name="page" value="%s" />', esc_attr(self::MENU_SLUG));
        echo '<p>';
        printf('<label for="cronheart-history-monitor">%s</label> ', esc_html__('Monitor', 'cronheart'));
        echo '<select id="cronheart-history-monitor" name="monitor">';
        printf('<option value="">%s</option>', esc_html__('— Select a monitor —', 'cronheart'));

        foreach ($monitors as $monitor) {
            $handle = self::handleFor($monitor->uuid);
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($handle),
                selected($selected, $handle, false),
                esc_html($monitor->name.' — '.$monitor->uuid)
            );
        }

        echo '</select> ';
        printf('<button type="submit" class="button">%s</button>', esc_html__('View history', 'cronheart'));
        echo '</p>';
        echo '<p class="description">'.esc_html(\sprintf(
            /* translators: %d: how many monitors the picker lists at most. */
            __('The picker lists up to %d of your monitors.', 'cronheart'),
            ManagementClient::MONITOR_LIST_CAP
        )).'</p>';
        echo '</form>';
    }

    /**
     * @param list<Ping> $pings
     */
    private function render_pings_table(array $pings): void
    {
        echo '<h2>'.esc_html__('Recent pings', 'cronheart').'</h2>';

        if ([] === $pings) {
            echo '<p>'.esc_html__('No pings recorded for this monitor yet.', 'cronheart').'</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Kind', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Received (UTC)', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Runtime', 'cronheart').'</th>';
        echo '</tr></thead><tbody>';

        foreach ($pings as $ping) {
            printf(
                '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
                esc_html(self::pingKindLabel($ping->kind)),
                esc_html(self::formatUtc($ping->receivedAt)),
                esc_html(null === $ping->runtimeMs ? '—' : \sprintf(
                    /* translators: %s: a duration in milliseconds. */
                    __('%s ms', 'cronheart'),
                    number_format_i18n($ping->runtimeMs)
                ))
            );
        }

        echo '</tbody></table>';
    }

    /**
     * @param list<Alert> $alerts
     */
    private function render_alerts_table(array $alerts): void
    {
        echo '<h2>'.esc_html__('Recent alerts', 'cronheart').'</h2>';

        if ([] === $alerts) {
            echo '<p>'.esc_html__('No alerts recorded for this monitor yet.', 'cronheart').'</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Kind', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Created (UTC)', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Delivered to', 'cronheart').'</th>';
        echo '</tr></thead><tbody>';

        foreach ($alerts as $alert) {
            printf(
                '<tr><td>%1$s</td><td>%2$s</td><td>%3$s</td></tr>',
                esc_html(self::alertKindLabel($alert->kind)),
                esc_html(self::formatUtc($alert->createdAt)),
                esc_html(self::deliveredToLabel($alert->dispatchedTo))
            );
        }

        echo '</tbody></table>';
    }

    /**
     * The monitor handle from the read-only GET picker, or an empty string
     * when absent or malformed. The value only scopes which monitor's
     * read-only history is shown — it changes no state.
     */
    private function selectedHandle(): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only, manage_options-gated display filter: the chosen monitor only scopes which read-only history renders; it mutates nothing, so no nonce applies.
        $raw = isset($_GET['monitor']) && \is_string($_GET['monitor']) ? strtolower(sanitize_text_field(wp_unslash($_GET['monitor']))) : '';

        return 1 === preg_match(self::HANDLE_PATTERN, $raw) ? $raw : '';
    }

    private static function handleFor(string $uuid): string
    {
        return substr(hash('sha256', strtolower($uuid)), 0, 16);
    }

    private function renderNotice(string $message): void
    {
        echo '<div class="notice notice-info inline"><p>'.esc_html($message).'</p></div>';
    }

    private static function pingKindLabel(PingKind|string $kind): string
    {
        return match ($kind) {
            PingKind::Heartbeat => __('Heartbeat', 'cronheart'),
            PingKind::Start => __('Start', 'cronheart'),
            PingKind::Success => __('Success', 'cronheart'),
            PingKind::Fail => __('Fail', 'cronheart'),
            default => Vocabulary::value($kind),
        };
    }

    private static function alertKindLabel(AlertKind|string $kind): string
    {
        return match ($kind) {
            AlertKind::Late => __('Late', 'cronheart'),
            AlertKind::Fail => __('Fail', 'cronheart'),
            AlertKind::Recovered => __('Recovered', 'cronheart'),
            default => Vocabulary::value($kind),
        };
    }

    /**
     * A compact, translated count of the channels that confirmed delivery of
     * an alert. The API's `dispatched_to` maps a channel id to the time its
     * delivery succeeded; null or an empty map means no channel has confirmed
     * one, which is not the same as the alert never being sent.
     *
     * @param array<string, mixed>|null $dispatchedTo
     */
    private static function deliveredToLabel(?array $dispatchedTo): string
    {
        if (null === $dispatchedTo || [] === $dispatchedTo) {
            return __('No delivery confirmed', 'cronheart');
        }

        $count = \count($dispatchedTo);

        return \sprintf(
            /* translators: %d: number of channels that confirmed delivery of an alert. */
            _n('%d channel', '%d channels', $count, 'cronheart'),
            $count
        );
    }

    private static function formatUtc(\DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s T');
    }
}
