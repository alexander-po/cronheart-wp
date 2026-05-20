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
}
