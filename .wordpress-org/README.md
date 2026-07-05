# wordpress.org listing assets

Images for the **plugin page** on wordpress.org. They are **not** part of the
plugin: they live in the `/assets` folder of the WP.org SVN, separate from
`/trunk` and `/tags`, so they are **not** included in the distributable zip.

| File | Use |
|------|-----|
| `icon-128x128.png` / `icon-256x256.png` | Icon (plugin grid, search results) |
| `banner-772x250.png` / `banner-1544x500.png` | Banner at the top of the listing (1x / retina) |
| `screenshot-1.jpg` … `screenshot-4.jpg` | Screenshots (1200×959); numbering matches the `== Screenshots ==` captions in `readme.txt` |

The four screenshots are a top-to-bottom tour of the settings page (General,
Markdown output, `/llms.txt`, Integrations, Advanced). WP.org matches
`screenshot-N.{png,jpg}` to caption N in `readme.txt`, so keep the numbering in
sync if you add or reorder them.

> Optional improvement: a shot of the actual output (a page served as `.md`,
> and/or the `/llms.txt` response) tends to sell the plugin better than the
> settings alone — add them as `screenshot-5`/`-6` with matching captions when
> convenient.

## How they reach wordpress.org

- **Manual**: copy the files into `svn/assets/` and `svn commit`.
- **Automated**: the `10up/action-wordpress-plugin-asset-update` action reads
  this very `.wordpress-org/` folder and syncs it with `svn/assets`.

## Regeneration

Icon and banners are generated programmatically (Pillow), palette aligned with
the admin panel (ink `#1d2327`, WP blue `#2271b1`). They are a clean starting
point, replaceable with custom artwork whenever desired.
