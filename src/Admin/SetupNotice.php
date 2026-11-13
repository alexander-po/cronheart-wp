<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Config\Resolver;

\defined('ABSPATH') || exit;

/**
 * One-time setup prompt on the Plugins and Dashboard screens, for users who
 * can manage options, until a heartbeat monitor is configured. Each user
 * can dismiss it; it disappears on its own once a UUID is saved or defined.
 */
final class SetupNotice
{
    public const DISMISS_META = 'cronheart_setup_notice_dismissed';
    public const DISMISS_PARAM = 'cronheart_dismiss_setup';
    public const DISMISS_NONCE = 'cronheart_dismiss_setup';

    private const SCREENS = ['plugins', 'dashboard'];

    public function __construct(private readonly Resolver $resolver)
    {
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'handle_dismiss']);
        add_action('admin_notices', [$this, 'render']);
    }

    public function handle_dismiss(): void
    {
        if (!current_user_can('manage_options')) {
            return;
        }

        $nonce = isset($_GET['_wpnonce']) ? sanitize_text_field(wp_unslash($_GET['_wpnonce'])) : '';
        if (!wp_verify_nonce($nonce, self::DISMISS_NONCE) || !isset($_GET[self::DISMISS_PARAM])) {
            return;
        }

        update_user_meta(get_current_user_id(), self::DISMISS_META, '1');
    }

    public function render(): void
    {
        if (!current_user_can('manage_options') || !$this->onNoticeScreen() || $this->monitorConfigured()) {
            return;
        }

        if ('' !== (string) get_user_meta(get_current_user_id(), self::DISMISS_META, true)) {
            return;
        }

        $base = admin_url('plugins' === $this->screenId() ? 'plugins.php' : 'index.php');

        printf(
            '<div class="notice notice-info"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a> &middot; <a href="%5$s" target="_blank" rel="noopener noreferrer">%6$s</a> &middot; <a href="%7$s">%8$s</a></p></div>',
            esc_html__('Cronheart is installed.', 'cronheart'),
            esc_html__('To finish setup: create a free account, add a monitor and paste its UUID, so you hear about it when WP-Cron stops.', 'cronheart'),
            esc_url(admin_url('options-general.php?page='.SettingsPage::MENU_SLUG)),
            esc_html__('Set up Cronheart', 'cronheart'),
            esc_url(Links::REGISTER),
            esc_html__('Create a free account', 'cronheart'),
            esc_url(wp_nonce_url(add_query_arg(self::DISMISS_PARAM, '1', $base), self::DISMISS_NONCE)),
            esc_html__('Dismiss', 'cronheart')
        );
    }

    private function onNoticeScreen(): bool
    {
        return \in_array($this->screenId(), self::SCREENS, true);
    }

    private function screenId(): ?string
    {
        $screen = \function_exists('get_current_screen') ? get_current_screen() : null;
        $id = \is_object($screen) ? ($screen->id ?? null) : null;

        return \is_string($id) ? $id : null;
    }

    private function monitorConfigured(): bool
    {
        if (\defined(Resolver::HEARTBEAT_CONSTANT)) {
            return true;
        }

        try {
            return null !== $this->resolver->heartbeatUuid();
        } catch (\Throwable) {
            return true;
        }
    }
}
