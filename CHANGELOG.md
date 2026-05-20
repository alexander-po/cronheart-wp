# Changelog

All notable changes to the `cronheart/wp` plugin land here, newest
first. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Repository scaffolding: GPL-2.0-or-later license, composer manifest
  pulling `cron-monitor/php-sdk: ^0.2.1`, plugin entry point with WP
  header, `Cronheart\WP\Plugin` bootstrap class.
- CI matrix on PHP 8.2 / 8.3 / 8.4 running PHPUnit, PHPStan level 8,
  php-cs-fixer dry-run, `composer validate --strict`, and `composer
  audit`.
- WPCS (`WordPress-Core` + `WordPress-Extra`) ruleset scoped to
  user-facing PHP only so the SDK-style internal code can stay
  PSR-12 / strict-typed without fighting the WordPress conventions.
- Heartbeat layer (`Hooks\HeartbeatScheduler`, `Hooks\HeartbeatHandler`)
  driven by a 5-minute custom WP-Cron schedule. Activation /
  deactivation hooks in `cronheart.php` schedule and clear the tick.
- `Config\Resolver` for UUID resolution with precedence
  `wp-config.php constant > wp_options > cronheart_monitor_map filter`,
  preserving the SDK's empty-string-as-suppression policy.
- `Api\Client` thin façade over the bundled `CronMonitorClient` with
  belt-and-suspenders `try/catch` to guard the host job against a
  hypothetical custom-transport contract violation.
- **Per-event monitoring layer** (`Hooks\PerEventInstrumentation`):
  wraps registered hooks with start / success / fail pings via
  `PHP_INT_MIN` / `PHP_INT_MAX` priority sandwich, plus a shutdown
  sweep that fires `fail` pings for hooks that started but never
  reached the success listener. The fail body includes the
  `error_get_last()` capture when a PHP fatal triggered the
  failure.
- **`cronheart_monitor( $hook, $uuid )` helper** (registered via
  Composer `autoload.files` so it is available immediately after
  `vendor/autoload.php`) adds an entry to the `cronheart_monitor_map`
  filter. Passing `null` for the UUID registers the hook name only,
  letting `CRONHEART_EVENT_<HOOK>_UUID` in `wp-config.php` supply
  the value through the resolver's precedence chain.
- `Resolver::eventHookNames()` enumerates hooks to instrument from
  the union of the `cronheart_event_map` option keys and the
  `cronheart_monitor_map` filter keys.
- **Admin Settings page** at `Settings → Cronheart`
  (`Admin\SettingsPage`): one editable field for the site heartbeat
  UUID plus a read-only "Monitored events" table fed by
  `Admin\EventList`. Per-event editing through the UI is deferred to
  v0.1.1 — for v0.1.0 operators wire those through
  `cronheart_monitor()` calls or `CRONHEART_EVENT_<HOOK>_UUID`
  constants. Security: `current_user_can( 'manage_options' )` guard
  on render, Settings API handles CSRF nonces, UUID input validated
  by `sanitize_uuid` with an `add_settings_error` notice surfacing
  rejections instead of silent data loss, all echoed output runs
  through `esc_html` / `esc_attr` per WPCS. When
  `CRONHEART_HEARTBEAT_UUID` is defined in wp-config.php the page
  renders a note explaining that the constant takes precedence so
  saved values are ignored at ping time.

### Known limitations

- Vendor namespace prefixing (Strauss / php-scoper) is **deferred to
  v0.1.1+** when we submit to wordpress.org. For the GitHub-only
  v0.1.0 release the SDK ships under its canonical `CronMonitor\…`
  namespace; conflict risk is minimal because no other plugin
  currently bundles `cron-monitor/php-sdk`. Strauss 0.21 has an
  over-aggressive prefixer that rewrites `\is_string()`-style global
  function calls (issue specific to leading-backslash builtins);
  next iteration will either pin a fixed Strauss version or migrate
  to `humbug/php-scoper`.
