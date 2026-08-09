# Filters (public contract)

Every filter System Markdown Alternate exposes, with its default value and what
changing it does. This is the **canonical list**: `readme.txt` and `README.md`
carry examples and link here, and this page is what a version bump is measured
against.

Add these from a theme's `functions.php` or, better, a small site plugin.

- [Which content is served](#which-content-is-served)
- [HTTP headers](#http-headers)
- [Caching](#caching)
- [The conversion pipeline](#the-conversion-pipeline)
- [Front matter](#front-matter)
- [ACF integration](#acf-integration)
- [`/llms.txt`](#llmstxt)
- [Hit counter](#hit-counter)
- [Default exclusions](#default-exclusions)
- [Examples](#examples)

## Which content is served

```php
apply_filters( 'sysmda_markdown_supported_post_types', array() );
```
Post types that expose a `.md`. Defaults to **empty**, so the plugin stays
inactive until a type is selected in the settings panel. The result is
normalized and `attachment` is always stripped
(`PostSupport::sanitize_types()`).

```php
apply_filters( 'sysmda_markdown_excluded_post_formats', $formats, $post );
```
Non-standard post formats that never expose a `.md`. Defaults to all nine
(aside, audio, chat, gallery, image, link, quote, status, video); return an
empty array to serve every format. The rule lives in `is_servable()`, so it
applies to the `.md` route, negotiation, `rel="alternate"`, `/llms.txt`, the
shortcodes and the dynamic tag at once.

## HTTP headers

```php
apply_filters( 'sysmda_markdown_robots_header', 'noindex, follow', $post );
```
The `X-Robots-Tag` value. `''` means the header is not sent. Sanitized before
reaching `header()`.

```php
apply_filters( 'sysmda_markdown_strict_406', true );
```
Return `406` when the client accepts neither HTML nor Markdown. `false` always
serves the default HTML instead. Real clients always send `text/html` or a
wildcard, so this is rarely hit in practice.

```php
apply_filters( 'sysmda_markdown_canonical_url', get_permalink( $post ), $post );
```
Canonical URL for the `Link` header. `''` means no `Link: rel=canonical` is
sent.

## Caching

```php
apply_filters( 'sysmda_cache_control', 'public, max-age=0, must-revalidate' );
```
`Cache-Control` on the URLs the plugin owns (the `.md` endpoint and
`/llms.txt`). `''` sends no header at all, WordPress's own included.

It does **not** apply to Markdown negotiated on the canonical permalink: that
representation shares its URL with the HTML page, so it is always sent
non-cacheable and this filter is not consulted there.

> **Setting a freshness lifetime here (`s-maxage`, `max-age`) makes stale
> Markdown possible**: no page cache purges a `.md` when the post is saved,
> because cache plugins purge the permalink and have no idea `permalink.md`
> exists. Correctness rests on revalidation, which is why the default declares
> the response stale on arrival.

```php
apply_filters( 'sysmda_markdown_cache_ttl', DAY_IN_SECONDS, $post );
```
Body-cache TTL in seconds. `0` disables the cache. Conditional requests
(`ETag` / `304`) keep working with the cache off, since the validator derives
from `post_modified_gmt` rather than from the stored body.

```php
apply_filters( 'sysmda_markdown_cache_dependencies', array(), $post );
```
Extra inputs folded into the cache validator and the `ETag`, for output the
plugin cannot fingerprint on its own: dynamic blocks, shortcodes, or site
filters that read options or remote data. Takes a list of scalars; `[]` means
none.

**This is the answer to "my output changes and the `.md` does not."** Anything
that can change the emitted Markdown without touching `post_modified_gmt` has
to be declared here, or a client holding the old validator keeps getting `304`
with stale content.

⚠️ **It runs on every request, including `304`s** — it feeds the `ETag`, which
is computed before the cache is consulted. Declare a value you already have or
one that is cheap to read; a remote call or a heavy query here is paid even on
responses that send no body. See
[Filters on the every-request path](#filters-on-the-every-request-path).

```php
apply_filters( 'sysmda_markdown_prewarm', false, $post_id );
```
`true` rebuilds a post's Markdown cache on WP-Cron about 30 seconds after each
save, instead of on the first request. Off by default because cron has no
request context: a dynamic block or shortcode inspecting `is_singular()` or the
queried object can render differently there, and that difference is what would
get cached. No-op when the TTL is `0`.

## The conversion pipeline

Two kinds of hook live here, and treating them alike is the mistake this
section exists to prevent: four run once, in a known order; three run wherever
content is cleaned, an unbounded number of times.

### The document hooks, in order

These four run **once per document build**, in this order.

```php
apply_filters( 'sysmda_markdown_source_content', $post->post_content, $post );
```
Raw source content, before any rendering. This is where the ACF integration
appends its fields.

```php
apply_filters( 'sysmda_markdown_rendered_html', $html, $post );
```
Cleaned HTML — after shortcode stripping, block cleaning, block rendering,
code-block normalization and URL absolutization. The last point before the
Markdown conversion.

```php
apply_filters( 'sysmda_markdown_preamble', '', $post );
```
Markdown inserted between the `# Title` heading and the body. This is what the
ACF integration uses for subtitle and TL;DR.

<a id="the-preamble-re-entry"></a>
**A preamble that renders HTML re-enters the cleaning path.** A callback here
that turns HTML into Markdown has to clean that HTML too, and
`ContentRenderer::render_fragment()` exists for exactly that. It strips
shortcodes and runs the DOM pass, so two of the cleaning filters below fire
again — after `sysmda_markdown_rendered_html` has already run. It does not
parse blocks.

In the bundled ACF integration this is the TL;DR path only (a WYSIWYG field, so
it carries markup) and only when the field is configured and non-empty; the
subtitle goes through `wp_strip_all_tags()` and never re-enters.

```php
apply_filters( 'sysmda_markdown_output', $markdown, $post );
```
The final Markdown document, front matter included. Last hook of the build.

### The cleaning filters, which are not points in that sequence

```php
apply_filters( 'sysmda_markdown_excluded_shortcodes', $shortcodes );
apply_filters( 'sysmda_markdown_excluded_block_names', $block_names );
apply_filters( 'sysmda_markdown_excluded_classes', $css_classes );
```

Shortcodes, Gutenberg blocks and CSS classes dropped from the output. See
[Default exclusions](#default-exclusions) for the values they receive.

These are **not** stages of the ordered sequence above. They are consulted
wherever the plugin cleans content, which is more places than the body
conversion — including one that runs *before* `sysmda_markdown_source_content`,
and one on a different endpoint entirely. Known call sites:

| Filter | Called from |
|--------|-------------|
| `sysmda_markdown_excluded_shortcodes` | the post body; rendered preamble fragments; expanded synced patterns (`core/block`); the front-matter description fallback, which runs **before** the source-content hook; and `/llms.txt`, for entries that carry a description |
| `sysmda_markdown_excluded_block_names` | block cleaning — only when the post has blocks |
| `sysmda_markdown_excluded_classes` | block cleaning (blocks only); every DOM pass, body and fragments alike, unless the HTML is empty or fails to parse |

**Treat that column as illustrative, not as a contract.** It spans four classes
and two endpoints, and it has been wrong every time it was written down as a
count. A new call site can appear in any release without notice.

What is guaranteed instead is the shape of the callback. Write these filters as
**pure, cheap functions of their input**: same list every time, no accumulated
state, no counting of invocations, no side effects, no expensive work. A
callback that assumes a single invocation will be wrong on a post with synced
patterns, and a slow one is paid again for every entry `/llms.txt` describes.

### None of them run on a cache hit

`MarkdownController::get_markdown()` returns the stored document before
`build_markdown()` is reached, so a request served from cache fires none of
the hooks in this section — document hooks and cleaning filters alike. With the
default TTL that is the common case.

**Do not read that as "filters only run when the document is rebuilt."** Others
run on the opposite schedule — see below.

## Filters on the every-request path

Eligibility is decided before anything else, and `cache_version()` produces the
`ETag`, so it runs **before** the cache lookup and before any header is sent.
Both are reached on cache hits and on `304 Not Modified` responses that send no
body at all. The filters they read are reached with them:

| Filter | Reached through |
|--------|-----------------|
| `sysmda_markdown_supported_post_types` | route eligibility, on every candidate request |
| `sysmda_markdown_excluded_post_formats` | `PostSupport::is_servable()`, same |
| `sysmda_front_matter_taxonomy_slugs` | `cache_version()` → `taxonomies_fingerprint()` |
| `sysmda_front_matter_taxonomies` | same |
| `sysmda_markdown_cache_dependencies` | `cache_version()` → `dependencies_fingerprint()` |
| `sysmda_acf_field_keys` | same, and only while ACF is active |
| `sysmda_acf_subtitle_key` | same |
| `sysmda_acf_tldr_key` | same |

The header filters are not alike, and the difference is the `304`:

- `sysmda_markdown_robots_header` and `sysmda_markdown_canonical_url` are
  applied in `send_headers()`, on the `200` path only. A `304` sends `ETag` and
  `Last-Modified` and nothing filtered, so neither is reached.
- `sysmda_cache_control` is sent before the body on the `.md` route, so the
  conditional `304` carries the same policy as the `200` and the filter is
  reached by both. The negotiated permalink does not use it at all: that route
  sends a fixed no-cache set instead, because the Markdown variant shares its
  URL with the HTML page.

As with the cleaning filters, **this is membership, not a schedule**: which of
these a given request reaches depends on the route, on whether the body is
rebuilt, and on which integrations are active. Do not derive a count from it.

**Keep them cheap, and never do I/O in them.** A `304` exists to cost almost
nothing, and work attached here is paid even by responses that send no body.
This matters most for `sysmda_markdown_cache_dependencies`, whose whole purpose
is to describe out-of-post data: declare a value you already have, or a cheap
one — do not fetch it here.

## Front matter

```php
apply_filters( 'sysmda_front_matter_enabled', true, $post );
```
`false` emits no front matter at all and the document starts at the `# Title`
heading, with no leading blank line. A per-site opt-out for setups that treat
YAML front matter as build-time noise — **not** a change of default: `url`,
`date_modified` and `author` are provenance the body cannot carry.

```php
apply_filters( 'sysmda_front_matter_taxonomies', ! empty( $slugs ) );
```
Kill switch for the nested `taxonomies:` block. Defaults to on as soon as one
taxonomy is selected in the panel; `false` never emits it.

```php
apply_filters( 'sysmda_front_matter_taxonomy_slugs', $slugs, $post );
```
Which taxonomies are emitted. Receives the selection saved in the panel (fed in
at priority 5), **not** an auto-detected list, and may narrow it or extend it —
opting a non-public taxonomy in is a deliberate choice. Return `[]` to opt out
for a post. `category`, `post_tag`, `post_format` and invalid slugs are always
stripped afterwards, so this filter can neither duplicate the dedicated keys nor
break the YAML.

## ACF integration

```php
apply_filters( 'sysmda_acf_field_keys', array(), $post );
```
ACF fields appended to the source content.

```php
apply_filters( 'sysmda_acf_subtitle_key', '', $post );
apply_filters( 'sysmda_acf_tldr_key', '', $post );
```
ACF field names for the subtitle and the TL;DR. `''` disables each one. Both are
also configurable from the settings panel when ACF is active.

All three are read from two places, for different reasons: where the value is
used, and inside the cache validator, so that editing an ACF field moves the
`ETag`. Both are skipped entirely while ACF is inactive — `acf_dependencies()`
and the bundled callbacks return before applying them if `get_field()` does not
exist.

The validator read is on the
[every-request path](#filters-on-the-every-request-path). Return a field name,
not the result of looking one up.

## `/llms.txt`

```php
apply_filters( 'sysmda_llms_txt_max_posts', 500, $post_type );
apply_filters( 'sysmda_llms_txt_cache_ttl', DAY_IN_SECONDS );
```
Maximum posts listed per type, and the cache TTL in seconds (`0` = off).

```php
apply_filters( 'sysmda_llms_txt_enriched', false );
apply_filters( 'sysmda_llms_txt_lastmod', false );
```
Enable the enriched output, and append `(updated: YYYY-MM-DD)` to every entry.
Both default to `false`, and off means the base output is unchanged.

```php
apply_filters( 'sysmda_llms_txt_summary', '' );
apply_filters( 'sysmda_llms_txt_key_content', array() );
apply_filters( 'sysmda_llms_txt_main_posts', 25, $post_type );
apply_filters( 'sysmda_llms_txt_footer', '' );
```
Enriched mode only: the site summary paragraph, the featured content (post IDs
or URLs), how many posts per type appear in the main section before the
overflow moves under `## Optional`, and a free-form trailing block — the hook
for policy or LLM signals.

## Hit counter

```php
apply_filters( 'sysmda_md_hits_bot_patterns', $patterns );
apply_filters( 'sysmda_md_hits_retention_days', 90 );
```
Case-insensitive user-agent substrings classified as bot, and how many days of
daily buckets are kept.

The counter stores **only** aggregate daily totals split bot/human. It never
stores IP addresses, raw user-agent strings, timestamps finer than the day, or
any per-visitor identifier: the user agent is read once to classify the request
and immediately discarded.

## Default exclusions

Returned to `sysmda_markdown_excluded_block_names`,
`sysmda_markdown_excluded_shortcodes` and `sysmda_markdown_excluded_classes`
respectively.

| Kind | Defaults |
|------|----------|
| Block names | `gravityforms/form`, `contact-form-7/contact-form-selector`, `wpforms/form-selector`, `mailerlite/form`, `luckywp/toc` |
| Shortcodes | `contact-form-7`, `gravityform`, `wpforms`, `mailerlite_form`, `lwptoc` |
| CSS classes | `no-md`, `md-exclude`, `exclude-from-markdown` |

## Examples

```php
// Append a custom footer to every Markdown document.
add_filter( 'sysmda_markdown_output', function ( $markdown, $post ) {
    return $markdown . "\n---\nConverted from " . get_permalink( $post ) . "\n";
}, 10, 2 );

// Exclude an extra CSS class from the conversion.
add_filter( 'sysmda_markdown_excluded_classes', function ( $classes ) {
    $classes[] = 'my-private-block';
    return $classes;
} );

// Serve every post format again, including asides and statuses.
add_filter( 'sysmda_markdown_excluded_post_formats', '__return_empty_array' );

// Serve the body without the YAML front matter.
add_filter( 'sysmda_front_matter_enabled', '__return_false' );

// Enable the enriched /llms.txt output.
add_filter( 'sysmda_llms_txt_enriched', '__return_true' );

// Declare an out-of-post dependency so the ETag changes when it does.
add_filter( 'sysmda_markdown_cache_dependencies', function ( $deps, $post ) {
    $deps[] = get_option( 'my_plugin_setting' );
    return $deps;
}, 10, 2 );
```

## See also

- [`output-format.md`](output-format.md) — the front-matter keys, their order
  and the HTTP contract, as a stable append-only contract.
- [`../README.md`](../README.md) — repository overview.
