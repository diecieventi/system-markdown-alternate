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

```php
apply_filters( 'sysmda_markdown_prewarm', false, $post_id );
```
`true` rebuilds a post's Markdown cache on WP-Cron about 30 seconds after each
save, instead of on the first request. Off by default because cron has no
request context: a dynamic block or shortcode inspecting `is_singular()` or the
queried object can render differently there, and that difference is what would
get cached. No-op when the TTL is `0`.

## The conversion pipeline

Listed in the order they run **for the post body**. The exclusion filters are
consulted inside `ContentRenderer::render()`, between the source-content and
rendered-HTML hooks — not, as a flat list might suggest, after the final
output. Attach a transformation to the stage that still has the shape you need.

### When the exclusion filters actually run

They are not called a fixed number of times per request: it depends on whether
the body is block-based and on whether a preamble renders HTML of its own.

| Filter | Body | Preamble fragment |
|--------|------|-------------------|
| `sysmda_markdown_excluded_shortcodes` | always, once | once per fragment |
| `sysmda_markdown_excluded_block_names` | only when the post has blocks | never |
| `sysmda_markdown_excluded_classes` | once in the DOM pass, plus once more when the post has blocks | once per fragment |

So `sysmda_markdown_excluded_classes` runs twice for a block-based post, once
for classic content, and once more for each rendered fragment. It is also
skipped entirely when the HTML is empty or fails to parse, because
`process_dom()` returns before reaching it.

**Write these filters as pure functions of their input.** Returning a different
list depending on when the filter is called produces output where one pass
disagrees with another — the reason to state the call sites rather than a
count.

```php
apply_filters( 'sysmda_markdown_source_content', $post->post_content, $post );
```
Raw source content, before any rendering. This is where the ACF integration
appends its fields.

```php
apply_filters( 'sysmda_markdown_excluded_shortcodes', $shortcodes );
```
Shortcodes stripped from the raw source, block content included. Runs first,
so an excluded shortcode never reaches the renderer.

```php
apply_filters( 'sysmda_markdown_excluded_block_names', $block_names );
```
Gutenberg blocks dropped while the block tree is cleaned, before
`render_block()` is called on what remains. Classic (non-block) content never
reaches this filter.

```php
apply_filters( 'sysmda_markdown_excluded_classes', $css_classes );
```
CSS classes whose elements are dropped. Applied against each block's
`className` attribute during block cleaning, and again during the DOM pass over
the rendered HTML — the latter is what catches nested elements a block
attribute cannot describe. See [the table above](#when-the-exclusion-filters-actually-run)
for how many times it runs.

```php
apply_filters( 'sysmda_markdown_rendered_html', $html, $post );
```
Cleaned HTML, after block rendering, exclusions, code-block normalization and
URL absolutization — the last point before the Markdown conversion.

```php
apply_filters( 'sysmda_markdown_preamble', '', $post );
```
Markdown inserted between the `# Title` heading and the body. This is what the
ACF integration uses for subtitle and TL;DR.

<a id="the-preamble-re-entry"></a>
**The preamble re-enters the pipeline.** A callback that turns HTML into
Markdown here has to clean that HTML too, and the plugin exposes
`ContentRenderer::render_fragment()` for exactly that. It strips shortcodes and
runs the DOM pass, so `sysmda_markdown_excluded_shortcodes` and
`sysmda_markdown_excluded_classes` fire **again, after
`sysmda_markdown_rendered_html` has already run**. It does not parse blocks, so
`sysmda_markdown_excluded_block_names` is not consulted for a fragment.

In the bundled ACF integration this happens only on the TL;DR path (a WYSIWYG
field, so it carries markup), and only when the field is configured and
non-empty. The subtitle goes through `wp_strip_all_tags()` and never re-enters.

```php
apply_filters( 'sysmda_markdown_output', $markdown, $post );
```
The final Markdown document, front matter included. Last hook in the pipeline.

See [Default exclusions](#default-exclusions) for the values the three
exclusion filters receive.

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
