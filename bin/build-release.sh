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
#   - Push to the WordPress.org SVN (deferred to v0.1.1+ submission).
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
cp -R vendor "$STAGE_DIR/"

# Strip non-essential files from vendored packages. Composer's
# `archive.exclude` only affects `composer archive`, not the
# vendor/ tree we ship, so every dependency's tests, docs, and CI
# scaffolding gets dragged in unless we explicitly trim it here.
# Saves ~60% of the zip size and avoids shipping third-party test
# suites that could surprise users who poke around with grep.
find "$STAGE_DIR/vendor" -type d \( -name tests -o -name test -o -name docs -o -name doc -o -name examples -o -name '.github' \) -exec rm -rf {} +
find "$STAGE_DIR/vendor" -type f \( -name 'phpunit.*' -o -name 'phpstan.*' -o -name '.php-cs-fixer*' -o -name 'psalm.*' -o -name '*.dist' -o -name '.editorconfig' -o -name '.gitignore' -o -name '.gitattributes' \) -delete

# Wrap the staged tree in `cronheart/` so the zip extracts directly
# into a `wp-content/plugins/cronheart/` directory.
cd "$BUILD_DIR"
zip -rq cronheart.zip cronheart

echo
echo "Built: $BUILD_DIR/cronheart.zip"
ls -lh "$BUILD_DIR/cronheart.zip"
