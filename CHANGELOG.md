# Changelog

All notable changes to the `cronheart/wp` plugin land here, newest
first. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

_Nothing yet — open a PR and add your entry under the appropriate subsection._

## [0.1.3] — 2026-05-20

WordPress.org Plugin Check pre-flight fixes. Sprint D's local run
of `wp plugin check cronheart` against the staged v0.1.2 zip
surfaced four blocking errors and one warning; this release
clears them so the WP.org review queue doesn't bounce the
submission for static-analysis nits.

### Changed

- **Direct-access guards** (`defined( 'ABSPATH' ) || exit;`) added
  to `src/Plugin.php`, `src/Admin/SettingsPage.php`, and
  `src/Helpers/monitor.php`. The class files are loaded through
  Composer's PSR-4 autoloader (never as top-level scripts), so the
  guard has no runtime effect — Plugin Check's
  `missing_direct_file_access_protection` sniff fires on the
  missing token regardless, and submissions that omit it routinely
  get held up in review. The check's regex matches the canonical
  shape strictly (`defined('ABSPATH') (||/or) (exit/die);`); any
  decorating clause between the constant probe and the exit (e.g.
  a CLI escape hatch) defeats the match. We carry the canonical
  pattern verbatim and address the CLI / test-runner case at the
  loader layer (next bullet) instead.
- **`cronheart_monitor()` helper loading** moved out of Composer's
  `autoload.files` directive and into an explicit `require_once`
  from `cronheart.php` (production) plus `tests/bootstrap.php`
  (unit tests). The autoload-files path triggered the helper on
  every `vendor/autoload.php` require — including the one PHPUnit
  performs *before* its own bootstrap runs, which would silently
  exit the test runner once the helper carried the canonical
  ABSPATH guard. Production behaviour is unchanged: the function
  is still available the moment the plugin boots, because
  `cronheart.php` runs inside WordPress where `ABSPATH` is set by
  `wp-load.php` long before any plugin loads.
- **`tests/bootstrap.php`** now predefines `ABSPATH` to a sentinel
  before requiring autoload, so PSR-4 loads of `Plugin` /
  `SettingsPage` from test classes pick up the guard as a no-op
  instead of exiting. The sentinel is the `tests/` directory
  itself — deliberately not a real install path — so any test
  that accidentally derefs it fails loudly rather than silently
  reading the wrong location.
- **`Admin\SettingsPage::render_event_table`** refactor: the
  escaped UUID display is now inlined as a ternary directly inside
  the `printf` call instead of pre-assigned to `$uuid_display`.
  Plugin Check's `WordPress.Security.EscapeOutput` sniff doesn't
  track escape calls across variable assignments, so the previous
  shape produced a false-positive "OutputNotEscaped" error even
  though both branches called `esc_html_*` before assignment. The
  inline form makes the escape visible to the scanner. Output
  identical.
- **`bin/build-release.sh`** now copies `composer.json` and
  `composer.lock` into the staged tree alongside `vendor/`. Plugin
  Check warns when `/vendor` exists without the manifest that
  produced it, and downstream contributors can now run
  `composer install` against the checked-in lock to recreate the
  exact dependency tree we shipped.
- Bumped plugin header `Version` from `0.1.2` to `0.1.3`; bumped
  `readme.txt` `Stable tag` to `0.1.3`.

## [0.1.2] — 2026-05-20

WordPress.org submission readiness. No code changes — pure
metadata polish so the plugin can be submitted to the Plugin
Directory at https://wordpress.org/plugins/cronheart/ with a
complete, validating `readme.txt`.

### Changed

- **`readme.txt` major rewrite.** Expanded from the v0.1.0
  placeholder to a full WordPress.org Plugin Directory entry:
  Description with "What it does" / "Never breaks WP-Cron" /
  External-services disclosure sections; Installation walk-through;
  Frequently Asked Questions block (≥7 entries); Screenshots
  manifest (3 entries; PNG assets live in the WP.org SVN under
  `assets/`, not in this git tree); Upgrade Notice section.
- Bumped plugin header `Version` from `0.1.1` to `0.1.2`.
- Set `readme.txt` `Stable tag: 0.1.2` (was `trunk` in v0.1.0/v0.1.1).
  WordPress.org's readme validator now rejects `trunk` as the stable
  marker — the field must name a concrete version so users can roll
  back to a specific SVN tag if a future release regresses. The tag
  becomes meaningful once we create `tags/0.1.2/` in SVN after the
  plugin is approved; until then, the WP.org infrastructure falls
  back to trunk for the download.

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
