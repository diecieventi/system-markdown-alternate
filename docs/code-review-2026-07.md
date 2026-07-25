# Code review — v0.25.0 (July 2026)

> **Status: resolved in `0.26.0`.** Every finding below was fixed in that
> release — see the `readme.txt` changelog for the user-facing summary and
> `AGENTS.md` for the decisions that came out of it. Two items were deliberately
> closed without a code change, and say so inline: **L7** (memoizing the cache
> validator would introduce intra-request staleness for a gain the object cache
> already provides) and **L8** (the hit counter's substring bot matching cannot
> take word boundaries without losing `Googlebot`, and its per-hit option write
> is a documented accepted trade-off). The document is kept as the record of what
> was found and why each fix looks the way it does.

Full review of the shipped code: edge cases, WordPress compatibility, and
readiness for a wordpress.org submission. **Planned/future work is deliberately
out of scope** — everything below concerns code that exists today.

No code was changed. Every finding states where it is, what goes wrong, how it
was verified, and a fix direction.

## Method

- Read every PHP file under `system-markdown-alternate/` (20 files, ~4.5k lines
  excluding tests), plus `readme.txt`, `phpcs.xml.dist`, `bin/build.sh`,
  `.distignore`, the CI workflow and `docs/output-format.md`.
- Ran the existing suite: **211 assertions, 0 failed** (PHP 8.4).
- Ran PHPCS with the project ruleset after a full `composer install`:
  **0 errors, 18 warnings** in 20 files.
- Exercised the real pipeline against `league/html-to-markdown` and the real
  `ContentRenderer` DOM pass (via reflection, with minimal stubs) to confirm or
  reject suspected edge cases rather than reasoning about them. Reproductions are
  in the appendix; findings marked **verified** were reproduced, not inferred.

## Summary

| # | Severity | Finding | File |
|---|---|---|---|
| H1 | High | An unbalanced `</div>` in the content silently truncates the Markdown body | `ContentRenderer.php:86` |
| H2 | High | Tables and definition lists convert to unreadable glued text | `MarkdownConverter.php:29` |
| M1 | Medium | `.md` route renders blocks without a global `$post`, so `.md` and `?format=markdown` can differ | `MarkdownController.php:40` |
| M2 | Medium | `.htaccess` written without a lock or atomic replace | `LiteSpeedCompat.php:233` |
| M3 | Medium | Negotiation not limited to singular views: feed/embed/comment-page URLs serve Markdown | `MarkdownController.php:53` |
| M4 | Medium | Non-`http(s)` absolute URLs (`ftp:`, `callto:`, …) mangled into bogus URLs | `ContentRenderer.php:235` |
| M5 | Medium | Whitespace normalization rewrites the inside of fenced code blocks | `MarkdownConverter.php:51` |
| M6 | Medium | Highlighters that emit one element per line lose their line breaks | `ContentRenderer.php:160` |
| M7 | Medium | `/llms.txt` is on by default while the rest of the plugin ships inert | `LlmsTxtController.php:59` |
| M8 | Medium | No multisite handling (uninstall leaves data behind; static memo crosses blogs) | `uninstall.php`, `PostSupport.php:32` |
| L1 | Low | A filter-supplied class containing a quote fatals the request (XPath) | `ContentRenderer.php:120` |
| L2 | Low | `.md` matched case-sensitively; trailing-slash 301 fires before any eligibility check | `MarkdownController.php:144` |
| L3 | Low | `[sysmda_md_url]` returns the main post's URL inside a secondary loop | `Shortcodes.php:51` |
| L4 | Low | Saved post types are silently dropped when a CPT is temporarily unregistered | `AdminSettings.php:806` |
| L5 | Low | Filter-supplied header values reach `header()` unsanitized | `MarkdownController.php:604` |
| L6 | Low | Query-only relative URL resolved wrongly on non-trailing-slash permalinks | `ContentRenderer.php:265` |
| L7 | Low | Cache validator and taxonomy fingerprint recomputed 2–4× per request | `MarkdownController.php:346` |
| L8 | Low | Hit counter writes `wp_options` on every hit; substring bot matching misfires | `HitCounter.php:96` |
| L9 | Low | Conflict detector looks for `llms.txt` in `ABSPATH`, not the home path | `ConflictDetector.php:49` |
| L10 | Low | ACF field whose value is the string `'0'` is skipped | `AcfIntegration.php:71` |
| L11 | Low | `.htaccess` write happens on a GET of the settings page | `AdminSettings.php:190` |
| L12 | Low | Settings page reads private core globals | `AdminSettings.php:1051` |
| L13 | Low | Test coverage has a hole exactly where H1/H2/M5/M6 live | `tests/run-tests.php` |
| L14 | Low | Tab a11y: no `role="tablist"`, no `aria-labelledby`, no arrow keys | `assets/admin-settings.js:54` |

---

## High

### H1 — An unbalanced `</div>` silently truncates the Markdown body

**Where** `src/ContentRenderer.php:86-108`

`process_dom()` wraps the rendered HTML in `<div id="sysmda-root">`, parses it,
and then serializes **only the wrapper's child nodes**:

```php
$wrapped = '<?xml encoding="UTF-8"?><div id="sysmda-root">' . $html . '</div>';
$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );
$root = $dom->getElementsByTagName( 'div' )->item( 0 );
...
foreach ( iterator_to_array( $root->childNodes ) as $child ) { $out .= $dom->saveHTML( $child ); }
```

A stray `</div>` in the content closes the wrapper early. Everything after it
becomes a *sibling* of the wrapper and is never serialized — silently.

**Verified**

| Input | Output |
|---|---|
| `<p>first</p></div><p>second</p><p>third</p>` | `<p>first</p>` |
| `</div><p>content that must survive</p>` | *(empty string)* |
| `<p>a</p></section><p>b</p>` | `<p>a</p><p>b</p>` (unmatched non-`div` tags are harmless) |

**Why it matters** The HTML page renders fine — browsers tolerate the stray tag —
so the loss is invisible unless someone diffs the `.md` against the page. Real
sources of an unbalanced `</div>` in post content: Custom HTML blocks, content
migrated from another CMS or a page builder, classic content hand-edited in the
Text tab, and legacy column shortcodes (`[/one_half]`-style) whose closing half
emits a bare `</div>`. Because `render_block()`/`do_shortcode()` run *before*
this pass, a third-party shortcode's unbalanced markup is enough.

Note also that `id="sysmda-root"` is never read: `getElementById()` needs a DTD,
so the actual mechanism is "first `div` in document order". The id gives a false
impression of robustness.

**Fix direction** Use a wrapper element the content cannot close — e.g.
`<sysmda-root>` (unknown tags are kept as generic elements) — or serialize every
top-level node of the document instead of only the wrapper's children. Cheap
belt-and-braces: if serialization returns `''` while `trim($html) !== ''`, return
the input unchanged rather than an empty body.

### H2 — Tables and definition lists convert to unreadable glued text

**Where** `src/MarkdownConverter.php:29-37`, `src/ContentRenderer.php:137-150`

`league/html-to-markdown` has no converter for `table`/`tr`/`td`/`th` or
`dl`/`dt`/`dd`, and the config sets `'strip_tags' => true`, so those tags are
removed and their text is concatenated with no separator at all.

**Verified** — through the real pipeline (real DOM pass, real converter), using
the exact markup WordPress's core table block emits:

```
input : <figure class="wp-block-table"><table><thead><tr><th>Name</th><th>Price</th></tr></thead>
        <tbody><tr><td>Coffee</td><td>2</td></tr><tr><td>Tea</td><td>3</td></tr></tbody></table></figure>
output: NamePriceCoffee2Tea3

input : <dl><dt>Term</dt><dd>Definition</dd></dl>
output: TermDefinition
```

**Why it matters** `core/table` is a core block in everyday use. The output is
not merely lossy, it is *actively misleading* for the plugin's stated consumer:
an LLM reading `NamePriceCoffee2Tea3` will invent structure. Dropping the block
entirely would be better than this; a GFM table is better still.

`unwrap_figures()` compounds it by replacing `<figure class="wp-block-table">`
with `<p>`, so the table ends up nested inside a paragraph — invalid HTML that
also rules out a simple "pass the table through as raw HTML" fallback later.

**Fix direction** Either (a) register a small GFM table `ConverterInterface`
(`table`, `thead`, `tbody`, `tr`, `th`, `td`) on the `HtmlConverter`'s
environment — Markdown consumers all understand pipe tables — or (b) as a
stopgap, keep `<table>` subtrees as verbatim HTML (legal in Markdown) and skip
them in `unwrap_figures()`. Treat `<dl>` the same way (`**Term**` + indented
definition converts cleanly). Either way, exclude `table` from the
figure→paragraph rewrite.

---

## Medium

### M1 — The `.md` route renders blocks without a global `$post`

**Where** `src/MarkdownController.php:40-50` → `ContentRenderer::render()`

On the `.md` suffix route the main query resolved `/slug.md`, which matches no
post, so WordPress 404s and `WP::register_globals()` leaves `$GLOBALS['post']`
`null`. `build_markdown()` then calls `render_block()` (and `do_shortcode()` for
classic content) with no post context and never calls `setup_postdata()`.

On the **negotiated** route (`?format=markdown` / `Accept: text/markdown`) the
main query *did* resolve the post, so the global is set correctly.

**Why it matters** The same post can convert differently depending on which of
the two documented Markdown URLs was requested. Anything reading the loop
context is affected: dynamic core blocks (`core/post-title`, `core/post-excerpt`,
`core/query` and friends), GenerateBlocks dynamic content, and the many
shortcodes that fall back to `get_the_ID()` / `$GLOBALS['post']` (a bare
`[gallery]` being the classic case). It also makes the cached body dependent on
which route populated the cache first, since both routes share the
`sysmda_md_{id}` key.

**Fix direction** In `build_markdown()`, set `$GLOBALS['post']` and call
`setup_postdata( $post )` before rendering, restoring the previous value
afterwards (`wp_reset_postdata()` is not appropriate here — there is no
secondary query). Worth an explicit test that the two routes produce identical
bytes.

### M2 — `.htaccess` written without a lock or atomic replace

**Where** `src/LiteSpeedCompat.php:232-233`, `267-274`

```php
$contents = file_exists( $path ) ? (string) file_get_contents( $path ) : '';
$written  = false !== file_put_contents( $path, self::prepend_rules( $contents ) );
```

Read-modify-write with no `LOCK_EX`, no temp-file-plus-`rename()`, and no
backup — on the one file whose corruption takes the entire site down with a 500.
`sync()` runs on **every** load of the settings page (`AdminSettings::add_menu()`
hooks `load-{$hook}`), so two concurrent admin loads, or a save plus a load, can
interleave. WordPress core's own `insert_with_markers()` takes an `flock()` for
exactly this reason.

Also note `strip_rules()` only matches a *complete* `BEGIN…END` pair: if a file
is ever left with a `BEGIN` and no `END`, `prepend_rules()` adds a second block
instead of replacing the first.

**Fix direction** At minimum pass `LOCK_EX` to `file_put_contents()`. Better:
write to `.htaccess.sysmda-tmp` in the same directory and `rename()` over the
target (atomic on the same filesystem), and keep a one-time `.htaccess.sysmda-bak`
before the first write so a bad state is recoverable without FTP.

### M3 — Negotiation is not limited to singular views

**Where** `src/MarkdownController.php:53-56`

```php
$queried = get_queried_object();
if ( ! $queried instanceof \WP_Post || ! $this->is_servable( $queried ) ) { return; }
```

No `is_singular()` check — unlike `print_alternate_link()` (line 90), which does
guard on `is_singular( $types )`. The two guards disagree about what a
"negotiable URL" is.

`WP_Query::get_queried_object()` returns the `WP_Post` whenever `is_singular` is
true, and `is_singular` stays true for a post's **comment feed**, its **embed**
view and its **paged comment** URLs. So all of these serve the full Markdown
body:

- `/my-post/feed/?format=markdown` — instead of the RSS feed
- `/my-post/embed/` with `Accept: text/markdown`
- `/my-post/comment-page-2/` with `Accept: text/markdown`

Each also emits `Vary: Accept` on the corresponding HTML/XML response.

**Why it matters** The Markdown representation appears at URLs that are not the
canonical permalink and are not what `markdown_url()` advertises. Impact is
contained (`noindex` + no-cache), but a feed reader that sends a Markdown-first
`Accept` gets a broken feed, and it widens the surface for the URL-keyed-cache
problem the plugin works hard to avoid elsewhere.

**Fix direction** Require `is_singular( PostSupport::supported_post_types() )
&& ! is_feed() && ! is_embed()` before negotiating, so the negotiation guard
matches the alternate-link guard.

### M4 — Non-`http(s)` absolute URLs are mangled

**Where** `src/ContentRenderer.php:227-271`

The "already absolute" test is `#^(https?:)?//#i`, and the only other pass-through
prefixes are `data:`, `mailto:`, `tel:`, `#`. Every other scheme falls into
document-relative resolution.

**Verified** (base `https://example.com/blog/my-post/`):

| Input | Output |
|---|---|
| `ftp://host/file` | `https://example.com/blog/my-post/ftp://host/file` |
| `callto:123` | `https://example.com/blog/my-post/callto:123` |
| `javascript:alert(1)` | `https://example.com/blog/my-post/javascript:alert(1)` |

`ftp:`, `ftps:`, `sms:`, `skype:`, `whatsapp:`, `viber:`, `webcal:`, `magnet:`,
`bitcoin:` behave the same. Real content does contain these — `sms:` and
`whatsapp:` links in particular are common on business sites.

**Fix direction** Recognise any RFC 3986 scheme as absolute before the relative
branches: `if ( preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) { return $url; }`
(placed after the `//` and `#` checks). That also makes the hand-maintained
prefix list unnecessary.

### M5 — Whitespace normalization rewrites the inside of fenced code blocks

**Where** `src/MarkdownConverter.php:51-57`

```php
$markdown = preg_replace( '/[ \t]+\n/', "\n", $markdown );
$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );
```

Both run over the whole document, fences included.

**Verified** — a `language-python` block containing `a = 1`, three blank lines,
`b = 2␠␠␠`, `print(a)` comes out of `convert()` with a single blank line and the
trailing spaces stripped: the code inside the fence is not what the author wrote.

**Why it matters** Code fidelity is a core promise of a "clean Markdown for LLMs"
plugin. Trailing double-spaces are semantically meaningful in a Markdown code
sample, and blank-line runs matter in REPL/doctest transcripts, diffs and patch
bodies. Silent alteration inside a fence is worse than leaving the noise in.

**Fix direction** Split the Markdown on fence delimiters (```` ``` ```` / `~~~`)
and apply the two `preg_replace` calls only to the segments outside fences.

### M6 — Highlighter code blocks can lose their line breaks

**Where** `src/ContentRenderer.php:157-173`

`normalize_code_blocks()` rebuilds each `<pre>` from `$pre->textContent`. That is
correct only when the highlighter leaves literal newlines in the text. Renderers
that emit one element per line and no newline text nodes collapse to one line.

**Verified** — `$pre->textContent` on Shiki-shaped markup
(`<span class="line">…</span><span class="line">…</span>`) returns
`"echo 1;echo 2;"`.

Prism and highlight.js keep the newlines, so they are unaffected — which is
likely why this has not surfaced on the GeneratePress/Code Block Pro test stack.
Code Block Pro is Shiki-based, so it is worth checking on real content before
dismissing.

**Fix direction** When the `<pre>`'s `<code>` has element children that each
represent a line (block-level children, or `class` containing `line`), join their
`textContent` with `"\n"` instead of using the flat `textContent`; fall back to
the current behaviour otherwise.

### M7 — `/llms.txt` is enabled by default while the rest of the plugin is inert

**Where** `src/LlmsTxtController.php:59`, `src/AdminSettings.php:875`

`sysmda_llms_txt_enabled` defaults to `'1'`, so the endpoint answers from the
moment the plugin is activated. But `sysmda_markdown_supported_post_types`
defaults to empty by durable decision, so `build()` iterates nothing and the
response is just:

```
# Site name

> Tagline
```

**Why it matters** Three ways:

1. It contradicts the documented promise that the plugin "stays inactive until at
   least one type is selected".
2. Activating the plugin can silently take over `/llms.txt` from an SEO plugin
   that already generates a good one — and by durable decision the conflict
   detector only *informs*, it never yields. The user has to notice the notice.
3. The first thing a curious visitor or crawler sees at a brand-new install is a
   two-line file that looks broken.

**Fix direction** Keep the option's default as it is (flipping it would change
behaviour for existing installs), but return early — no output, let WordPress
404 — when `PostSupport::supported_post_types()` is empty. That preserves the
"no auto-yield" decision while making the endpoint honestly reflect whether the
plugin has anything to index. Alternatively default the option off and seed `'1'`
on first activation only.

### M8 — No multisite handling

`grep` finds no `is_multisite()`, `get_sites()` or `switch_to_blog()` anywhere in
the plugin. Two concrete consequences:

**Uninstall leaves data on every other site.** `uninstall.php` deletes the 20
`sysmda_*` options and runs one `DELETE … LIKE '\_transient\_sysmda\_%'` against
`{$wpdb->options}` — the *current* site's options table. Deleting a
network-activated plugin from a 50-site network leaves 49 sites' options and
transients behind. wordpress.org reviewers do look for this.

**The static memo crosses blog boundaries.** `PostSupport::supported_post_types()`
memoizes into a `static` (`src/PostSupport.php:32-39`) that survives
`switch_to_blog()`. Any cross-site loop — WP-CLI, network cron, a multisite
aggregator — evaluates site B's posts against site A's enabled types. The same
memo also freezes the filter result on first call, so a theme or plugin that
registers `sysmda_markdown_supported_post_types` after that first call is
silently ignored (ordering is currently safe because `AdminSettings::boot()` runs
last inside `Plugin::boot()`, but nothing enforces it).

**Fix direction** In `uninstall.php`, wrap the cleanup in a
`if ( is_multisite() ) { foreach ( get_sites( … ) as $site ) { switch_to_blog(…); …
restore_current_blog(); } }` loop (batched by `number`/`offset` for large
networks). Key the memo on `get_current_blog_id()`, or drop it — the filter chain
is three `get_option()` calls behind an autoloaded option.

---

## Low

**L1 — A filter-supplied class with a quote fatals the request.**
`src/ContentRenderer.php:119-130` interpolates each excluded class straight into
an XPath expression. Verified: a class of `it's-bad` makes `$xpath->query()`
return `false`, and `iterator_to_array( false )` throws
`TypeError: Argument #1 ($iterator) must be of type Traversable|array` — a fatal
on the `.md` request. The panel path is safe (`sanitize_html_class()` strips
quotes), so this is reachable only through `sysmda_markdown_excluded_classes` —
but that is a documented public contract. Guard with
`if ( ! $nodes instanceof \DOMNodeList ) { continue; }`, and/or skip classes not
matching `/^[a-zA-Z0-9_-]+$/`.

**L2 — `.md` matching is case-sensitive, and the trailing-slash 301 is
unconditional.** `src/MarkdownController.php:144-156`: `/my-post.MD` is not
recognised (`'.md' !== substr( $path, -3 )`), and the `/slug.md/ → /slug.md`
redirect happens before *any* eligibility check — so an inactive plugin (no post
types selected) still issues 301s, and a non-existent slug gets a 301 to a 404.
Both are defensible, neither is documented.

**L3 — `[sysmda_md_url]` ignores the current loop.** `src/Shortcodes.php:45-58`
tries `get_queried_object()` before `get_post()`. Inside a secondary loop — a
related-posts query, a query block, a shortcode in a widget on a single post —
`get_queried_object()` returns the *main* post, so every item renders the same
URL. The WordPress convention is the reverse order: `get_post()` (which respects
the loop's global `$post`) first, `get_queried_object()` as the fallback.

**L4 — Saved post types are silently dropped when a CPT is unregistered.**
`sanitize_post_types()` (`AdminSettings.php:422`) keeps only currently-registered
public types, and `field_post_types()` (line 806) only renders currently-registered
ones. Deactivate WooCommerce, save the settings page, reactivate → `product` is
gone from the selection and `.md` silently stops working for it. The taxonomy
field solves exactly this problem deliberately (lines 917-924: unknown saved
slugs are re-rendered checked, with a note); the post-type field should use the
same pattern.

**L5 — Filter-supplied header values are not sanitized.**
`MarkdownController.php:604-618` passes `sysmda_markdown_robots_header` and
`sysmda_markdown_canonical_url` straight to `header()`. PHP rejects CR/LF, so
this is not a header-injection hole — but a value with a stray newline produces a
fatal instead of degrading. `sanitize_text_field()` / `esc_url_raw()` are free.

**L6 — Query-only relative URLs and a missing-scheme base.**
`ContentRenderer.php:265-270`: a reference like `?utm=1` is treated as
document-relative, so with a permalink that has no trailing slash
(`/blog/my-post`) it resolves to `/blog/?utm=1` instead of `/blog/my-post?utm=1`
(verified). RFC 3986 keeps the base path for a query-only reference. Default
WordPress structures end in `/`, so this only bites structures like
`/%postname%.html`. Separately, line 257 reads `$parts['scheme']` without the
`isset()` guard used for every neighbouring key — a scheme-less base would emit a
PHP 8 warning.

**L7 — Redundant recomputation per request.** *(Closed in 0.26.0 without
memoization: `serve_markdown()` now passes the validator it already computed into
`get_markdown()`, removing one of the duplicate computations for free. A per-post
static memo was tried and reverted — it makes a term change stop moving the ETag
within the same request, which is exactly the guarantee the fingerprint exists
to provide, and the object cache already makes the repeat term lookups cheap.
The `/llms.txt` rebuild cost stands as described.)* `serve_markdown()` calls
`cache_version()` and then `get_markdown()` calls it again; `date_is_strong_validator()`
adds a third `taxonomies_fingerprint()`, and `append_taxonomies()` a fourth
`taxonomy_terms()`. Each one re-runs two filters and `get_the_terms()`.
Everything is object-cached so the cost is small, but a per-post-ID memo in
`MetadataBuilder` would remove it outright. Bigger version of the same shape: the
enriched `/llms.txt` runs `MetadataBuilder::description()` — full-content regex
chain — once per post across up to 500 posts *per type*, and
`MarkdownController::invalidate_cache()` drops the whole index on **every**
`save_post`, so the rebuild lands on whichever visitor arrives first after any
edit. Consider rebuilding on a scheduled event, or only invalidating for
supported post types.

**L8 — Hit counter cost and bot classification.** *(Closed in 0.26.0 with no
change, deliberately. Word-boundary matching cannot fix the `CUBOT` false
positive without also losing `Googlebot` — the token sits at a word boundary in
neither case — and any brand blocklist would be endless. The per-hit
`wp_options` write and the lost-update race are already documented as accepted
limits of an indicator that is opt-in and off by default. Revisit only if the
counter's own data shows the noise matters.)* `HitCounter::record()`
(`HitCounter.php:96`) does a `get_option()` + `update_option()` per counted
request: one `wp_options` write per `.md` hit, with an acknowledged lost-update
race under concurrency. Both are documented as accepted; still worth knowing
before recommending the toggle on a busy site. Separately, matching is plain
case-insensitive substring, so `CUBOT` phones (a real Android brand) classify as
`bot` via the `'bot'` token, and `'java'`/`'node'`/`'http'` are broad by design.
Word-boundary matching for the short generic tokens would tighten it cheaply.

**L9 — Conflict detector checks the wrong directory.**
`ConflictDetector::physical_file_exists()` looks for `ABSPATH . 'llms.txt'`, but
the file that shadows the endpoint sits in the **home** directory. On a
subdirectory install (WordPress in `/wp/`, site at `/`) it checks the wrong path
and reports no conflict when one exists. `LiteSpeedCompat::htaccess_path()`
already uses `get_home_path()` for precisely this reason.

**L10 — ACF field valued `'0'` is skipped.** `AcfIntegration.php:71` uses
`! $value`, which is true for the string `'0'`. Should be an explicit
`'' === trim( (string) $value )` check (the `is_string()` test is already there).

**L11 — `.htaccess` write on a GET.** `sync_litespeed_htaccess()`
(`AdminSettings.php:190`) is hooked to `load-{$hook}` and only checks
`current_user_can()`. The write is idempotent and derived entirely from the
stored option, so there is no exploitable state change — but "modifies a file on
a GET request, nonce-less" is a pattern wordpress.org reviewers question. Worth
an explicit code comment stating the invariant, so it reads as deliberate.

**L12 — Settings page depends on private core globals.**
`render_page()` (`AdminSettings.php:1051-1052`) reads `$wp_settings_sections`
and `$wp_settings_fields` to build the tab shell. There is no public accessor, so
this is the only way to do it — but it is undocumented internals. A comment plus
a fallback to plain `do_settings_sections()` when the globals are missing or
shaped unexpectedly would make a future core change a cosmetic regression instead
of a broken page.

**L13 — The test hole maps exactly onto the bugs above.** The suite is genuinely
good where it exists (211 assertions; the taxonomy-fingerprint/ETag/`If-Modified-Since`
interaction is thoroughly pinned, and the golden front-matter fixtures do their
job). But:

- `MarkdownConverter` has **zero** assertions — it is only instantiated to build a
  controller.
- `ContentRenderer` is exercised only through the private `absolutize()`. The DOM
  pipeline — `process_dom`, `remove_excluded_nodes`, `unwrap_figures`,
  `normalize_code_blocks`, `detect_code_language` — has no coverage at all.
- `AcfIntegration`, `Shortcodes`, `DynamicTags`, `ConflictDetector`: no tests.

H1, H2, M5 and M6 would each have been caught by a handful of HTML-in /
Markdown-out golden fixtures, and they need **no** WordPress: the harness already
stubs `apply_filters`, `wp_parse_url`, `home_url` and `wp_strip_all_tags`, which
is everything `process_dom()` touches. This is the highest-value place to add
tests.

**L14 — Tab accessibility.** `assets/admin-settings.js:54-60` sets `role="tab"`
and `aria-selected` on the anchors, but the container has no `role="tablist"`,
the panels have no `aria-labelledby`, and there is no arrow-key handling. Since
the panels are `role="tabpanel"` already, completing the pattern is a few lines.

---

## What is solid

Worth stating plainly, because it shapes how much weight the findings above
deserve:

- **PHPCS is clean.** 0 errors across 20 files with `WordPress-Core` +
  `WordPress-Extra` + `PHPCompatibilityWP` (7.4-). All 18 warnings are either the
  intentional direct file I/O (M2 relates) or cosmetic (`$default` as a parameter
  name, unused `$post` in filter closures that must match the filter signature).
- **No red flags in the security sweep.** No `eval`, `base64_*`,
  `create_function`, `extract()`, `error_log`, `var_dump`, and no `$_POST` /
  `$_REQUEST` / `$_COOKIE` access anywhere. Every superglobal read is
  `wp_unslash()`-ed with a justified `phpcs:ignore` naming the sniff.
- **Admin output escaping is thorough and correct** — `esc_html`, `esc_attr`,
  `esc_textarea`, `esc_url`, and `wp_kses_post` reserved for strings that
  intentionally carry inline `<code>`/`<strong>`. Capability checks on both
  `render_page()` and the `.htaccess` sync; nonces handled by the Settings API.
- **The conditional-request design is genuinely careful.** Folding
  `taxonomies_fingerprint()` into the ETag, and downgrading `If-Modified-Since`
  when `post_modified_gmt` stops being a strong validator, is the kind of thing
  most plugins get wrong. It is correct here, documented, and tested.
- **The ACF-options-only-when-ACF-is-active guard** (`register_settings()`) shows
  real understanding of how `options.php` writes every registered option in a
  group. Same for the taxonomy field preserving unknown saved slugs.
- **`uninstall.php` is thorough for single-site**, including both legacy option
  keys and the `.htaccess` block.
- **The output-format contract** (`docs/output-format.md`) matches the code:
  key order, conditional keys, the append-after-`description` position of
  `taxonomies:`, and the `---\n\n# Title\n\n` document shape all check out
  against `build_front_matter()` / `build_markdown()`.

## wordpress.org submission checklist

Beyond the findings above, everything I could check against the guidelines:

| Item | Status |
|---|---|
| Text domain = plugin slug, no bundled `.mo`, no manual loader | ✅ correct for language packs |
| Prefixing (`sysmda_` / `SYSMDA_` / namespace), ≥4 chars, distinctive | ✅ enforced by the ruleset |
| `defined( 'ABSPATH' ) \|\| exit;` in every file | ✅ all 20 |
| `readme.txt` header: `Tags` (exactly 5, the maximum), `Requires at least`, `Tested up to`, `Requires PHP`, `Stable tag`, license | ✅ |
| Short description within 150 chars | ✅ (~127) |
| No tracking without consent | ✅ hit counter is opt-in, count-only, no external calls |
| Sanitize / escape / capability checks on settings | ✅ |
| `vendor/bin` excluded from the package ("not permitted files") | ⚠️ correct, but duplicated in three places — `.distignore`, `bin/build.sh`, and the deploy workflow's `rsync`. Drift risk; a single shared exclude list would be safer |
| Plugin Check: direct filesystem writes | ⚠️ will flag `file_put_contents` (see M2). Justified, but expect a reviewer question — the inline rationale should say *why* `WP_Filesystem` is unsuitable here (it needs credentials the settings-page load does not have) |
| Plugin Check: direct DB query in `uninstall.php` | ⚠️ will flag; justified in a comment already |
| Multisite cleanup on uninstall | ❌ see M8 |
| Screenshots current | ❌ known: `.wordpress-org/screenshot-*.jpg` still show the pre-0.17.0 UI |

## Suggested order of work

1. **H1** — silent content loss, small and self-contained fix.
2. **H2** — the most visible quality gap in the actual output.
3. **L13** — add HTML-in/Markdown-out golden fixtures *while* fixing 1 and 2, so
   they stay fixed. This is what makes the rest cheap.
4. **M3, M4, M5** — small, well-understood correctness fixes with tests.
5. **M1** — needs care (global state), and a test that both routes agree.
6. **M2, M8** — robustness and the wordpress.org multisite expectation.
7. **M6, M7** and the Low findings as they fit.

---

## Appendix — reproductions

All of these ran against the real code (`ContentRenderer` private methods via
reflection, real `league/html-to-markdown`) with minimal stubs for
`apply_filters`, `wp_parse_url`, `home_url`, `wp_strip_all_tags`.

**H1 — body truncation**

```php
$dom = ( new ReflectionMethod( ContentRenderer::class, 'process_dom' ) );
$dom->setAccessible( true );
$dom->invoke( $renderer, '<p>first</p></div><p>second</p><p>third</p>', $base );
// => "<p>first</p>"
$dom->invoke( $renderer, '</div><p>content that must survive</p>', $base );
// => ""
```

**H2 — table collapse (full pipeline: DOM pass, then converter)**

```php
$converter->convert( $dom->invoke( $renderer,
  '<figure class="wp-block-table"><table><thead><tr><th>Name</th><th>Price</th></tr></thead>'
. '<tbody><tr><td>Coffee</td><td>2</td></tr></tbody></table></figure>', $base ) );
// => "NamePriceCoffee2"
```

**M4 — scheme mangling**

```php
$abs->invoke( $renderer, 'ftp://host/file', 'https://example.com/blog/my-post/' );
// => "https://example.com/blog/my-post/ftp://host/file"
```

**M5 — fenced code rewritten**

```php
$converter->convert( "<pre><code class=\"language-python\">a = 1\n\n\n\nb = 2   \nprint(a)</code></pre>" );
// => "```python\na = 1\n\nb = 2\nprint(a)\n```"   (blank lines collapsed, trailing spaces gone)
```

**M6 — highlighter line collapse**

```php
$pre->textContent; // <pre><code><span class="line">…</span><span class="line">…</span></code></pre>
// => "echo 1;echo 2;"
```

**L1 — XPath fatal**

```php
// with sysmda_markdown_excluded_classes returning array( "it's-bad" )
$dom->invoke( $renderer, '<p>hello</p>', $base );
// Warning: DOMXPath::query(): Invalid expression
// TypeError: iterator_to_array(): Argument #1 ($iterator) must be of type Traversable|array, false given
```

**L6 — query-only reference**

```php
$abs->invoke( $renderer, '?utm=1', 'https://example.com/blog/my-post' );
// => "https://example.com/blog/?utm=1"   (expected: .../blog/my-post?utm=1)
```
