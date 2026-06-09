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
// so prefer this constant over storing it in the database. Requires a
// Starter plan or higher; without it you simply pick monitors by UUID.
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
  the roadmap, not in 0.2.x.
- **No multisite / network-activation handling** yet. The plugin works
  on a single-site install.
- **No Action Scheduler instrumentation** — only WP-Cron hooks are
  monitored. WooCommerce stacks using Action Scheduler for tasks
  will not see those events on the cronheart dashboard yet.
- **The monitor picker covers the site heartbeat only.** Per-event
  monitors are still registered through PHP (`cronheart_monitor()`) or
  `CRONHEART_EVENT_<HOOK>_UUID` constants; the "Monitored events" table
  in Settings → Cronheart is read-only.

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

Two flows depending on whether you have access to the cron-monitor
backend source. **External contributors use flow A (production);
maintainers with backend access can use flow B (local) for
faster iteration and full DB-level assertions.**

#### A. Against production cronheart.com (public contributors)

The cron-monitor backend powering `cronheart.com` is a closed-source
SaaS — public contributors cannot run a local copy. The default
end-to-end verification path is therefore against the production
service. It still validates the full plugin → SDK → wire-contract
→ backend pipeline; only the DB-level assertion is replaced with a
dashboard check.

```bash
# 1. Sign up at https://cronheart.com and create two monitors:
#    one for the site heartbeat, one for a test per-event hook.
#    Copy each UUID from the dashboard.

# 2. Build the plugin zip:
./bin/build-release.sh

# 3. Bring up WordPress + MySQL + WP-CLI (cronheart.com is the
#    backend; no local backend network needed):
docker compose -f devstack/docker-compose.yml up -d

# 4. Drive the smoke with your real UUIDs (HEARTBEAT and EVENT
#    accept any UUID v4; smoke.sh reads them from these env vars).
#    The script auto-detects "prod" mode when CRONHEART_LOCAL_BACKEND
#    is unset and skips the DB-side assertion.
HEARTBEAT_UUID=<your-heartbeat-uuid> \
EVENT_UUID=<your-event-uuid> \
    ./devstack/smoke.sh

# 5. Verify on the cronheart.com dashboard that the expected
#    heartbeat / start / success pings arrived.

# 6. Tear down:
docker compose -f devstack/docker-compose.yml down -v
```

#### B. Against a local cron-monitor backend (maintainers only)

This flow requires checked-out access to the closed-source
`cron-monitor` backend repository (sibling directory
`../cron-monitor`). Outside the cronheart maintainer team you do
not have access — use flow A above.

The advantage of the local flow is full DB-level assertion: the
smoke script reads back from the `pings` table and fails loudly if
the expected rows are missing.

```bash
# 1. Bring up the cronheart backend (private sibling repo —
#    maintainers only):
cd ../cron-monitor && make up && cd -

# 2. Pre-register the two smoke monitors in the cronheart DB —
#    these UUIDs are what smoke.sh defaults to in local mode:
docker compose -f ../cron-monitor/docker-compose.yml exec -T db \
    mysql -uapp -papp cronmonitor -e "
INSERT IGNORE INTO monitors (project_id, uuid, name, schedule_kind, schedule_expr, tz, grace_seconds, status, created_at) VALUES
  (2, UNHEX(REPLACE('11111111-1111-4111-8111-111111111111', '-', '')), 'cronheart-wp smoke: heartbeat', 'interval', '300', 'UTC', 60, 'new', NOW()),
  (2, UNHEX(REPLACE('22222222-2222-4222-8222-222222222222', '-', '')), 'cronheart-wp smoke: per-event', 'cron',     '0 2 * * *', 'UTC', 60, 'new', NOW());"

# 3. Build the plugin zip:
./bin/build-release.sh

# 4. Bring up WordPress + MySQL + WP-CLI joined to the cronheart
#    backend's Docker network. The `docker-compose.local.yml`
#    override layers in the external network reference — without it
#    the base compose runs in prod-mode and won't resolve the local
#    backend.
docker compose \
    -f devstack/docker-compose.yml \
    -f devstack/docker-compose.local.yml \
    up -d

# 5. Run smoke in local-backend mode — DB assertion enabled:
CRONHEART_LOCAL_BACKEND=1 ./devstack/smoke.sh

# 6. Tear down WordPress (keeps cronheart backend running):
docker compose \
    -f devstack/docker-compose.yml \
    -f devstack/docker-compose.local.yml \
    down -v
```

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Mandated by WordPress.org;
the embedded `cron-monitor/php-sdk` is MIT-licensed and GPL-compatible.
