# wordpress.org listing assets

Images for the **plugin page** on wordpress.org. They are **not** part of the
plugin: they live in the `/assets` folder of the WP.org SVN, separate from
`/trunk` and `/tags`, so they are **not** included in the distributable zip.

| File | Use |
|------|-----|
| `icon-128x128.png` / `icon-256x256.png` | Icon (plugin grid, search results) |
| `banner-772x250.png` / `banner-1544x500.png` | Banner at the top of the listing (1x / retina) |
| `screenshot-1.png` … `screenshot-5.png` | Screenshots; numbering matches the `== Screenshots ==` captions in `readme.txt` |

Screenshots 1–4 are one per tab of the settings page, in panel order: General,
Markdown output, Integrations, Advanced. Screenshot 5 is the
`[sysmda_md_actions]` split button on the front end (copy/view/download), not
a settings tab — added because a shot of the actual reader-facing output sells
the plugin better than the settings panel alone. WP.org matches
`screenshot-N.{png,jpg}` to caption N in `readme.txt`, so keep the numbering in
sync if you add or reorder them. Heights may differ (each shot is as tall as its
subject); only the numbering and the caption order matter.

> **⚠ All four settings screenshots are stale and must be retaken before
> `0.53.0` is published to wordpress.org.** Not just one — this was understated
> when the removal landed and corrected after review. Checked image by image:
> `screenshot-1` through `screenshot-4` each show the **`llms.txt` tab** in the
> nav bar *and* the **`llms.txt status`** aside in the right-hand column, both
> removed with the endpoint; `screenshot-1` additionally shows the old field
> text *"Content types exposed as `.md` and in `/llms.txt`"*. They also all
> read **v0.49.2** in the page header, so they were already four releases
> behind before any of this. `screenshot-5` is the front-end split button and
> is unaffected.
>
> They cannot be regenerated from a code change: it takes a WordPress admin
> session in a browser, on a site running this version. `.wordpress-org/` is
> synced to the public listing on every release, so publishing `0.53.0` with
> these advertises an endpoint that no longer exists to anyone deciding whether
> to install. Retaking them is tracked in [`docs/STATUS.md`](../docs/STATUS.md).
>
> The `/llms.txt` tab's own shot was deleted and 4–6 renumbered to 3–5 in that
> same release.
>
> Optional improvement: a shot of the actual `.md` output would round this out
> further — add it as `screenshot-6` with a matching caption when convenient.

## How they reach wordpress.org

- **Manual**: copy the files into `svn/assets/` and `svn commit`.
- **Automated**: the `10up/action-wordpress-plugin-asset-update` action reads
  this very `.wordpress-org/` folder and syncs it with `svn/assets`.

## Regeneration

Icon and banners are generated programmatically (Pillow), palette aligned with
the admin panel (ink `#1d2327`, WP blue `#2271b1`). They are a clean starting
point, replaceable with custom artwork whenever desired.
