=== Cronheart ===
Contributors: alexanderpo
Tags: cron, wp-cron, monitoring, healthcheck, deadman-switch
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.2
Stable tag: trunk
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Monitor WP-Cron with cronheart.com — detect when WP-Cron stops
firing and when individual scheduled events fail to complete.

== Description ==

WP-Cron is request-driven. On a low-traffic site no requests arrive,
no events fire, and a scheduled backup can be stalled for weeks
before anyone notices. Uptime monitors do not catch this — the site
responds to HTTPS just fine, it just is not running its jobs.

Cronheart turns WP-Cron into a dead-man switch: the plugin pings
cronheart.com every five minutes and on every individual event you
register; if the pings stop, cronheart alerts you (email, Telegram,
Slack, Discord, or webhook).

= What it does =

* **Site heartbeat.** A 5-minute custom WP-Cron event whose only job
  is to ping cronheart. Proves WP-Cron itself is alive.
* **Per-event monitoring.** Register any scheduled hook for
  start / success / fail pings with one PHP one-liner:
  `cronheart_monitor( 'my_nightly_report', 'xxxxxxxx-…' );`
* **PHP fatal capture.** When a scheduled callback fatals, the fail
  ping body includes the `error_get_last()` summary so the cronheart
  dashboard shows the cause without tailing `debug.log`.
* **Never breaks WP-Cron.** Every network / HTTP failure is folded
  into a logged warning — a broken cronheart backend cannot punish
  the host scheduler.

= Status =

This is a pre-release scaffold (v0.1.0 — GitHub-only). The full
WordPress.org submission text and screenshots land in v0.1.1+ once
the API has stabilised against early GitHub adopters.

Source, issues, and roadmap:
https://github.com/alexander-po/cronheart-wp

== Installation ==

1. Download the latest `cronheart.zip` from
   https://github.com/alexander-po/cronheart-wp/releases
2. WP Admin → Plugins → Add New → Upload Plugin → select the zip.
3. Activate.
4. Create a monitor at https://cronheart.com, copy the UUID, and
   either define `CRONHEART_HEARTBEAT_UUID` in `wp-config.php`
   (recommended) or paste it under Settings → Cronheart.

== Changelog ==

= 0.1.0 =
* Initial scaffold (GitHub-only release; WP.org submission deferred
  to v0.1.1).
* Site-wide heartbeat layer with a 5-minute custom schedule.
* Per-event monitoring with `cronheart_monitor()` helper and
  `cronheart_monitor_map` filter.
* `CRONHEART_HEARTBEAT_UUID` and `CRONHEART_EVENT_<HOOK>_UUID`
  constants for sourcing UUIDs from `wp-config.php`.
* Admin page at Settings → Cronheart for sites without
  `wp-config.php` access.
* PHP fatal-error capture for the fail-ping body.
