<?php

declare(strict_types=1);

/*
 * Hand-maintained stubs for the WordPress core symbols this plugin
 * references. PHPStan reads this via the `scanFiles` directive in
 * `phpstan.dist.neon` to learn signatures, but does NOT analyse the
 * function bodies (they exist only so the file parses as valid PHP).
 *
 * NEVER included at runtime — the WP runtime ships the real
 * implementations, and the `.php-cs-fixer.dist.php` Finder excludes
 * `tests/stubs/` so the bodies are not subject to our coding-standard
 * sweep.
 *
 * Grows as later commits reach into new WP APIs.
 *
 * Why not `szepeviktor/phpstan-wordpress`: ~1500 stubs + large
 * transitive surface (Composer, Symfony deps) for static analysis
 * only. We touch a handful of hooks; the maintenance cost of
 * declaring them by hand is well below the install-time cost of
 * pulling the full WordPress-stubs package every CI run.
 */

namespace {
    /**
     * @return mixed
     */
    function get_option(string $option, mixed $default_value = false)
    {
        return $default_value;
    }

    /**
     * @return mixed
     */
    function apply_filters(string $hook_name, mixed $value, mixed ...$args)
    {
        return $value;
    }

    function add_action(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }

    function add_filter(string $hook_name, callable $callback, int $priority = 10, int $accepted_args = 1): bool
    {
        return true;
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return int|false
     */
    function wp_schedule_event(int $timestamp, string $recurrence, string $hook, array $args = [], bool $wp_error = false)
    {
        return false;
    }

    /**
     * @param array<int, mixed> $args
     *
     * @return int|false
     */
    function wp_next_scheduled(string $hook, array $args = [])
    {
        return false;
    }

    /**
     * @param array<int, mixed> $args
     */
    function wp_clear_scheduled_hook(string $hook, array $args = [], bool $wp_error = false): int
    {
        return 0;
    }

    /**
     * @return mixed
     */
    function register_activation_hook(string $file, callable $callback)
    {
    }

    /**
     * @return mixed
     */
    function register_deactivation_hook(string $file, callable $callback)
    {
    }

    function current_action(): string|false
    {
        return false;
    }

    function current_user_can(string $capability): bool
    {
        return false;
    }

    function add_options_page(string $page_title, string $menu_title, string $capability, string $menu_slug, callable $callback = null, int $position = null): string|false
    {
        return false;
    }

    /**
     * @param array<string, mixed> $args
     */
    function register_setting(string $option_group, string $option_name, array $args = []): void
    {
    }

    function add_settings_section(string $id, string $title, ?callable $callback, string $page): void
    {
    }

    /**
     * @param array<string, mixed> $args
     */
    function add_settings_field(string $id, string $title, callable $callback, string $page, string $section = 'default', array $args = []): void
    {
    }

    function settings_fields(string $option_group): void
    {
    }

    function do_settings_sections(string $page): void
    {
    }

    function submit_button(string $text = '', string $type = 'primary', string $name = 'submit', bool $wrap = true): void
    {
    }

    function add_settings_error(string $setting, string $code, string $message, string $type = 'error'): void
    {
    }

    /**
     * @return never
     */
    function wp_die(string $message = '', string $title = '', mixed $args = []): void
    {
        throw new \RuntimeException($message);
    }

    function esc_html(string $text): string
    {
        return $text;
    }

    function esc_html__(string $text, string $domain = 'default'): string
    {
        return $text;
    }

    function esc_attr(string $text): string
    {
        return $text;
    }

    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }

    function esc_url(string $url, ?array $protocols = null, string $_context = 'display'): string
    {
        return $url;
    }

    function _n(string $single, string $plural, int $number, string $domain = 'default'): string
    {
        return 1 === $number ? $single : $plural;
    }

    function selected(mixed $selected, mixed $current = true, bool $display = true): string
    {
        $result = (string) $selected === (string) $current ? " selected='selected'" : '';
        if ($display) {
            echo $result;
        }

        return $result;
    }

    function esc_attr__(string $text, string $domain = 'default'): string
    {
        return $text;
    }

    /**
     * @return int|false
     */
    function check_ajax_referer(string $action = '-1', string|false $query_arg = false, bool $stop = true)
    {
        return 1;
    }

    /**
     * @param mixed $data
     *
     * @return never
     */
    function wp_send_json_success($data = null, ?int $status_code = null, int $options = 0): void
    {
        exit;
    }

    /**
     * @param mixed $data
     *
     * @return never
     */
    function wp_send_json_error($data = null, ?int $status_code = null, int $options = 0): void
    {
        exit;
    }

    function sanitize_text_field(string $str): string
    {
        return trim($str);
    }

    function sanitize_key(string $key): string
    {
        return strtolower($key);
    }

    /**
     * @template T
     *
     * @param T $value
     *
     * @return T
     */
    function wp_unslash($value)
    {
        return $value;
    }

    function plugins_url(string $path = '', string $plugin = ''): string
    {
        return $path;
    }

    /**
     * @param array<int, string> $deps
     */
    function wp_enqueue_style(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, string $media = 'all'): void
    {
    }

    /**
     * @param array<int, string> $deps
     */
    function wp_enqueue_script(string $handle, string $src = '', array $deps = [], string|bool|null $ver = false, bool $in_footer = false): void
    {
    }

    /**
     * @param array<string, mixed> $l10n
     */
    function wp_localize_script(string $handle, string $object_name, array $l10n): bool
    {
        return true;
    }

    function wp_create_nonce(string $action = '-1'): string
    {
        return 'nonce';
    }

    function admin_url(string $path = '', string $scheme = 'admin'): string
    {
        return 'https://example.test/wp-admin/'.$path;
    }

    function site_url(string $path = '', ?string $scheme = null): string
    {
        return 'https://example.test'.$path;
    }

    /**
     * @return array<int|string, mixed>|false
     */
    function _get_cron_array()
    {
        return false;
    }

    /**
     * @return array<string, array{interval: int, display: string}>
     */
    function wp_get_schedules(): array
    {
        return [];
    }

    function wp_timezone_string(): string
    {
        return 'UTC';
    }

    /**
     * @param mixed $value
     */
    function update_option(string $option, $value, $autoload = null): bool
    {
        return true;
    }

    function number_format_i18n(int|float $number, int $decimals = 0): string
    {
        return (string) $number;
    }
}
