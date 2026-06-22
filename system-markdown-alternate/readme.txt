=== System Markdown Alternate ===
Contributors: diecieventi
Tags: markdown, llms.txt, ai, llm, content negotiation
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.9.0
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
  or `?format=markdown` requests.
* **`rel="alternate"` link** in the `<head>` of supported singular content.
* **Correct HTTP headers**: `Content-Type: text/markdown`, `X-Robots-Tag`
  (default `noindex, follow`) and a `Link: rel="canonical"` back to the HTML.
* **Clean conversion**: Gutenberg blocks are rendered individually (no injected
  related/CTA blocks), excluded blocks/shortcodes/CSS classes are removed, code
  blocks become fenced blocks, URLs are made absolute.
* **`/llms.txt` endpoint** (optional): an index of your content for LLMs and AI
  agents.
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

== Changelog ==

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
