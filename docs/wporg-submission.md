# WordPress.org submission flow (pre-approval)

The record of how `cronheart` got into the Plugin Directory. The flow
below is obsolete: the plugin is approved, and every release now goes
through the SVN repository (`CLAUDE.md` → "WordPress.org SVN flow").
The rules that outlived it are in `CLAUDE.md` → "WordPress.org readme
rules".

`https://wordpress.org/plugins/developers/add/` — log in, fill the form,
upload the zip. The journey:

## 1. Automated scan (instant)

Validates `readme.txt` (Stable tag must be a concrete version, not
`trunk`; `Tested up to:` must match WP.org's current stable). If
rejected, the form returns inline errors and you can re-upload the same
slug after fixing them. **Does not enter manual queue until automated
scan passes.**

Known rejections we've hit:
- `outdated_tested_upto_header: Tested up to: 6.7 < 6.9` (v0.1.4)
- `outdated_tested_upto_header: Tested up to: 6.9 < 7.0` (v0.1.7 re-upload)

v0.1.4 was bounced even though local Plugin Check passed: the devstack
was on `wordpress:6.7-php8.2-apache`, and local PCP compares against the
running WP version, not wp.org's release feed. v0.1.5 bumped both
`readme.txt` and `devstack/docker-compose.yml` to WP 6.9. Then v0.1.7
was re-uploaded for round-2 review and got bounced *again* — WordPress
7.0 had shipped during the review cycle, so the readme that was current
at automated-scan time was now stale. v0.1.8 bumped to 7.0.

## 2. Manual review (1–2 weeks typical, can be longer)

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
  list the **WordPress.org account that owns the plugin slug**, not
  just any related WP.org account. The slug `cronheart` was claimed
  by the WP.org account `cronheart` (every upload's confirmation
  email shows "File updated by **cronheart**, version 0.1.x"). We
  tried two wrong identities before getting this right:
  `alexanderpo` (GitHub handle — not a WP.org user, v0.1.5) and
  `cronmonitor` (a separate WP.org account that exists but does
  not own the slug, v0.1.7). v0.1.9 finally settled on
  `Contributors: cronheart`. **The reviewer's static analysis
  compares your contributors list to the slug owner specifically,
  not to any WP.org account that uploaded.**
- **`vendor/*/bin/*` files** — the build script strips these now, but
  if a new bundled dep ships a `bin/` directory, the reviewer will
  flag it. The strip list in `bin/build-release.sh` catches `-name bin`
  at the directory level.

## 3. Approval → SVN provisioning

After approval the team provisions an SVN repo at
`https://plugins.svn.wordpress.org/cronheart/`. From that point on,
the release flow shifts from "upload zip via the Add-your-Plugin
form" to "commit to SVN `trunk/` and `tags/X.Y.Z/`", and re-uploads
through the form plus a reply to the reviewer email stop.
