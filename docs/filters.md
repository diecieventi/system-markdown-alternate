# Developer extension API

Every filter System Markdown Alternate exposes, with its default value, what
changing it does and **how much compatibility it promises**. This is the
**canonical list**: `readme.txt` and `README.md` carry examples and link here,
and this page is what a version bump is measured against.

Add these from a theme's `functions.php` or, better, a small site plugin.

- [Stability levels](#stability-levels)
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

## Stability levels

Not every hook carries the same promise, and the difference is not how useful it
is — it is **what the hook is anchored to**, because that decides what the plugin
can still change underneath it.

### Stable

Anchored to something that is not moving: a setting in the panel, or a concept
this plugin *is about* — what may be served, what the final document is, what the
response says about caching. Renaming one, removing it, dropping a parameter or
changing its meaning is a breaking change, and goes through deprecation
(`apply_filters_deprecated()` where practical), the changelog and this page.

The other half of that promise is what it does **not** cover, because a rule that
only says what is forbidden is read as forbidding everything: appending an
optional parameter after the existing ones, adding a new hook, and rewriting the
implementation behind a hook while its input and output stay the same are all
compatible changes, and ship without ceremony. A callback registered with the
arity it was written for keeps working across all three.

Most of these are stable for free rather than by sacrifice: fourteen of them are
the mechanism by which a saved setting reaches the code — `AdminSettings` feeds
the stored option in as the filter's own value — so they last exactly as long as
the checkbox does, and keeping them costs no design freedom at all.

### Advanced

Anchored to a **stage of the current implementation**: where the conversion
pipeline happens to cut, how ACF fields are read, how the hit counter classifies
a request, how `/llms.txt` is laid out. Supported, documented and not changed
without reason — but they may evolve while the plugin is pre-1.0, and a future
conversion engine (a block-native pipeline, a different converter) is allowed to
move them. Changes stay deliberate and documented; they are simply not treated
as breaking.

Use them freely. Just do not build a product on their exact shape without
pinning a plugin version.

### What this is not

The Markdown **output** is a different and stronger contract, versioned
separately in [`output-format.md`](output-format.md) and enforced by golden
tests. Consuming the `.md` format is not the same as hooking the PHP API, and
the two do not need the same guarantee — the format is read by crawlers and
agents that cannot be asked to pin a version, the hooks are read by code that
can.

Anything not listed on this page is internal implementation, with no
compatibility promise of any kind.

### The full list

| Filter | Level |
|--------|-------|
| `sysmda_markdown_supported_post_types` | Stable |
| `sysmda_markdown_excluded_post_formats` | Stable |
| `sysmda_post_is_servable` | Stable |
| `sysmda_markdown_unsupported_builders` | Stable |
| `sysmda_markdown_excluded_woocommerce_pages` | Stable |
| `sysmda_markdown_robots_header` | Stable |
| `sysmda_markdown_strict_406` | Stable |
| `sysmda_markdown_canonical_url` | Stable |
| `sysmda_cache_control` | Stable |
| `sysmda_markdown_cache_ttl` | Stable |
| `sysmda_markdown_cache_dependencies` | Stable |
| `sysmda_markdown_prewarm` | Advanced |
| `sysmda_markdown_source_content` | Advanced |
| `sysmda_markdown_appended_html` | Advanced |
| `sysmda_markdown_rendered_html` | Advanced |
| `sysmda_markdown_preamble` | Advanced |
| `sysmda_markdown_output` | Stable |
| `sysmda_markdown_excluded_shortcodes` | Stable |
| `sysmda_markdown_excluded_block_names` | Stable |
| `sysmda_markdown_excluded_classes` | Stable |
| `sysmda_markdown_excluded_builder_elements` | Stable |
| `sysmda_markdown_builder_adapters` | Advanced |
| `sysmda_markdown_builder_suppress_content_filters` | Advanced |
| `sysmda_front_matter_enabled` | Stable |
| `sysmda_front_matter_taxonomy_slugs` | Stable |
| `sysmda_front_matter_taxonomies` | Advanced |
| `sysmda_markdown_extra_meta_keys` | Stable |
| `sysmda_acf_field_keys` | Advanced |
| `sysmda_acf_subtitle_key` | Stable |
| `sysmda_acf_tldr_key` | Stable |
| `sysmda_llms_txt_cache_ttl` | Stable |
| `sysmda_llms_txt_enriched` | Stable |
| `sysmda_llms_txt_lastmod` | Stable |
| `sysmda_llms_txt_summary` | Stable |
| `sysmda_llms_txt_key_content` | Stable |
| `sysmda_llms_txt_max_posts` | Advanced |
| `sysmda_llms_txt_main_posts` | Advanced |
| `sysmda_llms_txt_footer` | Advanced |
| `sysmda_md_hits_bot_patterns` | Advanced |
| `sysmda_md_hits_named_bot_patterns` | Advanced |
| `sysmda_md_hits_retention_days` | Advanced |

The Advanced ones are marked again where they are documented below. The three
pipeline hooks are the load-bearing ones in that list: they are where a new
conversion engine would cut differently, and classifying them honestly is most
of the reason this page distinguishes levels at all.

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

```php
apply_filters( 'sysmda_markdown_unsupported_builders', $builders, $post );
```
Page builders whose posts have **no Markdown representation**. Defaults to every
builder the plugin can detect — `bricks`, `elementor`, `divi`, `wpbakery`,
`oxygen`, `beaver-builder`, `breakdance` — because none of them has an adapter
yet. A post rendered by one of these is denied everywhere `is_servable()`
reaches: the `.md` URL returns 404, no `alternate` link or `Link:` header is
advertised, the post leaves `/llms.txt`, and the shortcodes and the dynamic tag
render nothing.

Detection is **per post** and reads the builder's own render-mode meta, so
enabling a builder on a site of ordinary posts changes nothing, and a post
switched back to the WordPress editor keeps its `.md` even though the builder
data is still stored. It never inspects `post_content`: an article quoting
`[et_pb_section]` in a code sample is not a Divi page.

Remove a key to serve that builder's posts again — which means accepting
whatever the ordinary pipeline makes of them: an empty document for the
meta-based builders, and layout shortcodes converted as prose for Divi and
WPBakery. Return an empty array to switch the veto off entirely. Adding a key
the plugin cannot detect has no effect; use `sysmda_post_is_servable` to deny
posts built with something else.

Two notes. The list is **not** a supported-builders roster that shrinks as
adapters ship on your site — it shrinks in the plugin, and `bricks` leaving it
in a future version is a feature addition, not a breaking change. And the
settings panel's per-type breakdown describes the **built-in** list: it is
rendered without a post, so it cannot evaluate this filter.

```php
// Serve Bricks pages anyway, empty body and all.
add_filter( 'sysmda_markdown_unsupported_builders', function ( array $builders ) {
    return array_diff( $builders, array( 'bricks' ) );
} );
```

```php
apply_filters( 'sysmda_markdown_excluded_woocommerce_pages', array( 'cart', 'checkout', 'myaccount' ) );
```
WooCommerce page keys kept out of the Markdown: cart, checkout and my-account
by default. These are ordinary published `page` posts, so nothing else denies
them, but their body is WooCommerce's own runtime chrome ("Your cart is
currently empty!"), not editorial content. Return an empty array to serve
them again, or a shorter list to exclude only some. The shop page is
deliberately never in this list — that one is real content.

Reads `wc_get_page_id()` when WooCommerce is active (so any WooCommerce-side
filtering of these IDs is respected) and falls back to WooCommerce's own
`woocommerce_{key}_page_id` options directly when it is inactive, because
deactivating WooCommerce does not un-publish the pages it created.

```php
// Serve the checkout page too — a site that documents its own checkout flow.
add_filter( 'sysmda_markdown_excluded_woocommerce_pages', function ( array $keys ) {
    return array_diff( $keys, array( 'checkout' ) );
} );
```

```php
apply_filters( 'sysmda_post_is_servable', true, $post );
```
Final **veto** on a single post. The built-in rules understand WordPress's own
notion of access — post status and the core password field — and nothing else.
A membership, paywall or editorial plugin usually protects an otherwise
published post from a `template_redirect` callback or a `the_content` filter,
and **neither reaches this endpoint**: it runs at `template_redirect` priority
`0` and exits, so later callbacks never run, and it renders cleaned blocks
instead of `the_content` by design. Return `false` here to deny a post
everywhere at once (the `.md` route, negotiation, `rel="alternate"`,
`/llms.txt`, both shortcodes and the dynamic tag).

Veto only: it is consulted **just when the built-in rules already said yes**,
so returning `true` can never publish a draft, a password-protected post or a
type the site has not enabled — use `sysmda_markdown_supported_post_types` and
`sysmda_markdown_excluded_post_formats` to widen what is served. On the
every-request path (see [Filters on the every-request path](#filters-on-the-every-request-path)).

```php
// Deny anything the membership plugin considers restricted.
add_filter( 'sysmda_post_is_servable', function ( $servable, $post ) {
    return $servable && ! my_membership_plugin_is_restricted( $post->ID );
}, 10, 2 );
```

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
**[Advanced](#advanced)** — tied to the WP-Cron rebuild strategy, which a
different caching design would replace.

`true` rebuilds a post's Markdown cache on WP-Cron about 30 seconds after each
save, instead of on the first request. Off by default because cron has no
request context: a dynamic block or shortcode inspecting `is_singular()` or the
queried object can render differently there, and that difference is what would
get cached. No-op when the TTL is `0`. Applies to Bricks posts too, and for the
same reason plus one of its own: an element's own visibility condition
(Bricks' `_conditions`) reads only post/user/date/WooCommerce/dynamic-data/
browser/referer/current-URL state — confirmed against the installed
`\Bricks\Conditions::check()`, not assumed — but `current_url` (the parsed
request path) genuinely differs between the `.md` suffix route and the
negotiated permalink route, and stays unverified under cron's missing request
context either way.

## The conversion pipeline

Two kinds of hook live here, and treating them alike is the mistake this
section exists to prevent: four run once, in a known order; three run wherever
content is cleaned, an unbounded number of times.

### The document hooks, in order

These four run **once per document build**, in this order.

The first three are **[Advanced](#advanced)**: they exist at the points where
*this* pipeline cuts — raw source, rendered HTML, preamble — and a block-native
engine would not have the same seams. `sysmda_markdown_output` is Stable
precisely because it does not depend on how the document was produced.

```php
apply_filters( 'sysmda_markdown_source_content', $post->post_content, $post );
```
**[Advanced](#advanced)** — raw source content, before any rendering.

**It replaces the source; it does not append to the document.** For a post a
page-builder adapter claims, the adapter renders from the builder's own stored
tree and this filtered value is never read, so anything concatenated here is
silently dropped. To *add* content to the document, use the hook below, which is
honoured on every branch.

```php
apply_filters( 'sysmda_markdown_appended_html', '', $post );
```
**[Advanced](#advanced)** — HTML appended after the main content, on all three
render branches (page-builder adapter, blocks, classic). Added in `0.47.0`; it
is where the ACF integration and the extra custom fields put their values.

The returned HTML is rendered with the same two branches the main path uses —
block markup is parsed and cleaned by the block rules, anything else goes through
`wpautop()` — and then joins the single DOM pass, so excluded shortcodes, excluded
CSS classes and absolute URLs all apply to it exactly as they do to the post
content.

```php
apply_filters( 'sysmda_markdown_rendered_html', $html, $post );
```
**[Advanced](#advanced)** — cleaned HTML, after shortcode stripping, block
cleaning, block rendering, code-block normalization and URL absolutization. The
last point before the Markdown conversion.

```php
apply_filters( 'sysmda_markdown_preamble', '', $post );
```
**[Advanced](#advanced)** — Markdown inserted between the `# Title` heading and
the body. This is what the ACF integration uses for subtitle and TL;DR.

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
The final Markdown document, front matter included. Last hook of the build, and
the one extension point that survives any change of engine: it receives a
finished document and returns one.

### Page builders

Two more hooks sit inside the render step, before `sysmda_markdown_rendered_html`
is reached — consulted only for a post a [page-builder adapter](#which-content-is-served)
actually claims, which on a site with no builder content is never.

```php
apply_filters( 'sysmda_markdown_builder_adapters', $adapters, $post );
```
**[Advanced](#advanced)** — the list of `BuilderAdapter` instances tried, in
order, before the block/classic branches. Ships with one entry, the Bricks
adapter. A future conversion engine may have no concept of "adapters" at all,
which is why this is Advanced rather than Stable even though it decides what
gets served.

```php
apply_filters( 'sysmda_markdown_builder_suppress_content_filters', true, $post );
```
**[Advanced](#advanced)** — whether foreign `the_content` callbacks (a related-
posts block, a CTA) are suppressed while a builder adapter's "Post Content"
style element renders. Bricks' own `post-content` element calls the full
`the_content` filter chain, which is exactly the class of interference
`render_block()` is used instead of `the_content()` to avoid everywhere else in
this pipeline (see "Technical notes" §4 in AGENTS.md). Default on; return
`false` to accept whatever a real visitor sees there, related/CTA content
included — a maintainer-reversible design choice, not a settled one, and
documented as such where it is implemented (`BricksAdapter`).

### The cleaning filters, which are not points in that sequence

```php
apply_filters( 'sysmda_markdown_excluded_shortcodes', $shortcodes );
apply_filters( 'sysmda_markdown_excluded_block_names', $block_names );
apply_filters( 'sysmda_markdown_excluded_classes', $css_classes );
apply_filters( 'sysmda_markdown_excluded_builder_elements', $builder_selectors );
```

Shortcodes, Gutenberg blocks, CSS classes and page-builder chrome dropped from
the output. See [Default exclusions](#default-exclusions) for the values they
receive.

These are **not** stages of the ordered sequence above. They are consulted
wherever the plugin cleans content, which is more places than the body
conversion — including one that runs *before* `sysmda_markdown_source_content`,
and one on a different endpoint entirely. Known call sites:

| Filter | Called from |
|--------|-------------|
| `sysmda_markdown_excluded_shortcodes` | the post body; rendered preamble fragments; expanded synced patterns (`core/block`); the front-matter description fallback, which runs **before** the source-content hook; and `/llms.txt`, for entries that carry a description |
| `sysmda_markdown_excluded_block_names` | block cleaning — only when the post has blocks |
| `sysmda_markdown_excluded_classes` | block cleaning (blocks only); every DOM pass, body and fragments alike, unless the HTML is empty or fails to parse |
| `sysmda_markdown_excluded_builder_elements` | the same DOM pass as `sysmda_markdown_excluded_classes` (merged into one removal), applied to page-builder-rendered content; and the description fallback's exclusion pass, against the synthetic markup a builder adapter's `source_text()` produces |

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
| `sysmda_markdown_excluded_woocommerce_pages` | same, via `WooCommerceCompat::is_utility_page()` |
| `sysmda_post_is_servable` | same, once the built-in rules have said yes |
| `sysmda_front_matter_taxonomy_slugs` | `cache_version()` → `taxonomies_fingerprint()` |
| `sysmda_front_matter_taxonomies` | same |
| `sysmda_markdown_cache_dependencies` | `cache_version()` → `dependencies_fingerprint()` |
| `sysmda_markdown_extra_meta_keys` | `cache_version()` → `dependencies_fingerprint()` |
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
**[Advanced](#advanced)** — kill switch for the nested `taxonomies:` block.
Defaults to on as soon as one taxonomy is selected in the panel; `false` never
emits it.

It is a survivor of the 0.24.x auto-detection design and is redundant by
construction: returning `[]` from `sysmda_front_matter_taxonomy_slugs` below
suppresses the block just as well. Kept because it works and costs nothing, but
it is the one hook here that a later cleanup could consolidate.

```php
apply_filters( 'sysmda_front_matter_taxonomy_slugs', $slugs, $post );
```
Which taxonomies are emitted. Receives the selection saved in the panel (fed in
at priority 5), **not** an auto-detected list, and may narrow it or extend it —
opting a non-public taxonomy in is a deliberate choice. Return `[]` to opt out
for a post. `category`, `post_tag`, `post_format` and invalid slugs are always
stripped afterwards, so this filter can neither duplicate the dedicated keys nor
break the YAML.

## Extra custom fields

```php
apply_filters( 'sysmda_markdown_extra_meta_keys', array(), $post );
```
**[Stable](#stable)** — post meta keys whose values are appended to the end of
the Markdown body, in the order listed. Fed by the **Extra custom fields** panel
field at **priority 5**, and Stable because it is anchored to that field: it
lasts exactly as long as the setting does.

Priority 5, not the 20 the exclusion filters use, and the difference is not
cosmetic: this callback *replaces* the incoming list, so running it after site
code would discard whatever that code returned. Feeding the saved list in as the
filter's **default** is what lets a callback at the ordinary priority 10 narrow
or extend it. The exclusion filters can sit at 20 precisely because they merge.

Works with anything that stores post meta — ACF, JetEngine, Meta Box, or the
native Custom Fields box — because underneath they all do. With ACF active the
value is read with `get_field()`, so a registered ACF field arrives formatted the
way ACF would render it; for a key ACF has no field definition for, `get_field()`
returns the stored value, so a JetEngine or native key behaves identically
whether or not ACF is installed.

Values that are not strings are skipped: an array from a serialized value or a
repeater has a structure this plugin has no brief to invent a rendering for.

The list **replaces** rather than accumulates, unlike the three exclusion
filters. There are no built-in defaults to preserve, and a curated inclusion list
is the caller's whole answer — the same semantics as
`sysmda_llms_txt_key_content`.

```php
// Pull two fields into every post's Markdown.
add_filter( 'sysmda_markdown_extra_meta_keys', function ( array $keys, WP_Post $post ) {
    $keys[] = 'product_specification';
    return $keys;
}, 10, 2 );
```

**One caveat if you use this filter without the panel field.** A configured key
contributes to the cache validator only while the post actually has that meta
key — which is what keeps posts that never use the field on the faster
`If-Modified-Since` path. Deleting the last such value can therefore return the
fingerprint to empty without `post_modified_gmt` moving, and the plugin covers
that by bumping the global cache salt when a key **saved in the panel** is
deleted. It does not apply this filter from inside that hook, deliberately: it
fires on every meta deletion on the site and must stay trivial. So if you add
keys through the filter alone, declare them through
[`sysmda_markdown_cache_dependencies`](#caching) as well, or bump the salt
yourself when one is deleted.

## ACF integration

```php
apply_filters( 'sysmda_acf_field_keys', array(), $post );
```
**[Advanced](#advanced)** — ACF fields appended to the source content. Tied to
how the bundled integration reads ACF (flat field names, appended as HTML), which
is the part most likely to change if structured field extraction is ever added.

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
Maximum posts listed per type, and the shared anonymous body-cache TTL in
seconds (`0` = off). Authenticated requests always bypass that body cache.
`sysmda_llms_txt_max_posts` is **[Advanced](#advanced)**: it describes how the
index is assembled, and the llms.txt layout follows a spec that is still moving.

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

`sysmda_llms_txt_main_posts` and `sysmda_llms_txt_footer` are
**[Advanced](#advanced)**: both describe the *shape* of the generated file (the
main/`## Optional` split, and a trailing block whose eventual content depends on
an LLM-signals spec that has not settled). The two toggles and the summary above
them are Stable — they are panel settings.

## Hit counter

```php
apply_filters( 'sysmda_md_hits_bot_patterns', $patterns );
apply_filters( 'sysmda_md_hits_retention_days', 90 );
apply_filters( 'sysmda_md_hits_named_bot_patterns', $map );
```
All three **[Advanced](#advanced)** — case-insensitive user-agent substrings
classified as bot, how many days of daily buckets are kept, and the map of
canonical crawler name => substrings used to name a few known crawlers within
the bot total. They describe the counter's current storage and classification
strategy, not a domain concept.

The counter stores **only** aggregate daily totals split bot/human, plus an
optional per-day breakdown of that bot total by a short, curated list of known
crawler names (`ClaudeBot`, `GPTBot`, `PerplexityBot`, `CCBot` by default,
matched against their documented user-initiated variants too — `Claude-User`,
`ChatGPT-User`, `OAI-SearchBot`, `Perplexity-User`). The breakdown is a
refinement of the bot count, not a second classification: a request the site's
own `sysmda_md_hits_bot_patterns` filter has decided is not a bot is never
looked up in this map either. It never stores IP addresses, raw user-agent
strings, timestamps finer than the day, or any per-visitor identifier: the user
agent is read once to classify the request (and, for a named match, to pick a
name from this fixed list) and immediately discarded — a site cannot use this
filter to make the counter remember request-derived text, only to change which
of a code-defined set of names it looks for.

## Default exclusions

Returned to `sysmda_markdown_excluded_block_names`,
`sysmda_markdown_excluded_shortcodes` and `sysmda_markdown_excluded_classes`
respectively.

| Kind | Defaults |
|------|----------|
| Block names | `gravityforms/form`, `contact-form-7/contact-form-selector`, `wpforms/form-selector`, `ninja-forms/form`, `formidable/simple-form`, `mailerlite/form`, `luckywp/toc` |
| Shortcodes | `contact-form-7`, `gravityform`, `wpforms`, `fluentform`, `ninja_form`, `formidable`, `mailerlite_form`, `mc4wp_form`, `mailpoet_form`, `newsletter_form`, `sibwp_form`, `lwptoc`, `ez-toc`, `ez-toc-widget-sticky`, `toc` |
| CSS classes | `no-md`, `md-exclude`, `exclude-from-markdown` |
| Builder elements | `brxe-form`, `brxe-nav-menu`, `brxe-nav-nested`, `brxe-post-sharing`, `brxe-post-toc`, `brxe-breadcrumbs` (Bricks; see `BricksAdapter::DEFAULT_EXCLUDED_ELEMENTS`) |

The panel's four exclusion textareas **add to** these lists rather than replace
them (since `0.40.0`; before that, typing anything into a box dropped every
default in it). So the filter is now the only way to *remove* a default — it runs
at priority 10, before the closure that appends the saved lines at 20:

```php
// Publish the LuckyWP table of contents in the .md after all.
add_filter( 'sysmda_markdown_excluded_shortcodes', function ( $tags ) {
    return array_values( array_diff( $tags, array( 'lwptoc' ) ) );
} );
```

Two tags cannot be removed by any means: `ShortcodeCleaner::ALWAYS_EXCLUDED`
(`sysmda_md_button`, `sysmda_md_actions`) is merged in after the filter, because
this plugin's own interface controls must never appear in its own output.

Excluded shortcodes are not stripped from inside `<pre>` or `<code>`: a tag in a
code sample is being shown, not used. The same masking protects it from
expansion (see `CodeRegions`).

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
