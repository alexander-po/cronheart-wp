#!/usr/bin/env bash
#
# Build a distributable plugin zip for cronheart-wp.
#
# What this script produces:
#   build/cronheart.zip — uploadable through WP Admin → Plugins → Add New
#                        → Upload Plugin. Contains the plugin source and
#                        the Strauss-prefixed SDK; excludes dev deps,
#                        tooling configs, CI, and tests.
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
# php-scoper) is deferred to v0.1.1+ — see README and CHANGELOG. For
# the v0.1.0 GitHub-only release we ship the SDK under its canonical
# `CronMonitor\…` namespace.
composer install --no-dev --no-interaction --no-progress --prefer-dist

# Stage only the files that ship with the plugin. Everything not listed
# here is dev-time scaffolding (tests, CI, linters) that does not belong
# in the WordPress install directory.
cp cronheart.php "$STAGE_DIR/"
cp readme.txt "$STAGE_DIR/"
cp LICENSE "$STAGE_DIR/"
cp -R src "$STAGE_DIR/"
cp -R vendor "$STAGE_DIR/"

# Wrap the staged tree in `cronheart/` so the zip extracts directly
# into a `wp-content/plugins/cronheart/` directory.
cd "$BUILD_DIR"
zip -rq cronheart.zip cronheart

echo
echo "Built: $BUILD_DIR/cronheart.zip"
ls -lh "$BUILD_DIR/cronheart.zip"
