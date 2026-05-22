# CLAUDE.md

Project-specific notes for agents (Claude Code, Cursor, etc.) working in
this repository. Sibling notes in
[`../cron-monitor-php/CLAUDE.md`](../cron-monitor-php/CLAUDE.md) cover the
PHP SDK this plugin bundles, and [`../cron-monitor/CLAUDE.md`](../cron-monitor/CLAUDE.md)
covers the closed-source backend both ultimately ping.

## What this repo is

`cronheart/wp` — the official WordPress plugin for
[cronheart.com](https://cronheart.com). Wraps the `cron-monitor/php-sdk`
PHP package into a WP-Cron monitoring layer:

* a 5-minute site-wide heartbeat tick;
* per-event `start` / `success` / `fail` pings on any
  `wp_schedule_event` hook the operator registers via the
  `cronheart_monitor()` helper, the `cronheart_monitor_map` filter,
  or `CRONHEART_EVENT_<HOOK>_UUID` constants in `wp-config.php`;
* an admin settings page at **Settings → Cronheart**.

The plugin is published on Packagist as `cronheart/wp` and (currently
in review at the time of writing) submitted to the WordPress.org Plugin
Directory under the slug `cronheart`. PHP ≥ 8.2, WordPress ≥ 6.0.

## The three repos and what flows between them

```
cronheart-wp (this)            cron-monitor-php (SDK)        cron-monitor (backend)
─────────────────              ──────────────────────         ─────────────────────
WordPress plugin               PHP library on Packagist       Symfony SaaS at cronheart.com
open-source, GPL-2.0-or-later  open-source, MIT-licensed      closed-source, our monetisation
bundles the SDK in vendor/     ⇐ this is what we bundle      ← both ping this in production
```

The plugin re-exports a handful of SDK primitives (`CronMonitorClient::create()`,
`Configuration`, `PingResult`) through its own thin `Api\Client` facade.
We **do not** prefix the bundled SDK's namespace (Strauss / php-scoper is
deferred pending a first reported collision — `cron-monitor/php-sdk` is
not currently bundled by any other WP plugin, so the canonical
`CronMonitor\…` namespace is effectively unique to this integration).

## Hard contract: never break the host job

Inherited from the SDK. Every code path in this plugin runs inside a
WP-Cron job. A broken backend, an unreachable network, a misbehaving
PSR-18 client — **none of them may cause the wrapping job to fail**. The
whole point of the service is to detect when a scheduled job stops
running; if our plugin becomes the cause, we invert the value we're
meant to provide.

Concrete:

- `Api\Client` wraps every SDK call in a belt-and-suspenders
  `try/catch \Throwable` even though the SDK contract says it doesn't
  throw. The host cron run must complete regardless.
- `Hooks\PerEventInstrumentation` registers a shutdown handler so that
  a fatal `wp_die()` or PHP error inside the wrapped hook still produces
  a `fail` ping with an `error_get_last()` summary in the body.
- All hook callbacks swallow errors into a logged warning. We never
  re-throw upward.

If you add a new bridge or hook, mirror this. New files that call the
SDK must have at least one test that simulates a thrown SDK error and
asserts the host-job equivalent still completes.

## Branch & commit conventions

Same as the sibling SDK repo — same rules, same reasoning:

- **Never commit directly to `main`.** Every change lives on its own
  feature branch and lands on `main` via a merged PR. No exceptions for
  "small" or "docs-only".
- **Branch naming:** `feature/<short-kebab-topic>`. The topic must
  describe **what was done**, not just the area touched
  (`feature/restore-legal-links` ✓, `feature/readme-stuff` ✗).
- **One commit per branch.** Before opening the PR, squash review /
  fixup commits into a single self-contained commit. The canonical
  recipe is `git reset --soft origin/main && git commit && git push
  --force-with-lease`.
- **Don't add `Co-Authored-By: Claude` trailers** to commit messages.
  Don't add any AI-attribution trailers at all.
- **Don't leak `@capital.com` email** anywhere — repo content, commit
  authorship, tag identity, GitHub UI. This is a hard rule (we did
  a `git filter-branch` purge in a prior session; do not regress).

## Per-repo git config (NOT global)

Public-facing repos (`cronheart-wp`, `cron-monitor-php`) use
GitHub-noreply identity, set per-repo, not globally:

```bash
git config user.name  "Alexander Palazok"
git config user.email "alexander-po@users.noreply.github.com"
```

Global git config keeps the work email — we deliberately do not touch
it. Always verify after a clean clone:

```bash
git config user.email   # must be alexander-po@users.noreply.github.com
git log -1 --format='%ae %ce'   # verify last commit
```

## Plugin author name convention (deliberate inconsistency)

| Surface | Value | Why |
|---|---|---|
| `cronheart.php` plugin header `Author:` | `Aliaksandr Palazok` | Belarusian transliteration — user's preferred public identity in the WP-admin UI |
| `LICENSE` copyright line | `Alexander Palazok` | Matches git config and sister `cron-monitor-php` LICENSE |
| Git author / committer | `Alexander Palazok <alexander-po@users.noreply.github.com>` | Same |

The inconsistency is **intentional**. Do not "fix" `Aliaksandr → Alexander`
in the plugin header — the user explicitly rejected that rename in a
prior session.

## Running the toolchain locally

No PHP install on the host — everything runs in Docker:

```bash
# Tests
docker run --rm -v "$PWD":/app -w /app php:8.2-cli vendor/bin/phpunit

# PHPStan level 8 (needs more memory than the 128M default)
docker run --rm -v "$PWD":/app -w /app php:8.2-cli \
    php -d memory_limit=512M vendor/bin/phpstan analyse --no-progress

# php-cs-fixer (Symfony/PSR-12, scoped to src/ and tests/)
docker run --rm -v "$PWD":/app -w /app -e PHP_CS_FIXER_IGNORE_ENV=1 \
    php:8.2-cli vendor/bin/php-cs-fixer fix --dry-run --diff

# WPCS phpcs (scoped to cronheart.php + admin layer per .phpcs.xml.dist)
docker run --rm -v "$PWD":/app -w /app php:8.2-cli sh -c "
    php vendor/bin/phpcs --config-set installed_paths \
        vendor/wp-coding-standards/wpcs,\
vendor/phpcsstandards/phpcsutils,\
vendor/phpcsstandards/phpcsextra >/dev/null 2>&1
    php vendor/bin/phpcs --standard=.phpcs.xml.dist"
```

CI matrix (`.github/workflows/ci.yml`): PHP 8.2 / 8.3 / 8.4. The
`lint` job on 8.2 covers `composer validate --strict`,
`check-platform-reqs`, php-cs-fixer, PHPStan, phpcs, and
`composer audit`. The `test` job runs PHPUnit across the matrix plus a
`lowest-deps` lane on 8.2.

## Behind a corporate proxy (Netskope)

`composer install` and `wp plugin install` inside Docker hit SSL
verification errors when the host has Netskope intercepting traffic.
Mount the system cert bundle into the container:

```bash
# Generate once (refresh if you get cert errors again):
security export -k /Library/Keychains/System.keychain -t certs -f pemseq -o /tmp/sys_certs.pem

# Then mount it into any composer / wp-cli docker run:
docker run --rm -v "$PWD":/app \
    -v /tmp/sys_certs.pem:/usr/local/share/ca-certificates/sys_certs.crt \
    -w /app composer:2 sh -c "update-ca-certificates >/dev/null 2>&1 || true; composer install ..."
```

`wp plugin install` from `wp.org` will still fail for cert reasons —
download the zip on the host with `curl` first, then `docker cp` it
into the wp-cli container and install from the local path.

## Devstack — two modes

`devstack/` carries a docker-compose harness for end-to-end smoke runs.

### Mode A — production (public contributors)

Pings real `cronheart.com`. Public, doesn't require backend access.

```bash
docker compose -f devstack/docker-compose.yml up -d
HEARTBEAT_UUID=<from-cronheart-dashboard> \
EVENT_UUID=<from-cronheart-dashboard> \
./devstack/smoke.sh
```

Verification is visual — check the cronheart.com dashboard for the
incoming pings. No automated DB assertion.

### Mode B — local backend (maintainers only)

Joins the cronheart-wp WordPress + wp-cli containers to the
`cron-monitor` backend's Docker network, points the plugin at the local
backend, runs the smoke, then **automatically asserts the three
expected ping rows landed in the `pings` table**.

```bash
# 1. Bring up the closed-source backend first (separate repo):
cd ../cron-monitor && make up && cd -

# 2. Bring up cronheart-wp devstack with the local overlay:
docker compose \
    -f devstack/docker-compose.yml \
    -f devstack/docker-compose.local.yml \
    up -d

# 3. Run smoke in mode B:
CRONHEART_LOCAL_BACKEND=1 ./devstack/smoke.sh
```

Expected output ends with three ping rows
(heartbeat / per-event start / per-event success) printed from the
backend DB, plus `✓ All expected pings observed. Smoke run complete.`

**"End-to-end" means this.** A green `wp cron event run cronheart_heartbeat_tick`
returning exit 0 only proves the hook didn't throw — the SDK swallows
all network errors per the never-break-the-host-job contract, so the
cron run will succeed even when the backend is unreachable, the UUID
is fake, or the body is malformed. The only honest end-to-end signal
is *rows appearing in the `pings` table*. Use mode B for that.

## The WP-image trap

The default devstack pin must track the latest WordPress stable. WP.org's
Plugin Check automated scan rejects submissions whose `Tested up to:`
lags `wp.org`'s current stable — but the **local** Plugin Check passes
when the devstack is pinned to an older WP image, because it compares
against the *running* WP version, not wp.org's release feed.

v0.1.4 was bounced for `Tested up to: 6.7 < 6.9` even though local PCP
passed (devstack was on `wordpress:6.7-php8.2-apache`). v0.1.5 bumped
both `readme.txt` and `devstack/docker-compose.yml` to WP 6.9.

**Rule:** when bumping `readme.txt` `Tested up to:`, also bump
`devstack/docker-compose.yml` `image:` to the matching tag. Verify the
image tag exists on Docker Hub first:

```bash
curl -sS "https://hub.docker.com/v2/repositories/library/wordpress/tags?name=<X.Y>-php8.2&page_size=10" \
  | python3 -c "import json,sys; d=json.load(sys.stdin); print('\n'.join(t['name'] for t in d['results']))"
```

After bumping the image, **wipe the WP-data volume** (`docker compose
down -v`) so the new image's WP files take over — otherwise the
persisted `/var/www/html` keeps the old WP version even with the new
image.

## Release zip build flow

```bash
./bin/build-release.sh
# Produces build/cronheart.zip — ~190 KB at v0.1.x
```

The script:

1. Runs `composer install --no-dev --prefer-dist` (host needs composer
   on PATH OR run the script's body manually after running composer in
   docker).
2. Stages: `cronheart.php`, `readme.txt`, `LICENSE`, `composer.json`,
   `composer.lock`, `src/`, `vendor/` into `build/cronheart/`.
3. Strips from vendored packages: `tests/`, `test/`, `docs/`, `doc/`,
   `examples/`, `.github/`, **`bin/`** (Composer CLI shims),
   `phpunit.*`, `phpstan.*`, `.php-cs-fixer*`, `psalm.*`, `*.dist`,
   `.editorconfig`, `.gitignore`, `.gitattributes`, **`CLAUDE.md`**,
   **`AGENTS.md`**, `CONTRIBUTING.md`, `SECURITY.md`, `UPGRADING.md`,
   `MAINTAINING.md`, `CODE_OF_CONDUCT.md`, `.scrutinizer.yml`,
   `.travis.yml`, `.circleci`.
4. Zips into `build/cronheart.zip`.

**`LICENSE` / `LICENSE.md` deliberately stay** in vendored packages
(third-party attribution requirement).

Things explicitly stripped to dodge WP.org review nits:

| Pattern | Why |
|---|---|
| `vendor/*/bin/*` | Reviewer flagged `vendor/bin/cron-monitor` and `vendor/cron-monitor/php-sdk/bin/cron-monitor` as "not permitted files" in round 1 |
| `CLAUDE.md`, `AGENTS.md` | AI-tooling notes inside a plugin zip look out of scope to reviewers |
| `CONTRIBUTING.md`, `SECURITY.md` etc. | Contributor docs target SDK consumers, not WP operators |

This top-level `CLAUDE.md` (the one you're reading) lives at the repo
root, **not** inside `vendor/`, and `build-release.sh` only copies
specific paths into the stage dir — so this file is safe from getting
shipped in the zip. Do not add it to the copy list.

## Plugin Check pre-flight

Always run `wp plugin check cronheart` in the devstack before submitting
or re-uploading to WP.org. Catches the same checks the reviewer's
automated scan runs, locally.

```bash
# Install plugin-check once (download zip on host, docker cp it in):
docker cp /tmp/plugin-check.zip cronheart-wp-cli:/tmp/plugin-check.zip
docker compose -f devstack/docker-compose.yml exec -T wp-cli \
    wp plugin install /tmp/plugin-check.zip --activate --allow-root

# Then run, after every rebuild:
docker compose -f devstack/docker-compose.yml exec -T wp-cli \
    wp plugin install /tmp/cronheart.zip --force --activate --allow-root
docker compose -f devstack/docker-compose.yml exec -T wp-cli \
    wp plugin check cronheart --allow-root
```

Expected output: `Success: Checks complete. No errors found.`

### Known PCP gotchas (from this session)

- **`defined('ABSPATH') || exit;` regex is strict.** PCP matches only the
  canonical shape — any decorating clause (e.g. `... || 'cli' === PHP_SAPI || exit`)
  defeats the match. We use the canonical pattern in `src/` files and
  handle the CLI / test-runner case by predefining `ABSPATH` in
  `tests/bootstrap.php` and loading `src/Helpers/monitor.php` explicitly
  (not via `composer.autoload.files`).
- **`WordPress.Security.EscapeOutput` doesn't track variables.** Even
  if both branches of a ternary are `esc_html_*`, pre-assigning to a
  variable then passing to `printf` triggers a false positive. Inline
  the ternary inside the `printf` call.
- **`outdated_tested_upto_header` only catches lag locally if the
  devstack runs the latest WP.** See "The WP-image trap" above.

## WP.org submission flow

`https://wordpress.org/plugins/developers/add/` — log in, fill the form,
upload the zip. The journey:

### 1. Automated scan (instant)

Validates `readme.txt` (Stable tag must be a concrete version, not
`trunk`; `Tested up to:` must match WP.org's current stable). If
rejected, the form returns inline errors and you can re-upload the same
slug after fixing them. **Does not enter manual queue until automated
scan passes.**

Known rejections we've hit:
- `outdated_tested_upto_header: Tested up to: 6.7 < 6.9` (v0.1.4)

### 2. Manual review (1–2 weeks typical, can be longer)

A volunteer reviewer goes through the entire plugin. They send a
review email with a list of issues. You fix them, re-upload via the
same "Add your plugin" form (it overwrites the slug's pending
submission), and reply to the email.

**Critical:** the reviewer instructions say
*"Be brief and direct in your reply (please, avoid copy-pasting bloated
AI responses)"*. Respect that. Short, factual, one bullet per fixed
issue. No essays.

Known round-1 findings (v0.1.5 → v0.1.6):
- **Dead URLs in readme.txt** — reviewer's automated probe checks every
  URL referenced in `readme.txt` for HTTP 200. Always `curl -sI` every
  URL in the readme before submission. If a URL 404s, **check
  alternative paths before deleting the reference** — pages may exist
  at a sibling URL (e.g. `cronheart.com/privacy` vs the wrong
  `cronheart.com/legal/privacy` we shipped in early versions).
- **Contributors mismatch** — `readme.txt` `Contributors:` line must
  list at least one WordPress.org account that matches the plugin
  owner. The slug `cronheart` was claimed by the WP.org account
  `cronheart`; the publishing identity is `cronmonitor`. Don't list
  GitHub-handle-only names (`alexanderpo`) — they fail validation.
- **`vendor/*/bin/*` files** — the build script strips these now, but
  if a new bundled dep ships a `bin/` directory, the reviewer will
  flag it. The strip list in `bin/build-release.sh` catches `-name bin`
  at the directory level.

### 3. Approval → SVN provisioning

After approval the team provisions an SVN repo at
`https://plugins.svn.wordpress.org/cronheart/`. From that point the
release flow shifts from "upload zip via form" to "commit to SVN
trunk + tag". That flow is **not yet implemented** — when we get
there, document it here.

## End-to-end release checklist

For each new version bump:

1. Branch: `git checkout -b feature/<topic>` from `main`.
2. Code / readme / changelog edits.
3. Version bumps in three places that must agree:
   - `cronheart.php` plugin header `Version:`
   - `readme.txt` `Stable tag:`
   - `CHANGELOG.md` adds a `## [X.Y.Z] — YYYY-MM-DD` section
   - `readme.txt` adds matching `= X.Y.Z =` entries in both
     `== Changelog ==` and `== Upgrade Notice ==` blocks
4. Local toolchain (Docker, four lanes — see "Running the toolchain"
   above). All four must be green:
   - PHPUnit: 54/54 (number grows as tests are added)
   - PHPStan: `[OK] No errors`
   - php-cs-fixer: `Found 0 of N files that can be fixed`
   - phpcs: clean
5. Rebuild zip: `./bin/build-release.sh` (or the manual equivalent if
   you don't have composer on the host PATH).
6. Plugin Check in devstack: `wp plugin check cronheart` →
   `Success: Checks complete. No errors found.`
7. **Real end-to-end smoke (mode B)**: `CRONHEART_LOCAL_BACKEND=1
   ./devstack/smoke.sh` — must end with three ping rows + green check.
8. Squash to single commit. Author / committer identity =
   `Alexander Palazok <alexander-po@users.noreply.github.com>`. No
   `Co-Authored-By` trailers.
9. Push branch, open PR. User merges via GitHub UI (squash-merge).
10. After merge: `git pull --ff-only`, then `git tag -a vX.Y.Z -m "..."`
    with a multi-paragraph annotated message. Push tag:
    `git push origin vX.Y.Z`.
11. Packagist picks up the tag automatically via webhook (~1 min).
12. Create GitHub Release at
    `https://github.com/alexander-po/cronheart-wp/releases/new`. Select
    the tag, write a description (use soft-wrap — GitHub renders
    Markdown in browser; **do not** hard-wrap paragraphs to ~70 chars
    like you would in commit messages), attach `build/cronheart.zip`,
    set as latest release.
13. If a WP.org review is in progress, re-upload the new zip via
    `https://wordpress.org/plugins/developers/add/` and reply to the
    reviewer email (brief — see flow #2 above).

## What this plugin does NOT do (and why)

Don't add these without explicit design discussion:

- **WP-CLI commands** (`wp cronheart status`, `wp cronheart sync`) —
  deferred to v0.2.
- **Multisite / network-activation** — single-site only in v0.1.x.
  Multisite is a separate UX problem (network-level options vs
  site-level) and deferred to v0.2.
- **Action Scheduler integration** — WooCommerce's bundled task runner
  is not yet instrumented; deferred to v0.2 pending user demand.
- **Vendor namespace prefixing (Strauss / php-scoper)** — deferred
  pending the first reported collision in the wild.
- **Per-event UUID editing in admin UI** — read-only table is enough
  for v0.1.x. Operators wire UUIDs through `cronheart_monitor()` calls
  or `CRONHEART_EVENT_<HOOK>_UUID` constants. Editable UI is v0.2.
- **API for managing monitors** — backend doesn't expose a public REST
  API yet (see `../cron-monitor`'s plan doc). Add this only after the
  backend ships `/api/v1/monitors`.

## Lessons learned (the embarrassing ones)

Things this agent got wrong in past sessions; encoded here as warnings
so future-you doesn't repeat them.

1. **"End-to-end" must mean real ping rows in the backend DB.**
   Don't conflate "`wp cron event run` returned exit 0" with end-to-end
   verification. The SDK swallows network errors by design; a hook can
   return success while the ping silently fails. Use mode B and assert
   on the `pings` table.

2. **Check alternative URL paths before removing references.** When a
   URL 404s, the right first step is `curl` on plausible alternative
   paths (`/legal/X` → `/X`, `/X` → `/legal/X`, slug variants). Removing
   a reference is a last resort, not a first response.

3. **`Tested up to:` is a freshness signal, not a stability claim.**
   WP.org excludes plugins whose readme lags from search results
   regardless of whether the code is unchanged. Bump it (and the
   devstack image) on every release that goes near WP.org.

4. **GitHub Release descriptions render in a browser.** Don't hard-wrap
   paragraphs to ~70 chars (good for commit messages, bad for web
   markdown). One paragraph = one line, let the browser wrap.

5. **Plugin Check's regex on `defined('ABSPATH') || exit;` is
   strict.** Any escape hatch (`... || 'cli' === PHP_SAPI || exit;`)
   defeats the match. Don't try to be clever; the canonical pattern
   is the only one that works.

6. **Composer's `autoload.files` runs before PHPUnit's bootstrap.** If
   a file in `autoload.files` carries an `ABSPATH || exit` guard, it
   silently kills the test runner. Use explicit `require_once` from
   `cronheart.php` (and `tests/bootstrap.php`) instead.

7. **Squash-merge commits inherit the committer from the GitHub bot,
   not the PR author.** Verify after pull:
   `git log -1 --format='%an <%ae> %cn <%ce>'`. Author must be
   `Alexander Palazok <alexander-po@users.noreply.github.com>`;
   committer can be `GitHub <noreply@github.com>` (that's fine).
