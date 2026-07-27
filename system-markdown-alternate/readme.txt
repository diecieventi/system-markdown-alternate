=== System Markdown Alternate ===
Contributors: system4pc
Tags: markdown, llms.txt, ai, llm, content negotiation
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.33.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes a clean Markdown version of your posts (readable by LLMs, agents and technical tools) by appending .md to the permalink.

== Description ==

System Markdown Alternate publishes a clean, machine-readable Markdown
representation of your content. Append `.md` to any supported permalink and you
get YAML front matter plus the post body converted to Markdown — with marketing
clutter, forms and navigation widgets stripped out.

`https://example.com/my-post/`    → HTML
`https://example.com/my-post.md`  → Markdown (front matter + content)

It is built for the era of AI assistants, agents and technical scrapers that
prefer plain Markdown over rendered HTML. It is **not** a generic SEO plugin.

= Key features =

* **`.md` endpoint** for every supported, published, public post.
* **Content negotiation**: the same Markdown is returned for `Accept: text/markdown`
  or `?format=markdown` requests. The `Accept` header is parsed with q-values, so
  a client that prefers HTML (higher q) still gets HTML.
* **`Vary: Accept`** on negotiable URLs, so caches and CDNs that honour it keep the
  HTML and Markdown representations of the same address apart. Because some page
  caches key by URL only and ignore `Vary`, the negotiated Markdown (and `406`)
  responses are also sent non-cacheable, so safety never depends on `Vary` alone.
* **`rel="alternate"` link** in the `<head>` of supported singular content.
* **Correct HTTP headers**: `Content-Type: text/markdown`, `X-Robots-Tag`
  (default `noindex, follow`) and a `Link: rel="canonical"` back to the HTML.
* **Clean conversion**: Gutenberg blocks are rendered individually (no injected
  related/CTA blocks), excluded blocks/shortcodes/CSS classes are removed, code
  blocks become fenced blocks, URLs are made absolute.
* **`/llms.txt` endpoint** (optional): an index of your content for LLMs and AI
  agents. An optional **enriched mode** (off by default) adds a site summary, a
  curated "Key content" section, a description for each entry and an `Optional`
  section for older posts. Another optional toggle appends the **last modified
  date** (`updated: YYYY-MM-DD`) to every entry, so crawlers can spot changed
  content without re-fetching each URL.
* **Custom taxonomies in the front matter** (optional, nothing selected by
  default): tick the taxonomies you want and their terms are added as a
  `taxonomies:` block, alphabetically ordered. Nothing is ever published
  automatically: a taxonomy registered by another plugin appears in the panel
  unticked, and taxonomies with no public term archive are labelled as internal.
* **Object cache** with proactive invalidation on post edit, plugin update and
  settings change: a persistent object cache is used when one is available,
  falling back to transients otherwise.
* **Optional `.md` hit counter** (off by default): counts how many times the
  Markdown endpoint is served, split bot vs human. Privacy by design: only
  aggregate daily totals are stored — no IP addresses, no user-agent strings,
  no per-visitor data, no cookies, no external calls.
* **Admin panel** to choose which post types are exposed and to tune cache,
  exclusions and headers — no post type is exposed until you pick one.
* **Shortcode** `[sysmda_md_url]` to output the Markdown URL anywhere.
* **Optional Markdown button** for readers: a small dropdown that copies the `.md`
  link, opens it in a new tab, downloads the `.md` file, or copies the Markdown
  itself to the clipboard, ready to paste into an AI assistant. Place it with
  `[sysmda_md_button]` wherever you want it. Neutral styling that inherits from
  your theme and is restyled with CSS custom properties, and it works without
  JavaScript.
* **Optional integrations**, shown only when the related plugin is active:
  * **Advanced Custom Fields**: add a subtitle and a TL;DR (from ACF fields) as a
    preamble between the H1 and the body.
  * **GenerateBlocks 2.x**: a `{{sysmda_md_url}}` Dynamic Tag, available
    automatically, usable in element fields (e.g. a Button URL).

= Developer filters =

The output is customizable through filters:

* `sysmda_markdown_supported_post_types` — post types that expose `.md` (default: none).
* `sysmda_markdown_excluded_post_formats` — post formats that never expose a `.md`
  (default: every non-standard format; return an empty array to serve them all).
* `sysmda_markdown_robots_header` — the `X-Robots-Tag` value (`''` = no header).
* `sysmda_markdown_strict_406` — return `406` when the client accepts neither HTML nor
  Markdown (default `true`; `false` always serves the HTML default).
* `sysmda_markdown_canonical_url` — canonical URL for the `Link` header (`''` = no header).
* `sysmda_markdown_cache_ttl` — cache TTL in seconds (`0` = disabled).
* `sysmda_markdown_prewarm` — rebuild a post's Markdown cache in the background
  after every save instead of on the first request (default `false`).
* `sysmda_cache_control` — the `Cache-Control` sent on the URLs the plugin owns
  (`.md` and `/llms.txt`); default `public, max-age=0, must-revalidate`, `''` =
  no header at all. Setting a freshness lifetime here (`s-maxage`, `max-age`)
  makes stale Markdown possible: no page cache purges a `.md` on save.
* `sysmda_markdown_source_content` — raw source content before rendering.
* `sysmda_markdown_rendered_html` — cleaned HTML before conversion.
* `sysmda_markdown_preamble` — Markdown inserted between the H1 and the body.
* `sysmda_markdown_output` — final Markdown.
* `sysmda_markdown_excluded_block_names` — Gutenberg blocks to drop.
* `sysmda_markdown_excluded_shortcodes` — shortcodes to drop.
* `sysmda_markdown_excluded_classes` — CSS classes whose elements are dropped.
* `sysmda_front_matter_enabled` — emit the YAML front-matter block at all
  (default `true`; `false` starts the document at the `# Title` heading).
* `sysmda_front_matter_taxonomies` — kill switch for the `taxonomies:` block
  (default: on as soon as one taxonomy is selected; `false` = never emit it).
* `sysmda_front_matter_taxonomy_slugs` — which taxonomies are emitted. Receives
  the selection saved in the panel and may narrow it or extend it (return an
  empty array to opt out for a post).
* `sysmda_acf_field_keys` — ACF fields appended to the source.
* `sysmda_acf_subtitle_key` / `sysmda_acf_tldr_key` — ACF fields for subtitle/TL;DR.
* `sysmda_llms_txt_max_posts` — max posts per type in `/llms.txt`.
* `sysmda_llms_txt_cache_ttl` — `/llms.txt` cache TTL in seconds (`0` = disabled).
* `sysmda_llms_txt_enriched` — enable the enriched `/llms.txt` output (default `false`).
* `sysmda_llms_txt_lastmod` — append `(updated: YYYY-MM-DD)` to every `/llms.txt`
  entry (default `false`).
* `sysmda_llms_txt_summary` — site summary paragraph (enriched mode only).
* `sysmda_llms_txt_key_content` — featured content, post IDs or URLs (enriched mode only).
* `sysmda_llms_txt_main_posts` — posts per type in the main sections before the
  overflow moves to `Optional` (enriched mode only, default 25).
* `sysmda_llms_txt_footer` — free-form block appended at the end (enriched mode only).
* `sysmda_md_hits_bot_patterns` — user-agent substrings the hit counter classifies as bot.
* `sysmda_md_hits_retention_days` — retention of the daily hit-counter buckets (default 90).
* `sysmda_md_button_items` — which menu entries appear, in order: `copy-link`,
  `view`, `download`, `copy-content`.
* `sysmda_md_button_label` — label of the button that opens the menu (default `Markdown`).
* `sysmda_md_button_enqueue_style` — load the button stylesheet (`false` leaves the
  markup unstyled so the theme owns the presentation).
* `sysmda_md_button_html` — the final button markup.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it through the
   Plugins screen in WordPress.
2. Activate the plugin.
3. Go to **Settings → Markdown Alternate** and select at least one post type
   under **Supported post types**. Until you do, the plugin stays inactive.
4. Visit any supported post and append `.md` to its URL.

No rewrite rules are added, so no permalink flush is required.

== Frequently Asked Questions ==

= Why is nothing served at the .md URL? =

By default no post type is enabled. Open **Settings → Markdown Alternate** and
tick at least one post type under **Supported post types**.

= Which content does NOT get a .md version? =

Anything the endpoint would not be able to serve honestly:

* content types not enabled in the settings page;
* drafts, pending and private content, and password-protected posts;
* media attachments (always excluded);
* posts with a **non-standard post format** — aside, status, quote, link,
  gallery, image, video, audio, chat. These are short snippets, usually
  untitled, with no editorial body worth serving as a document. Use the
  `sysmda_markdown_excluded_post_formats` filter to change that.

Markdown is also never served for URL *variants* of a post — its feed, its
oEmbed view, its trackback endpoint, paged comments and the sub-pages of a
post split with `<!--nextpage-->` — even with `Accept: text/markdown`. Only the
canonical permalink and its `.md` URL return Markdown.

= What does the Markdown output look like? =

Each `.md` response is a UTF-8 document with a YAML front-matter block (title,
URL, Markdown URL, published/modified dates, and — when available — author,
featured image, categories, tags and a description), followed by the `# Title`
heading and the post body converted to clean Markdown. The exact keys, their
order and the escaping rules are documented as a stable contract, with
conformance tests, in `docs/output-format.md` in the source repository.

= Can I include my custom taxonomies? =

Yes. Open **Settings → Markdown Alternate → Markdown output** and tick the ones
you want under *Custom taxonomies*: the front matter then carries a
`taxonomies:` block with their terms, sorted alphabetically. Categories and tags
already have their own keys and are not repeated.

Nothing is selected by default and nothing is ever added implicitly — a taxonomy
registered by a plugin you install later shows up in the list unticked, so it
cannot start publishing itself. Taxonomies used for editorial classification
only, with no public term archive ("publicly queryable" off), are labelled as
internal in the list: they are still selectable, but only on purpose. Developers
can curate the list further with the `sysmda_front_matter_taxonomy_slugs` filter.

= How do I exclude part of a post from the Markdown? =

Add one of the CSS classes `no-md`, `md-exclude` or `exclude-from-markdown` to a
block; the element (and its children) is removed from the Markdown output. You
can customize the list with the `sysmda_markdown_excluded_classes` filter.

= Does it affect my SEO? =

The `.md` responses are sent with `X-Robots-Tag: noindex, follow` and a
`Link: rel="canonical"` header pointing back to the HTML version, so search
engines are told to prefer the original page.

= How do I get the Markdown URL in a button or template? =

Use the `[sysmda_md_url]` shortcode. If you run GenerateBlocks 2.x, the
`{{sysmda_md_url}}` Dynamic Tag is available automatically — use it in element
fields such as a Button URL. When the post has no `.md`, the tag resolves to an
empty value so GenerateBlocks can hide the element instead of leaving a broken
link.

= Is the .md content cached? =

Yes (default 24h). It uses a persistent object cache when one is available and
falls back to transients otherwise. The cache is regenerated automatically when
the post is edited, when the plugin is updated, or when you save the settings —
and also when something outside the post changes what the Markdown says: a
synced pattern, the featured image, the description, an ACF field, the author's
display name, the permalink structure or the site address.

That is the cache inside WordPress. Caches *outside* it — your browser, a page
cache, Varnish, a CDN — are told
`Cache-Control: public, max-age=0, must-revalidate`: they may keep a copy, but
they must ask the site whether it is still current before serving it, and the
answer is a small `304 Not Modified` when nothing changed. So a `.md` cannot
keep circulating after you edit the article, without depending on anyone
purging it — which matters, because page caches purge the article's URL and do
not know its `.md` version exists. If your infrastructure has its own purge
mechanism and you would rather trade that guarantee for raw speed, the
`sysmda_cache_control` filter lets you set a real lifetime.

= Can I customize the plugin from my own code? =

Yes: the plugin is developer-extensible through WordPress filters — every
behaviour listed in the "Developer filters" section above can be changed from a
theme or site plugin. A few examples:

`add_filter( 'sysmda_markdown_output', fn( $md, $post ) => $md . "\n---\nCustom footer.\n", 10, 2 );`

`add_filter( 'sysmda_markdown_excluded_classes', fn( $classes ) => array_merge( $classes, array( 'my-private-block' ) ) );`

`add_filter( 'sysmda_llms_txt_enriched', '__return_true' );`

The full, always up-to-date list (with default values) lives in the
[GitHub repository](https://github.com/diecieventi/system-markdown-alternate)
under "Filters (public contract)" in `AGENTS.md`.

= Content negotiation misbehaves behind LiteSpeed cache. What can I do? =

Some LiteSpeed cache configurations key the page cache by URL only and ignore
`Vary: Accept`, so a cached representation can be served regardless of the
`Accept` header. The plugin already tells the cache not to store the negotiated
Markdown; if requests for Markdown on the permalink still receive cached HTML,
enable **LiteSpeed cache compatibility** in **Settings → Markdown Alternate →
Advanced**: it adds `.htaccess` rules that make Markdown-negotiating requests
bypass the LiteSpeed page cache (normal browser traffic stays cached; on other
servers the rules are inert). Then purge the LiteSpeed cache. The explicit
`.md` URLs are not affected and remain fully cacheable.

Not sure whether your host is affected? Whether a LiteSpeed server honours
`Vary: Accept` depends on the host and cannot be detected automatically, so if
in doubt simply enable the option: it is the safe choice, and on hosts that
already behave correctly the rules are just redundant. To test it yourself:
open a post in a normal browser first (so its HTML gets cached), then request
the same permalink with a Markdown Accept header, for example:

`curl -A "Mozilla/5.0" -H "Accept: text/markdown" https://example.com/my-post/`

If the response is HTML (often with an `x-litespeed-cache: hit` header) instead
of Markdown, your server ignores `Vary: Accept` and you need the option. The
browser-like `-A` value matters: a WAF/CDN may block non-browser user agents.

= Does it work behind a CDN (Cloudflare, Fastly, Varnish)? =

The `.md` URLs need nothing from you. The negotiated permalink depends on your
CDN, and the difference matters:

* the dedicated `.md` URLs are their own cache key — one URL, one
  representation, nothing to mix up. Any CDN may store them, and
  `Cache-Control: public, max-age=0, must-revalidate` means it must revalidate
  before reuse, which is a cheap `304 Not Modified` when nothing changed. This
  route works everywhere, with no configuration;
* the **negotiated** permalink (`Accept: text/markdown` on the HTML page's own
  URL) is sent `no-store`, so a Markdown response is never stored and can never
  be handed to a browser that asked for HTML. That closes the harmful direction,
  but it cannot fix the opposite one: if your CDN caches the HTML page by URL and
  ignores `Vary: Accept`, a later Markdown request is answered at the edge, PHP
  never runs, and the client simply gets HTML. `Vary: Accept` is sent on every
  negotiable response, which is all a cache that honours it needs.

So for the negotiated route one of these has to be true: your CDN honours
`Vary: Accept`, or you configure it to bypass the cache — or to vary its cache
key — for requests whose `Accept` mentions `text/markdown`. On LiteSpeed the
plugin ships that bypass for you: see the previous entry. If you are not sure
which case you are in, the three-request test below tells you in a few seconds.
And the `.md` URL keeps working regardless — it is what the `rel="alternate"`
link and `/llms.txt` advertise, so agents following either one are unaffected.

Two more things worth knowing. Some CDNs rewrite validators in transit — Cloudflare
turns a strong `ETag` into a weak one — which the plugin handles: incoming
validators are compared with the weak-comparison rules, so revalidation keeps
working either way. And if you would rather have the CDN really cache the `.md`
instead of revalidating it, set a lifetime with the `sysmda_cache_control`
filter, keeping in mind that nothing purges a `.md` when you edit the post.

= How do I check my cache is not mixing HTML and Markdown? =

Send three requests to the same permalink, in this order, and compare the
`content-type` of each:

`curl -sI -A "Mozilla/5.0" -H "Accept: text/markdown" https://example.com/my-post/`
`curl -sI -A "Mozilla/5.0" -H "Accept: text/html" https://example.com/my-post/`
`curl -sI -A "Mozilla/5.0" -H "Accept: text/markdown" https://example.com/my-post/`

The first and third must answer `text/markdown`, the second `text/html`. If the
second returns Markdown, or the third returns HTML, something in front of PHP is
serving one stored representation to everyone: look at the `age`, `x-cache`,
`cf-cache-status` or `x-litespeed-cache` headers to see which layer, and purge it
(on LiteSpeed, see the entry above).

To check revalidation on a `.md` URL, read its `etag` and send it back:

`curl -sI -A "Mozilla/5.0" https://example.com/my-post.md`
`curl -sI -A "Mozilla/5.0" -H 'If-None-Match: W/"paste-the-etag-here"' https://example.com/my-post.md`

The second request should answer `304` with no body. A `200` instead is usually
not the plugin: some stacks strip conditional headers from the request before PHP
ever sees them (observed with nginx configured to cache the location). It is a
missed optimisation, not a correctness problem — the response is still current.

As above, the browser-like `-A` value matters: a WAF/CDN may block non-browser
user agents outright, and a block page is easy to mistake for a plugin bug.

= How do I show a Markdown button on my posts? =

Put `[sysmda_md_button]` wherever you want it — in the post, in a template, in a
widget, in a GenerateBlocks element — adding `id="123"` to point it at a specific
post. There is no automatic insertion: a button is a design decision, so it goes
exactly where you put it and nowhere else.

The button never appears on content that has no Markdown version (drafts,
password-protected posts, unsupported post types, non-standard post formats), so
it can never link to a 404. It also never ends up inside the `.md` file itself.

Which entries the menu offers is set under **Settings → Markdown Alternate →
Markdown button**.

= Can I restyle the Markdown button? =

Yes, and without fighting selectors. Twelve CSS custom properties are the whole
surface. For the button: `--sysmda-btn-fg`, `--sysmda-btn-bg`,
`--sysmda-btn-hover-fg`, `--sysmda-btn-hover-bg`, `--sysmda-btn-border`,
`--sysmda-btn-radius`, `--sysmda-btn-padding` and `--sysmda-btn-font-size`. For
the dropdown: `--sysmda-btn-menu-fg`, `--sysmda-btn-menu-bg`,
`--sysmda-btn-menu-hover-fg` and `--sysmda-btn-menu-hover-bg`.

Padding and font size are shared with the entries, so one value moves the button
and its menu together; focus reuses the hover colours, so there is no third state
to style; and the `menu` properties fall back to the button's own, so you only
set them when you want the dropdown to differ.

Paste them into **Appearance → Customize → Additional CSS** or your child theme's
stylesheet, keeping only the lines you change. For a solid dark pill:

`.sysmda-md-button { --sysmda-btn-bg: #111; --sysmda-btn-fg: #fff; --sysmda-btn-border: 0; --sysmda-btn-radius: 999px; --sysmda-btn-menu-bg: #111; --sysmda-btn-menu-hover-bg: #333; }`

The plugin never declares these properties itself — it only reads them, with the
defaults built in as fallbacks. That is what makes your rule always win, from the
Customizer or a child theme alike, whichever stylesheet the browser loads last.
The settings page shows the same list as a snippet you can copy.

If a menu entry looks invisible, set `--sysmda-btn-menu-fg`: two of the entries
are links, and some themes colour every link inside the content strongly enough
to repaint them.

To drop the plugin's stylesheet entirely and write your own, return `false` from
`sysmda_md_button_enqueue_style`.

= The button does not copy anything on my site =

Almost always because the site is served over plain HTTP. The browser Clipboard
API only exists in a secure context, so the plugin falls back to the older copy
method, which some browsers refuse in a background task. **Copy Markdown
content** is the one most affected, since it has to fetch the document first.

The plugin never shows a control it cannot operate: if no copy mechanism is
available at all, the two copy entries are simply not rendered, and **View as
Markdown** and **Download Markdown** — plain links, which need neither
JavaScript nor a secure context — remain. Moving the site to HTTPS fixes it.

One thing worth knowing if you use the hit counter: **View**, **Download** and
**Copy Markdown content** all request the `.md` URL from a real browser, so they
are counted in the *human* column. That is accurate — a person asked for them —
but the totals mix reader clicks with agents fetching the document directly.

== Screenshots ==

1. Settings — General and Markdown output: choose which content types expose a `.md`, set the cache TTL, and define the shortcode/block exclusions.
2. Settings — exclusion defaults (blocks and CSS classes) and the ACF availability notice, above the `/llms.txt` section.
3. Settings — the `/llms.txt` controls: enable the endpoint and, optionally, the enriched output (site summary and curated key content).
4. Settings — Integrations and Advanced: the `[sysmda_md_url]` shortcode, ACF/GenerateBlocks detection, and the `X-Robots-Tag` header.

== Changelog ==

= 0.33.0 =

* **The Markdown button's dropdown can now be coloured separately from the
  button**, and hover and focus are stylable. Five new custom properties:
  `--sysmda-btn-hover-fg` and `--sysmda-btn-hover-bg` for the button,
  `--sysmda-btn-menu-fg`, `--sysmda-btn-menu-hover-fg` and
  `--sysmda-btn-menu-hover-bg` for the entries. Each falls back to the button's
  own value, so you only set them when you want the menu to differ, and focus
  reuses the hover colours rather than needing a third state.
* **Fixed: a menu entry could be invisible.** Two of the four entries are links,
  and a theme rule such as `.entry-content a` is specific enough to repaint them
  — on a dark dropdown that made "View as Markdown" vanish. The plugin's own
  rules are now scoped deeply enough to hold against that, without affecting your
  own overrides, which go through the custom properties and are unaffected by
  specificity.

= 0.32.0 =

* **Removed the Markdown button's automatic placement.** The button is a design
  decision, so it now goes exactly where you put `[sysmda_md_button]` and nowhere
  else. The "Automatic placement" setting is gone, along with the machinery that
  had to keep it out of feeds, oEmbed views, excerpts and secondary loops. If you
  were relying on it, add the shortcode to your single-post template.
* **Fixed: the button could not be restyled.** Setting `--sysmda-btn-bg` in the
  Customizer did nothing, because the plugin declared the same properties on the
  same selector and its stylesheet can load last. It now declares nothing at all —
  every value is a fallback — so your rule always wins, from the Customizer or a
  child theme alike.
* **The stylesheet is down to the minimum**, and the whole styling surface is
  seven custom properties: text colour, background, border, corner radius,
  padding, font size and the dropdown backdrop. The menu entries reuse the same
  values, so one change moves the button and its menu together. The settings page
  lists them as a copy-and-paste snippet and names where to paste it.
* Fixed: site code hooking `sysmda_md_button_items` at the ordinary priority was
  overwritten by the saved selection as soon as the settings were saved, so the
  documented "may reorder and narrow" behaviour silently stopped working. The
  panel selection is now fed in as the filter's *default*, like the taxonomy
  selection.

= 0.31.0 =

* **New: an optional Markdown button for readers.** Until now the `.md` version
  was discoverable only by machines. The new `[sysmda_md_button]` shortcode adds
  a small **Markdown** dropdown offering four actions: copy the `.md` link, view
  it in a new tab, download it as a `.md` file, and copy the Markdown *itself* to
  the clipboard, ready to paste into an AI assistant. A new **Markdown button**
  settings tab can also place it before and/or after the content of every enabled
  post automatically — disabled by default, so nothing appears until you ask.
* The menu is keyboard operable, announces the result of a copy to screen
  readers, and **works without JavaScript**: the two clipboard entries are
  revealed only once the browser is known to support them, so a reader without
  JavaScript sees the two entries that are genuinely links rather than controls
  that do nothing.
* The button never appears inside the Markdown itself: the shortcode is stripped
  from the source unconditionally, even on sites that have customized the
  "Excluded shortcodes" list.
* Styling is neutral and inherits from your theme through CSS custom properties,
  follows dark themes and RTL with no extra stylesheet, and can be switched off
  entirely with `sysmda_md_button_enqueue_style`. The stylesheet and script load
  only on pages that actually show a button.
* New filters: `sysmda_md_button_position`, `sysmda_md_button_items`,
  `sysmda_md_button_label`, `sysmda_md_button_enqueue_style` and
  `sysmda_md_button_html`.

[View the full changelog](https://github.com/diecieventi/system-markdown-alternate/blob/main/CHANGELOG.md)

== Upgrade Notice ==

= 0.8.0 =
The GenerateBlocks Dynamic Tag is now always available when GenerateBlocks is
active; the enable/disable toggle was removed. No action required.

= 0.7.0 =
Integrations now appear only when ACF or GenerateBlocks are active. No action
required.
