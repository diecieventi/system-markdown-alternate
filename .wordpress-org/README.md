# wordpress.org listing assets

Images for the **plugin page** on wordpress.org. They are **not** part of the
plugin: they live in the `/assets` folder of the WP.org SVN, separate from
`/trunk` and `/tags`, so they are **not** included in the distributable zip.

| File | Use |
|------|-----|
| `icon-128x128.png` / `icon-256x256.png` | Icon (plugin grid, search results) |
| `banner-772x250.png` / `banner-1544x500.png` | Banner at the top of the listing (1x / retina) |
| `screenshot-1.png` … `screenshot-6.png` | Screenshots; numbering matches the `== Screenshots ==` captions in `readme.txt` |

Screenshots 1–5 are one per tab of the settings page, in panel order: General,
Markdown output, `/llms.txt`, Integrations, Advanced. Screenshot 6 is the
`[sysmda_md_actions]` split button on the front end (copy/view/download), not
a settings tab — added because a shot of the actual reader-facing output sells
the plugin better than the settings panel alone. WP.org matches
`screenshot-N.{png,jpg}` to caption N in `readme.txt`, so keep the numbering in
sync if you add or reorder them. Heights may differ (each shot is as tall as its
subject); only the numbering and the caption order matter.

> Optional improvement: a shot of the actual `.md` output (a page served as
> `.md`, and/or the `/llms.txt` response) would round this out further — add
> it as `screenshot-7` with a matching caption when convenient.

## How they reach wordpress.org

- **Manual**: copy the files into `svn/assets/` and `svn commit`.
- **Automated**: the `10up/action-wordpress-plugin-asset-update` action reads
  this very `.wordpress-org/` folder and syncs it with `svn/assets`.

## Regeneration

Icon and banners are generated programmatically (Pillow), palette aligned with
the admin panel (ink `#1d2327`, WP blue `#2271b1`). They are a clean starting
point, replaceable with custom artwork whenever desired.
