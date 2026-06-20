=== Cronheart ===
Contributors: cronheart
Tags: cron, wp-cron, monitoring, healthcheck, deadman-switch
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.2
Stable tag: 0.4.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Dead-man-switch monitoring for WP-Cron. Get alerted when scheduled events stop firing — uptime monitors do not catch this.

== Description ==

**WP-Cron is request-driven.** On a low-traffic site no requests arrive, no events fire, and a scheduled backup can be stalled for weeks before anyone notices. Uptime monitors do not catch this — the site responds to HTTPS just fine, it just is not running its jobs.

Cronheart turns WP-Cron into a **dead-man switch**: the plugin pings [cronheart.com](https://cronheart.com) every five minutes and on every individual event you register. If the pings stop, cronheart alerts you via email, Telegram, Slack, Discord, or a custom webhook.

= What it does =

* **Site heartbeat.** A 5-minute custom WP-Cron event whose only job is to ping cronheart. Proves WP-Cron itself is alive on this site.
* **Per-event monitoring.** Register any scheduled hook for start / success / fail pings with one PHP one-liner: `cronheart_monitor( 'my_nightly_report', 'xxxxxxxx-…' );`
* **PHP fatal-error capture.** When a scheduled callback fatals or throws, the fail-ping body includes the `error_get_last()` summary — the cronheart dashboard shows the cause without you tailing `debug.log`.
* **Settings page.** A read-only "Monitored events" table at Settings → Cronheart shows every hook the plugin is watching and where its UUID came from (constant, option, filter).
* **Monitor picker.** Save a cronheart.com API token and the site heartbeat field becomes a dropdown of your account's monitors instead of a hand-typed UUID. Entirely optional — without a token you paste the UUID as before, and any API hiccup falls back to that field. The token is write-only and never leaves wp-admin.
* **Account overview and monitor management.** With a token configured, Settings → Cronheart shows your plan and monitor budget, and a "Your monitors" table listing each monitor with its status and any active snooze. From that table you can pause, resume, snooze (1 hour, 4 hours, 1 day, or 1 week), or unsnooze a monitor; the change applies on cronheart.com immediately. Every action is an authenticated administrator request — nothing happens without your click.
* **Per-event monitoring UI.** A new Settings → Cronheart Events screen lists the recurring WP-Cron events on your site and lets you, per event, either assign one of your monitors from a dropdown or auto-create an interval monitor for it in one click — no code required. This is the point-and-click alternative to the `cronheart_monitor()` helper and `CRONHEART_EVENT_<HOOK>_UUID` constants (both still work and take precedence).
* **Configuration through `wp-config.php` constants** for production (`CRONHEART_HEARTBEAT_UUID`, `CRONHEART_EVENT_<HOOK>_UUID`), with admin-UI fallback for sites where editing `wp-config.php` is not practical.

= Never breaks WP-Cron =

The plugin's hard contract: a broken cronheart backend, an unreachable network, a misbehaving PSR-18 HTTP client — none of them may cause WP-Cron to fail. Every network / HTTP error is swallowed into a logged warning. If cronheart goes down for a day, your `wp_schedule_event` callbacks still run normally; you just stop seeing pings on the dashboard.

= External services =

This plugin sends HTTP requests to [cronheart.com](https://cronheart.com) in two distinct situations: the monitoring pings your scheduled jobs send, and the account-management calls the admin settings page makes. Both are opt-in: without configuration the plugin loads and does nothing — no telemetry, no usage statistics, no anonymous reports.

**1. Monitoring pings (front end / WP-Cron).** Sent on every scheduled WP-Cron run, but **only when you supply a monitor UUID**. The exact data sent per ping:

* The per-monitor UUID you configured (path segment).
* A short body excerpt — capped at 10 KB — containing either an exception summary (for `fail` pings) or nothing (for `start` / `success` / `heartbeat`).
* The plugin / SDK version in a `User-Agent` header.

**2. Account management (wp-admin only).** When — and only when — you save a cronheart.com API token, the **Cronheart admin screens** (Settings → Cronheart and Settings → Cronheart Events) talk to the cronheart.com management API at `https://cronheart.com/api/v1/...`. Every such request carries the token as an `Authorization: Bearer` header and runs **only while a logged-in administrator is on one of those screens** — and, for the write actions below, only when that administrator clicks the control. Never on the front end, during WP-Cron, or in any other context. No token, no request. The calls are:

* **Read your monitors** — `GET /api/v1/monitors` — to populate the heartbeat picker, the "Your monitors" table, and the per-event assignment dropdowns. Sends nothing beyond the token.
* **Read your account** — `GET /api/v1/account` — to show your plan, monitor budget, and API rate-limit standing. Sends nothing beyond the token.
* **Lifecycle actions** — `POST /api/v1/monitors/<uuid>/pause` (or `/resume`, `/snooze`, `/unsnooze`) — sent when you click a pause / resume / snooze / unsnooze button. Sends the monitor's UUID (in the path) and the action; snooze also sends the chosen duration (1 hour, 4 hours, 1 day, or 1 week).
* **Create a monitor** — `POST /api/v1/monitors` — sent when you click "Auto-create & assign" for a recurring event on the Cronheart Events screen. Sends the event's hook name (as the monitor name), its schedule as an interval in seconds, the site timezone, and a grace period — all derived from the WP-Cron schedule.

The lifecycle and create calls are the only requests that change anything on cronheart.com, and each is one deliberate click. The token is optional: without it the plugin makes none of these management calls — you assign monitors by hand (or via the constants / helper) and only the monitoring pings above are ever sent.

[Cronheart.com Terms of Service](https://cronheart.com/terms) · [Privacy policy](https://cronheart.com/privacy)

= Open source =

Source code and issue tracker: [github.com/alexander-po/cronheart-wp](https://github.com/alexander-po/cronheart-wp).

The plugin wraps the [`cron-monitor/php-sdk`](https://github.com/alexander-po/cron-monitor-php) PHP package (also open source, MIT-licensed). Both projects are maintained independently.

== Installation ==

1. Install the plugin: **WP Admin → Plugins → Add New →** search for "Cronheart" → **Install Now → Activate.** Or upload `cronheart.zip` from a GitHub release.
2. Sign up at [cronheart.com](https://cronheart.com) and create a monitor for your site's heartbeat. Copy the monitor UUID from the dashboard.
3. Configure the UUID. Either add it to `wp-config.php` with `define( 'CRONHEART_HEARTBEAT_UUID', 'xxxxxxxx-…' );` (recommended), or paste it under **Settings → Cronheart** in the WP admin.
4. Done. Within five minutes you should see the first `heartbeat` ping on the cronheart dashboard.
5. *(Optional)* To choose a monitor from a dropdown instead of pasting its UUID, create a Personal Access Token at cronheart.com (**Settings → API Tokens**) and save it under **Settings → Cronheart**. API access requires a Starter plan or higher; the plugin works fully on the free tier without a token. For production, prefer `define( 'CRONHEART_API_TOKEN', 'cmk_…' );` in `wp-config.php` to keep the account credential out of the database.

For per-event monitoring (a specific scheduled hook, not just the site heartbeat), register the hook from a plugin / theme / mu-plugin:

`add_action( 'plugins_loaded', function () {
    cronheart_monitor( 'my_nightly_report', 'xxxxxxxx-…' );
}, 1 );`

The hook then emits `start` / `success` (or `fail` on a fatal / thrown exception) pings on every scheduled run.

== Frequently Asked Questions ==

= Does this work when WP-Cron is disabled (system-cron mode)? =

Yes. If you set `define( 'DISABLE_WP_CRON', true );` and trigger `wp-cron.php` from a real system cron, the plugin's `heartbeat_tick` action still fires on each run — the trigger mechanism is different, the action chain is the same.

= What if my host blocks outgoing HTTPS? =

The plugin will retry once (built-in retry budget) and then log a warning to `debug.log`. Your scheduled callbacks still run normally — the plugin never raises an exception that could break the cron runner. To diagnose, check `wp-content/debug.log` for entries beginning with "cron-monitor".

= Do I need a paid cronheart.com account? =

No. Cronheart's free tier covers 20 monitors per account — enough for a typical site's heartbeat plus several per-event monitors. Paid tiers (Starter / Growth / Scale) raise the cap and unlock additional notification channels.

= Do I need an API token? =

No — it is entirely optional. Paste a monitor UUID under Settings → Cronheart (or define it in `wp-config.php`) and the plugin works on any plan, including the free tier. A token only adds convenience: the settings page can then list your monitors and let you pick one from a dropdown instead of copying a UUID by hand. The token is an account-level credential, so for production prefer defining `CRONHEART_API_TOKEN` in `wp-config.php` over storing it in the database. The picker (API access) requires a Starter plan or higher; if your plan does not include it the page shows a notice and falls back to manual UUID entry.

= Where do I find my monitor UUID? =

Sign in at [cronheart.com](https://cronheart.com), open the monitor you created, and copy the UUID from the address bar or the "Ping URL" block on the monitor page.

= What happens to my scheduled jobs if cronheart.com is unreachable? =

Nothing. The plugin catches every network / HTTP error from the SDK and logs a warning — your `wp_schedule_event` callbacks continue to run. You will stop seeing pings on the cronheart dashboard, and after the configured grace period cronheart sends you the down-alert. When cronheart comes back the next successful ping resolves the incident automatically.

= Does the plugin track or report anything about my site? =

No. The plugin sends a ping to cronheart only when you have configured a monitor UUID. The ping payload is the UUID, an optional short body excerpt (capped at 10 KB), and the SDK's `User-Agent` header. There is no anonymous-statistics beacon, no plugin-usage telemetry, no calls to any third-party analytics service.

= Can I point the plugin at a non-production cronheart deployment (staging / private / self-hosted)? =

Yes. Define `CRONHEART_ENDPOINT` in `wp-config.php` with the URL of your alternate deployment. For plain `http://` endpoints (local development, private VPNs without TLS) also set `CRONHEART_ALLOW_INSECURE_ENDPOINT` to `true`. With both unset, the plugin pings the production cronheart.com over HTTPS.

= Where can I report bugs or request features? =

Open an issue on [GitHub](https://github.com/alexander-po/cronheart-wp/issues).

== Screenshots ==

1. The Cronheart settings page in WP admin: the cronheart.com connection section with the account plan and monitor-budget card, and the site-heartbeat monitor picker.
2. The "Your monitors" table on the settings page — each monitor's status and any active snooze, with pause, resume, snooze, and unsnooze actions applied straight to cronheart.com.
3. The Cronheart Events screen — the site's recurring WP-Cron events, each with a dropdown to assign a monitor or an "Auto-create & assign" button.

== Changelog ==

= 0.4.0 =
* **Per-event monitoring UI.** A new Settings → Cronheart Events screen lists the site's recurring WP-Cron events and lets you, per event, assign one of your monitors from a dropdown or auto-create an interval monitor for it in one click — the point-and-click alternative to the `cronheart_monitor()` helper and `CRONHEART_EVENT_<HOOK>_UUID` constants (both still work and still take precedence).
* **Auto-create.** "Auto-create & assign" derives an interval monitor from the event's WP-Cron schedule — name from the hook, interval in seconds, site timezone, and a grace period — within cronheart.com's accepted ranges, and is offered only for unmapped, recurring events. It is idempotency-keyed so a double click is safe.
* The assign / auto-create actions go through the same admin-AJAX contract as 0.3.0 (nonce, `manage_options`, boundary validation, no public endpoint); the request hook is always validated against the discovered event set, and assigning a monitor is a local option write that needs no token. The live controls are token-gated like the heartbeat picker; without a token the screen is a read-only view.
* The "External services" disclosure now also covers the create call (`POST /api/v1/monitors`), sent only when you click "Auto-create & assign".

= 0.3.0 =
* **Account overview.** With a cronheart.com API token configured, Settings → Cronheart now shows your plan, monitor budget (used / limit / remaining), and API rate-limit standing, with an upgrade nudge when you are near your monitor limit.
* **Monitor management from wp-admin.** A new "Your monitors" table lists each monitor with its status and any active snooze, and lets you pause, resume, snooze (1 hour / 4 hours / 1 day / 1 week), or unsnooze it — applied on cronheart.com immediately. This is the plugin's first admin-AJAX surface: every action is nonce-checked, capability-gated (manage_options), validated at the boundary, and degrades to a readable message rather than a fatal if the API call fails. No public (nopriv) endpoint is registered.
* **Monitor status in the picker.** The heartbeat dropdown now shows each monitor's status alongside its name.
* Upgraded the bundled `cron-monitor/php-sdk` to `^1.1`, which adds the account and monitor-lifecycle endpoints these features use.
* The "External services" disclosure has been rewritten to cover the new read (`GET /api/v1/account`) and write (`POST /api/v1/monitors/<uuid>/{pause,resume,snooze,unsnooze}`) calls; all remain wp-admin-only, token-gated, and triggered by an administrator's explicit action.

= 0.2.1 =
* Documentation and screenshots for the monitor picker. No functional change from 0.2.0 — the plugin code is identical; this release refreshes the readme ("What it does", external-services disclosure, FAQ) and adds an updated settings-page screenshot showing the picker.

= 0.2.0 =
* **Monitor picker.** Save a cronheart.com API token under Settings → Cronheart and the heartbeat field becomes a dropdown of your monitors instead of a free-text UUID box. The selection still saves to the same `cronheart_heartbeat_uuid` option, so nothing changes about how pings are sent — only how you fill in the UUID.
* **Write-only API token field.** The token is never echoed back into the page; it can be set in the database or, preferred for production, via a new `CRONHEART_API_TOKEN` constant in `wp-config.php`.
* **wp-admin-only API call.** Listing your monitors happens only on the settings page, only when a token is configured, and never on the front end or during WP-Cron. The runtime ping path is unchanged and carries no account credential. See the updated "External services" disclosure.
* **Graceful fallback.** If the listing fails — no API access on your plan, an invalid token, a rate limit, or a network error — the page shows a notice and falls back to manual UUID entry. The admin page never fatals.
* Upgraded the bundled `cron-monitor/php-sdk` to `^1.0`, which adds the authenticated management-API client the picker uses.

= 0.1.9 =
* Plugin Directory review round 2 fix. `Contributors:` changed from `cronmonitor` to `cronheart` — the reviewer's static analysis pointed out that the WordPress.org account that actually owns the `cronheart` plugin slug (and uploaded every version including v0.1.8) is `cronheart`, not `cronmonitor`. v0.1.7's switch to `cronmonitor` was a wrong guess at the right owner identity; v0.1.9 puts the actual slug owner in the contributors line.
* No code changes.

= 0.1.8 =
* Bump "Tested up to" from 6.9 to 7.0. The v0.1.7 re-upload was rejected by WP.org's automated scan because WordPress 7.0 had shipped between our v0.1.5 submission and the v0.1.7 re-upload, and the "Tested up to" header now lagged again. Devstack also moved to `wordpress:7.0-php8.2-apache`; smoke run + Plugin Check re-verified green on 7.0.
* No code changes.

= 0.1.7 =
* Restored Terms of Service / Privacy policy links in the readme. The URLs the WP.org reviewer flagged as HTTP 404 in v0.1.5 (`cronheart.com/legal/terms`, `cronheart.com/legal/privacy`) were wrong paths — the live pages have always been at `cronheart.com/terms` and `cronheart.com/privacy`. v0.1.6 removed the links entirely as the most cautious response to the review feedback; v0.1.7 puts them back, pointing at the correct URLs (both return HTTP 200).
* No code changes.

= 0.1.6 =
* Plugin Directory review round 1 fixes. No behaviour changes — pings, hooks, admin UI all identical to 0.1.5.
* Removed two `cronheart.com/legal/*` links from the readme that responded with HTTP 404. The "External services" section in this readme already provides a full data-flow disclosure; stand-alone Terms / Privacy pages will be linked back when the corresponding cronheart.com URLs are live.
* `Contributors:` set to `cronmonitor` (the WordPress.org account that submitted the plugin); previously held a stale GitHub handle (`alexanderpo`) that did not match any WP.org user.
* Release zip no longer ships `vendor/bin/cron-monitor` or `vendor/cron-monitor/php-sdk/bin/cron-monitor` — those CLI binaries are part of the SDK's local-dev tooling and have no use inside a WordPress plugin. `bin/build-release.sh` now strips every `vendor/*/bin/` directory at zip time. PSR-4 autoload of the SDK's runtime classes is unaffected.

= 0.1.5 =
* Bump "Tested up to" from 6.7 to 6.9. WordPress.org's automated scan blocks submission when the readme's "Tested up to" lags the current stable WordPress release, even when the underlying code is unchanged — the field is treated as a freshness signal for the Plugin Directory search. Devstack also moved to `wordpress:6.9-php8.2-apache`; smoke run + Plugin Check re-verified green on 6.9.
* No code changes.

= 0.1.4 =
* Pre-submission cleanup before the WordPress.org Plugin Directory review. No behaviour changes — pings, hooks, and admin UI all identical to 0.1.3.
* Release zip no longer ships `CLAUDE.md` and similar contributor-only docs from vendored packages; the bundled tree is now scoped to what the runtime actually needs.
* `LICENSE` gained an explicit project copyright header (`cronheart-wp — Copyright (C) 2026 Alexander Palazok`); the GPL-2.0 preamble follows unchanged.
* CHANGELOG.md hygiene: missing `[0.1.1]` section header restored; internal sprint-tracking term ("Sprint D") removed from the public 0.1.3 entry; stale "deferred to v0.1.1+" notes on vendor namespace prefixing rewritten to reflect the current "deferred pending first reported collision" stance.

= 0.1.3 =
* WordPress.org Plugin Check fixes: added `defined('ABSPATH')` direct-access guards to every PHP file the static analyser reaches; refactored the monitored-events table render so the escape calls are direct printf arguments (the previous pre-assigned variable was flagged by EscapeOutput); shipped `composer.json` / `composer.lock` alongside `vendor/` in the release zip so the bundled dependencies are reproducible.
* No behaviour changes — pings, hooks, and admin UI all identical to 0.1.2.

= 0.1.2 =
* WordPress.org submission readiness: full readme.txt (Description, FAQ, Screenshots, External-services disclosure), version bump from 0.1.1.
* No code changes — pure metadata polish for the Plugin Directory submission.

= 0.1.1 =
* Endpoint override: `CRONHEART_ENDPOINT` constant and `cronheart_endpoint` option for pointing the plugin at a non-production cronheart deployment (staging, private VPC, local backend).
* `CRONHEART_ALLOW_INSECURE_ENDPOINT` constant / `cronheart_allow_insecure_endpoint` option to opt into plain `http://` endpoints (required for local backends behind `host.docker.internal` or TLS-less private VPNs; default false).
* Local end-to-end smoke harness under `devstack/` for verifying the plugin against either production cronheart.com (public contributors) or a local cron-monitor backend (maintainers).
* No breaking changes — installs without the new constants keep the v0.1.0 behaviour.

= 0.1.0 =
* Initial scaffold (GitHub-only release; WP.org submission deferred to v0.1.2+).
* Site-wide heartbeat layer with a 5-minute custom schedule.
* Per-event monitoring with `cronheart_monitor()` helper and `cronheart_monitor_map` filter.
* `CRONHEART_HEARTBEAT_UUID` and `CRONHEART_EVENT_<HOOK>_UUID` constants for sourcing UUIDs from `wp-config.php`.
* Admin page at Settings → Cronheart for sites without `wp-config.php` access.
* PHP fatal-error capture for the fail-ping body.

== Upgrade Notice ==

= 0.4.0 =
Adds a Cronheart Events screen to assign or auto-create monitors for your recurring WP-Cron events from wp-admin — no code needed. Same admin-AJAX safety as 0.3.0; the constants and helper still work and take precedence. No token required; the runtime ping path is unchanged. Safe to upgrade.

= 0.3.0 =
Adds an account/plan card and a "Your monitors" table to pause, resume, snooze, or unsnooze monitors from wp-admin — the plugin's first admin-AJAX endpoint, nonce- and capability-gated. No token required; the runtime ping path is unchanged. Safe to upgrade.

= 0.2.1 =
Documentation + screenshot refresh for the 0.2.0 monitor picker. No code change. Safe to upgrade.

= 0.2.0 =
Adds an optional monitor picker: save a cronheart.com API token and choose your heartbeat monitor from a dropdown instead of pasting a UUID. No token required — manual UUID entry works exactly as before. The runtime ping path is unchanged. Safe to upgrade.

= 0.1.9 =
Plugin Directory review round 2 metadata fix (Contributors set to the slug owner). No code changes. Safe to upgrade.

= 0.1.8 =
"Tested up to" bump to 7.0. No code changes. Safe to upgrade.

= 0.1.7 =
Restored Terms / Privacy links with the correct URLs (cronheart.com/terms and /privacy). No code changes.

= 0.1.6 =
Plugin Directory review round 1 metadata fixes. No code changes. Safe to upgrade.

= 0.1.5 =
"Tested up to" bump to 6.9. No code changes. Safe to upgrade.

= 0.1.4 =
Pre-submission cleanup only — no behaviour changes. Safe to upgrade.

= 0.1.3 =
WordPress.org Plugin Check fixes only — no behaviour changes. Safe to upgrade.

= 0.1.2 =
WordPress.org metadata polish only. Safe to upgrade.

= 0.1.1 =
Adds opt-in endpoint override. Existing installs are unaffected — the default endpoint remains https://cronheart.com.
