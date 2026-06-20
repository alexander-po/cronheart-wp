<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use Cronheart\WP\Cron\EventDiscovery;
use Cronheart\WP\Cron\IntervalMonitorBlueprint;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;

// Direct-access guard. See `Plugin.php` for the rationale —
// same canonical pattern, same Plugin-Check-imposed shape.
\defined('ABSPATH') || exit;

/**
 * Settings → Cronheart Events: the per-event monitoring screen.
 *
 * Lists the site's recurring WP-Cron hooks ({@see EventDiscovery}) and, per
 * hook, lets an administrator either assign one of the account's monitors
 * (a dropdown) or — only when the hook is unmapped and its interval is
 * auto-creatable — create an interval monitor for it in one click. Both
 * persist the `cronheart_event_map` option through the {@see Ajax} layer,
 * which is what {@see \Cronheart\WP\Hooks\PerEventInstrumentation} reads to
 * wire start / success / fail pings on the next request.
 *
 * The live controls are token-gated, like the heartbeat picker: the dropdown
 * is populated from the account's monitors, which requires the API token.
 * Without a token (or if the listing fails) the screen degrades to a
 * read-only view of the discovered hooks and their resolved mapping, with a
 * note pointing at the token field / the constant + helper alternatives.
 *
 * A hook whose UUID comes from a `CRONHEART_EVENT_<HOOK>_UUID` constant
 * renders read-only (constant outranks the option, so editing it here would
 * be a no-op), mirroring how the heartbeat field treats its constant.
 */
final class CronEventsScreen
{
    public const MENU_SLUG = 'cronheart-events';

    /**
     * Cap on rendered rows. A WooCommerce / Action-Scheduler site can carry a
     * large number of recurring hooks; the table shows the first this-many
     * (sorted by hook name) and notes the remainder rather than rendering an
     * unbounded list.
     */
    private const MAX_EVENTS = 100;

    private ?string $hookSuffix = null;

    /**
     * Why the live controls are unavailable (no token, or a failed listing),
     * already translated. Null when monitors loaded or before any attempt.
     */
    private ?string $monitorsNotice = null;

    /**
     * @param \Closure(string): ManagementClient $managementClientFactory builds the
     *                                                                    admin-only
     *                                                                    management
     *                                                                    client for a
     *                                                                    token; null
     *                                                                    disables the
     *                                                                    live controls
     */
    public function __construct(
        private readonly EventDiscovery $eventDiscovery,
        private readonly Resolver $resolver,
        private readonly ?\Closure $managementClientFactory = null,
        private readonly string $pluginFile = '',
    ) {
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_assets']);
    }

    public function add_menu(): void
    {
        $hookSuffix = add_options_page(
            __('Cronheart Events', 'cronheart'),
            __('Cronheart Events', 'cronheart'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );

        $this->hookSuffix = \is_string($hookSuffix) ? $hookSuffix : null;
    }

    /**
     * Enqueue the shared admin script + style on this screen only, gated on
     * the hook suffix from {@see add_options_page()} (never a hard-coded
     * page string). Reuses the same localized data as the settings page.
     */
    public function enqueue_assets(string $hook_suffix): void
    {
        if (null === $this->hookSuffix || $hook_suffix !== $this->hookSuffix || '' === $this->pluginFile) {
            return;
        }

        $version = \defined('CRONHEART_VERSION') ? (string) \constant('CRONHEART_VERSION') : false;

        wp_enqueue_style('cronheart-admin', plugins_url('assets/admin.css', $this->pluginFile), [], $version);
        wp_enqueue_script('cronheart-admin', plugins_url('assets/admin.js', $this->pluginFile), [], $version, true);
        wp_localize_script('cronheart-admin', 'cronheartAdmin', Ajax::scriptData());
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'cronheart'));
        }

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Cronheart Events', 'cronheart').'</h1>';
        echo '<p>'.esc_html__(
            'Assign a cronheart.com monitor to any recurring WP-Cron event on this site, or create one automatically. Cronheart then sends start / success / fail pings each time the event runs.',
            'cronheart'
        ).'</p>';

        $events = $this->eventDiscovery->recurringEvents();
        $total = \count($events);
        if ($total > self::MAX_EVENTS) {
            $events = \array_slice($events, 0, self::MAX_EVENTS);
        }

        $monitors = $this->fetchMonitors();
        if (null === $monitors && null !== $this->monitorsNotice) {
            echo '<div class="notice notice-info inline"><p>'.esc_html($this->monitorsNotice).'</p></div>';
        }

        if ([] === $events) {
            echo '<p>'.esc_html__('No recurring WP-Cron events were found on this site.', 'cronheart').'</p>';
            echo '</div>';

            return;
        }

        echo '<table class="widefat striped cronheart-events">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Event', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Schedule', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Monitor', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Actions', 'cronheart').'</th>';
        echo '</tr></thead><tbody>';

        foreach ($events as $event) {
            $this->render_event_row($event, $monitors);
        }

        echo '</tbody></table>';

        if ($total > self::MAX_EVENTS) {
            echo '<p class="description">'.esc_html(\sprintf(
                /* translators: 1: number of events shown; 2: total recurring events found. */
                __('Showing the first %1$d of %2$d recurring events.', 'cronheart'),
                self::MAX_EVENTS,
                $total
            )).'</p>';
        }

        echo '</div>';
    }

    /**
     * @param array{hook: string, schedule: ?string, intervalSeconds: ?int, nextRun: ?int, isRecurring: bool} $event
     * @param list<Monitor>|null                                                                              $monitors
     */
    private function render_event_row(array $event, ?array $monitors): void
    {
        $hook = $event['hook'];
        $isConstant = $this->resolver->eventUuidIsConstant($hook);
        $resolved = $this->resolver->eventUuid($hook);

        printf('<tr data-cronheart-hook="%s">', esc_attr($hook));
        printf('<td><code>%s</code></td>', esc_html($hook));
        printf('<td>%s</td>', esc_html($this->scheduleLabel($event)));
        printf(
            '<td class="cronheart-event-mapping">%s</td>',
            esc_html($this->mappingLabel($resolved, $isConstant))
        );

        echo '<td>';
        if ($isConstant) {
            echo '<span class="description">'.esc_html__('Set via wp-config.php constant', 'cronheart').'</span>';
        } elseif (null === $monitors) {
            echo '<span class="description">'.esc_html__('Connect an API token to assign or create monitors.', 'cronheart').'</span>';
        } else {
            $this->render_assign_select($resolved, $monitors);
            if (null === $resolved && $this->isAutoCreatable($event)) {
                printf(
                    '<button type="button" class="button cronheart-event-create">%s</button>',
                    esc_html__('Auto-create & assign', 'cronheart')
                );
            }
            echo '<span class="cronheart-event-feedback" role="status" aria-live="polite"></span>';
        }
        echo '</td>';

        echo '</tr>';
    }

    /**
     * @param list<Monitor> $monitors
     */
    private function render_assign_select(?string $current, array $monitors): void
    {
        echo '<select class="cronheart-event-monitor">';
        printf('<option value="">%s</option>', esc_html__('— Do not monitor —', 'cronheart'));

        $known = false;
        foreach ($monitors as $monitor) {
            if ($monitor->uuid === $current) {
                $known = true;
            }
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($monitor->uuid),
                selected($current, $monitor->uuid, false),
                esc_html($monitor->name.' — '.Ajax::statusLabel($monitor->status).' — '.$monitor->uuid)
            );
        }

        // A mapped-but-unlisted UUID (assigned earlier, or via the filter)
        // stays selected so re-saving does not silently drop it.
        if (null !== $current && !$known) {
            printf(
                '<option value="%1$s" selected="selected">%2$s</option>',
                esc_attr($current),
                esc_html(\sprintf(
                    /* translators: %s: a monitor UUID not present in the connected account. */
                    __('%s (not in this account)', 'cronheart'),
                    $current
                ))
            );
        }

        echo '</select>';
    }

    /**
     * Monitors fetched for the assign dropdowns, or null when no token is
     * configured or the listing failed (in which case {@see $monitorsNotice}
     * explains why and the screen degrades to read-only). Never lets an
     * exception escape into the page render.
     *
     * @return list<Monitor>|null
     */
    private function fetchMonitors(): ?array
    {
        $token = $this->resolver->apiToken();
        if (null === $this->managementClientFactory || null === $token) {
            $this->monitorsNotice = __('Connect a cronheart.com API token on the Cronheart settings page to assign or create monitors here. You can also wire hooks with cronheart_monitor() or CRONHEART_EVENT_<HOOK>_UUID constants.', 'cronheart');

            return null;
        }

        try {
            return array_values(($this->managementClientFactory)($token)->listMonitors());
        } catch (PlanRestrictionException) {
            $this->monitorsNotice = __('Your cronheart.com plan does not include API access, so monitors cannot be listed here.', 'cronheart');
        } catch (AuthenticationException) {
            $this->monitorsNotice = __('Could not authenticate with cronheart.com — check the API token on the Cronheart settings page.', 'cronheart');
        } catch (RateLimitException) {
            $this->monitorsNotice = __('cronheart.com is rate-limiting requests right now. Reload this page in a minute.', 'cronheart');
        } catch (\Throwable) {
            $this->monitorsNotice = __('Could not reach cronheart.com to load your monitors.', 'cronheart');
        }

        return null;
    }

    /**
     * @param array{hook: string, schedule: ?string, intervalSeconds: ?int, nextRun: ?int, isRecurring: bool} $event
     */
    private function isAutoCreatable(array $event): bool
    {
        return null !== IntervalMonitorBlueprint::fromEvent(
            $event['hook'],
            $event['intervalSeconds'],
            $this->eventDiscovery->timezone(),
            site_url()
        );
    }

    /**
     * @param array{hook: string, schedule: ?string, intervalSeconds: ?int, nextRun: ?int, isRecurring: bool} $event
     */
    private function scheduleLabel(array $event): string
    {
        $parts = [];
        if (null !== $event['schedule']) {
            $parts[] = $event['schedule'];
        }
        if (null !== $event['intervalSeconds']) {
            $parts[] = \sprintf(
                /* translators: %s: an interval in seconds. */
                __('every %ss', 'cronheart'),
                number_format_i18n($event['intervalSeconds'])
            );
        }
        if (null !== $event['nextRun']) {
            $parts[] = \sprintf(
                /* translators: %s: a UTC date-time. */
                __('next %s', 'cronheart'),
                gmdate('Y-m-d H:i', $event['nextRun']).' UTC'
            );
        }

        return implode(' · ', $parts);
    }

    private function mappingLabel(?string $resolved, bool $isConstant): string
    {
        if ($isConstant) {
            return __('Set by wp-config.php constant', 'cronheart');
        }
        if (null === $resolved) {
            return __('Not monitored', 'cronheart');
        }

        return $resolved;
    }
}
