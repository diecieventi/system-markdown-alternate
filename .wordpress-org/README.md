# wordpress.org listing assets

Images for the **plugin page** on wordpress.org. They are **not** part of the
plugin: they live in the `/assets` folder of the WP.org SVN, separate from
`/trunk` and `/tags`, so they are **not** included in the distributable zip.

| File | Use |
|------|-----|
| `icon-128x128.png` / `icon-256x256.png` | Icon (plugin grid, search results) |
| `banner-772x250.png` / `banner-1544x500.png` | Banner at the top of the listing (1x / retina) |

**Screenshots are still missing** (`screenshot-1.png`, …): they must be captured
from the plugin's real UI and their numbering must match the captions already
listed in the `== Screenshots ==` section of `readme.txt`:

1. `screenshot-1.png` — the settings page (content types, cache, exclusions).
2. `screenshot-2.png` — a post served as clean Markdown at the `.md` URL.
3. `screenshot-3.png` — the `/llms.txt` output / enriched mode settings.

## How they reach wordpress.org

- **Manual**: copy the files into `svn/assets/` and `svn commit`.
- **Automated**: the `10up/action-wordpress-plugin-asset-update` action reads
  this very `.wordpress-org/` folder and syncs it with `svn/assets`.

## Regeneration

Icon and banners are generated programmatically (Pillow), palette aligned with
the admin panel (ink `#1d2327`, WP blue `#2271b1`). They are a clean starting
point, replaceable with custom artwork whenever desired.
