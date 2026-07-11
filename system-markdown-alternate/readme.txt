=== System Markdown Alternate ===
Contributors: system4pc
Tags: markdown, llms.txt, ai, llm, content negotiation
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.19.0
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
* **`Vary: Accept`** on negotiable URLs, so caches and CDNs never mix the HTML and
  Markdown representations of the same address.
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
* **Transient cache** with proactive invalidation on post edit, plugin update
  and settings change.
* **Admin panel** to choose which post types are exposed and to tune cache,
  exclusions and headers — no post type is exposed until you pick one.
* **Shortcode** `[sma_md_url]` to output the Markdown URL anywhere.
* **Optional integrations**, shown only when the related plugin is active:
  * **Advanced Custom Fields**: add a subtitle and a TL;DR (from ACF fields) as a
    preamble between the H1 and the body.
  * **GenerateBlocks 2.x**: a `{{sma_md_url}}` Dynamic Tag, available
    automatically, usable in element fields (e.g. a Button URL).

= Developer filters =

The output is customizable through filters:

* `sma_markdown_supported_post_types` — post types that expose `.md` (default: none).
* `sma_markdown_robots_header` — the `X-Robots-Tag` value (`''` = no header).
* `sma_markdown_strict_406` — return `406` when the client accepts neither HTML nor
  Markdown (default `true`; `false` always serves the HTML default).
* `sma_markdown_canonical_url` — canonical URL for the `Link` header (`''` = no header).
* `sma_markdown_cache_ttl` — cache TTL in seconds (`0` = disabled).
* `sma_markdown_source_content` — raw source content before rendering.
* `sma_markdown_rendered_html` — cleaned HTML before conversion.
* `sma_markdown_preamble` — Markdown inserted between the H1 and the body.
* `sma_markdown_output` — final Markdown.
* `sma_markdown_excluded_block_names` — Gutenberg blocks to drop.
* `sma_markdown_excluded_shortcodes` — shortcodes to drop.
* `sma_markdown_excluded_classes` — CSS classes whose elements are dropped.
* `sma_acf_field_keys` — ACF fields appended to the source.
* `sma_acf_subtitle_key` / `sma_acf_tldr_key` — ACF fields for subtitle/TL;DR.
* `sma_llms_txt_max_posts` — max posts per type in `/llms.txt`.
* `sma_llms_txt_cache_ttl` — `/llms.txt` cache TTL in seconds (`0` = disabled).
* `sma_llms_txt_enriched` — enable the enriched `/llms.txt` output (default `false`).
* `sma_llms_txt_lastmod` — append `(updated: YYYY-MM-DD)` to every `/llms.txt`
  entry (default `false`).
* `sma_llms_txt_summary` — site summary paragraph (enriched mode only).
* `sma_llms_txt_key_content` — featured content, post IDs or URLs (enriched mode only).
* `sma_llms_txt_main_posts` — posts per type in the main sections before the
  overflow moves to `Optional` (enriched mode only, default 25).
* `sma_llms_txt_footer` — free-form block appended at the end (enriched mode only).

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

= How do I exclude part of a post from the Markdown? =

Add one of the CSS classes `no-md`, `md-exclude` or `exclude-from-markdown` to a
block; the element (and its children) is removed from the Markdown output. You
can customize the list with the `sma_markdown_excluded_classes` filter.

= Does it affect my SEO? =

The `.md` responses are sent with `X-Robots-Tag: noindex, follow` and a
`Link: rel="canonical"` header pointing back to the HTML version, so search
engines are told to prefer the original page.

= How do I get the Markdown URL in a button or template? =

Use the `[sma_md_url]` shortcode. If you run GenerateBlocks 2.x, the
`{{sma_md_url}}` Dynamic Tag is available automatically — use it in element
fields such as a Button URL. When the post has no `.md`, the tag resolves to an
empty value so GenerateBlocks can hide the element instead of leaving a broken
link.

= Is the .md content cached? =

Yes, in a transient (default 24h). The cache is regenerated automatically when
the post is edited, when the plugin is updated, or when you save the settings.

== Screenshots ==

1. Settings — General and Markdown output: choose which content types expose a `.md`, set the cache TTL, and define the shortcode/block exclusions.
2. Settings — exclusion defaults (blocks and CSS classes) and the ACF availability notice, above the `/llms.txt` section.
3. Settings — the `/llms.txt` controls: enable the endpoint and, optionally, the enriched output (site summary and curated key content).
4. Settings — Integrations and Advanced: the `[sma_md_url]` shortcode, ACF/GenerateBlocks detection, and the `X-Robots-Tag` header.

== Changelog ==

= 0.19.0 =
* `/llms.txt`: new optional **last modified dates** toggle (off by default —
  when off the output is unchanged). When enabled, every entry gets an
  `(updated: YYYY-MM-DD)` note (ISO date, taken from the post's last
  modification), in both the basic and the enriched output, so LLM crawlers can
  spot changed content without re-fetching each `.md` URL. New
  `sma_llms_txt_lastmod` filter.

= 0.18.0 =
* Conditional requests on the `.md` endpoint: the Markdown response now sends
  `ETag` and `Last-Modified`, and honours `If-None-Match` / `If-Modified-Since`,
  replying `304 Not Modified` (no body) when the client already has the current
  version. The validator reuses the existing cache-version hash
  (`post_modified_gmt` + plugin version + settings salt), so a `304` always means
  the cached body would be identical. Works even with the body cache disabled.
* `/llms.txt`: escape the link text and normalise each entry onto a single line,
  so titles or descriptions containing `[`, `]`, `(`, `)`, backslashes, newlines
  or control characters can no longer break a link or the file structure.

= 0.17.1 =
* Plugin Check compliance (wordpress.org): escape the post-type checkbox state via
  the core `checked()` helper, and annotate the deliberate direct transient cleanup
  query in `uninstall.php`. No change to behaviour or Markdown output.
* Minimum WordPress bumped to 6.1 (the object-cache group flush on uninstall uses
  `wp_cache_flush_group()`, available since 6.1).

= 0.17.0 =
* Admin settings page restyle (presentation only — no change to options, saving,
  sanitization, security or Markdown output): a page header with a single Save
  button, native WordPress tabs (General, Markdown output, llms.txt, Integrations,
  Advanced), section cards, a two-column layout with an at-a-glance `/llms.txt`
  status/conflict panel, and the built-in exclusion defaults collapsed into a
  `details` disclosure. Fully responsive, admin-scoped CSS, and a tiny dependency-
  free vanilla-JS enhancement for the tabs (the page stays usable without JS).

= 0.16.0 =
* Optional enriched `/llms.txt` output (new toggle, off by default — when off the
  output is unchanged): site summary paragraph, curated "Key content" section
  (post IDs or URLs from the settings page), a description for each entry (Rank
  Math meta → excerpt → trimmed text, same chain as the front matter), overflow
  beyond the most recent posts moved to an `Optional` section, and a
  `sma_llms_txt_footer` filter as a hook for future LLM signals.

= 0.15.0 =
* Synced patterns (reusable blocks) are now expanded and cleaned like regular
  content: excluded blocks and shortcodes inside a pattern no longer leak into
  the Markdown output.
* Plain permalinks (`?p=123`) no longer produce broken `.md` URLs: Markdown URLs
  fall back to `?format=markdown` (served via content negotiation) and the
  settings page shows a notice.
* New `sma_llms_txt_cache_ttl` filter for the `/llms.txt` cache TTL
  (previously shared with `sma_markdown_cache_ttl`, which received a `null`
  post and could break third-party callbacks).
* Internal: post eligibility rules centralized in a single helper; local test
  suite and CI added.

= 0.14.0 =
* Content negotiation is now RFC 9110 compliant. The `Accept` header is parsed with
  q-values: Markdown is served only when explicitly preferred, so clients that prefer
  HTML (or send a wildcard such as `*/*`) keep getting HTML.
* Negotiable URLs now send `Vary: Accept`, so caches/CDNs store the HTML and Markdown
  representations separately instead of poisoning each other.
* Optional `406 Not Acceptable` when the client accepts neither HTML nor Markdown
  (new `sma_markdown_strict_406` filter, on by default; real browsers and crawlers are
  never affected).

= 0.13.1 =
* Repository moved to the Web Dietro le Quinte GitHub organization: updated the
  Plugin URI and Composer package name accordingly, and added an Author URI.
  No functional changes.

= 0.13.0 =
* Internationalization (i18n): all admin panel strings (and the plugin header
  description) are now translatable through the `system-markdown-alternate` text
  domain, with English as the source language. A bundled `it_IT` translation
  keeps the UI in Italian. The translation template (`.pot`) and the Italian
  translation (`.po`, `.mo` and a `.l10n.php` preferred by WordPress 6.5+) ship
  in `/languages`, and the text domain is loaded on `init`.

= 0.12.1 =
* Removed the on-demand HTTP "Check /llms.txt now" button and the loopback
  request: it was unreliable behind a WAF/CDN and added no real value. The
  /llms.txt conflict detection now relies only on stable local signals (active
  SEO plugins + physical file).

= 0.12.0 =
* Settings page UX overhaul (single page, native Settings API): sections grouped
  into Generale, Output Markdown, llms.txt, Integrazioni, Avanzate; supported
  post types moved to the top; compact exclusion textareas with defaults shown
  one per line; llms.txt status (enabled + URL); page-scoped admin CSS.
* Exclusion lists are normalized on save (trim, drop empty lines, de-duplicate).
* Supported post types are validated against the registered public types.
* ACF settings are registered only when ACF is active, so saving while ACF is
  inactive no longer wipes the saved field names.

= 0.11.0 =
* Simpler, low-maintenance `/llms.txt` conflict detection: it now only checks
  whether known SEO plugins (Rank Math, Yoast, AIOSEO, SEOPress) are active and
  whether a physical llms.txt file exists, then warns. It no longer reads those
  plugins' internal options to guess if their feature is on (brittle and
  maintenance-heavy). The on-demand HTTP check is kept.

= 0.10.1 =
* The on-demand `/llms.txt` HTTP check now uses a browser User-Agent (avoids
  false negatives from WAFs that block bot user agents) and uses the response
  content type to tell a real text llms.txt from an HTML block/soft-404 page.

= 0.10.0 =
* Automatic conflict detection for `/llms.txt`: warns in the settings if another
  SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress) has an llms.txt feature active,
  if a physical `llms.txt` file exists at the site root, or (on demand) if the
  URL already responds. Detection checks the feature state, not just whether the
  plugin is installed.

= 0.9.1 =
* No `rel="alternate"` link is printed when no post type is enabled (previously
  it could appear on any singular content).
* Relative links and images are now resolved against the source permalink, not
  the site root (e.g. `file.pdf` inside `/blog/post/` resolves correctly).
* The Rank Math description is only discarded when it contains an unresolved
  `%variable%` placeholder, not any `%` (so "20% off" is kept).
* The ACF TL;DR now goes through the same DOM pipeline as the body (exclusions,
  code normalization, absolute URLs).

= 0.9.0 =
* Performance: the `/llms.txt` index is now cached and skips priming meta/term
  caches; password-protected posts are excluded from it.
* Caching now uses the persistent object cache (Redis/Memcached) when available,
  falling back to transients otherwise.
* Cache invalidation skips revisions and autosaves.
* Added `uninstall.php` to remove all plugin options and cached data on deletion.

= 0.8.0 =
* The GenerateBlocks `{{sma_md_url}}` Dynamic Tag now registers automatically
  whenever GenerateBlocks 2.x is active (the on/off toggle has been removed). It
  resolves to an empty value for non-servable posts, so leftover tags never
  render as literal text while the plugin and GenerateBlocks are active.

= 0.7.0 =
* Admin panel reorganized into sections; ACF and GenerateBlocks integrations are
  shown only when the related plugin is active.
* Dedicated Shortcode section.

= 0.6.0 =
* Single `[sma_md_url]` shortcode.
* GenerateBlocks 2.x `{{sma_md_url}}` Dynamic Tag, with an on/off toggle.

= 0.5.0 =
* Shortcodes to output the Markdown URL.

= 0.4.1 =
* Cache invalidation on plugin update and settings change.

= 0.4.0 =
* ACF subtitle and TL;DR rendered as a preamble between the H1 and the body.

= 0.3.0 =
* `Link: rel="canonical"` header on `.md` responses.

= 0.2.1 =
* Settings-driven filters now apply on front-end requests too.

= 0.2.0 =
* `/llms.txt` endpoint, custom post type support, content negotiation,
  proactive cache invalidation, ACF integration, admin settings panel and the
  `sma_markdown_excluded_classes` filter.

= 0.1.0 =
* Initial release: `.md` endpoint, alternate link, front matter, block/shortcode
  cleaning and transient cache.

== Upgrade Notice ==

= 0.8.0 =
The GenerateBlocks Dynamic Tag is now always available when GenerateBlocks is
active; the enable/disable toggle was removed. No action required.

= 0.7.0 =
Integrations now appear only when ACF or GenerateBlocks are active. No action
required.
