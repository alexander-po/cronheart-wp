#!/usr/bin/env bash
#
# Build a distributable plugin zip for cronheart-wp.
#
# What this script produces:
#   build/cronheart.zip — uploadable through WP Admin → Plugins → Add New
#                        → Upload Plugin. Contains the plugin source and
#                        the production-only Composer vendor tree;
#                        excludes dev deps, tooling configs, CI, and tests.
#
# What it does NOT do:
#   - Push to the WordPress.org SVN. Once the plugin is approved
#     and provisioned, that flow lives in a separate script
#     (planned).
#   - Sign or verify the zip.
#   - Bump versions in `cronheart.php` / `readme.txt`. Do that in a
#     separate commit before invoking this script.
#
# Run from the repository root:
#     ./bin/build-release.sh

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
BUILD_DIR="$ROOT/build"
STAGE_DIR="$BUILD_DIR/cronheart"

cd "$ROOT"

rm -rf "$BUILD_DIR"
mkdir -p "$STAGE_DIR"

# Production deps only. Vendor namespace prefixing (Strauss /
# php-scoper) is deferred — see README and CHANGELOG. We ship the
# SDK under its canonical `CronMonitor\…` namespace; conflict risk
# is minimal in practice because no other WP plugin currently bundles
# `cron-monitor/php-sdk`.
composer install --no-dev --no-interaction --no-progress --prefer-dist

# Stage only the files that ship with the plugin. Everything not
# listed here is dev-time scaffolding (tests, CI, linters) that does
# not belong in the WordPress install directory.
#
# `composer.json` and `composer.lock` are intentionally included so
# the shipped `vendor/` tree is reproducible — WP.org's Plugin Check
# warns when `/vendor` exists without the manifest that produced it,
# and downstream contributors can run `composer install` against the
# checked-in lock to recreate the exact tree.
cp cronheart.php "$STAGE_DIR/"
cp readme.txt "$STAGE_DIR/"
cp LICENSE "$STAGE_DIR/"
cp composer.json "$STAGE_DIR/"
cp composer.lock "$STAGE_DIR/"
cp -R src "$STAGE_DIR/"
# The plugin's own front-of-house assets (admin JS/CSS for the
# monitor-lifecycle UI). Distinct from the WordPress.org SVN-root
# `assets/` (icons/banners/screenshots), which is NOT shipped in the zip.
cp -R assets "$STAGE_DIR/"
cp -R vendor "$STAGE_DIR/"

# Strip non-essential files from vendored packages. Composer's
# `archive.exclude` only affects `composer archive`, not the
# vendor/ tree we ship, so every dependency's tests, docs, and CI
# scaffolding gets dragged in unless we explicitly trim it here.
# Saves ~60% of the zip size and avoids shipping third-party test
# suites that could surprise users who poke around with grep.
#
# The agent-instruction / contributor-doc files (`CLAUDE.md`,
# `AGENTS.md`, `CONTRIBUTING.md`, `SECURITY.md`, etc.) are stripped
# specifically because WordPress.org plugin reviewers may flag
# stray AI-tooling notes inside a plugin zip as out-of-scope
# bundling — those files target SDK contributors, not WP-plugin
# operators, and have no business in the runtime distribution.
# `LICENSE` / `LICENSE.md` deliberately stay (third-party
# attribution requirement).
# `bin/` directories specifically: Composer's `vendor/bin/` (CLI
# shim) and the underlying `vendor/*/bin/` executables (e.g.
# `vendor/cron-monitor/php-sdk/bin/cron-monitor`) are CLI tools
# meant for SDK consumers' local dev workflow — a WordPress
# plugin never invokes them. The WP.org review team explicitly
# flags `cronheart/vendor/bin/*` paths as "not permitted files",
# so we strip them here. PSR-4 autoload of the SDK's classes is
# unaffected.
find "$STAGE_DIR/vendor" -type d \( -name tests -o -name test -o -name docs -o -name doc -o -name examples -o -name '.github' -o -name bin \) -exec rm -rf {} +
find "$STAGE_DIR/vendor" -type f \( -name 'phpunit.*' -o -name 'phpstan.*' -o -name '.php-cs-fixer*' -o -name 'psalm.*' -o -name '*.dist' -o -name '.editorconfig' -o -name '.gitignore' -o -name '.gitattributes' \) -delete
find "$STAGE_DIR/vendor" -type f \( -name 'CLAUDE.md' -o -name 'AGENTS.md' -o -name 'CONTRIBUTING.md' -o -name 'SECURITY.md' -o -name 'UPGRADING.md' -o -name 'MAINTAINING.md' -o -name 'CODE_OF_CONDUCT.md' -o -name '.scrutinizer.yml' -o -name '.travis.yml' -o -name '.circleci' \) -delete

# Wrap the staged tree in `cronheart/` so the zip extracts directly
# into a `wp-content/plugins/cronheart/` directory.
cd "$BUILD_DIR"
zip -rq cronheart.zip cronheart

echo
echo "Built: $BUILD_DIR/cronheart.zip"
ls -lh "$BUILD_DIR/cronheart.zip"
