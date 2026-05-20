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
- **Never breaks WP-Cron** — every network / HTTP failure is folded
  into a logged warning. A broken cronheart backend cannot punish
  the host scheduler.
- **PHP fatal capture** — when a scheduled callback fatals, the fail
  ping body includes the `error_get_last()` summary so the cronheart
  dashboard shows the cause without you tailing `debug.log`.

## Install

### Manual (v0.1.0)

1. Download the latest `cronheart.zip` from the
   [GitHub releases page](https://github.com/alexander-po/cronheart-wp/releases).
2. WP Admin → **Plugins → Add New → Upload Plugin** → select
   `cronheart.zip`.
3. Activate.
4. Create a monitor on [cronheart.com](https://cronheart.com), copy
   the UUID, and either:
   - Add it to `wp-config.php`:
     ```php
     define( 'CRONHEART_HEARTBEAT_UUID', 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx' );
     ```
   - Or paste it under **Settings → Cronheart** in wp-admin.

WP.org plugin-directory submission is deferred to v0.1.1+ — we are
iterating the API on early GitHub adopters first.

### Composer (developers)

Not yet published on Packagist for v0.1.0; track this repo and ship
a release once the API stabilises.

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

## Known limitations (v0.1.0)

- **Vendor namespace prefixing is deferred to v0.1.1+.** Today the
  bundled SDK ships under its canonical `CronMonitor\…` namespace.
  When we re-enable Strauss / php-scoper for the WordPress.org
  submission, the SDK will be relocated to
  `Cronheart\WP\Vendor\CronMonitor\…`. **Do not depend on the
  `CronMonitor\…` namespace from outside this plugin** (e.g. another
  plugin reading our autoload) — that surface is going to move in
  a minor release.
- **No WP-CLI commands** in v0.1.0 (planned for v0.2).
- **No multisite / network-activation handling** in v0.1.0 (planned
  for v0.2). The plugin works on a single-site install.
- **No Action Scheduler instrumentation** — only WP-Cron hooks are
  monitored. WooCommerce stacks using Action Scheduler for tasks
  will not see those events on the cronheart dashboard yet.
- **No admin UI for per-event UUID editing** — register through PHP
  (`cronheart_monitor()`) or constants in v0.1.0. The "Monitored
  events" table in Settings → Cronheart is read-only.

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

## License

GPL-2.0-or-later — see [LICENSE](LICENSE). Mandated by WordPress.org;
the embedded `cron-monitor/php-sdk` is MIT-licensed and GPL-compatible.
