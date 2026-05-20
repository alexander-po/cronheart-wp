<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Config\Resolver;

/**
 * Settings → Cronheart admin page.
 *
 * v0.1.0 surface: one editable field (the site-wide heartbeat UUID,
 * persisted as the `cronheart_heartbeat_uuid` option) plus a
 * read-only summary of the per-event monitors `EventList` discovered.
 * Per-event editing through the UI is deferred to v0.1.1+ — for now
 * operators wire those through `cronheart_monitor()` in PHP or
 * `CRONHEART_EVENT_<HOOK>_UUID` in `wp-config.php` (both honoured by
 * the resolver's precedence chain).
 *
 * Security:
 *   - Page render guarded by `current_user_can( 'manage_options' )`.
 *   - Form CSRF / nonce handled by the Settings API
 *     (`settings_fields()` emits the nonce; `options.php` verifies on
 *     submission).
 *   - UUID input validated by `sanitize_uuid` — invalid input drops
 *     to empty string AND surfaces a `add_settings_error` notice so
 *     the operator sees the rejection rather than silent data loss.
 *   - All echoed output runs through `esc_html` / `esc_attr` /
 *     `esc_url` per WPCS.
 *
 * UUID-source precedence (mirrors the runtime resolver):
 *   constant > option > filter
 *
 * The settings page only edits the *option*. If a wp-config.php
 * constant is set, the option still saves but the constant continues
 * to win at ping time — the form renders a read-only notice in that
 * case so the operator is not surprised.
 */
final class SettingsPage
{
    public const MENU_SLUG = 'cronheart';
    public const OPTION_GROUP = 'cronheart_settings';
    public const HEARTBEAT_SECTION = 'cronheart_heartbeat_section';

    public function __construct(
        private readonly EventList $eventList,
    ) {
    }

    /**
     * Hook the admin-menu and admin-init callbacks. Safe to call
     * outside of an admin request — both hooks only fire in admin
     * context anyway, so the cost of registration is one closure
     * each.
     */
    public function register(): void
    {
        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
    }

    public function add_menu(): void
    {
        add_options_page(
            __('Cronheart', 'cronheart'),
            __('Cronheart', 'cronheart'),
            'manage_options',
            self::MENU_SLUG,
            [$this, 'render']
        );
    }

    public function register_settings(): void
    {
        register_setting(
            self::OPTION_GROUP,
            Resolver::HEARTBEAT_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => [self::class, 'sanitize_uuid'],
                'default' => '',
            ]
        );

        add_settings_section(
            self::HEARTBEAT_SECTION,
            __('Site heartbeat', 'cronheart'),
            [self::class, 'render_heartbeat_intro'],
            self::MENU_SLUG
        );

        add_settings_field(
            Resolver::HEARTBEAT_OPTION,
            __('Monitor UUID', 'cronheart'),
            [$this, 'render_heartbeat_field'],
            self::MENU_SLUG,
            self::HEARTBEAT_SECTION
        );
    }

    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access this page.', 'cronheart'));
        }

        echo '<div class="wrap">';
        echo '<h1>'.esc_html__('Cronheart', 'cronheart').'</h1>';

        echo '<form action="options.php" method="post">';
        settings_fields(self::OPTION_GROUP);
        do_settings_sections(self::MENU_SLUG);
        submit_button();
        echo '</form>';

        $this->render_event_table();

        echo '</div>';
    }

    public static function render_heartbeat_intro(): void
    {
        echo '<p>'.esc_html__(
            'Cronheart sends a heartbeat ping every 5 minutes to prove WP-Cron is still firing on this site. Paste the per-monitor UUID from your cronheart.com dashboard below.',
            'cronheart'
        ).'</p>';

        if (\defined(Resolver::HEARTBEAT_CONSTANT)) {
            echo '<p><strong>'.esc_html__(
                'Note:',
                'cronheart'
            ).'</strong> '.esc_html(\sprintf(
                /* translators: %s: the wp-config.php constant name. */
                __('The %s constant is defined in wp-config.php and takes precedence over this field — saved values here are ignored until the constant is removed.', 'cronheart'),
                Resolver::HEARTBEAT_CONSTANT
            )).'</p>';
        }
    }

    public function render_heartbeat_field(): void
    {
        $value = (string) get_option(Resolver::HEARTBEAT_OPTION, '');

        printf(
            '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text code" placeholder="xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx" />',
            esc_attr(Resolver::HEARTBEAT_OPTION),
            esc_attr($value)
        );

        echo '<p class="description">'.esc_html__(
            'Leave empty to suppress the heartbeat (deliberate "do not monitor in this environment" signal).',
            'cronheart'
        ).'</p>';
    }

    /**
     * Sanitisation callback registered with the Settings API.
     *
     * Accepts a UUID v4 (case-insensitive, normalised to lowercase)
     * or an empty string. Any other input is dropped to empty and a
     * Settings API error surfaces in the admin notice area on the
     * redirect after save, so the operator sees the rejection
     * instead of silently losing their input.
     *
     * @param mixed $value the raw value from `$_POST`; Settings API
     *                     passes whatever is in the request, so we
     *                     must type-guard before string operations
     */
    public static function sanitize_uuid($value): string
    {
        if (!\is_string($value)) {
            return '';
        }
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return '';
        }
        if (1 === preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $trimmed)) {
            return strtolower($trimmed);
        }

        add_settings_error(
            Resolver::HEARTBEAT_OPTION,
            'cronheart_invalid_uuid',
            esc_html__('The Cronheart monitor UUID must be a valid v4 UUID, or left empty.', 'cronheart')
        );

        return '';
    }

    private function render_event_table(): void
    {
        $entries = $this->eventList->entries();

        echo '<h2>'.esc_html__('Monitored events', 'cronheart').'</h2>';

        if ([] === $entries) {
            echo '<p>'.esc_html__(
                'No per-event monitors are currently registered. Call cronheart_monitor( $hook, $uuid ) from your plugin/theme to register one, or define a CRONHEART_EVENT_<HOOK>_UUID constant in wp-config.php.',
                'cronheart'
            ).'</p>';

            return;
        }

        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>'.esc_html__('Hook', 'cronheart').'</th>';
        echo '<th>'.esc_html__('Resolved UUID', 'cronheart').'</th>';
        echo '</tr></thead><tbody>';

        foreach ($entries as $entry) {
            $uuid_display = null === $entry['uuid']
                ? esc_html__('(suppressed)', 'cronheart')
                : esc_html($entry['uuid']);
            printf(
                '<tr><td><code>%s</code></td><td><code>%s</code></td></tr>',
                esc_html($entry['hook']),
                $uuid_display
            );
        }

        echo '</tbody></table>';
    }
}
