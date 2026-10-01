# Cronheart for WordPress

Official WordPress plugin for [cronheart.com](https://cronheart.com) —
detect when WP-Cron silently stops firing and when individual
scheduled events fail to complete.

[![CI](https://github.com/alexander-po/cronheart-wp/actions/workflows/ci.yml/badge.svg)](https://github.com/alexander-po/cronheart-wp/actions/workflows/ci.yml)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](LICENSE)

## Why

WP-Cron is request-driven. On a low-traffic site no requests arrive, no
events fire, and a scheduled backup can be stalled for weeks before
anyone notices. Uptime monitors do not catch this — the site responds
to HTTPS just fine, it just is not running its jobs. Cronheart turns
WP-Cron into a dead-man switch: the plugin pings cronheart.com every
five minutes and on every individual event you register; if the pings
stop, cronheart alerts you.

## What's in the box

- **Site heartbeat** — a 5-minute custom WP-Cron event whose only job
  is to ping cronheart. Proves WP-Cron itself is alive and firing.
- **Per-event monitoring** — register any scheduled hook for
  start/success/fail pings:
  ```php
  cronheart_monitor( 'my_nightly_report', 'xxxxxxxx-…' );
  ```
- **`#[CRONHEART_*]` constants** — keep the per-monitor UUID (a write
  capability secret) out of the database and out of git history by
  defining it in `wp-config.php`:
  ```php
  define( 'CRONHEART_HEARTBEAT_UUID',  getenv( 'CRONHEART_HEARTBEAT_UUID' ) );
  define( 'CRONHEART_EVENT_MY_NIGHTLY_REPORT_UUID', getenv( 'CRONHEART_NIGHTLY_UUID' ) );
  ```
- **Admin UI** at `Settings → Cronheart` for sites without
  `wp-config.php` access.
- **Monitor picker** (since v0.2.0) — paste a cronheart.com API token
  (or define `CRONHEART_API_TOKEN` in `wp-config.php`) and the
  heartbeat field becomes a dropdown of your account's monitors
  instead of a hand-typed UUID. Entirely optional and gracefully
  degrading: no token — or any API error — falls back to the manual
  UUID field. The token is write-only in the UI and is never carried
  on the runtime ping path.
- **Never breaks WP-Cron** — every network / HTTP failure is folded
  into a logged warning. A broken cronheart backend cannot punish
  the host scheduler.
- **PHP fatal capture** — when a scheduled callback fatals, the fail
  ping body includes the `error_get_last()` summary so the cronheart
  dashboard shows the cause without you tailing `debug.log`.

## Install

### From WordPress.org (recommended)

WP Admin → **Plugins → Add New** → search **"Cronheart"** →
**Install Now → Activate**. Or download `cronheart.zip` from the
[WordPress.org plugin page](https://wordpress.org/plugins/cronheart/)
or a [GitHub release](https://github.com/alexander-po/cronheart-wp/releases)
and upload it under **Plugins → Add New → Upload Plugin**.

Then create a monitor on [cronheart.com](https://cronheart.com), copy
the UUID, and either:

- Add it to `wp-config.php`:
  ```php
  define( 'CRONHEART_HEARTBEAT_UUID', 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx' );
  ```
- Or set it under **Settings → Cronheart** — paste the UUID, or, with
  an API token configured, pick the monitor from the dropdown.

### Composer (developers)

```bash
composer require cronheart/wp
```

### Requirements

- WordPress ≥ 6.0
- PHP ≥ 8.2
- `ext-curl` (every reasonable WP install has it on)

## Configuration

### Source precedence

For both the heartbeat and per-event UUIDs:

| Precedence | Source                                          | Recommended for     |
|------------|-------------------------------------------------|---------------------|
| 1 (highest)| `wp-config.php` constant                        | Production          |
| 2          | WordPress option (admin UI)                     | Hosted environments |
| 3 (lowest) | `cronheart_monitor_map` filter (`cronheart_monitor()`) | Plugin developers |

An empty string at any level is treated as an explicit "do not
monitor in this environment" signal — useful when the same plugin is
deployed across dev / staging / prod and only prod should ping.

### Constants

```php
// wp-config.php

// Site heartbeat — recommended for production.
define( 'CRONHEART_HEARTBEAT_UUID', getenv( 'CRONHEART_HEARTBEAT_UUID' ) ?: '' );

// Per scheduled event. Hook name is uppercased, `-`/`.`/`:` → `_`.
// e.g. for the hook `my:nightly-report`:
define( 'CRONHEART_EVENT_MY_NIGHTLY_REPORT_UUID', getenv( 'NIGHTLY_UUID' ) ?: '' );

// Optional: point the plugin at a non-production cronheart deployment.
// Defaults to https://cronheart.com.
define( 'CRONHEART_ENDPOINT', 'https://staging.cronheart.example.com' );

// Optional: allow plain http:// endpoints (default false). Required for
// local-dev backends behind host.docker.internal or private VPNs that
// do not terminate TLS. NEVER set this with a public http:// endpoint —
// the monitor UUID leaks over the network in clear text.
define( 'CRONHEART_ALLOW_INSECURE_ENDPOINT', true );

// Optional: cronheart.com account API token (cmk_…) to enable the
// monitor picker on the settings page. Account-level and write-capable,
// so prefer this constant over storing it in the database. Every plan,
// the free one included, can use the API; the account email must be
// verified to create a token. Without a token you pick monitors by UUID.
define( 'CRONHEART_API_TOKEN', getenv( 'CRONHEART_API_TOKEN' ) ?: '' );
```

### Per-event helper

```php
// In your plugin / theme / mu-plugin:

add_action( 'plugins_loaded', function () {
    cronheart_monitor( 'my_nightly_report', 'xxxxxxxx-…' );

    // Or, with the UUID coming from a constant:
    cronheart_monitor( 'my_other_event' );
} );
```

> **Timing constraint.** Hook enumeration runs at the very end of
> `plugins_loaded` (priority `PHP_INT_MAX`), so `cronheart_monitor()`
> calls **must register from `plugins_loaded` or earlier** — calls
> made from `init` or any later hook are missed by the
> instrumentation. Direct top-level calls in a mu-plugin or in your
> plugin's main file (before any `add_action`) are also fine.

> **Fail-ping reliability.** Per-event `fail` pings are best-effort.
> The shutdown handler runs on PHP fatal errors, but the outbound
> HTTP request may not complete if PHP terminates abruptly (out-of-
> memory, segfault, FPM hard kill). Most fatals will surface on the
> cronheart dashboard; some edge cases will show as "silent stop"
> instead — at which point the **heartbeat** layer catches that the
> WP-Cron run itself never completed.

## For coding agents

`skills/add-cronheart/SKILL.md` is a step-by-step recipe an AI coding
agent (Claude Code, Cursor, Codex and the like) follows to add Cronheart
to a WordPress site: install the plugin, attach the heartbeat monitor,
add per-event monitors, connect a token for the admin screens, move
WP-Cron onto a system cron and verify the first ping. It uses
placeholders only — a real monitor UUID or API token never belongs in a
repository. Copy the directory into your project's `.claude/skills/`, or
point the agent at the file, and ask it to add Cronheart to the site.
`AGENTS.md` at the repository root is the pointer agents read first.
Neither ships in the WordPress.org zip: `bin/build-release.sh` strips
`skills/` from vendored packages and refuses to package the root copies.

## Known limitations

- **Vendor namespace prefixing is deferred.** Today the bundled SDK
  ships under its canonical `CronMonitor\…` namespace; we'll
  reach for Strauss / php-scoper if a real collision is reported in
  the wild, at which point the SDK relocates to
  `Cronheart\WP\Vendor\CronMonitor\…`. Conflict risk is low because
  no other WP plugin currently bundles `cron-monitor/php-sdk`.
  **Do not depend on the `CronMonitor\…` namespace from outside
  this plugin** (e.g. another plugin reading our autoload) — that
  surface may move in a future minor release without warning.
- **No WP-CLI commands** yet — `wp cronheart status` / `sync` are on
  the roadmap.
- **No multisite / network-activation handling** yet. The plugin works
  on a single-site install.
- **No Action Scheduler instrumentation** — only WP-Cron hooks are
  monitored. WooCommerce stacks using Action Scheduler for tasks
  will not see those events on the cronheart dashboard yet.

## Companion projects

- [`cron-monitor/php-sdk`](https://github.com/alexander-po/cron-monitor-php)
  — the underlying PHP SDK this plugin wraps (also available
  standalone for Symfony / Laravel / plain-PHP cron jobs).
- [cronheart.com](https://cronheart.com) — the SaaS backend.

## Development

```bash
# Tests
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit

# PHPStan (level 8)
docker run --rm -v "$PWD":/app -w /app php:8.2-cli \
    php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress

# php-cs-fixer (internal SDK-style code)
docker run --rm -v "$PWD":/app -w /app -e PHP_CS_FIXER_IGNORE_ENV=1 \
    php:8.2-cli vendor/bin/php-cs-fixer fix --dry-run --diff

# phpcs (WordPress Coding Standards on user-facing PHP)
docker run --rm -v "$PWD":/app -w /app php:8.2-cli \
    vendor/bin/phpcs --standard=.phpcs.xml.dist

# Build the distributable zip
./bin/build-release.sh
# → build/cronheart.zip
```

CI runs all four checks plus `composer validate --strict` and
`composer audit` on PHP 8.2 / 8.3 / 8.4.

### End-to-end smoke testing

`devstack/smoke.sh` brings up a throwaway WordPress, installs the
built plugin into it, fires the heartbeat tick and a test per-event
hook, and checks that the backend recorded the pings. The check reads
each monitor's ping history through the public REST API
(`GET /api/v1/monitors/<uuid>/pings`), so it needs an API token; it
reads the history before the stack starts, so a wrong token or UUID
fails within seconds, reads it again after the run (up to five times,
three seconds apart) and fails unless the heartbeat monitor gained a
`heartbeat` ping and the per-event monitor a `start` and a `success`.
Without a token the script stops short of the check and prints what to
look for on the dashboard.

#### A. Against production cronheart.com (public contributors)

```bash
# 1. Sign up at https://cronheart.com and create two monitors:
#    one for the site heartbeat, one for a test per-event hook.
#    Copy each UUID, and create an API token (Account → API tokens).

# 2. Build the plugin zip:
./bin/build-release.sh

# 3. Drive the smoke; it brings up WordPress + MySQL + WP-CLI itself.
#    The token is read without echo, kept out of shell history and
#    passed to this run only (skip it to verify on the dashboard
#    instead):
read -rs CRONHEART_API_TOKEN
CRONHEART_API_TOKEN="$CRONHEART_API_TOKEN" \
HEARTBEAT_UUID=<your-heartbeat-uuid> \
EVENT_UUID=<your-event-uuid> \
    ./devstack/smoke.sh

# 4. Tear down:
docker compose -f devstack/docker-compose.yml down -v
unset CRONHEART_API_TOKEN
```

#### B. Against a backend on a local Docker network (maintainers only)

The same script and the same API check, pointed at a backend that
runs in Docker next to the devstack. Bring the backend up the way its
own repository documents, create the two monitors and an API token on
it, then pass its network to the smoke so the devstack joins it.

```bash
# 1. Build the plugin zip:
./bin/build-release.sh

# 2. Drive the smoke against the backend's address on its Docker
#    network; the smoke brings up WordPress + MySQL + WP-CLI joined to
#    that network. Pass the network per run rather than exporting it,
#    so a later production run does not join it too. The script refuses
#    to send a token without TLS unless told to; only do that with a
#    throwaway token on the local backend:
CRONHEART_BACKEND_NETWORK=<backend-docker-network> \
CRONHEART_ENDPOINT=<http://backend-host-on-that-network> \
HEARTBEAT_UUID=<heartbeat-uuid> \
EVENT_UUID=<event-uuid> \
CRONHEART_API_TOKEN=<throwaway-api-token> \
CRONHEART_ALLOW_INSECURE_TOKEN=1 \
    ./devstack/smoke.sh

# 3. Tear down WordPress (leaves the backend running):
CRONHEART_BACKEND_NETWORK=<backend-docker-network> docker compose \
    -f devstack/docker-compose.yml \
    -f devstack/docker-compose.local.yml \
    down -v
```

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Mandated by WordPress.org;
the embedded `cron-monitor/php-sdk` is MIT-licensed and GPL-compatible.
