# `.wordpress-org/` — source of truth for the Plugin Directory assets

This directory holds the **SVG sources** for the icons and banner that
ship on `https://wordpress.org/plugins/cronheart/`. The rendered PNG
artefacts live in the SVN `assets/` directory of the WP.org plugin
repository (separate from this git repository, see
[CLAUDE.md → WordPress.org SVN flow](../CLAUDE.md)); the SVGs here
are the authoritative source those rasters are regenerated from.

## What's here

| File | Renders to | Where it shows |
|---|---|---|
| `icon.svg` | `assets/icon-128x128.png`, `assets/icon-256x256.png` | Plugin card in WP.org search results and in WP-admin "Plugins → Add New" |
| `banner.svg` | `assets/banner-772x250.png`, `assets/banner-1544x500.png` | Header strip on the plugin page |

Screenshots (`screenshot-1.png`, `screenshot-2.png`, …) are GUI
captures of the WP-admin **Settings → Cronheart** page and of the
cronheart.com dashboard — they are not regeneratable from SVG and are
not tracked here. The `readme.txt` `== Screenshots ==` section is the
source of truth for which screenshots exist and what they depict.

## Regenerating the rasters

```bash
./bin/generate-wp-assets.sh
```

The script renders both PNG densities for both icon and banner via a
Docker `debian:stable-slim` container with `librsvg2-bin` and `optipng`
installed (matches the rendering library Firefox uses, so the output
matches what the live web renders). Outputs land in
`build/wp-org-assets/` (gitignored).

## Deploying to WP.org

The PNGs need to be committed to the WP.org SVN repository at
`https://plugins.svn.wordpress.org/cronheart/assets/`. After
regeneration:

```bash
# Copy into the SVN working copy (checked out separately):
cp build/wp-org-assets/*.png /path/to/cronheart-svn/assets/

cd /path/to/cronheart-svn
svn status                                # confirm only the files you changed
svn add assets/*.png --force              # no-op for already-tracked files
svn commit -m "Refresh icon / banner"     # uses cached SVN credentials
```

WP.org refreshes the live plugin page within a few minutes of the
SVN commit. No plugin-version bump is required to update assets —
they are decoupled from the `trunk/` and `tags/X.Y.Z/` plugin
release artefacts.

## Design notes (so the next iteration matches)

Both SVGs follow the cronheart.com brand system:

- **Palette.** Dark base `#0a0a0c`, violet gradient strokes
  (`#a78bfa → #7c5cff`) for the heart-cron mark, a subtle 48×48
  grid pattern at `#1f1f27`, two radial-glow accents (top-right
  + bottom-left), green status pip `#34d399`, text in
  `#ededf0` / `#9a9aa3`.
- **Mark.** Circle + EKG pulse path. The path data
  (`M19 60 H35 L45 41 L55 79 L65 60 H101` at banner scale) is the
  canonical heart-cron lockup — reuse it verbatim when extending.
- **Typography.** Inter (the Docker pipeline installs `fonts-inter`
  for rsvg-convert; without it, rsvg falls back to DejaVu Sans
  whose wider metrics overflow tightly-spaced layouts).
- **Mobile-safe zone for banner.** WP.org crops banners to 772×250
  on small screens, keeping the centre. Keep the lockup and
  tagline within roughly the central 772 px columns so nothing
  important crops away. The decorative pulse line on the right
  side is allowed to crop — it's atmospheric, not informational.

When iterating, eyeball the live cronheart.com pages (homepage,
dashboard) as the visual reference — users arriving at the WP.org
plugin page should recognise the same product on sight.
