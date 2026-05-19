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
}
