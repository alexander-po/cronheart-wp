#!/usr/bin/env bash
#
# Regenerate the WordPress.org Plugin Directory rasters from the SVG
# sources in `.wordpress-org/`.
#
#   .wordpress-org/icon.svg    ->  build/wp-org-assets/icon-128x128.png
#                                  build/wp-org-assets/icon-256x256.png
#   .wordpress-org/banner.svg  ->  build/wp-org-assets/banner-772x250.png
#                                  build/wp-org-assets/banner-1544x500.png
#
# Output lives under `build/` which is gitignored. The PNGs are then
# committed to the WP.org SVN repo's `assets/` directory (separate
# from this git tree, see `.wordpress-org/README.md` for the deploy
# step). No plugin version bump is required for asset refreshes.
#
# Why Docker: librsvg2-bin, ImageMagick, optipng aren't installed on
# the host. The project already requires Docker for the devstack, so
# reusing that runtime keeps the host clean.
#
# Why Debian (not Alpine): Alpine's `imagemagick` ships without the
# librsvg delegate, so SVG rendering falls back to ImageMagick's MSVG
# renderer which produces pixelated output. Debian's `librsvg2-bin`
# ships `rsvg-convert` — the same library Firefox uses to render
# SVG — and gives crisp output at every target size.
#
# Why `fonts-inter`: the banner's wordmark and tagline render in
# Inter to match cronheart.com's live typography. Without it
# rsvg-convert falls back to DejaVu Sans (wider metrics) and the
# layout shifts.
#
# Why `optipng`: rsvg-convert PNGs carry several KB of metadata
# (version stamps, color profile) and use suboptimal filters.
# `optipng -o5 -strip all` losslessly trims them ~30-40 %, which
# matters more for the 1544×500 retina banner than for the small
# icons.
#
# Usage:
#   ./bin/generate-wp-assets.sh
#
# After successful regeneration, inspect the PNGs in
# `build/wp-org-assets/`, then deploy to WP.org SVN — see
# `.wordpress-org/README.md` for the `svn cp` + `svn ci` recipe.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SRC_DIR="$ROOT/.wordpress-org"
OUT_DIR="$ROOT/build/wp-org-assets"

for src in icon.svg banner.svg; do
    if [[ ! -f "$SRC_DIR/$src" ]]; then
        echo "Source SVG not found: $SRC_DIR/$src" >&2
        exit 1
    fi
done

if ! command -v docker >/dev/null 2>&1; then
    echo "Docker not on PATH. Install Docker or run rsvg-convert + optipng on the host." >&2
    exit 1
fi

rm -rf "$OUT_DIR"
mkdir -p "$OUT_DIR"

# Stage the SVGs into the output dir so the Docker working directory
# is just the small build target rather than the whole repo (avoids
# mounting `vendor/`, `node_modules/`, etc.).
cp "$SRC_DIR/icon.svg" "$SRC_DIR/banner.svg" "$OUT_DIR/"

echo "Rendering WP.org assets from SVG sources ..."

# Optional: mount the host system cert bundle so apt can reach
# debian repos through a corporate TLS-intercepting proxy (Netskope
# etc.). Harmless when the bundle is absent. The `+...` parameter
# expansion keeps `set -u` happy when the array is empty.
CERT_MOUNT=()
if [[ -f /tmp/sys_certs.pem ]]; then
    CERT_MOUNT=(-v /tmp/sys_certs.pem:/usr/local/share/ca-certificates/sys_certs.crt)
fi

docker run --rm \
    -v "$OUT_DIR:/work" \
    ${CERT_MOUNT[@]+"${CERT_MOUNT[@]}"} \
    -w /work \
    debian:stable-slim bash -c '
        set -eu
        export DEBIAN_FRONTEND=noninteractive
        update-ca-certificates >/dev/null 2>&1 || true
        apt-get update -qq
        apt-get install -y --no-install-recommends \
            librsvg2-bin optipng fonts-inter fontconfig > /dev/null
        fc-cache -f > /dev/null

        # Icon: standard + retina from the same SVG. Square aspect,
        # transparent background outside the SVG-drawn rounded rect.
        rsvg-convert -w 128 -h 128 -a -b none -o icon-128x128.png icon.svg
        rsvg-convert -w 256 -h 256 -a -b none -o icon-256x256.png icon.svg

        # Banner: standard (772×250) + retina (1544×500). The source
        # SVG is authored at the retina canvas size; rsvg-convert
        # rasterises both densities from the same vector.
        rsvg-convert -w 1544 -h 500 -a -b none -o banner-1544x500.png banner.svg
        rsvg-convert -w 772  -h 250 -a -b none -o banner-772x250.png  banner.svg

        # Lossless re-encode of every PNG. Trims metadata and picks
        # optimal filtering for the actual content.
        optipng -quiet -strip all -o5 \
            icon-128x128.png \
            icon-256x256.png \
            banner-1544x500.png \
            banner-772x250.png
    '

# Drop the staged SVGs — output dir should only carry the PNG
# artefacts that get copied into SVN.
rm -f "$OUT_DIR/icon.svg" "$OUT_DIR/banner.svg"

echo
echo "Generated WP.org assets:"
ls -lh "$OUT_DIR"/*.png
echo
echo "Next step: copy into the WP.org SVN working copy and commit."
echo "See .wordpress-org/README.md for the deploy recipe."
