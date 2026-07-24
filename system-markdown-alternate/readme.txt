=== System Markdown Alternate ===
Contributors: system4pc
Tags: markdown, llms.txt, ai, llm, content negotiation
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.23.3
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
* **Optional integrations**, shown only when the related plugin is active:
  * **Advanced Custom Fields**: add a subtitle and a TL;DR (from ACF fields) as a
    preamble between the H1 and the body.
  * **GenerateBlocks 2.x**: a `{{sysmda_md_url}}` Dynamic Tag, available
    automatically, usable in element fields (e.g. a Button URL).

= Developer filters =

The output is customizable through filters:

* `sysmda_markdown_supported_post_types` — post types that expose `.md` (default: none).
* `sysmda_markdown_robots_header` — the `X-Robots-Tag` value (`''` = no header).
* `sysmda_markdown_strict_406` — return `406` when the client accepts neither HTML nor
  Markdown (default `true`; `false` always serves the HTML default).
* `sysmda_markdown_canonical_url` — canonical URL for the `Link` header (`''` = no header).
* `sysmda_markdown_cache_ttl` — cache TTL in seconds (`0` = disabled).
* `sysmda_markdown_source_content` — raw source content before rendering.
* `sysmda_markdown_rendered_html` — cleaned HTML before conversion.
* `sysmda_markdown_preamble` — Markdown inserted between the H1 and the body.
* `sysmda_markdown_output` — final Markdown.
* `sysmda_markdown_excluded_block_names` — Gutenberg blocks to drop.
* `sysmda_markdown_excluded_shortcodes` — shortcodes to drop.
* `sysmda_markdown_excluded_classes` — CSS classes whose elements are dropped.
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

= What does the Markdown output look like? =

Each `.md` response is a UTF-8 document with a YAML front-matter block (title,
URL, Markdown URL, published/modified dates, and — when available — author,
featured image, categories, tags and a description), followed by the `# Title`
heading and the post body converted to clean Markdown. The exact keys, their
order and the escaping rules are documented as a stable contract, with
conformance tests, in `docs/output-format.md` in the source repository.

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
the post is edited, when the plugin is updated, or when you save the settings.

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

== Screenshots ==

1. Settings — General and Markdown output: choose which content types expose a `.md`, set the cache TTL, and define the shortcode/block exclusions.
2. Settings — exclusion defaults (blocks and CSS classes) and the ACF availability notice, above the `/llms.txt` section.
3. Settings — the `/llms.txt` controls: enable the endpoint and, optionally, the enriched output (site summary and curated key content).
4. Settings — Integrations and Advanced: the `[sysmda_md_url]` shortcode, ACF/GenerateBlocks detection, and the `X-Robots-Tag` header.

== Changelog ==

= 0.23.3 =
* Fixed: links using an uppercase or mixed-case scheme (`MAILTO:`, `TEL:`,
  `DATA:`) are now preserved instead of being mistaken for relative paths and
  rewritten into a broken absolute URL. Scheme names are case-insensitive per
  RFC 3986, which the absolute `http`/`https` check already assumed.
* Fixed: `attachment` is now excluded from the servable post types inside the
  shared eligibility logic, so the "media is never served" rule also holds when
  a post type list is injected through the `sysmda_markdown_supported_post_types`
  filter, not only when it comes from the settings page. The filtered list is
  also normalized (entries trimmed, empty and duplicate values dropped).

= 0.23.2 =
* Fixed: normalize excluded CSS-class entries with WordPress's class-specific
  sanitizer (`sanitize_html_class`), addressing the WordPress.org Plugin Check
  `register_setting()` sanitization notice. Whitespace-separated tokens are
  normalized individually, empty entries removed and duplicates dropped. The
  other multiline settings (shortcodes, block names, key content) are
  unchanged. No change to the Markdown output.

= 0.23.1 =
* Packaging: exclude the bundled `league/html-to-markdown` command-line
  binaries (`vendor/bin` and `vendor/league/html-to-markdown/bin`) from the
  distributed plugin. They are never used at runtime (the plugin calls the
  library classes directly) and are flagged as not-permitted files by the
  WordPress.org Plugin Check. No functional change.

= 0.23.0 =
* New "Settings" action link on the plugin row in the Plugins list, pointing
  to the settings page (Settings → Markdown Alternate).

= 0.22.1 =
* Clearer guidance for the LiteSpeed cache compatibility option: when LiteSpeed
  is detected and the option is off, the settings page now shows an explicit
  recommendation (whether a host honours `Vary: Accept` cannot be detected
  automatically, so enabling the rules is the safe choice when unsure). The
  FAQ now also documents a quick manual test to check whether a host ignores
  `Vary: Accept`. No change to behaviour or output.

= 0.22.0 =
* New optional `.md` hit counter (Advanced → "Count `.md` requests", off by
  default): counts how many times the Markdown endpoint is served — `200` and
  `304` alike, for both the `.md` suffix and the negotiated permalink — split
  bot vs human (empty user agents and known crawler/HTTP-client/AI-agent
  signatures count as bot; customizable via the `sysmda_md_hits_bot_patterns`
  filter). Only aggregate daily totals are stored (pruned after 90 days,
  `sysmda_md_hits_retention_days` filter): no IP addresses, no user-agent
  strings, no per-visitor data, no cookies, no external calls. The settings
  page shows read-only totals for today / last 7 / last 30 days. Note:
  requests served by a page cache or CDN without reaching PHP are not
  counted — an indicator, not analytics.
* Documented the developer filter API in the user-facing docs: new FAQ entry
  with examples and a pointer to the full filter list in the GitHub repository.

= 0.21.4 =
* Cache hardening: the negotiated Markdown and `406` responses now always send
  the standard `Cache-Control: no-cache, no-store, must-revalidate, private`
  header. These responses share their URL with the HTML page and some caches
  (default LiteSpeed, some CDNs) key by URL only and ignore `Vary: Accept`;
  previously the standard header only appeared when the LiteSpeed Cache plugin
  added it, so the protection now no longer depends on any specific cache
  plugin. The explicit `.md` URLs are unchanged: they remain fully cacheable
  with `ETag`/`Last-Modified` revalidation and no `Cache-Control`.
* The LiteSpeed page cache is now purged on plugin activation and deactivation
  (no-op when the LiteSpeed Cache plugin is absent): entries cached before
  activation carry no `Vary` header and could produce mixed HTML/Markdown
  responses that are hard to diagnose.

= 0.21.3 =
* Fix: removing the LiteSpeed `.htaccess` block (disabling the option or
  uninstalling) no longer leaves blank lines at the top of the file when the
  block was the first thing in `.htaccess`.

= 0.21.2 =
* Refined the LiteSpeed `.htaccess` rules: two separate bypass rules instead of
  one combined condition. Requests with an empty Accept header or a wildcard
  Accept (`text/*`, `*/*`) now stay on the cached HTML (PHP would serve HTML
  for them anyway), so fewer requests skip the page cache. Same behaviour for
  all real traffic (browsers, Markdown agents, 406 probes).
* The rules-present check compares directives only (comments and indentation
  ignored), so a hand-maintained block with equivalent directives is left
  untouched by the settings-page sync instead of being rewritten.

= 0.21.1 =
* Fix: the LiteSpeed compatibility block is now written at the TOP of
  `.htaccess`. Appended at the bottom (the `insert_with_markers` default) it
  landed after the `# BEGIN WordPress` block, whose `[L]` rules end every
  rewrite pass, so the bypass rules were never evaluated (verified live). An
  existing bottom copy is automatically moved to the top on the next settings
  page load.
* Fix: the rules-present check now ignores comment lines (WordPress adds its
  own instruction comment inside marker blocks) and verifies the block
  position; previously the settings page always reported the rules as missing
  and re-wrote the block (with a LiteSpeed purge) on every load.

= 0.21.0 =
* LiteSpeed cache compatibility. Some LiteSpeed servers cache the permalink by
  URL only and ignore `Vary: Accept`, so a cached Markdown variant could be
  served to HTML clients (and cached HTML to Markdown clients). The negotiated
  Markdown and `406` responses now send `X-LiteSpeed-Cache-Control: no-cache`
  and define `DONOTCACHEPAGE`, so URL-keyed page caches never store them; the
  explicit `.md` URLs remain fully cacheable. A new opt-in setting (Advanced →
  LiteSpeed cache compatibility) writes an `.htaccess` block, inert outside
  LiteSpeed, that makes Markdown-negotiating requests bypass the LiteSpeed page
  cache so PHP always performs the negotiation; the block is kept in sync from
  the settings page, purges the LiteSpeed cache on change, and is removed on
  uninstall.

= 0.20.2 =
* Packaging fix: keep `composer.json` alongside the bundled `vendor/` directory
  so WordPress.org Plugin Check can review the production dependencies. Tests
  and `composer.lock` remain excluded from the distributable package.

= 0.20.1 =
* Removed duplicate Settings API success notices from the plugin settings page;
  WordPress now remains the single source that renders these notices.
* Description fallbacks now remove complete `script`, `style` and `iframe`
  nodes before extracting text. This prevents embedded code from leaking into
  `.md` front matter and enriched `/llms.txt` entries.
* Completed the WordPress.org internationalization readiness audit: runtime
  strings remain static English with the plugin text domain, while code
  comments, tests, build tooling and workflow messages are now consistently
  English. Official translations remain delivered exclusively through
  translate.wordpress.org language packs.

= 0.20.0 =
* All internal names now use the distinctive `sysmda_` / `SYSMDA_` prefix and
  the `Diecieventi\SystemMarkdownAlternate` namespace, per the wordpress.org
  plugin review guidelines (options, settings, filters, shortcode, Dynamic Tag,
  constants, cache keys, asset handles). **Breaking**: filters and the shortcode
  are renamed (`sma_*` → `sysmda_*`, `[sma_md_url]` → `[sysmda_md_url]`,
  `{{sma_md_url}}` → `{{sysmda_md_url}}`); settings must be re-saved after
  updating, since option names changed.
* Removed bundled translation files and manual translation loading:
  translations are delivered as language packs by translate.wordpress.org.

= 0.19.0 =
* `/llms.txt`: new optional **last modified dates** toggle (off by default —
  when off the output is unchanged). When enabled, every entry gets an
  `(updated: YYYY-MM-DD)` note (ISO date, taken from the post's last
  modification), in both the basic and the enriched output, so LLM crawlers can
  spot changed content without re-fetching each `.md` URL. New
  `sysmda_llms_txt_lastmod` filter.

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
  `sysmda_llms_txt_footer` filter as a hook for future LLM signals.

= 0.15.0 =
* Synced patterns (reusable blocks) are now expanded and cleaned like regular
  content: excluded blocks and shortcodes inside a pattern no longer leak into
  the Markdown output.
* Plain permalinks (`?p=123`) no longer produce broken `.md` URLs: Markdown URLs
  fall back to `?format=markdown` (served via content negotiation) and the
  settings page shows a notice.
* New `sysmda_llms_txt_cache_ttl` filter for the `/llms.txt` cache TTL
  (previously shared with `sysmda_markdown_cache_ttl`, which received a `null`
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
  (new `sysmda_markdown_strict_406` filter, on by default; real browsers and crawlers are
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
* The GenerateBlocks `{{sysmda_md_url}}` Dynamic Tag now registers automatically
  whenever GenerateBlocks 2.x is active (the on/off toggle has been removed). It
  resolves to an empty value for non-servable posts, so leftover tags never
  render as literal text while the plugin and GenerateBlocks are active.

= 0.7.0 =
* Admin panel reorganized into sections; ACF and GenerateBlocks integrations are
  shown only when the related plugin is active.
* Dedicated Shortcode section.

= 0.6.0 =
* Single `[sysmda_md_url]` shortcode.
* GenerateBlocks 2.x `{{sysmda_md_url}}` Dynamic Tag, with an on/off toggle.

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
  `sysmda_markdown_excluded_classes` filter.

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
