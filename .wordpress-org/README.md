# wordpress.org listing assets

Images for the **plugin page** on wordpress.org. They are **not** part of the
plugin: they live in the `/assets` folder of the WP.org SVN, separate from
`/trunk` and `/tags`, so they are **not** included in the distributable zip.

| File | Use |
|------|-----|
| `icon-128x128.png` / `icon-256x256.png` | Icon (plugin grid, search results) |
| `banner-772x250.png` / `banner-1544x500.png` | Banner at the top of the listing (1x / retina) |
| `screenshot-1.png` … `screenshot-5.png` | Screenshots, one per settings tab; numbering matches the `== Screenshots ==` captions in `readme.txt` |

The five screenshots are one per tab of the settings page, in panel order:
General, Markdown output, `/llms.txt`, Integrations, Advanced. WP.org matches
`screenshot-N.{png,jpg}` to caption N in `readme.txt`, so keep the numbering in
sync if you add or reorder them. Heights may differ (each shot is as tall as its
tab); only the numbering and the caption order matter.

> Optional improvement: a shot of the actual output (a page served as `.md`,
> and/or the `/llms.txt` response) tends to sell the plugin better than the
> settings alone — add them as `screenshot-6`/`-7` with matching captions when
> convenient.

## How they reach wordpress.org

- **Manual**: copy the files into `svn/assets/` and `svn commit`.
- **Automated**: the `10up/action-wordpress-plugin-asset-update` action reads
  this very `.wordpress-org/` folder and syncs it with `svn/assets`.

## Regeneration

Icon and banners are generated programmatically (Pillow), palette aligned with
the admin panel (ink `#1d2327`, WP blue `#2271b1`). They are a clean starting
point, replaceable with custom artwork whenever desired.
