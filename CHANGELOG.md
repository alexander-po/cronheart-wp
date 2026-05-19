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
