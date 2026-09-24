<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Api\ManagementClient;
use Cronheart\WP\Config\Resolver;
use CronMonitor\Api\Dto\Channel;
use CronMonitor\Api\Dto\ChannelKind;
use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;

// Direct-access guard. See `Plugin.php` for the rationale —
// same canonical pattern, same Plugin-Check-imposed shape.
\defined('ABSPATH') || exit;

/**
 * Settings → Cronheart Channels: the notification-channel management screen.
 *
 * Lists the account's notification channels and, per channel, lets an
 * administrator send a real test alert through it and — for webhook channels
 * only — rotate the signing secret. Both run through the authenticated
 * {@see Ajax} layer over the throwing {@see ManagementClient}. Channel
 * *creation* is deliberately not here (deferred to a later release): this
 * screen reads and exercises channels that already exist, and points the
 * operator at cronheart.com to add or edit them.
 *
 * Token-gated like the other screens — the listing needs the account API
 * token, so without a token (or if the listing fails) the screen degrades to
 * a notice explaining why, with no live controls.
 *
 * Rotating a webhook secret returns a plaintext value the backend reveals
 * **once**. It is never rendered server-side: it travels only in the AJAX
 * response and is shown inline by `assets/admin.js` (via textContent), and is
 * never persisted or logged.
 */
final class ChannelsScreen
{
    public const MENU_SLUG = 'cronheart-channels';

    private ?string $hookSuffix = null;

    /**
     * Why the live controls are unavailable (no token, or a failed listing),
     * already translated. Null when channels loaded or before any attempt.
     */
    private ?string $channelsNotice = null;

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
            __('Cronheart Channels', 'cronheart'),
            __('Cronheart Channels', 'cronheart'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );

        $this->hookSuffix = \is_string($hookSuffix) ? $hookSuffix : null;
    }

    /**
     * Enqueue the shared admin script + style on this screen only, gated on
     * the hook suffix from {@see add_options_page()} (never a hard-coded page
     * string). Reuses the same localized data as the other Cronheart screens.
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
        echo '<h1>'.esc_html__('Cronheart Channels', 'cronheart').'</h1>';
        echo '<p>'.esc_html__(
            'Your cronheart.com notification channels. Send a test alert through any channel, or rotate the signing secret of a webhook channel. Create and edit channels at cronheart.com.',
            'cronheart'
        ).'</p>';

        $channels = $this->fetchChannels();
        if (null === $channels) {
            if (null !== $this->channelsNotice) {
                echo '<div class="notice notice-info inline"><p>'.esc_html($this->channelsNotice).'</p></div>';
            }
            echo '</div>';

            return;
        }

        if ([] === $channels) {
            echo '<p>'.esc_html__('This account has no notification channels yet. Create one at cronheart.com, then reload this page.', 'cronheart').'</p>';
            echo '</div>';

            return;
        }

        echo '<table class="widefat striped cronheart-channels">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Kind', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Label', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Verified', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Actions', 'cronheart').'</th>';
        echo '</tr></thead><tbody>';

        foreach ($channels as $channel) {
            $this->render_channel_row($channel);
        }

        echo '</tbody></table>';
        echo '</div>';
    }

    private function render_channel_row(Channel $channel): void
    {
        printf(
            '<tr data-cronheart-channel-id="%1$s" data-cronheart-channel-verified="%2$s">',
            esc_attr($channel->id),
            esc_attr($channel->verified ? '1' : '0')
        );

        printf('<td><code>%s</code></td>', esc_html($channel->kind));
        printf('<td>%s</td>', esc_html($channel->label));
        printf(
            '<td class="cronheart-channel-verified">%s</td>',
            esc_html($channel->verified ? __('Verified', 'cronheart') : __('Not verified', 'cronheart'))
        );

        echo '<td>';
        printf(
            '<button type="button" class="button cronheart-channel-test">%s</button>',
            esc_html__('Send test', 'cronheart')
        );
        if (ChannelKind::Webhook->value === $channel->kind) {
            printf(
                '<button type="button" class="button cronheart-channel-rotate">%s</button>',
                esc_html__('Rotate secret', 'cronheart')
            );
        }
        echo '<span class="cronheart-channel-feedback" role="status" aria-live="polite"></span>';
        echo '<div class="cronheart-channel-secret" hidden>';
        echo '<p class="description">'.esc_html__('New signing secret — copy it now, it is shown only once:', 'cronheart').'</p>';
        echo '<code class="cronheart-channel-secret-value"></code>';
        echo '</div>';
        echo '</td>';

        echo '</tr>';
    }

    /**
     * Channels fetched for the table, or null when no token is configured or
     * the listing failed (in which case {@see $channelsNotice} explains why
     * and the screen degrades to that notice). Never lets an exception escape
     * into the page render.
     *
     * @return list<Channel>|null
     */
    private function fetchChannels(): ?array
    {
        $token = $this->resolver->apiToken();
        if (null === $this->managementClientFactory || null === $token) {
            $this->channelsNotice = __('Connect a cronheart.com API token on the Cronheart settings page to manage notification channels here.', 'cronheart');

            return null;
        }

        try {
            return array_values(($this->managementClientFactory)($token)->listChannels());
        } catch (PlanRestrictionException) {
            $this->channelsNotice = __('Your cronheart.com plan does not include API access, so channels cannot be listed here.', 'cronheart');
        } catch (AuthenticationException) {
            $this->channelsNotice = __('Could not authenticate with cronheart.com — check the API token on the Cronheart settings page.', 'cronheart');
        } catch (RateLimitException) {
            $this->channelsNotice = __('cronheart.com is rate-limiting requests right now. Reload this page in a minute.', 'cronheart');
        } catch (\Throwable) {
            $this->channelsNotice = __('Could not reach cronheart.com to load your channels.', 'cronheart');
        }

        return null;
    }
}
