# Changelog

All notable changes to the `cronheart/wp` plugin land here, newest
first. The format follows [Keep a Changelog](https://keepachangelog.com/)
and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

_Nothing yet — open a PR and add your entry under the appropriate subsection._

## [0.4.0] — 2026-06-20

Part D of the management-UI arc: a point-and-click per-event monitoring screen. Settings → Cronheart Events lists the site's recurring WP-Cron events and, per event, assigns one of the account's monitors or auto-creates an interval monitor for it — the no-code alternative to the `cronheart_monitor()` helper and `CRONHEART_EVENT_<HOOK>_UUID` constants, which still work and still take precedence. Builds on the `Api\ManagementClient` seam and the admin-AJAX layer from 0.3.0; the never-throw runtime ping path is untouched.

### Added

- **`Cron\EventDiscovery`** — a pure service (closures over `_get_cron_array()` / `wp_get_schedules()` / `wp_timezone_string()`, same WP-boundary pattern as `Resolver`) that discovers WP-Cron events, de-dupes by hook, fills each recurring hook's interval, and exposes a recurring-only view plus an IANA-or-UTC site timezone.
- **`Cron\IntervalMonitorBlueprint`** — maps a discovered recurring hook to a backend-valid create request: auto-creatable only for an interval in `[30, 31,622,400]`s; `schedule_expr` the bare interval seconds; name clamped to `2..120`; grace `min(86400, max(60, interval/10))`; an idempotency key `wp-<sha256(site_url|hook)>`.
- **`Admin\CronEventsScreen`** (Settings → Cronheart Events) — the recurring-event table with a per-hook assign dropdown and an "Auto-create & assign" button (offered only when the hook is unmapped and auto-creatable). Constant-governed hooks render read-only; the live controls are token-gated like the heartbeat picker, degrading to a read-only view without a token.
- **Two admin-AJAX handlers** on `Admin\Ajax`: `cronheart_map_event` (assign / suppress — a read-modify-write of one `cronheart_event_map` entry, no API call, no token) and `cronheart_create_event_monitor` (auto-create via `ManagementClient::createIntervalMonitor()` then assign). Both validate the request hook against the discovered event set (never a trusted client string), keep the nonce + `manage_options` + boundary-validation contract, register no `nopriv` companion, and map a thrown SDK error — including a `409`/`422` from create — to a JSON error rather than a fatal.
- **`Resolver::eventUuidIsConstant()`** — a read-only query so the screen and the handlers treat a `CRONHEART_EVENT_<HOOK>_UUID`-governed hook as read-only.

### Changed

- `assets/admin.js` now wires both admin tables (monitor lifecycle + per-event mapping), still injecting every API-returned string via `textContent`; the localized data moved to a shared `Ajax::scriptData()`.
- Rewrote the `readme.txt` "External services" disclosure to add the create call (`POST /api/v1/monitors`, sent only on "Auto-create & assign") and the second admin screen.
- Bumped the plugin header `Version` and `readme.txt` `Stable tag` to `0.4.0`.

## [0.3.0] — 2026-06-19

Consumes the management surface added in `cron-monitor/php-sdk` 1.1.0 to turn Settings → Cronheart from a heartbeat-picker into a small monitor console: an account/plan card and a "Your monitors" table with pause / resume / snooze / unsnooze actions. This introduces the plugin's first authenticated admin-AJAX layer and the shared `Api\ManagementClient` seam. The never-throw runtime ping path (`Api\Client`) and its tokenless, least-privilege configuration are untouched — the write-capable `cmk_` token still leaves the site only from wp-admin, on an administrator's explicit action.

Part D (a per-event monitoring UI that discovers WP-Cron events and auto-creates interval monitors) is deferred to 0.4.0; this release deliberately makes no `createMonitor` calls.

### Added

- **`Api\ManagementClient`** — the admin-only, throwing counterpart to the never-throw ping `Api\Client`. Wraps the SDK's `MonitorApiClient`, built lazily and only from wp-admin, and exposes `listMonitors()` (capped at 200), `account()`, and `pause()` / `resume()` / `snooze(SnoozeDuration)` / `unsnooze()`. Lets the SDK's typed `ApiException` subclasses propagate so the admin layer owns the exception→notice/JSON ladder.
- **Account plan / budget card** on the settings page via `getAccount()` — plan label, monitor budget (used / limit / remaining), and API rate-limit standing, with an upgrade nudge once the monitor budget is ≥ 80% used.
- **`Admin\Ajax`** — the plugin's first `wp_ajax_*` handler (`cronheart_monitor_action`) for monitor lifecycle. Per-request contract: `check_ajax_referer` (a stale nonce returns a "reload and try again" JSON error, not a dead `-1`), `current_user_can( 'manage_options' )`, boundary validation (UUID v4 pattern, an `op` allow-list, the closed `SnoozeDuration` enum), and a thrown `ApiException` mapped to `wp_send_json_error` — never an uncaught 500. No `wp_ajax_nopriv_*` companion is registered.
- **"Your monitors" management table** on the settings page (reuses the listing already fetched for the picker) and **monitor status in the heartbeat picker** options.
- **`assets/admin.js` + `assets/admin.css`**, enqueued only on the Cronheart screen (gated on the hook suffix from `add_options_page()`). The script injects every API-returned string via `textContent` (never `innerHTML`) and takes its own user-facing strings from `wp_localize_script`.

### Changed

- Bumped the bundled `cron-monitor/php-sdk` constraint from `^1.0` to `^1.1`; the shipped `vendor/` tree changes by the SDK only.
- The heartbeat picker now fetches through `ManagementClient::listMonitors()` instead of a bespoke lister closure (behaviour-identical: the > 200 cap and a saved-but-unlisted UUID staying selectable both survive).
- **Rewrote** the `readme.txt` "External services" disclosure to cover the new wp-admin reads (`GET /api/v1/account`) and writes (`POST /api/v1/monitors/<uuid>/{pause,resume,snooze,unsnooze}`); all remain token-gated, wp-admin-only, and on an administrator's explicit action.
- `bin/build-release.sh` now stages `assets/` into the release zip (without it the admin JS/CSS would never ship).
- `.phpcs.xml.dist` now lints `src/Admin/` with the security and i18n sniffs (`EscapeOutput`, `NonceVerification`, `ValidatedSanitizedInput`, `WP.I18n`); the full WordPress layout rule set stays scoped to `cronheart.php` so the PSR-12 admin classes do not collide with it.
- Bumped the plugin header `Version` and `readme.txt` `Stable tag` to `0.3.0`.

## [0.2.1] — 2026-06-09

Documentation-only release on top of 0.2.0; the plugin code is identical. Cut so the WordPress.org Plugin Directory ships the monitor picker with an accurate readme and an updated screenshot (0.2.0 went to GitHub/Packagist only).

### Changed

- Refreshed `README.md` — corrected the stale "not on WP.org / not on Packagist / v0.1.0" install section, added the monitor picker to "What's in the box", documented the `CRONHEART_API_TOKEN` constant, and re-scoped the "Known limitations" list (picker is heartbeat-only; per-event remains manual).
- Expanded `readme.txt` "What it does" with the monitor picker, and updated the settings-page screenshot to show the connection section + picker.
- Bumped plugin header `Version` to `0.2.1` and `readme.txt` `Stable tag` to `0.2.1`.

## [0.2.0] — 2026-06-09

Adds an optional, account-token-backed **monitor picker** to the Settings → Cronheart page. With a cronheart.com Personal Access Token configured, the heartbeat field becomes a dropdown of the account's monitors instead of a free-text UUID box; the selection still persists to the same `cronheart_heartbeat_uuid` option, so the resolver and the runtime ping path are untouched — only how an operator fills the UUID changes. No token is required: manual UUID entry (and `wp-config.php` constants) work exactly as before.

The account token is a write-capable credential, so it is treated with care: a write-only settings field that never echoes the stored value, a preferred `CRONHEART_API_TOKEN` `wp-config.php` constant, and a separate token-bearing SDK `Configuration` built only for the admin listing call — the high-frequency runtime ping path keeps its tokenless, least-privilege config. The listing call is made only on the settings-page render and only when a token is present, never on the front end or during WP-Cron, which keeps the "External services" disclosure accurate.

### Added

- **Heartbeat monitor picker.** When an API token is configured and the listing succeeds, `SettingsPage::render_heartbeat_field()` renders a `<select>` of the account's monitors (label `name — uuid`, blank "do not monitor" option). A previously saved UUID that is not in the account list stays selectable so a form save never silently wipes it.
- **Write-only API-token field.** New "cronheart.com connection" settings section above the heartbeat section, with a `cmk_…` token field that never renders the stored value, a "remove token" checkbox, and a wp-config-constant notice.
- **`CRONHEART_API_TOKEN` constant / `cronheart_api_token` option**, resolved by `Config\Resolver::apiToken()` (constant over option, empty = suppression, deliberately no filter layer for a full-account credential).
- **Live connection status** under the token field: a success notice with the monitor count, or a warning notice that maps each failure — `402` (with an upgrade link), `401`, `429`, and any transport/other error — to a clear message, always falling back to the manual UUID field. The admin page never fatals on a listing failure.

### Changed

- Bumped the bundled `cron-monitor/php-sdk` constraint from `^0.2.1` to `^1.0` (the new SDK ships the authenticated `CronMonitor\Api\MonitorApiClient` the picker consumes).
- Bumped plugin header `Version` from `0.1.9` to `0.2.0`; bumped `readme.txt` `Stable tag` to `0.2.0`; expanded the `readme.txt` "External services" disclosure to cover the wp-admin-only management-API call.

## [0.1.9] — 2026-05-25

WordPress.org Plugin Directory review **round 2** response. The reviewer manually checked v0.1.8 and flagged a single remaining issue (round 1's legal URLs and `vendor/bin/*` findings are confirmed resolved); this release clears the last one. No code changes — pings, hooks, admin UI all identical to 0.1.8. Safe to upgrade.

The reviewer's static analysis pointed out:

    # WARNING: None of the listed contributors "cronmonitor" is
    the WordPress.org username of the owner of the plugin "cronheart".

v0.1.7 had changed `Contributors:` from `alexanderpo` to `cronmonitor` based on a misread of which WP.org account actually owned the slug. The slug `cronheart` was claimed and is owned by the `cronheart` WP.org account; every upload (including v0.1.8) shows in the reviewer's dashboard as "File updated by **cronheart**, version 0.1.8". The `cronmonitor` account exists but isn't the slug owner — it's at best a secondary identity, and listing only that account triggers the static-analysis warning.

### Changed

- **`Contributors:`** changed from `cronmonitor` to `cronheart` — the WordPress.org username that actually owns the slug and uploaded all submissions to date. `cronmonitor` is deliberately not included; the user chose to keep the contributors line minimal rather than display a non-owner identity on the Plugin Directory page.
- Bumped plugin header `Version` from `0.1.8` to `0.1.9`; bumped `readme.txt` `Stable tag` to `0.1.9`.

## [0.1.8] — 2026-05-22

`Tested up to: 7.0` bump. No code changes — pings, hooks, admin UI all identical to 0.1.7. Safe to upgrade.

This is the same class of release as v0.1.5 — WordPress.org's automated scan rejected the v0.1.7 re-upload because WordPress 7.0 had shipped during the review cycle and the readme's "Tested up to" now lagged again:

    readme.txt ERROR: outdated_tested_upto_header:
    Tested up to: 6.9 < 7.0

The CLAUDE.md "WP-image trap" rule already calls this pattern out — when bumping `readme.txt` `Tested up to:`, also bump `devstack/docker-compose.yml` so the next local Plugin Check run catches the lag before WP.org does. Followed it this time.

### Changed

- **`readme.txt` "Tested up to"** bumped from `6.9` to `7.0`.
- **`devstack/docker-compose.yml`** WordPress image bumped from `wordpress:6.9-php8.2-apache` to `wordpress:7.0-php8.2-apache`.
- Bumped plugin header `Version` from `0.1.7` to `0.1.8`; bumped `readme.txt` `Stable tag` to `0.1.8`.

## [0.1.7] — 2026-05-22

Restore the Terms of Service / Privacy policy links in `readme.txt`. v0.1.6's response to the WP.org reviewer was over-cautious: when the automated URL probe flagged `cronheart.com/legal/terms` and `cronheart.com/legal/privacy` as HTTP 404, the right fix was to point at the correct paths (`cronheart.com/terms`, `cronheart.com/privacy`, both HTTP 200), not to drop the links entirely. The live legal pages have always been at those shorter paths; the `/legal/*` prefix was simply a wrong assumption I never verified before flagging the URLs as "404 — pages don't exist". v0.1.7 puts the links back, pointing at the right URLs.

No code changes — pings, hooks, admin UI all identical to 0.1.6. Safe to upgrade.

### Changed

- `readme.txt` — restored `[Cronheart.com Terms of Service](https://cronheart.com/terms)` and `[Privacy policy](https://cronheart.com/privacy)` links in the "External services" block (last paragraph). Both URLs verified responding HTTP 200 before commit.
- Bumped plugin header `Version` from `0.1.6` to `0.1.7`; bumped `readme.txt` `Stable tag` to `0.1.7`.

## [0.1.6] — 2026-05-22

WordPress.org Plugin Directory review **round 1** response. The
manual reviewer (volunteer team) flagged three issues against the
v0.1.5 zip; this release clears all three. No behaviour changes —
pings, hooks, admin UI all identical to 0.1.5. Safe to upgrade.

### Changed

- **Removed two `cronheart.com/legal/*` links from `readme.txt`.**
  The reviewer's automated URL probe found that the Terms of
  Service and Privacy Policy URLs at
  `https://cronheart.com/legal/terms` and
  `https://cronheart.com/legal/privacy` both respond with HTTP
  404. The readme's "External services" section already gives a
  complete per-ping data disclosure (UUID in path, optional body
  excerpt capped at 10 KB on fail, User-Agent header) and
  declares the no-telemetry policy, so the standalone legal-page
  links were redundant rather than load-bearing. They'll be
  restored when the corresponding cronheart.com pages go live.
- **`Contributors:` set to `cronmonitor`.** The reviewer's
  static analysis flagged that the previously listed `alexanderpo`
  is not the WordPress.org username that owns the `cronheart`
  plugin slug. The dedicated `cronmonitor` account is the
  WordPress.org identity used for plugin publishing and is now
  the sole declared contributor.
- **Stripped Composer CLI bin shims from the release zip.**
  `bin/build-release.sh` now deletes every `vendor/*/bin/`
  directory before zipping, removing
  `vendor/bin/cron-monitor` and
  `vendor/cron-monitor/php-sdk/bin/cron-monitor` from the
  distributed plugin tree. Those are SDK-side dev tooling
  shims, not runtime artefacts — PSR-4 autoload of the SDK's
  classes is entirely unaffected by their absence. The WP.org
  reviewer specifically called the `cronheart/vendor/bin/*`
  paths out as "not permitted files".
- Bumped plugin header `Version` from `0.1.5` to `0.1.6`; bumped
  `readme.txt` `Stable tag` to `0.1.6`.

## [0.1.5] — 2026-05-20

`Tested up to: 6.9` bump. No code changes — pings, hooks, admin
UI all identical to 0.1.4. Safe to upgrade.

The v0.1.4 submission to the WordPress.org Plugin Directory was
rejected by the automated scan with a single finding:

    readme.txt ERROR: outdated_tested_upto_header:
    Tested up to: 6.7 < 6.9

WordPress.org treats "Tested up to" as a freshness signal — even
when the underlying code is unchanged, plugins whose readme lags
the current stable WP release are excluded from search results.
Our local devstack ran `wordpress:6.7-php8.2-apache`, so the
local Plugin Check passed; the WP.org scan is what surfaced the
gap.

### Changed

- **`readme.txt` "Tested up to"** bumped from `6.7` to `6.9`.
- **`devstack/docker-compose.yml`** WordPress image bumped from
  `wordpress:6.7-php8.2-apache` to `wordpress:6.9-php8.2-apache`,
  so future local Plugin Check runs catch this class of
  "freshness" complaint before WP.org does. Smoke run + Plugin
  Check re-verified green on 6.9.
- Bumped plugin header `Version` from `0.1.4` to `0.1.5`; bumped
  `readme.txt` `Stable tag` to `0.1.5`.

## [0.1.4] — 2026-05-20

Pre-submission cleanup ahead of the WordPress.org Plugin Directory
review. A privacy / hygiene audit of the public repo surfaced
shipping-zip bloat, stale "deferred to v0.1.1+" promises, and a
few inconsistencies with the sister `cron-monitor-php` repo's
license / changelog conventions. No behaviour changes — pings,
hooks, and admin UI all identical to 0.1.3. Safe to upgrade.

### Changed

- **Release-zip distribution scope tightened.** `bin/build-release.sh`
  now strips `CLAUDE.md`, `AGENTS.md`, `CONTRIBUTING.md`,
  `SECURITY.md`, `UPGRADING.md`, `MAINTAINING.md`,
  `CODE_OF_CONDUCT.md`, and stray CI configs (`.scrutinizer.yml`,
  `.travis.yml`, `.circleci`) from vendored packages before
  zipping. The bundled `vendor/cron-monitor/php-sdk/CLAUDE.md`
  in particular has no business in a WordPress-plugin runtime
  bundle — it targets SDK contributors, not WP operators, and
  Plugin Directory reviewers may rightly flag stray AI-tooling
  notes inside a plugin zip as out-of-scope bundling. Third-party
  `LICENSE` / `LICENSE.md` files stay (attribution requirement).
- **`LICENSE` project copyright header.** Added
  `cronheart-wp — Copyright (C) 2026 Alexander Palazok` plus a
  short GPL grant notice before the FSF GPL-2.0 preamble. The
  preamble itself is unchanged; the header makes the licensee
  attribution explicit and matches the convention the sister
  `cron-monitor-php` repo uses on its LICENSE file.
- **CHANGELOG hygiene.** Inserted the missing `## [0.1.1]` section
  header that v0.1.2's release prep accidentally collapsed into
  the `## [0.1.2]` block (the endpoint-override notes were under
  the wrong version). Removed the internal sprint-tracking term
  "Sprint D" from the v0.1.3 entry — that's planning vocabulary,
  not user-facing changelog content.
- **Stale Strauss / php-scoper notes.** Six files carried
  comments saying vendor namespace prefixing was "deferred to
  v0.1.1+ when we submit to wordpress.org". We're at v0.1.4 and
  submitting now, so the promise was misleading. Rewrote them to
  "deferred pending first reported collision" — the actual current
  stance — and dropped the "(v0.1.0)" suffix from the README's
  "Known limitations" heading so the section reads as ongoing
  product policy rather than a frozen historical note. Files
  touched: `cronheart.php`, `bin/build-release.sh`,
  `src/Api/Client.php`, `tests/Unit/Api/ClientTest.php`,
  `tests/bootstrap.php`, `README.md`.

## [0.1.3] — 2026-05-20

WordPress.org Plugin Check pre-flight fixes. A local run of
`wp plugin check cronheart` against the staged v0.1.2 zip
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
