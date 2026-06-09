<?php

declare(strict_types=1);

namespace Cronheart\WP\Admin;

use Cronheart\WP\Config\Resolver;
use CronMonitor\Api\Dto\Monitor;
use CronMonitor\Api\Exception\AuthenticationException;
use CronMonitor\Api\Exception\PlanRestrictionException;
use CronMonitor\Api\Exception\RateLimitException;

// Direct-access guard. See `Plugin.php` for the rationale —
// same canonical pattern, same Plugin-Check-imposed shape.
\defined('ABSPATH') || exit;

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
    public const API_SECTION = 'cronheart_api_section';

    /**
     * Virtual checkbox (not a registered setting) the operator ticks to
     * wipe a stored API token. Read from `$_POST` inside
     * {@see sanitize_api_token()} — see the nonce note there.
     */
    public const API_TOKEN_CLEAR_FIELD = 'cronheart_api_token_clear';

    /**
     * Guards the lazy {@see maybeFetchMonitors()} call so the lister
     * closure runs at most once per request, regardless of how many of the
     * field renderers ask for the result.
     */
    private bool $apiFetchAttempted = false;

    /**
     * Monitors fetched for the heartbeat picker, or null when no fetch has
     * succeeded (no token, lister absent, or the call failed — in which
     * case {@see $apiError} explains why and the field falls back to the
     * manual UUID input).
     *
     * @var list<Monitor>|null
     */
    private ?array $apiMonitors = null;

    /**
     * A human-readable, already-translated message describing why the
     * monitor listing could not be fetched. Null on success or before any
     * fetch attempt. Never contains the token value.
     */
    private ?string $apiError = null;

    /**
     * The plan-upgrade URL from a 402 response, surfaced as a link in the
     * connection notice. Null unless the last fetch failed with a plan
     * restriction that carried an `upgrade_url`.
     */
    private ?string $apiUpgradeUrl = null;

    /**
     * @param \Closure(string): list<Monitor> $monitorLister lists the
     *                                                       account's
     *                                                       monitors for
     *                                                       the picker;
     *                                                       throws on any
     *                                                       API failure.
     *                                                       Null disables
     *                                                       the picker
     *                                                       (manual UUID
     *                                                       entry only)
     */
    public function __construct(
        private readonly EventList $eventList,
        private readonly Resolver $resolver,
        private readonly ?\Closure $monitorLister = null,
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
        // API connection section first, so it renders above the heartbeat
        // section (Settings API paints sections in registration order).
        register_setting(
            self::OPTION_GROUP,
            Resolver::API_TOKEN_OPTION,
            [
                'type' => 'string',
                'sanitize_callback' => [self::class, 'sanitize_api_token'],
                'default' => '',
            ]
        );

        add_settings_section(
            self::API_SECTION,
            __('cronheart.com connection', 'cronheart'),
            [$this, 'render_api_intro'],
            self::MENU_SLUG
        );

        add_settings_field(
            Resolver::API_TOKEN_OPTION,
            __('API token', 'cronheart'),
            [$this, 'render_api_token_field'],
            self::MENU_SLUG,
            self::API_SECTION
        );

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

    public function render_api_intro(): void
    {
        echo '<p>'.esc_html__(
            'Connect your cronheart.com account with a Personal Access Token to pick a monitor from a list instead of pasting its UUID by hand. Create a token at cronheart.com under Settings → API Tokens. API access requires a Starter plan or higher.',
            'cronheart'
        ).'</p>';

        if ($this->resolver->apiTokenIsConstant()) {
            echo '<p><strong>'.esc_html__(
                'Note:',
                'cronheart'
            ).'</strong> '.esc_html(\sprintf(
                /* translators: %s: the wp-config.php constant name. */
                __('The %s constant is defined in wp-config.php and takes precedence over this field — the saved value here is ignored until the constant is removed.', 'cronheart'),
                Resolver::API_TOKEN_CONSTANT
            )).'</p>';
        }

        $this->render_api_connection_status();
    }

    /**
     * Render the live connection status under the API intro: a success
     * notice with the monitor count when listing worked, or a warning
     * notice (with an upgrade link for plan restrictions) when it failed.
     * Renders nothing when no token is configured — there is no connection
     * to report on yet.
     */
    private function render_api_connection_status(): void
    {
        if (null === $this->resolver->apiToken()) {
            return;
        }

        $this->maybeFetchMonitors();

        if (null !== $this->apiError) {
            echo '<div class="notice notice-warning inline"><p>'.esc_html($this->apiError);

            if (null !== $this->apiUpgradeUrl) {
                printf(
                    ' <a href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a>',
                    esc_url($this->apiUpgradeUrl),
                    esc_html__('Upgrade your plan', 'cronheart')
                );
            }

            echo '</p></div>';

            return;
        }

        if (\is_array($this->apiMonitors)) {
            $count = \count($this->apiMonitors);

            if (0 === $count) {
                echo '<div class="notice notice-info inline"><p>'.esc_html__(
                    'Connected to cronheart.com, but this account has no monitors yet. Create one at cronheart.com, then reload this page to pick it below.',
                    'cronheart'
                ).'</p></div>';

                return;
            }

            echo '<div class="notice notice-success inline"><p>'.esc_html(\sprintf(
                /* translators: %d: number of monitors found on the connected cronheart.com account. */
                _n(
                    'Connected — %d monitor available in the picker below.',
                    'Connected — %d monitors available in the picker below.',
                    $count,
                    'cronheart'
                ),
                $count
            )).'</p></div>';
        }
    }

    /**
     * Lazily list the account's monitors for the heartbeat picker, at most
     * once per request. Only runs when both a token and a lister closure
     * are present. Every failure mode is caught and mapped to a translated
     * {@see $apiError} message (never the token value) so the caller can
     * fall back to the manual UUID field — this method never lets an
     * exception escape into the admin page render.
     */
    private function maybeFetchMonitors(): void
    {
        if ($this->apiFetchAttempted) {
            return;
        }
        $this->apiFetchAttempted = true;

        if (null === $this->monitorLister) {
            return;
        }

        $token = $this->resolver->apiToken();
        if (null === $token) {
            return;
        }

        try {
            $this->apiMonitors = array_values(($this->monitorLister)($token));
        } catch (PlanRestrictionException $e) {
            $this->apiUpgradeUrl = $e->upgradeUrl;
            $this->apiError = __('Your cronheart.com plan does not include API access. Upgrade to Starter or higher to pick monitors from a list — you can still paste a monitor UUID below.', 'cronheart');
        } catch (AuthenticationException) {
            $this->apiError = __('Could not authenticate with cronheart.com — check that the API token is correct and still active. You can paste a monitor UUID manually below.', 'cronheart');
        } catch (RateLimitException) {
            $this->apiError = __('cronheart.com is rate-limiting requests right now. Reload this page in a minute, or paste a monitor UUID manually below.', 'cronheart');
        } catch (\Throwable) {
            $this->apiError = __('Could not reach cronheart.com to load your monitors. You can paste a monitor UUID manually below.', 'cronheart');
        }
    }

    /**
     * Write-only token field. The stored token is never echoed back into
     * the page: the input always renders empty, and an empty submit is
     * treated as "keep the existing token" (so saving other settings does
     * not wipe it). A separate "remove" checkbox clears it deliberately.
     */
    public function render_api_token_field(): void
    {
        $isConstant = $this->resolver->apiTokenIsConstant();
        $hasStored = '' !== (string) get_option(Resolver::API_TOKEN_OPTION, '');

        $placeholder = $isConstant
            ? __('Set in wp-config.php', 'cronheart')
            : ($hasStored
                ? __('Saved — leave blank to keep, paste a new token to replace', 'cronheart')
                : 'cmk_…');

        printf(
            '<input type="password" id="%1$s" name="%1$s" value="" autocomplete="off" class="regular-text code" placeholder="%2$s"%3$s />',
            esc_attr(Resolver::API_TOKEN_OPTION),
            esc_attr($placeholder),
            $isConstant ? ' disabled' : ''
        );

        if (!$isConstant && $hasStored) {
            printf(
                '<p><label><input type="checkbox" name="%1$s" value="1" /> %2$s</label></p>',
                esc_attr(self::API_TOKEN_CLEAR_FIELD),
                esc_html__('Remove the saved API token', 'cronheart')
            );
        }

        echo '<p class="description">'.esc_html__(
            'The token is an account-level, write-capable credential — for production prefer defining CRONHEART_API_TOKEN in wp-config.php over storing it in the database here.',
            'cronheart'
        ).'</p>';
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
        $this->maybeFetchMonitors();

        $value = (string) get_option(Resolver::HEARTBEAT_OPTION, '');

        if (\is_array($this->apiMonitors) && [] !== $this->apiMonitors) {
            $this->render_heartbeat_select($value, $this->apiMonitors);

            return;
        }

        $this->render_heartbeat_text_input($value);
    }

    /**
     * Dropdown of the account's monitors (shown when the API listing
     * succeeded). The select writes to the same `cronheart_heartbeat_uuid`
     * option as the manual field, so the resolver and the runtime ping
     * path are untouched — only the way an operator fills the UUID changes.
     *
     * @param list<Monitor> $monitors
     */
    private function render_heartbeat_select(string $value, array $monitors): void
    {
        echo '<select id="'.esc_attr(Resolver::HEARTBEAT_OPTION).'" name="'.esc_attr(Resolver::HEARTBEAT_OPTION).'">';

        printf('<option value="">%s</option>', esc_html__('— Do not monitor —', 'cronheart'));

        $valueIsKnown = false;
        foreach ($monitors as $monitor) {
            if ($monitor->uuid === $value) {
                $valueIsKnown = true;
            }
            printf(
                '<option value="%1$s"%2$s>%3$s</option>',
                esc_attr($monitor->uuid),
                selected($value, $monitor->uuid, false),
                esc_html($monitor->name.' — '.$monitor->uuid)
            );
        }

        // A saved UUID that is not in the account listing (revoked, owned
        // by another account, or hand-typed before connecting) must stay
        // selected so saving the form does not silently wipe it.
        if ('' !== $value && !$valueIsKnown) {
            printf(
                '<option value="%1$s" selected="selected">%2$s</option>',
                esc_attr($value),
                esc_html(\sprintf(
                    /* translators: %s: a monitor UUID not present in the connected account. */
                    __('%s (not in this account)', 'cronheart'),
                    $value
                ))
            );
        }

        echo '</select>';

        echo '<p class="description">'.esc_html__(
            'Pick the monitor that represents this site. Choose "— Do not monitor —" to suppress the heartbeat in this environment.',
            'cronheart'
        ).'</p>';
    }

    private function render_heartbeat_text_input(string $value): void
    {
        printf(
            '<input type="text" id="%1$s" name="%1$s" value="%2$s" class="regular-text code" placeholder="xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx" />',
            esc_attr(Resolver::HEARTBEAT_OPTION),
            esc_attr($value)
        );

        echo '<p class="description">'.esc_html__(
            'Leave empty to suppress the heartbeat (deliberate "do not monitor in this environment" signal). Connect an API token above to pick from a list instead.',
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

    /**
     * Sanitisation callback for the API token. Write-only semantics:
     *   - the "remove" checkbox clears the stored token;
     *   - an empty submit keeps the existing token (so saving the rest of
     *     the page does not wipe it);
     *   - a non-empty value must look like a `cmk_…` token, else it is
     *     rejected with a notice and the previously stored token is
     *     preserved (a typo in a replacement must not destroy a good one).
     *
     * The token value is never echoed into any notice or log.
     *
     * @param mixed $value the raw value from `$_POST`
     */
    public static function sanitize_api_token($value): string
    {
        $stored = (string) get_option(Resolver::API_TOKEN_OPTION, '');

        // Reading the sibling "remove" checkbox from $_POST is safe here:
        // options.php verifies the option-group nonce before invoking any
        // sanitize callback, so this code path is already nonce-guarded.
        // phpcs:ignore WordPress.Security.NonceVerification -- Settings API verifies the option-group nonce in options.php before any sanitize callback runs.
        if (isset($_POST[self::API_TOKEN_CLEAR_FIELD])) {
            return '';
        }

        if (!\is_string($value)) {
            return $stored;
        }
        $trimmed = trim($value);
        if ('' === $trimmed) {
            return $stored;
        }
        if (str_starts_with($trimmed, 'cmk_') && \strlen($trimmed) >= 16) {
            return $trimmed;
        }

        add_settings_error(
            Resolver::API_TOKEN_OPTION,
            'cronheart_invalid_api_token',
            esc_html__('The Cronheart API token must start with "cmk_". Create one at cronheart.com under Settings → API Tokens.', 'cronheart')
        );

        return $stored;
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
            // Inline ternary rather than a pre-assigned variable so
            // Plugin Check's `WordPress.Security.EscapeOutput` sniff
            // sees the `esc_html_*` calls as direct printf arguments
            // — it doesn't track escaped values through variable
            // assignment and would otherwise flag the substitution.
            printf(
                '<tr><td><code>%s</code></td><td><code>%s</code></td></tr>',
                esc_html($entry['hook']),
                null === $entry['uuid']
                    ? esc_html__('(suppressed)', 'cronheart')
                    : esc_html($entry['uuid'])
            );
        }

        echo '</tbody></table>';
    }
}
