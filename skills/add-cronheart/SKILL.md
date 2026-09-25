---
name: add-cronheart
description: Use when a WordPress site needs cronheart.com monitoring for WP-Cron — install the cronheart plugin (WordPress.org slug `cronheart` or `composer require cronheart/wp`), attach the site heartbeat monitor by UUID (Settings → Cronheart or a wp-config.php constant), add per-event monitors (the Cron Events screen, `cronheart_monitor()`, `CRONHEART_EVENT_<HOOK>_UUID`), connect a Personal Access Token for the picker, auto-create, Channels and History screens, move WP-Cron onto a system cron with DISABLE_WP_CRON, and verify the first ping. Not for the PHP SDK outside WordPress and not for developing the plugin itself.
---

# Add Cronheart to a WordPress site

Cronheart turns WP-Cron into a dead-man switch: the plugin pings cronheart.com every five minutes and on every scheduled event you register, and when the pings stop cronheart alerts the operator. Every angle-bracket value below is a placeholder. A monitor UUID and an API token are secrets: never write a real one into a repository, a log, a chat reply or a screenshot.

## Before you start

Confirm you have:

- `<site-url>` (scheme and host, `https://example.com`) and shell or WP-CLI access to `<wp-path>`. The `wp` commands below assume the working directory is already `<wp-path>`; add `--path=<wp-path>` to each one otherwise.
- A cronheart.com account. The Free plan holds 20 monitors: a site heartbeat plus per-event monitors for most sites.
- WordPress 6.0 or newer, PHP 8.2 or newer, the curl extension.

Pick the configuration path with the operator:

| Situation | Configure through |
|---|---|
| `wp-config.php` is editable (production) | Constants (step 3, option A): the UUID stays out of the database |
| Hosted site, no `wp-config.php` access | Settings → Cronheart (step 3, option B) |
| Composer-managed site (Bedrock and the like) | `composer require cronheart/wp`, then either option |

## Steps

1. **Install and activate the plugin.**
   - WP-CLI: `wp plugin install cronheart --activate`
   - WP admin: Plugins → Add New → search "Cronheart" → Install Now → Activate.
   - Composer: `composer require cronheart/wp`. The package type is `wordpress-plugin`, so it lands where the site's `installer-paths` maps that type; the plugin loads its own `vendor/autoload.php` when the directory has one and otherwise relies on the site's autoloader.

   Check: `wp plugin list --name=cronheart --field=status` prints `active`.

2. **Create the heartbeat monitor on cronheart.com.** Sign in, create a monitor named after the site, kind *interval*, every 5 minutes (the plugin's tick interval), with a grace period the operator accepts (5 minutes is a sensible default). Copy its UUID from the monitor page; it is `<heartbeat-uuid>` below and the only credential the ping path needs.

3. **Attach the heartbeat monitor.** A constant wins over the settings page, and an empty string at either level means "do not monitor in this environment", which is how one config serves dev, staging and production.
   - **Option A, `wp-config.php` constant (production).** Above the line `/* That's all, stop editing! Happy publishing. */` add:
     ```php
     define( 'CRONHEART_HEARTBEAT_UUID', getenv( 'CRONHEART_HEARTBEAT_UUID' ) ?: '' );
     ```
     and set the environment variable on the host. Write `'<heartbeat-uuid>'` literally only when `wp-config.php` is not under version control.
   - **Option B, settings page.** Settings → Cronheart → *Site heartbeat* → *Monitor UUID*: paste `<heartbeat-uuid>` → Save Changes. With a token (step 5) the field is a dropdown of the account's monitors instead. WP-CLI equivalent: `wp option update cronheart_heartbeat_uuid <heartbeat-uuid>`.

   Check: Settings → Cronheart shows the UUID or "Set in wp-config.php", and `wp cron event list --hook=cronheart_heartbeat_tick` lists the tick with its next run.

4. **Add per-event monitors for the jobs that matter** (a backup, a report, a sync). The heartbeat proves WP-Cron runs; a per-event monitor proves one hook started and finished. Wire each hook in one of three ways; when several apply, the constant wins, then the Cron Events assignment, then the helper.
   - **Cron Events screen** (plugin 0.4.0 and newer, needs the token from step 5). Settings → Cronheart Events lists the site's recurring events. Per event, pick a monitor from the dropdown, or click *Auto-create & assign*: the plugin creates an interval monitor named after the hook, with the event's interval, the site timezone and a grace period, and assigns it. A hook pinned by a constant shows "Set by wp-config.php constant" and is not editable there.
   - **Helper**, from a plugin, theme or mu-plugin, registered on `plugins_loaded` or earlier (`init` and later hooks are too late):
     ```php
     add_action( 'plugins_loaded', function () {
         cronheart_monitor( 'my_nightly_report', '<event-uuid>' );
     }, 1 );
     ```
   - **Constant in `wp-config.php`**: `CRONHEART_EVENT_<HOOK>_UUID`, where `<HOOK>` is the hook name upper-cased with every run of non-alphanumeric characters replaced by one underscore (`my:nightly-report` becomes `CRONHEART_EVENT_MY_NIGHTLY_REPORT_UUID`). A constant supplies the UUID for a hook the helper or the screen already registered, so call `cronheart_monitor( 'my_nightly_report' )` without a UUID and define the constant.

   Each registered hook then sends `start` and `success`, or `fail` on an exception; a PHP fatal inside the callback still produces the `fail` ping with the error summary.

5. **Connect a Personal Access Token** (optional; it enables the admin screens). Create it at cronheart.com under **Account → API tokens** (`https://cronheart.com/account/api-tokens`); it starts with `cmk_`. It is an account-level, write-capable credential, so prefer the constant:
   ```php
   define( 'CRONHEART_API_TOKEN', getenv( 'CRONHEART_API_TOKEN' ) ?: '' );
   ```
   or paste it under Settings → Cronheart → *cronheart.com connection* → *API token*. The field is write-only: a saved token is never shown again, and a checkbox removes it. The plugin uses the token only while an administrator has a Cronheart screen open, only over HTTPS, and never on the ping path.

   It unlocks, on Settings → Cronheart, the monitor picker and the account card with a "Your monitors" table (pause, resume, snooze); on Settings → Cronheart Events, assign and *Auto-create & assign*; and, from plugin 0.5.0, Settings → Cronheart Channels (list channels, send a test notification, rotate a webhook secret) and Settings → Cronheart History (a monitor's recent pings and alerts).

   Plan: every plan, the Free plan included, has the REST API behind the token; the per-minute rate grows with the plan, and the current numbers are on `https://cronheart.com/pricing` rather than here. Creating a token needs a verified email address on the account: if the token page shows a notice to verify the email instead of a create form, verify the address first. Plugin releases up to 0.5.0 still carry older copy that ties the API to a paid plan (an intro line saying API access needs Starter, or a notice that the plan does not include API access); that copy is out of date and does not mean the account lacks the API. Either way steps 1–4 above need no token: the plugin works fully on UUIDs pasted by hand, on any plan including Free.

6. **Move WP-Cron onto a system cron.** Page-load WP-Cron runs only when someone visits, so on a quiet or fully cached site the heartbeat arrives late and cronheart alerts; that alert is the finding, and the fix is a real cron. In `wp-config.php`:
   ```php
   define( 'DISABLE_WP_CRON', true );
   ```
   and in the crontab of the user that runs the site (`crontab -e`), every five minutes or more often, one of:
   ```
   */5 * * * * curl -fsS -o /dev/null <site-url>/wp-cron.php
   */5 * * * * wp --path=<wp-path> cron event run --due-now >/dev/null 2>&1
   ```
   The heartbeat monitor's interval must be at least the crontab period. To prove the trigger itself fires, chain a second monitor, `<trigger-uuid>`, onto the line and hand its ping URL to curl on stdin, so the UUID never appears on a command line other local users can read:
   ```
   PING_URL=https://cronheart.com/ping/<trigger-uuid>
   */5 * * * * curl -fsS -o /dev/null <site-url>/wp-cron.php && echo "url = $PING_URL" | curl -fsS -m 10 --retry 5 -o /dev/null -K -
   ```
   `echo` is a shell builtin and `-K -` makes curl read its options, the URL included, from stdin. On PHP-FPM and LiteSpeed `wp-cron.php` answers before the events run, so this ping proves the trigger, not the jobs; the per-event monitors from step 4 cover the jobs.

7. **Verify the first ping.**
   1. `wp cron event list --hook=cronheart_heartbeat_tick` shows the tick and its next run.
   2. `wp cron event run cronheart_heartbeat_tick` fires it now. Exit code 0 only proves the hook ran: the plugin never lets a failed ping break WP-Cron, so the proof is the next step.
   3. Open the monitor on cronheart.com: the heartbeat ping appears within seconds and the monitor turns green. With a token, Settings → Cronheart History → pick the monitor shows the same ping row in wp-admin.
   4. For a per-event monitor, `wp cron event run <hook>` produces a `start` and a `success` ping on that monitor.
   5. If nothing arrives: Tools → Site Health reports the loopback failures that stop page-load WP-Cron altogether. The plugin does not write to `wp-content/debug.log` — it swallows every ping failure into the SDK's no-op logger by design (never break the host job) — so the cronheart.com dashboard and the History screen are the only signal of a delivered or missing ping; a captured HTTP trace (a proxy, or `curl` against the same endpoint by hand) is the way to see why one failed.

8. **Report to the operator.** List what was configured (the path taken, the hooks registered, the screens available), where each placeholder still has to be filled in, and confirm that no UUID or token was written to a versioned file.

## Rules

- The plugin folds every network failure into a logged warning so the host job completes; do not add retries, `try/catch` wrappers or a second ping mechanism around it.
- Never set `CRONHEART_ALLOW_INSECURE_ENDPOINT` on a public site; it exists for local `http://` backends only.
- On the Channels screen, *Send test* delivers a real notification and *Rotate secret* invalidates the current webhook secret at once; click either only on the operator's word.
- The heartbeat interval is fixed at five minutes in the plugin; give the monitor that interval, never a shorter one.

## Versions

| Plugin | Adds |
|---|---|
| 0.2.0 | Monitor picker and `CRONHEART_API_TOKEN` |
| 0.3.0 | Account card and the "Your monitors" table (pause, resume, snooze) |
| 0.4.0 | Cron Events screen: assign or auto-create per-event monitors |
| 0.5.0 | Channels and History screens; SDK 1.4 (token only over HTTPS) |

`wp plugin list --name=cronheart --field=version` tells you which one a site runs; not every version is necessarily on WordPress.org yet — the plugin's `readme.txt` `Stable tag` there is the current word.
