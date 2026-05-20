# Changelog

All notable changes to the `cronheart/wp` plugin land here, newest
first. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

_Nothing yet — open a PR and add your entry under the appropriate subsection._

## [0.1.1] — 2026-05-20

Patch release that adds endpoint-override support and the local
smoke harness that uses it. No breaking changes — plugins not
setting the new constants keep the v0.1.0 behaviour (pinging
`https://cronheart.com`).

### Added

- **`CRONHEART_ENDPOINT` constant / `cronheart_endpoint` option** for
  pointing the plugin at a non-production cronheart deployment
  (staging, private VPC install, local dev backend). Resolver
  precedence matches the UUID story: `wp-config.php` constant >
  `wp_options` > default (`https://cronheart.com`).
- **`CRONHEART_ALLOW_INSECURE_ENDPOINT` constant /
  `cronheart_allow_insecure_endpoint` option** to opt into plain
  `http://` endpoints. Defaults to false (HTTPS-enforced). Required
  when pointing the plugin at a local backend behind
  `host.docker.internal` or any TLS-less private deployment. Accepts
  native booleans (`define('…', true)`) and the canonical truthy /
  falsy string forms (`'true'`, `'1'`, `'yes'`, `'on'` and
  inverses) — the latter useful for env-var-expanded values.
- **`Resolver::endpoint()` and `Resolver::allowInsecureEndpoint()`**
  expose the resolved values to consumers; `Plugin::boot()` wires
  them into the SDK's `Configuration`. Misconfigurations (plain
  `http://` without allow-insecure, malformed URL) are caught at
  Configuration construction time and fall back to defaults so the
  WP-Cron run is never blocked by bad config.
- **`devstack/` end-to-end smoke harness.** Two-mode docker-compose
  stack and smoke script for verifying the plugin against either
  production `cronheart.com` (default — public contributors) or a
  local cron-monitor backend (maintainers only, requires
  closed-source backend repo). Documented in README.

## [0.1.0] — 2026-05-20

First public GitHub release. WordPress.org plugin-directory
submission is deferred to v0.1.1+ — we are iterating the API on
early GitHub adopters first.

### Added

- **Repository scaffolding**: GPL-2.0-or-later license, composer
  manifest pulling `cron-monitor/php-sdk: ^0.2.1`, plugin entry
  point with WP header, `Cronheart\WP\Plugin` bootstrap class.
- **CI matrix** on PHP 8.2 / 8.3 / 8.4 running PHPUnit, PHPStan
  level 8, php-cs-fixer dry-run, `composer validate --strict`,
  `composer audit`, and `phpcs` against WordPress-Core +
  WordPress-Extra rule sets.
- **Two-rule-set style enforcement** by file scope:
  - `.php-cs-fixer.dist.php` — Symfony / PSR-12 / `strict_types`,
    scoped to `src/` and `tests/` (SDK-style internal code).
  - `.phpcs.xml.dist` — WordPress-Core + WordPress-Extra, scoped to
    `cronheart.php` and the admin layer.
- **Heartbeat layer** (`Hooks\HeartbeatScheduler`,
  `Hooks\HeartbeatHandler`) driven by a 5-minute custom WP-Cron
  schedule. Activation / deactivation hooks in `cronheart.php`
  schedule and clear the tick.
- **`Config\Resolver`** for UUID resolution with precedence
  `wp-config.php constant > wp_options > cronheart_monitor_map
  filter`, preserving the SDK's empty-string-as-suppression policy.
- **`Api\Client`** thin façade over the bundled
  `CronMonitor\Client\CronMonitorClient` with belt-and-suspenders
  `try/catch` to guard the host job against a hypothetical
  custom-transport contract violation.
- **Per-event monitoring** (`Hooks\PerEventInstrumentation`): wraps
  registered hooks with start / success / fail pings via
  `PHP_INT_MIN` / `PHP_INT_MAX` priority sandwich, plus a shutdown
  sweep that fires `fail` pings for hooks that started but never
  reached the success listener. The fail body includes the
  `error_get_last()` capture when a PHP fatal triggered the failure.
- **`cronheart_monitor( $hook, $uuid )` helper** (registered via
  Composer `autoload.files`) adds an entry to the
  `cronheart_monitor_map` filter. Passing `null` for the UUID
  registers the hook name only, letting
  `CRONHEART_EVENT_<HOOK>_UUID` in `wp-config.php` supply the value.
- **Admin Settings page** at `Settings → Cronheart`
  (`Admin\SettingsPage`): one editable field for the site
  heartbeat UUID plus a read-only "Monitored events" table fed by
  `Admin\EventList`. Per-event UI editing is deferred to v0.1.1;
  v0.1.0 operators wire those through `cronheart_monitor()` calls
  or `CRONHEART_EVENT_<HOOK>_UUID` constants.

### Known limitations

- **Vendor namespace prefixing** (Strauss / php-scoper) is deferred
  to v0.1.1+ alongside the WordPress.org submission. For the
  GitHub-only v0.1.0 release the SDK ships under its canonical
  `CronMonitor\…` namespace; conflict risk is minimal because no
  other plugin currently bundles `cron-monitor/php-sdk`. Strauss
  0.21 has an over-aggressive prefixer that rewrites
  `\is_string()`-style global function calls; next iteration will
  either pin a fixed Strauss version or migrate to
  `humbug/php-scoper`.
- **WP-CLI commands** (`wp cronheart status`, `wp cronheart sync`)
  are not shipped in v0.1.0; deferred to v0.2.
- **Network / multisite activation** is not formally supported in
  v0.1.0 — the plugin works on a single-site install. Multisite
  considerations (network-level options vs site-level, network
  admin UI) are deferred to v0.2.
- **Action Scheduler** (the WooCommerce-bundled task runner that
  some plugins use instead of WP-Cron) is not yet instrumented —
  the plugin monitors WP-Cron hooks only. Deferred to v0.2 pending
  user demand.
