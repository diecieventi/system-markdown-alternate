# AGENTS.md — System Markdown Alternate

**Tool-agnostic** operational guide for developing and maintaining this WordPress
plugin: current state, decisions, structure, conventions and workflow. The
functional state is documented here, in `README.md` and in the `readme.txt`
changelog.

> `CLAUDE.md` is a **symlink** to this file: Claude Code, Codex, Cursor, Copilot
> & co. all read the same source of truth, with no duplicates to keep aligned.
> Agent-specific notes (Claude Code web, Codex) live in the dedicated section at
> the end of "Identity, versioning, workflow".
>
> **Language**: the repository is **English-only** — runtime strings, docs,
> comments and workflow messages. The plugin itself is English-only too (see the
> i18n note in "Technical notes": translations come from translate.wordpress.org).

## What it is

A custom WordPress plugin that exposes a **clean Markdown version** of the
content (readable by LLMs, agents, technical scraping tools). Every published
post of the enabled types is reachable by appending `.md` to the permalink:

```
https://example.com/my-post/      → HTML
https://example.com/my-post.md    → Markdown (front matter + content)
```

It is **not** a generic SEO plugin: it is a technical feature. Priorities: work
well on the blog, stay easy to verify, produce clean Markdown, create no SEO
risk, stay extensible via filters.

## Commands

```bash
# Pure-logic tests (no WP/PHPUnit; CI runs them on PHP 7.4 and 8.4)
# The Markdown conversion tests need vendor/ (they exercise the real library);
# without it they skip themselves with a notice, so run `composer install` first.
php system-markdown-alternate/tests/run-tests.php

# Lint a touched file
php -l system-markdown-alternate/src/<File>.php

# Install Composer dependencies locally (creates vendor/, required to run the plugin;
# also installs the PHPCS/WPCS dev tooling — the build uses --no-dev, so it never ships)
composer install --working-dir=system-markdown-alternate

# Coding standards (PHPCS + WordPress Coding Standards); run from the plugin folder
composer --working-dir=system-markdown-alternate phpcs    # report
composer --working-dir=system-markdown-alternate phpcbf   # auto-fix what is fixable
# NOTE: bin/build.sh runs `composer install --no-dev`, which REMOVES the tooling;
# re-run the plain `composer install` above to get it back after a build.

# Build the distributable zip with vendor/ bundled → DIST/system-markdown-alternate.zip
bash bin/build.sh

# Create + push any missing release tag, notes from the CHANGELOG.md history.
# Usually NOT needed by hand: the "Release tag" GitHub Action runs this on every
# push to main that changes the version. Run it locally only to catch up offline;
# --dry-run previews. (Agents cannot push tags: the web proxy rejects them.)
bash bin/release-tag.sh
```

## Current state

The v1 scope is done and widely exceeded. Implemented:

- **`.md` endpoint** for the enabled post types (public post/page/CPT), published,
  public, not password-protected, **standard post format only** (see the decision
  below); **content negotiation** (`Accept: text/markdown`
  or `?format=markdown`). The `Accept` header is **parsed with q-values**
  (`AcceptNegotiator`): Markdown is served only when explicitly preferred
  (q ≥ HTML); a wildcard or missing Accept stays HTML. Negotiable URLs →
  **`Vary: Accept`**; optional **`406`** when the client accepts neither HTML nor
  Markdown (`sysmda_markdown_strict_406` filter, default on). Negotiation happens
  **only on the canonical singular permalink** (`is_negotiable_request()`):
  feeds, oEmbed views, trackbacks, paged comments (`cpage`) and `<!--nextpage-->`
  sub-pages (`page > 1`) are excluded — `is_singular()` stays true for all of
  them, so `Accept: text/markdown` on `/my-post/feed/` used to return the article
  body instead of the feed. Both discovery paths — `print_alternate_link()` in
  the document head and, since `0.37.0`, the typed HTTP `Link: rel="alternate"`
  header on HTML `GET`/`HEAD` — **call that same predicate**: what declares
  `Vary: Accept` and what advertises a Markdown alternate must stay in step, and
  two guards written to mirror each other did not — the old HTML-link guard
  checked only the enabled type and servability, so on an embed view (the one
  excluded variant that still runs `wp_head`) the link was advertised for a URL
  that does not negotiate. The HTTP header is sent from a separate
  `template_redirect` callback at `PHP_INT_MAX`, only after Markdown, `406` and
  canonical/access redirects have taken their exit; emitting it in the
  priority-0 controller left the field attached to a later `301`. It therefore
  describes the canonical HTML response, never the alternate or a redirect.
  One predicate, not two; do not fork it again. The `.md`
  suffix route sets up the loop
  (`setup_postdata` + global `$post`) before converting, because on that route the
  main query 404s and dynamic blocks/shortcodes would otherwise render against no
  post — and the two routes would disagree.
- **Markdown discovery in HTML and HTTP**: supported canonical singular content
  advertises the representation with both `<link rel="alternate"
  type="text/markdown">` in the document head and `Link: <markdown URL>;
  rel="alternate"; type="text/markdown"` in the HTML response headers. The
  latter also works for `HEAD`, appends rather than replacing other Link fields
  and suppresses an exact relation/target duplicate.
- **HTTP headers**: Markdown responses carry `Content-Type: text/markdown;
  charset=utf-8`, `X-Robots-Tag: noindex, follow` and `Link: <permalink>;
  rel="canonical"`; negotiable canonical HTML responses carry the alternate
  Link field above plus `Vary: Accept`. Markdown responses also carry **`ETag` +
  `Last-Modified`**. Negotiated
  Markdown and `406` responses additionally send
  `Cache-Control: no-cache, no-store, must-revalidate, private` (server-agnostic
  no-cache invariant — see "Product decisions"); the `.md` URLs send
  **`public, max-age=0, must-revalidate`** and drop WordPress's inherited
  `Expires` — storable anywhere, never reusable without revalidating. Set
  explicitly *because* WordPress had already put `no-store` on this route (see
  the decision; filter `sysmda_cache_control`).
- **Conditional requests**: the `.md` response honours `If-None-Match` /
  `If-Modified-Since` and replies **`304 Not Modified`** (no body) when the client
  already holds the current version. Validator = the existing cache-version hash
  (`post_modified_gmt` + `SYSMDA_VERSION` + settings salt + the taxonomy and
  out-of-post dependency fingerprints, see "Technical notes" 6), so a `304`
  always means the cached body would be identical; `If-None-Match` takes priority over
  `If-Modified-Since` (RFC 9110). Works even with the body cache disabled.
  `If-Modified-Since` is honoured **only while the date is a strong validator**:
  when the taxonomy block is emitted the body can change without
  `post_modified_gmt` moving, so the date check is skipped and the (taxonomy-aware)
  `ETag` is the sole validator. The `ETag` itself is **weak** (`W/"…"`, since
  `0.28.0` — see the decision below) and `If-None-Match` is compared with the
  weak comparison RFC 9110 requires: the `W/` flag is ignored on both sides, and
  so is the `-gzip`/`-br` suffix Apache appends inside the quotes when it
  compresses a response (`DeflateAlterETag AddSuffix`, the default — without
  that, gzip clients on a stock Apache never revalidate).
- **Clean conversion**: `render_block()` on the cleaned blocks (no related/CTA),
  excluded blocks/shortcodes/classes, fenced code blocks, **absolute URLs resolved
  against the source permalink** (document-relative, `../`, root-relative,
  query-only `?x` against the base *path* per RFC 3986 §5.3; any RFC 3986 scheme —
  `ftp:`, `sms:`, `whatsapp:`, … — is left untouched instead of being resolved as
  a path). Pipeline invariants worth knowing before touching `ContentRenderer`:
  - the fragment is parsed inside **`<sysmda-root>`**, never a `div`
    (`ROOT_TAG`): a stray `</div>` in the content closed a `div` wrapper early
    and everything after it was silently dropped from the body. Do not change it
    back to an HTML element the content can close.
  - `process_dom()` falls back to the unprocessed HTML if a non-empty input comes
    back empty, but **only when no exclusion rule matched** — otherwise the
    fallback would republish `md-exclude` content.
  - **tables** convert through the library's `TableConverter`, registered
    explicitly in `MarkdownConverter` (it is NOT in the library's default
    environment; without it `strip_tags` glued every cell together). `<figure>`
    holding a block element (`BLOCK_TAGS`) is therefore **not** rewritten to `<p>`.
    `<dl>` is flattened to a bold term plus paragraphs.
  - **whitespace normalization skips fenced code**: trailing spaces and blank-line
    runs are meaningful inside a fence (Markdown hard breaks, transcripts, diffs).
  - **no Markdown delimiter is ever chosen without looking at what it wraps**
    (`0.38.0`, `CodeFence`; independently rewritten `CodeElementConverter` in
    `0.41.0`). The library hardcodes
    three backticks for a block and one for a span, so content carrying that
    delimiter escaped its own construct: a code sample containing ` ``` ` closed
    its fence early, the rest of the sample became prose, and the trailing
    delimiter opened a fence that ran to the end of the document — heading and
    all. Fences are now sized to the longest run inside them and prose fences are
    escaped. Do not "simplify" this back to a constant; and if a new construct
    with a delimiter is ever added, size it the same way.
  - `CodeElementConverter::preConvert()` records whether a `<pre>` originally
    had exactly one `<code>` child, then consumes that flag when converting the
    parent. The library has replaced children with text nodes by then, so a late
    `getChildren()` check cannot distinguish converted child Markdown from a
    bare `<pre>` whose literal content happens to be a valid fence. Pass-through
    requires both recorded provenance and `CodeFence::is_safely_fenced()`; do
    not remove either half or introduce a marker into the emitted text.
  - code blocks whose highlighter wraps each line in its own element with no
    literal newline (Shiki → Code Block Pro) get their line breaks
    reconstructed (`code_text()`); markup that already has newlines is untouched.
  - **an embed keeps its text AND its address** (`0.43.0`, `link_embeds()`).
    `iframe` is in the converter's `remove_nodes`, so a resolved embed — a
    cached oEmbed result, a plugin filtering `render_block`, an embed block from
    another plugin — was stripped whole and the document kept no trace of it,
    the address included. The fix is not "replace the embed with a link": the
    first version did exactly that and bailed out whenever the element carried
    any other text, which loses the address again for the shape that keeps it
    only in the frame (a player plus a "Watch the video" span). Caught in
    review; the two goals are only in tension if the pass insists on replacing
    the whole element. So: nothing but the URL → the element becomes a
    paragraph linking it; real text plus a link that already names the resource
    → left alone; real text with the address only in the frame → **the frame
    alone** is replaced, in place. Four properties are load-bearing, each with
    a test seen to fail without it:
    - the pass runs **after `promote_figcaptions()`**, so the caption is
      already a sibling and survives;
    - the class is matched as a **whole token**, or core's
      `wp-block-embed-youtube` provider suffix would be swept in;
    - the stored URL is read **per text node**, never from `textContent`: that
      flattens the subtree, so a wrapper followed by a sibling paragraph reads
      as `https://example.com/v/1Note` — a string that passes for a URL, is not
      one, and outranks the frame that holds the real address;
    - the replacement is a **paragraph**, not a bare anchor: emitted flush
      against inline siblings the autolink came out as `<https://…>Watch the
      video` on one line, which is the defect `promote_figcaptions()` exists to
      prevent for captions.
    Frame references are resolved against the permalink inside this pass
    (`embed_reference()`), because `absolutize_urls()` runs later and only
    covers `a` and `img` — a protocol-relative or root-relative `src` would
    otherwise be rejected as a candidate and then removed, address and all. The
    protocol-relative completion is local to embeds: `absolutize()` keeps
    returning `//host/path` unchanged for ordinary links, which is a documented
    part of the output format. Scope is the embed construct only — a bare
    `<iframe>` elsewhere is still removed, and widening this to arbitrary
    framed markup is a different decision.
  - **a link that renders nothing is named from what the markup declares, never
    from what surrounds it** (`0.44.0`, `name_empty_links()`). A card whose whole
    surface is clickable is built as an empty anchor laid over it, with the
    title, image and summary as siblings — the "stretched link" idiom, which CSS
    frameworks document as a utility and link-preview/related-posts plugins emit
    by default. Nothing was lost, but the link came out `[](url "Title")` — no
    text at all — while the name sat in a paragraph further down, severing the
    one association the document exists to carry. The name is read off the
    anchor (`aria-label`, else `title`), which fixes every plugin producing the
    shape and none of them specifically. Four properties are load-bearing:
    - **a declared name or nothing at all.** With neither attribute the markup
      says nothing, and synthesising a name from the href would turn decorative
      anchors — `#top`, JS hooks, skip links — into visible URLs in documents
      that read cleanly today. The degenerate `[](url)` stays, deliberately.
      This is where it differs from the embed pass above: an embed is a known
      construct that holds a resource address, an arbitrary empty anchor is not.
    - **emptiness is what the anchor RENDERS**, not whether it holds text: an
      anchor wrapping an image is named by that image's alt and already converts
      correctly, and one holding an empty `<span>` may be a CSS-drawn icon. Only
      an anchor with no element children at all is claimed. And *rendering
      nothing* is decided in PHP, Unicode-aware, never by XPath (`0.46.1`,
      `renders_nothing()`): `normalize-space()` knows only XML whitespace, so an
      anchor holding `&nbsp;` — which is what a card generator reaches for when a
      link needs a body it does not have — was never selected and came back out
      as the very `[](url "Name")` this pass exists to prevent. Separators are
      matched as `\p{Z}` and the zero-width characters are listed one by one
      rather than swept up as `\p{C}`, which would also claim private-use code
      points: an icon font's glyph is invisible to a whitespace test and not to
      the reader.
    - **the consumed `title` is removed.** The library emits `title` as a
      Markdown link title, so keeping it prints the name twice —
      `[The Name](url "The Name")`. An `aria-label` it never emits, so that one
      stays and a `title` beside it is genuinely something else.
    - **it runs AFTER `link_embeds()`.** That pass reads an embed's text nodes to
      decide what the embed says; naming a fallback anchor first would answer
      that question for it and change which branch an embed takes.
    The sibling title is deliberately NOT folded into the link: that would mean
    guessing which of a card's elements is the title, which is the structural
    guesswork this pass avoids. The name is duplicated instead — link text and
    card heading — which is honest and cheaper than being wrong.
  - **the exclusion lists in the panel ADD to the built-in defaults; they do
    not replace them** (`0.40.0`). The old semantics were a trap with no visible
    symptom: `AdminSettings::option_to_list()` returned the defaults only while
    the textarea was empty, so typing one tag into "Excluded shortcodes" dropped
    all five built-in ones in the same save. Exclusions are a safety list — the
    cost of getting one wrong is a form published into every `.md` — so they
    accumulate. `option_to_merged_list()` is used by the three exclusion filters
    only; `sysmda_llms_txt_key_content` keeps replace semantics, because a
    curated list is the user's whole answer rather than an addition to one.
    Removing a default is deliberately filter-only (priority 10, before the
    closure that appends at 20). The panel's "built-in defaults" disclosure now
    reads `ShortcodeCleaner::DEFAULT_EXCLUDED` / `BlockCleaner::DEFAULT_EXCLUDED`
    / `ContentRenderer::EXCLUDED_CLASSES` instead of keeping a second copy that
    could drift from what is actually applied.
  - **shortcodes are expanded on both branches, and never inside code**
    (`0.38.1`, `expand_shortcodes()`). Two halves of one rule, and the second is
    why the fix is a single shared helper rather than a line added to the block
    branch. (a) `render_block()` does **not** expand shortcodes — on the front
    end that is `the_content`, which this pipeline skips by design (step 4
    below) — so nothing expanded them at all for block content, and a shortcode
    typed into a paragraph, a Custom HTML block or the core Shortcode block was
    published as literal `\[tag\]`. (b) `do_shortcode()` is a plain regex over
    the whole string with no notion of markup, so the classic branch, which had
    always called it, expanded a code sample *showing* `[gallery]` as if it were
    the real thing — rewriting the sample into whatever the shortcode renders.
    Code regions are therefore masked with placeholders around the expansion,
    for both branches at once. Do not "simplify" this to a bare `do_shortcode()`
    on either side, and do not add a filter to make the protection optional:
    WordPress's own `[[tag]]` escape already covers literal brackets outside
    code. A masking failure falls back to expanding unprotected, because
    publishing the raw tag would be the worse of the two.
  - **and the same masking guards REMOVAL, not just expansion** (`0.40.0`,
    `CodeRegions`). `0.38.1` shipped exactly half the rule: `strip()` runs
    before all of the above, on the raw source, and knew nothing about code, so
    an article documenting an *excluded* tag had it deleted from its own
    example — `echo do_shortcode('');`. One rule, one content, two halves of the
    pipeline, one of them missing. The masking therefore lives in a shared class
    used by both passes, which is the point: a helper cannot be applied on one
    side and forgotten on the other, whereas two copies of a regex demonstrably
    can. Two properties of that helper are load-bearing and were both got wrong
    first time round (caught by Codex on PR #72 and by a test):
    - **The transform runs at most once.** An enclosing shortcode may rewrite,
      escape or discard the body it is handed, so a placeholder can legitimately
      fail to come back. The first version answered that by re-running the
      transform on the *unmasked* string, which is worse than the problem twice
      over: it expands `[gallery]` inside the very code sample the class exists
      to protect, and it repeats every wrapper's side effects. Surviving regions
      are restored, a consumed one stays consumed — that is the wrapper's
      decision, not this helper's to undo. `strtr()` leaves absent keys alone, so
      the partial restore needs no extra pass. The single exception is a
      *masking* failure, where nothing was masked and the transform has not run
      at all.
    - **The placeholder is `[A-Za-z0-9_]` only.** It is handed to arbitrary
      shortcode callbacks, and the things they routinely do to their own body are
      exactly what would mangle a livelier token: `esc_html()` rewrites an
      HTML-comment-shaped one, and `wptexturize()` — reached through any callback
      that runs `the_content` — turns `--` into an en dash. A word-character
      token survives both and is restored normally, which removes the most
      plausible way for a region to go missing instead of handling it afterwards.
  **Synced patterns** (`core/block`) are expanded into the referenced content and
  cleaned with the same rules (reference-cycle guard).
- **Page-builder veto** (`BuilderDetector`): a post rendered by a builder the
  plugin has no adapter for is not servable, so the `.md` 404s and the post
  leaves `/llms.txt`, the alternate links, the shortcodes and the dynamic tag —
  one predicate, everything else by construction. Divi, WPBakery, Oxygen, Beaver
  Builder and Breakdance permanently; Elementor until its adapter lands (Bricks
  left this list in `0.46.0` — see the next bullet). Detection is **per post**,
  keys on the **render mode** rather than the presence of builder data, reads
  **meta and never `post_content`**, and holds whether the builder plugin is
  active or not. Escape hatch: `sysmda_markdown_unsupported_builders`. The panel
  shows the per-type breakdown (`BuilderCensus`, admin-only, transient-cached).
  Full rationale and the three rules easy to get backwards: the durable
  decision in "Product decisions".
- **Bricks adapter** (`BricksAdapter`, `0.46.0`, Phase 2 of
  `docs/page-builders-plan.md`): a Bricks-mode post (`_bricks_editor_mode ===
  'bricks'`) produces a real `.md`, rendered through Bricks' own
  `\Bricks\Frontend::render_data()` rather than a re-implementation — the
  plugin reads the builder's stored tree only to *decide* (does this post's
  current render mode match, what should the cache validator cover, what crude
  text can stand in for a description), never to reproduce Bricks' own element
  rendering. New `BuilderAdapter` interface
  (`is_active()`/`handles()`/`render()`/`fingerprint()`/`source_text()`/`element_selectors()`)
  and a third branch in `ContentRenderer::render()`, tried **before** the
  `has_blocks()` test and gated by both `is_active()` (the vendor plugin must
  actually be loaded — with Bricks deactivated `post_content` is the correct
  answer) and `handles()` (the *current* render mode, not the presence of
  stored data — a post switched to "Render with WordPress" is unaffected).
  Deliberately **not** hung off `sysmda_markdown_source_content`: that hook
  already ran, and already-rendered builder HTML falling into the classic
  branch would pick up `wpautop()` plus a second `do_shortcode()` pass over
  content Bricks already expanded. New Advanced filter
  `sysmda_markdown_builder_adapters` (the adapter list, default one entry).
  - **The lazy-load fix is mandatory, not optional cleanup.** Bricks' own
    image lazy-loading swaps `src` for an inline `data:image/svg+xml`
    placeholder and moves the real URL to `data-src`/`data-srcset` unless
    `\Bricks\Database::$page_settings['disableLazyLoad']` is set for the
    render; `render()` brackets the call with a save/restore of that flag
    (never a bare assignment — a bare one would leave every *other* Bricks
    render on the same PHP process, a preview, an admin-ajax call, with lazy
    loading silently disabled). Verified live against a **real WordPress
    attachment**: the bug does not reproduce at all with a raw external image
    reference, because Bricks only swaps `src` inside
    `wp_get_attachment_image_attributes`, a filter that fires only for a real
    attachment — an easy way to ship a guard that "passes" without ever having
    been seen to fire (see "A guard is not done until it has been seen to
    fire" in "Code conventions").
  - **Excluded builder elements** (`sysmda_markdown_excluded_builder_elements`,
    Stable, new panel field): page-builder chrome removed the same way an
    excluded CSS class is, additive to whatever else contributes to the list
    (the 0.40.0 rule — exclusions accumulate, never replace). Defaults come
    from the active adapter(s)' `element_selectors()`, not a fixed constant:
    for Bricks, `brxe-form`, `brxe-nav-menu`, `brxe-nav-nested`,
    `brxe-post-sharing`, `brxe-post-toc`, `brxe-breadcrumbs` — the class Bricks
    itself emits (`brxe-{element name}`) for its built-in form, navigation,
    share, table-of-contents and breadcrumbs elements, verified against the
    installed Bricks 2.0's own element registry. The existing `md-exclude`
    class needed **no new code at all**: Bricks already emits an element's
    custom CSS classes (`settings._cssClasses`) verbatim on the rendered
    wrapper, so `ContentRenderer`'s existing class-removal pass already
    reaches it — confirmed live rather than assumed.
  - **Cache fingerprint**: `MetadataBuilder::dependencies_fingerprint()` (now
    an **instance method**, not static — it needs `ContentRenderer`'s adapter
    list) folds in `ContentRenderer::builder_dependency_parts()`, which asks
    the matching adapter for its `fingerprint()`. For Bricks: the render mode
    (so a flip to/from "Render with WordPress" moves the validator even
    though the tree is untouched), a hash of the whole stored tree, and the
    modification date of any referenced `template` element's own post (a
    `bricks_template` post referenced via `settings.template` — confirmed
    live; the classic "out-of-post dependency" shape, same rule as a synced
    pattern). **Deliberately narrower than every out-of-post dependency**: a
    Bricks "component" instance carries a `cid` reference whose own
    definition was not confirmed to live anywhere resolvable on the
    reconnaissance install (no `bricks_component` post type registered, no
    populated components option) — the `cid` value itself still moves the
    tree hash, so a *reassigned* reference invalidates; a component's own
    definition changing elsewhere does not. Documented as an accepted, narrow
    residual rather than guessed at. **Cost measured, not assumed** (the plan's
    explicit requirement): `json_encode()` + `md5()` on a representative
    60-element/~22 KB tree costs ~0.09 ms, and on a deliberately large
    300-element/~89 KB tree ~0.36 ms — negligible next to the ~1000–1200 ms
    `.md` TTFB already measured in the `0.29.0` cache work, and this runs on
    every request, `304`s included.
  - **`description` / `/llms.txt` fallback**: `MetadataBuilder::description()`'s
    last-resort tier (after Rank Math, after the excerpt) now checks
    `ContentRenderer::builder_handles()` first. For a builder-handled post it
    uses `BuilderAdapter::source_text()` — a cheap, unrendered walk of the
    stored tree's text-bearing `settings.text` values, each wrapped in a span
    carrying the element's own `brxe-{name}`/custom class so the **same**
    `strip_excluded_content()` exclusion pass the body uses applies to it
    too — and **never** falls back to `post_content`, even when that text is
    empty. A Bricks post's `post_content` can hold stale prose left over from
    before the page was rebuilt in Bricks, and summarising it would reproduce,
    in the description field, the exact "confidently wrong" failure the
    page-builder veto exists to prevent in the body (see the `0.45.0`
    decision below) — empty is the honest answer there, not a fallback to a
    field that was never trustworthy for this post. `/llms.txt` inherits this
    for free through the shared `description()` method: it never renders N
    Bricks pages to build N entries.
  - **Post Content and `the_content`**: Bricks' `post-content` element calls
    WordPress's full `the_content` filter chain internally, which would
    reintroduce exactly the injected related/CTA content `render_block()` is
    used instead of `the_content()` to avoid everywhere else in this pipeline
    (see "Technical notes" §4). `maybe_suppress_content_filters()` removes
    every foreign callback from `the_content` for the duration of the render —
    but only when the tree actually contains a `post-content` element (a page
    without one pays nothing) and only when the new Advanced filter
    `sysmda_markdown_builder_suppress_content_filters` (default `true`)
    allows it. **A maintainer-reversible design choice, not a settled
    answer** — flip the filter to `false` to accept whatever a real visitor
    sees there instead, no code change required. Verified live: a foreign
    `the_content` callback appending a "SUBSCRIBE NOW" block is present in
    the Post Content element's output without the suppression and absent with
    it, while `wpautop`/`do_shortcode` still run either way. The snapshot has
    one sharp edge, caught by testing rather than reasoning about it: a
    `WP_Hook` object assigned to a variable copies the handle, not the state,
    so the snapshot must be a `clone` — a bare read shares the same object
    `remove_all_filters()` then empties, and the restore silently does
    nothing (see "A guard is not done until it has been seen to fire").
  - `sysmda_markdown_prewarm` stays **off** for Bricks posts as for every
    other post: element-level visibility conditions (`settings._conditions`,
    evaluated by `\Bricks\Conditions::check()`) were confirmed live to read
    only post/user/date/WooCommerce/dynamic-data/browser/referer/current-URL
    state — none of it `is_singular()`/archive/query-var predicates, contrary
    to what the reconnaissance had flagged as an open risk — but a `_conditions`
    rule keyed on `current_url` (which reads the parsed request path) still
    differs between the `.md` suffix route and the negotiated permalink route,
    and WP-Cron's missing request context remains unverified for that one
    case. Documentation only; no code change.
- **Plain permalinks** (`?p=123`): the `.md` suffix is not applicable, so
  `markdown_url()` falls back to `?format=markdown` (served via negotiation);
  notice in the settings page. Post eligibility centralized in `PostSupport`.
- **`/llms.txt`** (cached, excludes protected content) with an on/off toggle.
  The body cache is the anonymous representation only: authenticated requests
  rebuild in the visitor's context without reading or populating the shared
  entry and are sent `private, no-store, must-revalidate`. Their strong ETag is
  still safe because it is derived from the freshly rebuilt body, not from a
  shared metadata validator.
  Since `0.29.0` it answers conditional requests like the `.md` endpoint:
  **`ETag` + `304`** and the same `Cache-Control`. Its `ETag` is the **md5 of the
  body about to be sent** — the one strong validator in the plugin, and
  deliberately NOT `cache_version()`, which does not cover the posts listed in
  the file (a new post is picked up by deleting the cache entry, not by moving
  the version, so a version-derived `ETag` would answer `304` with an index
  missing it). Hashing the bytes is free here precisely because the body already
  exists before the response is written, which is exactly what the `.md`
  endpoint cannot do. No `Last-Modified`: the index has no single modification
  date, so `If-Modified-Since` is not honoured either.
  Optional **enriched mode** (`sysmda_llms_txt_enriched` toggle, default off;
  off = base output unchanged): site summary, curated "Key content" section
  (IDs/URLs from the settings page), per-entry description (Rank Math → excerpt →
  trimmed chain), overflow beyond the most recent posts under `## Optional`
  (spec keyword, not translated), `sysmda_llms_txt_footer` filter as a hook for
  policy/LLM signals. Optional **last modified dates** (`sysmda_llms_txt_lastmod`
  toggle, default off; off = output unchanged): appends `(updated: YYYY-MM-DD)`
  to every entry (base and enriched, Key content and Optional included) — ISO
  date from `post_modified_gmt`, English `updated:` label never translated
  (same convention as the `Optional` spec keyword), placed in the free-text
  notes after the `:` so it stays llms.txt-spec-compatible.
- **LiteSpeed page-cache compatibility** (`LiteSpeedCompat`): some LiteSpeed
  servers key the page cache by URL only and ignore `Vary: Accept` (observed
  live: a cached Markdown variant served to HTML clients and vice versa, while
  PHP negotiated correctly). Two layers: (1) the negotiated Markdown and `406`
  responses always send the standard
  `Cache-Control: no-cache, no-store, must-revalidate, private`
  (`MarkdownController::send_no_cache_headers()`, server-agnostic) plus the
  LiteSpeed-specific signals — `X-LiteSpeed-Cache-Control: no-cache` + define
  `DONOTCACHEPAGE` + fire the LSCache-plugin `litespeed_control_set_nocache`
  action — so URL-keyed caches never store them (`.md` URLs stay cacheable: they
  are their own key); the LiteSpeed cache is also **purged on plugin
  activation/deactivation** (`litespeed_purge_all`, no-op without LSCWP:
  entries cached before activation carry no `Vary`); (2) opt-in **`.htaccess` rules** (Advanced →
  `sysmda_litespeed_htaccess` checkbox, default off) wrapped in
  `<IfModule LiteSpeed>` (inert elsewhere): requests whose `Accept` mentions
  `text/markdown` get `[E=Cache-Control:no-cache]` and bypass the LiteSpeed
  cache, so PHP always negotiates even when the HTML variant is already cached.
  That is the **only** rule since `0.30.0` (the 406 bypass was removed — see the
  decision below). The block is
  written at the **top** of `.htaccess` — it MUST precede `# BEGIN WordPress`,
  whose `[L]` rules end every rewrite pass, so a block appended at the bottom
  is never evaluated (verified live; do not switch back to
  `insert_with_markers`, which appends). Synced (written/removed/moved back to
  the top) on every settings-page load, comparing directive lines only (WP
  injects an instruction comment inside marker blocks); triggers an LSCache
  purge-all on change, shows the rules to copy manually when `.htaccess` is
  not writable, and is removed on uninstall. When LiteSpeed is detected and
  the option is off, the panel shows an explicit "recommended on LiteSpeed"
  notice (whether a host honours `Vary` cannot be detected automatically —
  the rejected self-test decision stands — so the safe default when unsure
  is to enable); the `readme.txt` FAQ documents the manual curl diagnostic.
- **`.md` hit counter** (`HitCounter`; opt-in "Count `.md` requests" checkbox
  in Advanced, default off): counts how many times the `.md` endpoint is
  served — `200` **and** `304` (an access is an access), both the `.md`
  suffix and the negotiated permalink — split **bot vs human**
  (`is_bot()`: empty UA ⇒ bot; case-insensitive token list — crawlers, HTTP
  clients/CLIs, headless stacks, AI/LLM agents; filter
  `sysmda_md_hits_bot_patterns`). Stores ONLY aggregate daily buckets in
  option `sysmda_md_hits` (autoload off, UTC days, shape
  `[ 'YYYY-MM-DD' => [ 'bot' => n, 'human' => n ] ]`), pruned beyond 90 days
  (filter `sysmda_md_hits_retention_days`); the UA is read once to classify
  and never stored (count-only durable decision). Read-only totals in the
  panel (today / last 7 / last 30 days, bot vs human) with the page-cache
  undercount caveat. The buckets option is excluded from the settings-save
  cache-salt bump (it changes on every counted request and does not affect
  the output). Both options removed on uninstall.
- **Filter API surfaced in user-facing docs**: `readme.txt` FAQ entry with
  examples + "Extending via filters" section in `README.md`,
  all pointing to the full "Developer extension API" list in `docs/filters.md`
  (moved out of this file: the filter API is developer-facing documentation and
  does not belong inside the agent guide). Every hook there carries a
  **stability level** (Stable / Advanced) and the table is enforced by contract
  tests — see "Filters (developer extension API)" below.
- **Custom taxonomies in the front matter** (per-taxonomy selection in the panel,
  option `sysmda_front_matter_taxonomy_slugs`, **nothing selected by default**;
  empty = front matter and cache validator byte-identical to 0.23.x): appends a
  nested `taxonomies:` mapping **after `description`** (append-only contract)
  with the terms of the **selected** taxonomies. Slugs and term names sorted with
  `SORT_STRING` — **byte order, not locale collation**, so output never depends
  on the server locale. **No auto-detection** (removed in 0.25.0; see the
  durable decision below): the registry cannot
  say whether a taxonomy belongs in a machine-readable representation, and the
  0.24.x `public`-only check published editorial-internal taxonomies
  (`publicly_queryable => false`). `MetadataBuilder::candidate_taxonomies()` /
  `is_public_taxonomy()` exist only to build the panel list, label the
  not-publicly-queryable rows and seed the migration — never to gate the output.
  Curation via `sysmda_front_matter_taxonomy_slugs` (AdminSettings feeds the
  option in at **priority 5**, so site code at 10 may narrow **and** extend it;
  naming a non-public taxonomy stays a deliberate opt-in); the always-excluded
  set and invalid slugs are stripped *after* the filter, so it can neither
  duplicate `categories`/`tags` nor break the YAML.
  `sysmda_front_matter_taxonomies` survives as the **kill switch**, its default
  now being "at least one taxonomy is selected". The 0.24.x checkbox option is
  migrated on `wp_loaded` (seeded with the public **and** publicly queryable
  taxonomies, then deleted, with an explicit cache-salt bump) and kept in
  `uninstall.php` as a legacy key.
  **Cache/ETag**: term changes do not touch `post_modified_gmt`, so
  `MetadataBuilder::taxonomies_fingerprint()` is folded into `cache_version()`
  — without it a conditional request would answer `304` with stale terms even
  with the body cache off (see "Technical notes" 6). For the same reason
  `If-Modified-Since` is **ignored while the block is emitted**
  (`date_is_strong_validator()`): `Last-Modified` comes from
  `post_modified_gmt`, which a term change does not move, so a client sending
  no `If-None-Match` would otherwise get a stale `304`.
- **Documented output format** (`docs/output-format.md`): the front-matter keys,
  their order, the YAML scalar-escaping rules, the body pipeline and the HTTP
  contract, stated as a stable append-only contract (compatibility policy from
  `0.24.0`). Enforced by golden conformance tests in `tests/run-tests.php`
  (full + minimal fixtures, scalar-escaping cases); a `readme.txt` FAQ and a
  `README.md` section link it. Docs/tests only — no runtime change.
- **Redis-aware cache** (`Cache` helper): persistent object cache when present,
  transients otherwise. Invalidation via global salt + `post_modified_gmt` +
  `SYSMDA_VERSION`; salt bump on settings save; cleanup on `save_post`/
  `deleted_post` (skips revisions/autosaves). Optional **pre-warm** after a save
  (`sysmda_markdown_prewarm`, default off — see the decision): a WP-Cron event
  rebuilds the entry so the first reader does not pay for the conversion.
- **Front matter is suppressible as a whole** (`sysmda_front_matter_enabled`,
  default on): `false` starts the document at `# Title`. The layout lives in
  `MarkdownController::assemble_document()` (public + static, so the join is
  covered by golden tests), which owns the rule that the blank line after the
  block belongs to the block.
- **Admin panel** (single page, Settings API): General / Markdown output /
  llms.txt / Integrations / Advanced. Restyled UI (presentation only): page
  header + single Save button, native WP **tabs**, section **cards**, two-column
  layout with an at-a-glance `/llms.txt` status/conflict aside, built-in defaults
  in a `<details>` disclosure. `render_page()` iterates the registered Settings
  API sections (`$wp_settings_sections`) and wraps each in a card+tab-panel;
  **all fields stay in the single form** (tabs show/hide client-side), so saving,
  sanitization and nonces are unchanged. Admin-scoped CSS + a tiny dependency-free
  vanilla-JS enhancement (`assets/admin-settings.js`); usable without JS (all
  panels visible). Assets loaded only on the settings screen. A "Settings"
  action link on the plugin row in the Plugins list points to the panel.
- **i18n**: panel strings in `__()`/`esc_html__()` (**English** source), text
  domain `system-markdown-alternate` (= plugin slug). **No bundled translations
  and no manual translation loader**: language packs come from
  translate.wordpress.org and WP loads them automatically (≥ 4.6).
- **ACF**: subtitle (text) + TL;DR (WYSIWYG, goes through the DOM pipeline) as a
  preamble between the H1 and the body; field names configurable from the panel.
  **A text field is text, and the delimiters are chosen after escaping what they
  wrap** (`0.46.1`, `MarkdownConverter::escape_inline()`): the subtitle used to
  be interpolated raw between `*`, so `A *literal* marker` published as
  `*A *literal* marker*` — the reader's own asterisks parsed as formatting, one
  emphasized line arriving as three. The same rule as `CodeFence`, one construct
  over. Two properties are load-bearing: the escaping is **the library's**,
  obtained by handing it the value as a text node, so the subtitle escapes
  exactly what the body escapes and no second copy of the rule can drift from it
  (a backtick pair still forms a code span, and `&` still comes back as `&amp;`,
  in a subtitle for the same reason they do in a paragraph); and the emphasis
  delimiters are added by the **caller**, never by converting an `<em>`, because
  the library's emphasis converter tests its value with `! trim( $value )` and a
  subtitle of exactly `0` is falsy — measured, it comes back with the delimiters
  silently dropped.
- **Extra custom fields** (`0.47.0`, `MetaFields`): a panel textarea of post
  **meta keys** whose values are appended to the end of the body, in the order
  listed, through **`sysmda_markdown_appended_html`** at priority 20 (registered
  after `AcfIntegration`, so ACF's own fields keep their position). Empty by
  default; new Stable filter `sysmda_markdown_extra_meta_keys`, fed by the panel
  at **priority 5**. Motivated by a
  real case: a page built from a GeneratePress Elements template mixes
  `post_content` with pieces held in ACF/JetEngine/native Custom Fields, and
  none of it reached the `.md`.
  **One generic mechanism, not N per-plugin integrations** — ACF, JetEngine,
  Meta Box and the native Custom Fields box all store ordinary post meta, so a
  "JetEngine integration" would be the same twenty lines wearing a different
  name. Explicit and opt-in, never auto-detected: post meta is mostly internal
  plumbing, and no rule can tell which keys are content (same discipline as the
  taxonomy selection).
  Four properties are load-bearing:
  - **The value is read with `get_field()` when ACF is active, `get_post_meta()`
    otherwise** — and this costs nothing for non-ACF sources, which was measured
    rather than assumed. Against ACF 6.8.8, for a key ACF has *no field
    definition* for, the two functions returned **identical** values: an
    unregistered key, a protected `_`-prefixed key and a serialized one alike. So
    a JetEngine or native key behaves the same whether ACF is installed or not,
    while a registered ACF field arrives formatted the way ACF renders it. They
    diverge only on an **absent** key (`''` vs `null`), which is why presence is
    never inferred from the value.
  - **A fingerprint part is added only when the post ACTUALLY HAS the key**
    (`MetadataBuilder::collect_meta_dependencies()`, gated on
    `metadata_exists()`), deliberately departing from
    `collect_acf_dependencies()` one method up, which adds a part per configured
    key regardless. That is harmless for ACF, whose key list is filter-only and
    rarely set; it would not be harmless here, because a panel field is
    site-wide and `date_is_strong_validator()` refuses `If-Modified-Since` on a
    *merely non-empty* fingerprint without comparing values. One key typed into
    the box would otherwise switch the date validator off for every post on the
    site, including the ones that never had the field. Off has to mean
    byte-identical output **and** byte-identical validator, as it does for the
    optional taxonomies.
  - **Deletion bumps the salt; emptying does not**, and the asymmetry is the
    whole reason only `bump_for_deleted_dependency_meta()` was extended. An empty
    value leaves the row in place, so `metadata_exists()` stays true, the part
    survives as the hash of an empty value and the ETag moves by itself. A
    deletion removes the row, can return the fingerprint to empty, and
    `post_modified_gmt` never moved — the stale-`304` shape from "Technical
    notes" 6. That hook reads the **option**, not the filter: it fires on every
    meta deletion site-wide and must stay trivial, so a key added through the
    filter alone is not covered — documented in `docs/filters.md`, pointing at
    `sysmda_markdown_cache_dependencies`.
  - **A text value is text, and no wrapper may quietly say otherwise**
    (`0.47.1`, `MetaFields::emit()`). Each value used to be wrapped in a `<div>`,
    and the conversion library escapes a text node only when its parent is *not*
    a `div` — so a field reading `A *literal* marker` was published with the
    asterisks live and one word in italics, while the identical text inside
    `post_content` (which reaches `<p>`) was escaped correctly. The wrapper was
    disabling the escaping, invisibly. Values are separated by a blank line
    instead, so `wpautop()` gives a bare value the paragraph the escaping needs
    and leaves a WYSIWYG field's block markup alone; the `div` contributed
    nothing to the Markdown either way. **Exactly the `0.46.1` ACF-subtitle
    defect, one construct over** — which is the argument for
    `MarkdownConverter::escape_inline()` existing at all, and worth remembering
    the next time a value is placed into the document by hand. Caught by the
    staging acceptance run, not by review or by the suite: the pure suite stubs
    `wpautop()`, so the shape that matters was outside what it could model, and
    the confirmation was run against real WordPress
    (`render_fragment()` on the live install, `<div>`-wrapped vs bare).
    `MetaFields::append()` owns the separator for the same reason `emit()` owns
    the skip rules: two producers feed this seam, and without one shared rule
    one producer's last value glues to the next producer's first.
  - **Non-strings are skipped, and the skip rules are SHARED with
    `AcfIntegration`** (`MetaFields::emit()`). A repeater or a serialized value
    has a structure this plugin has no brief to invent a rendering for. And the
    emptiness test is an explicit `'' === trim()` precisely so the string `"0"`
    survives — a rule got right once, easy to rewrite from memory as `empty()`,
    and therefore worth exactly one copy (the `CodeRegions` argument).
  - **Appending is not replacing the source, and the seam has to say so**
    (`ContentRenderer::render_appended()`, filter
    `sysmda_markdown_appended_html`, Advanced). This started on
    `sysmda_markdown_source_content` and was caught by Codex on PR #107: a post
    a page-builder adapter claims is rendered from the builder's own tree, so
    `render()` discards the filtered source entirely — every configured value
    vanished from a Bricks page while still moving the cache validator. A Bricks
    page is *precisely* the "the template holds the content" case this feature
    exists for, so the motivating scenario was the one that failed. The same
    defect had been silently true of `AcfIntegration::append_fields()` since
    `0.46.0`; both moved. `render_appended()` mirrors the main path's own
    block/classic branches, and that is what makes the ACF move free — a synced
    pattern referenced from an ACF field is still expanded, which is what
    `collect_acf_dependencies()` assumes when it walks those references. It runs
    **before** the single outer `process_dom()`, so appended content is
    class-excluded and absolutized by the same pass rather than a second one.
    `render_appended()` also **paragraph-wraps freeform blocks individually**
    (`0.47.1`, Codex on PR #108): `has_blocks()` is a substring test over the
    whole fragment, so one value carrying block markup sends every plain-text
    sibling down the block branch, where `parse_blocks()` returns them as a
    `blockName === null` block that `render_block()` emits verbatim. Core gets
    its paragraphs back through `do_blocks()`'s `wpautop` dance, which this
    pipeline skips by design — so without the wrap the converter collapses the
    blank line and publishes two fields as one run-on line, which is the merging
    `MetaFields::append()` exists to prevent arriving through another door.
    Codex read that case as an **escaping** regression; it is not, and the
    measurement is worth keeping so it is not re-derived: escaping depends on the
    text node's parent not being a `div`, and at root level there is none, so
    bare text is escaped with or without `wpautop` (`A \*literal\* marker` in
    both). Only the `div` wrapper ever suppressed it.
  - **The panel feeds the key list at priority 5, not 20** (also Codex, PR #107).
    The callback REPLACES its input, so at 20 it runs after site code hooking at
    the default 10 and throws that code's additions away. Priority 5 makes the
    saved list the filter's *default*, which is what lets site code narrow **and**
    extend it — the same reasoning, and the same number, as
    `sysmda_front_matter_taxonomy_slugs`. The three exclusion filters can sit at
    20 only because they merge. Rule of thumb: **a replacing callback goes before
    site code, a merging one after.**
  Deliberately out of scope: the front-matter `description` fallback does not see
  these values (it reads `post_content` directly), exactly as
  `sysmda_acf_field_keys` already behaves; and there is no per-key placement —
  the plugin cannot know where in a template's layout a field renders, so
  appending is the honest answer rather than a guessed one.
  **Not adopted, and why** (the third Codex finding, P1): that `get_field()`
  refuses keys which are not registered ACF fields since 5.11, making the
  cross-plugin mechanism work only without ACF. Measured against the live ACF
  6.8.8 staging on six unregistered keys, on a site with no field groups and no
  `_{key}` reference rows at all: `get_field()` and `get_post_meta()` returned
  **identical** values every time, protected and serialized keys included. The
  claim does not reproduce. What was taken instead is a narrower insulation — a
  strict `null === $value` fallback to `get_post_meta()`, since `null` is what
  ACF returns for an absent key and what it *would* return if a future version
  started refusing unregistered ones. Keyed on `null`, never on falsiness: a
  registered true/false field returns `false` on purpose, and falling back there
  would publish the raw `"0"` ACF meant to suppress. Codex's own suggestion
  (detect ownership with `acf_get_field()`) was rejected: whether that resolves a
  *registered* field by name outside a post context is unverified, with no ACF
  field group on either staging to test against, and getting it wrong breaks the
  ACF formatting path.
- **Shortcodes**: `[sysmda_md_url]` (+ `id="123"`), always a bare URL; and
  `[sysmda_md_download]` (+ `id`, `text`), always markup — an anchor that saves
  the file instead of opening it. See the decision below for why they are two.
  The download is purely client-side: the link is same-origin and carries the
  HTML `download` attribute, which is all a browser needs. The response sends no
  `Content-Disposition` and the plugin reads no `download` argument — see the
  decision below. File name via `MetadataBuilder::download_filename()`:
  percent-decoded, transliterated, reduced to `[A-Za-z0-9._-]`, `post-<ID>.md`
  as fallback; the charset is the safety property, tested as such rather than as
  a fixed string.
- **Reader-facing Markdown actions** (`0.39.0`): `[sysmda_md_actions]` (+
  `id="123"`) is an explicit GitHub-style split button. The primary action copies
  the complete Markdown document; the dropdown repeats copy and adds new-tab
  view plus direct download. It is fixed-scope — no automatic placement,
  settings, item/label filters or theme-wide asset load. CSS/JS enqueue only
  when the shortcode renders (early when it sits in the queried content, late
  for a template/widget/secondary loop). A late render after `wp_head` explicitly
  prints the enqueued stylesheet at the start of `wp_footer`; unlike scripts,
  WordPress has no automatic footer queue for styles. Scripts use WordPress's
  native footer queue until its normal printer runs; since `0.41.1`, a first
  render after `wp_print_footer_scripts` prints only this handle immediately
  through the scripts API, because merely enqueueing it after the consumed pass
  leaves the component permanently hidden. JavaScript moves the
  dropdown to `document.body`, then anchors it to the **whole split button and
  not to the caret** (`0.45.1`): its start edge lines up with the group's start
  edge and it drops straight below, which is the placement the reference
  implementation at `acceptmarkdown.com` gets from a plain `left: 0` on the
  wrapper. Anchoring it to the caret instead made the menu hang off to the
  right of the button on every ordinary desktop placement — the fallbacks were
  correct and simply never ran there. They now run only when they are the point:
  end alignment when the group sits too close to the viewport edge, a clamp
  inside an 8 px inset when neither alignment fits, a flip above when there is no
  room below, and a `max-height` with scroll when there is room on neither side —
  that last one so the menu never grows across the button it belongs to. The
  direction is read per pass, so a right-to-left theme mirrors both alignments.
  The portal into `document.body` stays, because unlike the reference the
  shortcode can be placed anywhere in an unknown theme. The menu is sized to its
  content rather than to a fixed width, which couples placement to the labels:
  copy feedback ("Copying…", "Copied!") can be wider than the label it replaces,
  so **`setLabel()` schedules a repositioning pass**. Without it an open,
  end-aligned menu grew straight past the viewport edge on the first long
  translated feedback string and stayed there until the next scroll (caught by
  Codex on PR #100, then reproduced: 184 px outside the viewport). Anything else
  that can change the menu's size while it is open owes the same call. The root is hidden until setup, copy uses the
  Safari-safe promise-backed `ClipboardItem` path with fallbacks, and a response
  whose type is not `text/markdown` is refused rather than copied as HTML. The
  whole shortcode is in `ShortcodeCleaner::ALWAYS_EXCLUDED`: interface chrome
  never enters the `.md`.
- **GenerateBlocks Dynamic Tag** `{{sysmda_md_url}}`: self-registers when GB 2.x is
  active (no toggle).
- `uninstall.php` (removes `sysmda_*` options + transients + the LiteSpeed
  `.htaccess` block).

## Open / to do (towards wordpress.org)

- **Ideas surfaced by reviewing a comparable plugin** (`Serve Markdown` /
  `serve-md`, wordpress.org, `akumarjain`, v1.0 — read in full August 2026;
  not a plan, three separate candidates recorded for future evaluation, none
  built). It is smaller and less mature than this plugin on every engineering
  axis that matters here — regex-based HTML→Markdown conversion instead of a
  DOM pipeline, `the_content` instead of `render_block()` (reintroducing
  exactly the injected related/CTA content this plugin's rendering choice
  avoids, see "Technical notes" 4), no caching, no `ETag`/`304`, and an
  `Accept` parser that never compares against `text/html`'s own q-value and
  sends no `Vary`. None of that is worth adopting. Three narrower ideas are:
  - **Per-post opt-out.** A single postmeta checkbox in a meta box
    (`_serve_md_disabled` in their plugin), independent of every exclusion
    axis this plugin already has (post type, post format, taxonomy
    inclusion, page-builder veto, password). None of those cover "this one
    post, for an editorial reason, regardless of type or category." Cheap
    and additive; the natural implementation reuses the existing
    `sysmda_post_is_servable` veto filter (see the anonymous-representation
    decision) rather than adding a new gate to `is_servable()`.
  - **Category/tag exclusion.** Excluding whole taxonomy terms from being
    served is an axis this plugin does not have at all: the only
    taxonomy-shaped gates today are post format
    (`PostSupport::EXCLUDED_POST_FORMATS`) and the opt-in custom-taxonomy
    *inclusion* in front matter — neither lets an owner say "nothing in
    category X is servable." Same discipline as the generic-meta-fields item
    above: explicit, opt-in, additive, never auto-detected.
  - **A per-request crawler log — evaluated and NOT proposed as their
    plugin builds it, because it reopens a decision already made on
    purpose.** Their `Serve_MD_Logger` stores, per Markdown request, the raw
    IP address, the full User-Agent string and a day-resolution-or-finer
    timestamp in a dedicated DB table (with retention/row-count/size caps
    and a stats UI). That is exactly what "`.md` hit counter is count-only"
    (Product decisions) forbids here, for a stated reason: aggregate-only,
    no IP, no raw UA, no per-visitor identifier, so the feature stays
    outside GDPR scope with no consent flow needed. It is also exactly the
    shape "Server-side diagnostics" (below) already considered and
    declined, closing with "the only shipped request-side telemetry remains
    the count-only `.md` hit counter." Reopening either is not proposed.
    What IS worth evaluating, because it stays inside both boundaries:
    extending `HitCounter`'s daily buckets from the current bot/human split
    to a **per-known-bot-name breakdown** (`ClaudeBot`/`GPTBot`/
    `PerplexityBot`/… — the same detection list `is_bot()` already
    classifies against, one level more specific), still aggregate-only,
    still no IP or raw UA retained, same 90-day prune. This would also
    sharpen exactly the signal the homepage-index postponement above is
    explicitly waiting on ("re-evaluate only once the `.md` hit counter
    provides real demand data") — which bot is asking matters for that
    decision, not just how many. Not scoped further than this: whether the
    panel shows a fixed bot list or only bots seen at least once, and how
    far `sysmda_md_hits_bot_patterns` should reach into naming the buckets,
    are both open.
- Once live on wordpress.org: translate the strings into Italian on
  translate.wordpress.org (request PTE if needed) so the `it_IT` language pack
  gets built — no translation files live in this repo.
- Future idea: formalized **LLM signals** in `/llms.txt` once the spec
  (Cloudflare & co.) settles — the hook is already in place (`sysmda_llms_txt_footer`).
- **Serve `.md` for the site homepage** (postponed — decided July 2026:
  re-evaluate only once the `.md` hit counter provides real demand data; the
  shape is already settled, see the "NO synthesized homepage index" decision in
  "Product decisions"). If/when implemented: **static front page only**
  (`show_on_front = 'page'`: a real `WP_Post` converted with the existing
  pipeline), dedicated opt-in toggle (e.g. `sysmda_markdown_homepage`, default
  off) independent of `sysmda_markdown_supported_post_types`; when the front
  page is the blog posts index, **skip** (archive, no `WP_Post`; notice in the
  panel). Implementation notes parked for that day:
  - URL `https://example.com/.md`: `url_to_postid('/')` may return 0 for the
    front page → needs a `get_option('page_on_front')` fallback in the
    resolution; trailing-slash and query handling as today.
  - Eligibility through `PostSupport::is_servable()` (single source of truth),
    without loosening the rule for anything else; `attachment` stays excluded,
    published + not password-protected stay required.
  - `print_alternate_link()` guards on `is_singular($types)`, which is false
    for a front page whose type isn't enabled → guard to revisit.
  - Verify conversion quality first: front pages are block-heavy.
  - New toggle in `docs/filters.md` + docs + translations;
    tests for the `/.md` → front-page resolution and both `show_on_front`
    branches.
- **Translations in `/llms.txt`** (`docs/llms-txt-multilingual-plan.md`):
  greenlit, **not started**, and gated on the WPML/Polylang staging
  reconnaissance described inside — the current plan's central query assumption
  is not reliable and must be verified against real plugin behaviour before any
  code is written.
- **Page builders** (`docs/page-builders-plan.md`): **Phases 1, 1b, 0 and 2 are
  all shipped** (`0.46.0`) — the veto, the panel breakdown, the Bricks
  reconnaissance and the Bricks adapter itself; see "Current state" for what
  `BricksAdapter` does. Only Elementor remains parked in `AWAITING_ADAPTER`
  (a free-only staging cannot validate the Pro features that make it hard);
  Divi, WPBakery, Oxygen, Beaver Builder and Breakdance are **never** to be
  supported. Elementor — real demand and a Pro staging, in that order — is
  the only open item this plan still has.
- **Exclusion scanner** (`docs/exclusion-scanner-plan.md`): **parked, not
  started** — deferred August 2026, see the status note at the top of the plan.
  The damage half shipped in `0.40.0` (lists accumulate, code samples are safe,
  `ez-toc` added); discovery is what remains, and it is waiting on a real corpus
  to point at. An admin page that inventories the shortcode tags and block names
  actually present in the servable corpus, so the three exclusion lists can be
  filled in from evidence instead of guesswork. Greenlit by a measurement rather
  than an idea: `0.38.1` made a registered shortcode inside block content expand
  in full into every `.md` that contains it, and a staging reproduction on
  10 August 2026 confirmed it end to end (a newsletter form's label, button and
  GDPR paragraph landing in the middle of the prose). What that measurement
  cannot say is whether any real corpus *contains* such a shortcode — which is
  exactly what the scanner exists to answer, and why it is the cheapest
  available instrument rather than a feature looking for a use. The design is
  fixed and its constraints are all blocking; two are easy to get wrong and are
  called out here as well: applying a suggestion must write **the current
  effective list plus the new tag** (a non-empty option *replaces* the defaults,
  see `AdminSettings::option_to_list()`), and the results option must be
  **excluded from the settings-save cache-salt bump**, like the hit-counter
  buckets, or every scan invalidates the whole cache. It informs and never
  applies on its own — the same line as "never auto-detect which taxonomies to
  emit".
### To check next time (not urgent, parked here)

- **Freeform content in a mixed post never gets `wpautop()` on the main render
  path either** (noticed August 2026 while fixing the appended path in `0.47.1`;
  recorded, deliberately not changed). `ContentRenderer::render()`'s block branch
  calls `render_block()` in a loop rather than `do_blocks()`, so a `blockName
  === null` block's text is emitted verbatim — the same gap the appended path
  had. It rarely shows there because a freeform block's saved markup usually
  already contains its own `<p>` tags; the appended path bites because its input
  is genuinely bare text. Changing how every mixed post's body renders is not a
  patch-release change and needs its own verification against real content, so
  it is a separate decision rather than a silent fix. If picked up, the shape is
  the same three lines `render_appended()` now uses.

- **The caching contract is done; the `304` is a host property, not a gap.**
  Measured on webdietrolequinte.it (RunCloud/nginx behind Cloudflare) right
  after `0.29.0` shipped. Recorded as a closed measurement, NOT as pending
  work — nothing here calls for a plugin change, and the maintainer has
  explicitly declined to hand-tune the server for it. Re-measuring on a second,
  differently configured stack is the only thing still worth doing, and only
  out of curiosity. What was found:
  - the headers are correct — `public, max-age=0, must-revalidate`, no
    `Expires`, `ETag` and `Last-Modified` present, negotiated route still
    `no-store` — and **no `304` is ever produced**;
  - the reason is not the plugin: `If-None-Match: *` also answers `200`, and
    that wildcard makes `etag_matches()` return true without comparing
    anything, so PHP demonstrably never receives the header. Confirmed against
    the origin directly (`--resolve`, `server: nginx-rc`): the header is gone
    **before** Cloudflare, stripped by nginx, which removes conditional headers
    from the upstream request when caching is configured for the location —
    it wants the whole entity to store, then declines to store it because
    `max-age=0` says it is stale on arrival. Fixable only in the host's nginx
    config (exclude `.md` from the cached location), and **deliberately not
    done**: a `304` saves the body, ~12 KB, not the ~1 s of WordPress boot that
    dominates the response (measured: TTFB ~1.0–1.2 s on `.md`, ~0.4 s on a
    page-cache hit of the same article in HTML). The bottleneck is the boot, and
    no header touches it. Do not "fix" this by shipping host-specific config:
    the plugin sends a standard header that is correct everywhere and needs
    tuning nowhere; a stack that forwards conditional headers gets its `304`s
    for free.
  - Cloudflare **weakens strong ETags in transit**: `/llms.txt` emits `"…"` and
    arrives as `W/"…"`. Live confirmation that the `0.28.0` weak-tag decision
    was right, and that the symmetric comparison in `etag_matches()` is what
    keeps the round trip possible at all.
  A host that ignores `Cache-Control` on the way in
  (`fastcgi_ignore_headers`) would instead reintroduce staleness, and the
  answer there is a purge integration, not a header.
  **Control experiment, run on the same host (July 2026): the `max-age=0`
  explanation above is correct.** `sysmda_cache_control` was pointed at
  `public, max-age=0, s-maxage=600, must-revalidate` from an mu-plugin, and the
  RunCloud nginx cache — which had answered `x-runcache-status: MISS` on every
  single `.md` request before — started answering **`HIT`**, with PHP no longer
  running. Nothing else changed. So the cache was never unable to store the
  `.md`; it was declining to, exactly because the response declared itself stale
  on arrival. Two details worth keeping: nginx adds no `Age` header on a hit
  (`x-runcache-status` is the only reliable signal there), and Cloudflare stayed
  `cf-cache-status: DYNAMIC` throughout, confirming the `4b` table's prediction
  that `.md` is not a default-cached extension and needs an explicit Cache Rule.
  **What it does NOT buy, and the reason the default does not move:** a one-pass
  crawl is unaffected. Each URL is visited once, so every one is a first-time
  miss that boots WordPress anyway — 800 articles are still 800 boots. The
  lifetime pays off on re-crawls, on concurrent crawlers hitting the same URL
  (which is the realistic way to exhaust PHP-FPM workers, far more than the
  request total), and on ordinary repeat traffic. Against a single sweep the
  answer is rate limiting upstream, not a header. The cost is the documented one:
  nothing purges a `.md`, so an edit is invisible for up to the lifetime. This is
  a per-site trade, taken deliberately, and it stays out of the default —
  correctness of series, speed by explicit choice.
- **`acceptmarkdown.com` guides: reviewed, closed** (July 2026 — the
  *Generating the Markdown* and *Caching & CDN* pages, by Ben Word / Roots, which
  is also why they present `roots/post-content-to-markdown` as *the* WordPress
  approach). Recorded so the review is not redone from scratch. Outcome: three
  changes, all shipped in `0.30.0` and all with a decision above — the `.htaccess`
  406 bypass removed, `sysmda_front_matter_enabled`, `sysmda_markdown_prewarm` —
  plus two FAQ entries (behind a CDN, and the three-request test that proves no
  cache is mixing representations). Everything else was already covered, and in
  places exceeded: their "what to strip" list is satisfied *by construction*
  (rendering cleaned blocks rather than scraping the page means the chrome never
  enters the pipeline, which also makes their "scope the conversion to `<main>`"
  advice moot), and their "preserve what matters" list is satisfied item by item
  plus absolute-URL resolution, highlighter line reconstruction, `<dl>` and
  synced patterns. Their taxonomy of three approaches does not describe this
  plugin at all: it is neither an SSG, nor write-time dual rendering, nor an
  edge proxy re-fetching HTML, so two of the three tradeoffs they attribute to
  "runtime conversion" (per-request cost, and output drifting with a CSS change)
  do not apply. Deliberately NOT taken: their write-time "store both
  representations" model (the `Cache` helper already covers it without growing
  the DB) and every Nginx/Varnish/VCL/Worker snippet — the "do not ship
  host-specific config" rule from the `0.29.0` measurement stands.
- **Block-native Markdown engine: evaluated, not built** (August 2026 — a
  handoff document proposed replacing the generic HTML conversion with a
  pipeline rendering Markdown straight from `parse_blocks()`, keeping
  `render_block()` + League only as a fallback). Recorded so the evaluation is
  not redone from scratch. Outcome: **the premise did not survive measurement**,
  and what shipped instead was `0.38.0`'s delimiter hardening. What was found,
  against `league/html-to-markdown` 5.1.1 with this plugin's config:
  - **The library is already correct on most of what the proposal wanted to
    replace.** Nested lists at three levels, `<ol start>`, ordered-in-unordered,
    multi-paragraph list items, nested blockquotes, GFM tables with escaped
    pipes, `core/buttons` → a plain link, separators, and links with spaces or
    parentheses all convert correctly today. Nested lists in particular were
    singled out in the proposal as the biggest expected win; they were already
    right.
  - **The defects that are real were all one class — an unsized delimiter — and
    none of them is fixed by rendering blocks natively.** A native `core/code`
    renderer would fix the fence breakout for `core/code` only, leaving Code
    Block Pro (a third-party block), Classic content and ACF WYSIWYG broken;
    and the prose-fence case is `core/paragraph`, where a native renderer would
    need the identical escaping anyway. Overriding the library's converters
    fixes every source at once, which is why that is what shipped.
  - **Performance is not a motivator.** Measured on an 18 KB article: the whole
    conversion stage is **8.6 ms** and the DOM pass **1.1 ms**, against the
    ~1000–1200 ms `.md` TTFB already documented in the `0.29.0` measurement
    above. Under 1% of the response; the WordPress boot dominates, as it does
    everywhere else in this plugin.
  - **It would retire none of the five DOM passes.** Class exclusion, `<dl>`
    flattening, highlighter normalization and URL absolutization must all stay
    for the fallback path, so the engine is strictly additive — a second
    permanent pipeline, which is the proposal's own stated risk.
  - The one obstacle the proposal treated as decisive had already been removed:
    `sysmda_markdown_source_content`, `_rendered_html` and `_preamble` were
    classified **Advanced** in `0.37.0` precisely so a future engine could move
    them (`docs/filters.md`). That is not a reason to build it, only a reason it
    would not be blocked.
  **What would reopen it**: a census of real content showing a large share of
  the corpus inside blocks whose *meaning* — not merely layout — is lost through
  `render_block()`. Layout wrappers do not count: their children already convert
  correctly. The single genuinely block-aware idea worth keeping was
  `core/embed` → the canonical URL rather than the rendered oEmbed markup, and
  **that shipped in `0.43.0`** — as one DOM pass keyed on the `wp-block-embed`
  class, not as a block renderer, so it covers embed blocks from other plugins
  and already-resolved markup for free. Nothing of the engine proposal survives
  it.
- **Evaluate enriching/managing `/llms.txt` further**: beyond the current enriched
  mode, consider what else is worth adding (candidates TBD, see also the LLM
  signals idea above).
- **Server-side diagnostics** (parked, *future thought* — we will revisit):
  a read-only, in-process admin view of per-post servability, `.md` preview,
  size/token estimates, stripped/unconverted markup and unresolved internal
  links. Removed from the active plan in July 2026: `strip_tags()` cannot detect
  all conversion loss, `url_to_postid() === 0` does not prove a link is broken,
  and an in-process comparison cannot measure the public response through its
  cache/proxy layers. Do not promote it back to a plan without real demand and a
  deliberately small, read-only MVP on a separate admin page. The only shipped
  request-side telemetry remains the count-only `.md` hit counter above.

## Product decisions (durable)

- `sysmda_markdown_supported_post_types` defaults to **empty** → the plugin is
  **inactive** until at least one type is selected in the panel. `attachment` is
  always excluded. **CPTs are supported** (all public types are shown/validated).
  "Inactive" is now literal: `maybe_render_markdown()` returns immediately with no
  enabled type (it used to still 301-redirect `.md` URLs it would then 404), and
  `/llms.txt` stays silent as well (see below).
  **The public policy is applied to the SAVED SELECTION and nowhere else**
  (decided August 2026, `0.36.0`): the AdminSettings callback that feeds the
  option into this filter at priority 20 drops any slug whose type is not
  currently registered `public` (`PostSupport::type_is_public()`). Two sources,
  two treatments, and the seam between them is the point — `sanitize_post_types()`
  deliberately KEEPS a saved slug whose provider is temporarily inactive, so an
  afternoon of deactivation does not turn the endpoint off for its content, but
  a type re-registered as `public => false` (or replaced by an internal one of
  the same name) must not stay servable on the strength of a stale option; the
  slug survives and comes back by itself. Site code adding a non-public CPT
  **through the filter**, by contrast, is an explicit request, and widening what
  is served is this filter's documented job. So do NOT re-apply the check in
  `is_servable()` — a first attempt did, which silently overruled the filter and
  contradicted its own docblock (caught in review).
- **Password-protected content has NO Markdown representation, ever** (decided
  July 2026, closes M1 of the 0.26.3 review): the test is
  `'' === $post->post_password`, deliberately NOT `post_password_required()`.
  That function answers "does this visitor still have to supply it?", so a
  valid `wp-postpass_*` cookie made it false and a reader who had entered the
  password once also unlocked the `.md`, the `rel="alternate"` link, the
  shortcode and the dynamic tag. Having the password is irrelevant: the rule is
  about the content, not the visitor. This also makes `is_servable()` agree with
  `/llms.txt`, which always filtered on `has_password => false`. The old check
  was invisible to the tests because the stub for `post_password_required()`
  returned `! empty( $post->post_password )` — it encoded the assumption the
  code was making instead of WordPress's actual behaviour; it now models the
  cookie, which is what makes the regression test bite.
- **`/llms.txt` invalidation covers the site identity, and deliberately NOT the
  post format** (decided July 2026, closes M2 of the same review): the cached
  index is versioned on the site name and tagline as well, because they are its
  heading and subtitle and are edited in Settings → General, which never fires
  `save_post`. A post's **format** is deliberately left out even though it does
  change which posts are servable: it is set from the editor, where saving
  already clears the cache, and post formats are not part of how this site
  classifies content (see the decision below). Paying a `set_object_terms` hook
  on every term write to close a gap only reachable through programmatic term
  updates is not worth it. The residual risk is bounded by the TTL.
- **Non-standard post formats are never served** (decided July 2026):
  `PostSupport::EXCLUDED_POST_FORMATS` covers all nine (aside, audio, chat,
  gallery, image, link, quote, status, video). Rationale: those are short,
  usually untitled snippets with no editorial body worth a document
  representation; the standard format — the *absence* of a format — is
  unaffected, which is the overwhelming majority of content. The rule lives in
  `is_servable()`, so it applies everywhere at once: `.md`, negotiation,
  `rel="alternate"`, `/llms.txt`, the shortcode and the dynamic tag. Escape hatch:
  `sysmda_markdown_excluded_post_formats` (empty array = serve them all again).
  Corollary for `/llms.txt`: the listing query filters its results through
  `is_servable()` (with `update_post_term_cache => true` so the formats are primed
  in one query, not one per post) — the index must never advertise a `.md` URL
  that 404s.
- **A post rendered by an unsupported page builder has NO Markdown
  representation** (decided August 2026, Phase 1 of `docs/page-builders-plan.md`):
  `BuilderDetector::is_unsupported()` is the last built-in rule in
  `is_servable()`, so the `.md` 404s, no `rel="alternate"` link or `Link:`
  header is advertised, the post leaves `/llms.txt`, and the shortcodes and the
  dynamic tag render nothing — all by construction, from one predicate.
  `NEVER_SUPPORTED` is Divi, WPBakery, Oxygen, Beaver Builder and Breakdance;
  `AWAITING_ADAPTER` is Elementor (Bricks left it in `0.46.0`, moved out by its
  adapter shipping — see the decision below), and that list is how the work is
  phased — an adapter shipping moves its builder out of it, with no other edit
  and no window in which an empty or wrong `.md` is published.
  Escape hatch: `sysmda_markdown_unsupported_builders` (Stable; empty array =
  veto off). Rationale: the meta-based builders leave `post_content` empty, so
  the `.md` was front matter plus a bare `# Title`; Divi and WPBakery fill it
  with their own layout shortcodes, so the `.md` was layout chrome converted as
  prose. The second is the worse of the two — an empty document is useless, a
  wrong one actively misleads the audience this plugin exists for, and nothing
  about it looks broken from the admin side.
  **Measured on staging at 0.45.0, there is a third and worse case than either**
  (`docs/staging-acceptance.md`): a Bricks page whose `post_content` still held
  the prose from before it was rebuilt served a `.md` of six well-formed
  paragraphs, while the page itself rendered a single Bricks heading — the text
  appearing nowhere in the rendered page except `og:description`, and `/llms.txt`
  advertising it with the same text. Not empty, not chrome: **confidently wrong**.
  A builder does not have to leave `post_content` empty for the old behaviour to
  be harmful; it only has to leave it stale.
  Three rules carry the design, and each one is easy to get backwards:
  - **Per post, never per post type and never per site.** Sites routinely build
    their pages with a builder while the articles stay in the ordinary editor;
    mixed types are the normal case. Activating Bricks on a site of 150
    Gutenberg posts must change nothing at all, and it does not.
  - **The render mode decides, not the presence of builder data.** Bricks and
    Elementor both document a per-post switch back to the WordPress editor that
    leaves the builder tree stored while the front end serves `post_content`.
    Keying on the blob would deny a Markdown representation to a post that
    renders perfectly ordinary content — the same class of error as the old
    `post_password_required()` check, where the question asked was not the
    question that mattered. Hence exact-value matches on the mode meta, which is
    also what keeps `_wpb_vc_js_status` from claiming every post that ever had
    the WPBakery editor opened on it: it stores the string `false`, which is
    perfectly truthy. Oxygen and Breakdance ship no documented mode flag, so
    their payload is the closest available proxy — narrowly, and only for them.
  - **The veto applies whether the builder plugin is active or not**, a
    deliberate asymmetry with the adapters that will follow. An adapter needs the
    vendor present (with no renderer there is nothing to render, and
    `post_content` is then the correct answer); a veto is the opposite, because
    with Divi deactivated its `[et_pb_*]` shortcodes stay in `post_content`
    unregistered and would be published as literal text — the worst outcome of
    all. So detection reads meta and never calls a vendor API.
  And the detection **never sniffs `post_content`**: an article documenting Divi
  and quoting `[et_pb_section]` in a code sample would otherwise be made
  unservable by its own example, which is the defect `CodeRegions` exists to
  prevent, one level up.
  **Corollary for every batch caller: prime the meta cache.** `detect()` costs
  one query per post at most, because the first `get_post_meta()` loads the
  post's whole meta row set — but *per post*, so a loop over N posts with an
  unprimed cache is N queries. `LlmsTxtController::servable_posts()` had
  `update_post_meta_cache => $enriched`, from when the enriched descriptions
  were the only meta reader, and the veto silently inherited `false` on the
  basic path: up to 2500 extra queries per content type on a cold index. It now
  primes unconditionally, for the same reason it already primed the term cache
  that the post-format check reads — two rules, one shape, one line each. The
  regression has **no symptom** (the index is byte-identical either way, only
  the query count moves), so the priming is asserted in the suite. Caught by
  Codex on PR #97.
- **The panel's per-type breakdown informs and decides nothing** (decided with
  the above, Phase 1b): `BuilderCensus` shows what each content type's published
  posts are actually built with — *12 Bricks, 3 Gutenberg* — with a warning
  naming any builder that costs the Markdown version. A breakdown rather than
  one label, because mixed types are the normal case and a single label would be
  wrong for both halves. Same line as "never auto-detect which taxonomies to
  emit": it informs, the owner decides. Three constraints, all load-bearing:
  computed on the settings screen only and never on the front end; cached in a
  transient whose option name starts with `_transient_`, which is what keeps it
  out of `AdminSettings::maybe_bump_cache_salt()` (a census must not invalidate
  every cached body on the site — same rule as the hit-counter buckets, and
  asserted in the suite rather than assumed); and one query whose `CASE` chain is
  built from `BuilderDetector::RENDER_MODE_META` in the same order the detector
  tests it, so the census and the veto cannot disagree. **Revisions are excluded
  twice over** (`post_status = 'publish'` and the post-type list): verified on
  staging, a Bricks revision carries `_bricks_page_content_2` but *not*
  `_bricks_editor_mode`, so a census counting payload rows reported the one
  Bricks page three times. It describes the built-in veto list and deliberately
  does not evaluate `sysmda_markdown_unsupported_builders`, which is per post and
  has no post to be given here.
- **The Bricks adapter renders through the vendor and reads the tree only to
  decide** (decided August 2026, Phase 2 of `docs/page-builders-plan.md`,
  `0.46.0`): `BricksAdapter` calls `\Bricks\Frontend::render_data()` rather
  than mapping Bricks element types to semantic HTML — the rejected
  alternative is the "block-native Markdown engine" already evaluated and
  rejected elsewhere in this file, with the surface multiplied: an unmapped
  element type would disappear silently, and dynamic data would go
  unresolved. Same shape as `candidate_taxonomies()`: the stored tree answers
  `handles()`, `fingerprint()` and `source_text()`, never `render()`'s job.
  Four points in the design are load-bearing and were each verified live
  rather than assumed, because each one has exactly the "looks fine, is
  silently wrong" shape "A guard is not done until it has been seen to fire"
  warns about:
  - **The lazy-load fix needs a real WordPress attachment to even be
    testable.** Bricks only swaps an image's `src` for a placeholder inside
    `wp_get_attachment_image_attributes`, a filter that fires solely for a
    real attachment — a raw external image reference never reaches it, so a
    naive reproduction attempt (a synthetic element pointing at a bare URL)
    shows no bug at all and would have shipped a guard that had never been
    seen to fire. Reproduced and fixed against attachment ID 13 on
    `sma-bricks-instawp-co`: default render → `src="data:image/svg+xml,…"`
    with the real URL in `data-src`; with
    `\Bricks\Database::$page_settings['disableLazyLoad'] = true` → the real
    `src`/`srcset`. `render()` brackets the call with a save/restore of that
    flag, never a bare assignment, for the same reason `build_markdown()`
    already save/restores `$GLOBALS['post']`: a bare assignment would leave
    every *other* Bricks render sharing the PHP process — a preview, an
    admin-ajax call — with lazy loading silently disabled afterwards.
  - **`md-exclude` needed no code**, only verification: Bricks emits an
    element's custom CSS classes (`settings._cssClasses`) verbatim on the
    rendered wrapper, so `ContentRenderer`'s existing class-removal pass
    already reaches it. Confirmed live rather than assumed, alongside the
    `brxe-{name}` class Bricks emits for every element (verified against
    `\Bricks\Elements::$elements`, not guessed) — which is what
    `sysmda_markdown_excluded_builder_elements`'s Bricks defaults
    (`brxe-form`, `brxe-nav-menu`, `brxe-nav-nested`, `brxe-post-sharing`,
    `brxe-post-toc`, `brxe-breadcrumbs`) key on. Additive to whatever else
    contributes to the list, per the `0.40.0` rule — never a replacement.
  - **The `description`/`llms.txt` fallback never reads a Bricks post's
    `post_content`, even when it finds nothing better.** This is the same
    lesson the "confidently wrong" measurement above already taught for the
    body — a Bricks post's `post_content` can hold stale prose left over from
    before the page was rebuilt, and a `.md` is not the only place that would
    surface: the description fallback derives text from stored data the same
    way, and reads `post_content` for every other post type. `source_text()`
    (a cheap, unrendered read of the tree's `settings.text` values, each
    wrapped in a span carrying the element's own class so the same exclusion
    pass applies) is the one honest source there — empty when it finds
    nothing, never stale.
  - **Suppressing foreign `the_content` filters around Bricks' Post Content
    element is implemented, but as a maintainer-reversible default, not a
    settled answer** (closes `docs/page-builders-plan.md` §10's open
    question): `render_block()` is used instead of `the_content()` everywhere
    else in this pipeline specifically to keep injected related/CTA content
    out (see "Technical notes" §4), and Bricks' `post-content` element calls
    the full chain internally, reintroducing exactly that. The new
    `sysmda_markdown_builder_suppress_content_filters` filter (Advanced,
    default `true`) is the reversal switch. The snapshot-and-restore has one
    sharp edge, caught by testing the exact sequence rather than reasoning
    about it: `$wp_filter['the_content']` is a `WP_Hook` object, so
    `$previous = $wp_filter['the_content']` copies the object handle, not its
    state — `remove_all_filters()` then empties the "snapshot" too, and the
    restore silently does nothing. The fix is `clone`, not a bare read.
  Cache validator (§5 of the plan): `MetadataBuilder::dependencies_fingerprint()`
  became an **instance method** (it needs `ContentRenderer`'s adapter list,
  via the new `builder_dependency_parts()` seam) and folds in the render mode,
  a hash of the stored tree, and any referenced `template` element's own
  post's modification date — verified live that `settings.template` on a
  `template`-named element holds that referenced post's ID. Deliberately
  **not** covering a Bricks "component" (`cid`) reference's own definition:
  no `bricks_component` post type and no populated components option were
  found on the reconnaissance install to resolve it against, so a
  *reassigned* `cid` still invalidates through the tree hash while a
  component's own definition changing elsewhere does not — an accepted, named
  gap rather than a guess. **Cost measured, not assumed** (the plan's explicit
  requirement, since this runs on every request, `304`s included):
  `json_encode()` + `md5()` costs ~0.09 ms on a representative 60-element tree
  and ~0.36 ms on a deliberately large 300-element one — negligible next to
  the ~1000–1200 ms `.md` TTFB already measured for the `0.29.0` cache work.
  `sysmda_markdown_prewarm` stays off for Bricks posts as for everything else:
  `\Bricks\Conditions::check()` was read directly and confirmed to have no
  condition type keyed on `is_singular()`/archive/query-var state — narrowing
  the residual risk flagged in the plan's §7.5 to just the `current_url`
  condition type, which reads the parsed request path and genuinely does
  differ between the `.md` suffix route and the negotiated permalink route
  (documentation only; no code change, and no broader `is_singular()`-style
  risk turned out to exist to fix).
- **`/llms.txt` stays silent until a content type is enabled** (decided July
  2026): the option remains **on by default**, but with nothing to index the
  endpoint answered a site name plus a tagline and took the URL over from anything
  else that might serve it, while the rest of the plugin was still inactive. This
  is NOT auto-yielding (see the decision below): the plugin never reacts to
  another handler, it simply has nothing to say yet.
- **`.htaccess`: the lock spans the whole read-modify-write, and the write is
  in place** (decided July 2026, amended after review — do not "improve" it into
  an atomic rename again): `LiteSpeedCompat::update()` opens with `c+`, takes
  `flock(LOCK_EX)`, and only then reads, computes and rewrites (`ftruncate` +
  `fwrite`), keeping a one-time `.htaccess.sysmda-bak`. Two reasons, both
  learned the hard way:
  - **The lock must cover the read.** `sync()` runs on every settings-page load
    and `.htaccess` is a *shared* file — core rewrites it on a permalink save,
    cache/security plugins write to it too. Reading outside the lock lets another
    writer land between our read and our write, and our write then silently
    reverts their block.
  - **In place, not `rename`.** A temp-file rename is atomic for readers but
    replaces the inode, so a concurrent writer holding `flock` on the *old* inode
    — exactly what core's `insert_with_markers()` does — keeps writing to an
    orphaned file and loses its changes with no error. Interoperating with core's
    locking discipline matters more than the brief window in which a lock-less
    reader (Apache) could see a partially written file; core lives with the same
    window.
  - **A failed write is rolled back.** In place means `ftruncate` empties the
    live file before the new contents land, so a write that fails or falls
    short would leave a broken `.htaccess` (dead permalinks, or a 500 from a
    rule cut in two). `overwrite()` compares the byte count `fwrite()` returns
    with the payload — a short write is NOT a `false` return — and on any
    failure rewrites the previous contents before the lock is released, **empty
    contents included**: a short write leaves half a directive behind even on a
    file that was just created, so "empty is already the prior state" is only
    true when nothing was written. Do not reduce that back to a bare
    `false !== fwrite(...)`, and do not re-add a guard that skips the rollback.
    **Seen to fire, August 2026 — recorded so it is not re-derived.** This is a
    guard whose silence is the expected output, so the "a guard is not done
    until it has been seen to fire" rule in "Code conventions" applies to it
    directly, and until now it had only been reasoned about. Exercised against a
    real `ENOSPC` short write rather than a mock: a 1 MiB tmpfs filled to
    capacity, `.htaccess` padded to an exact 4 KiB page multiple so that
    appending the 293-byte block necessarily needs one more page, and `update()`
    invoked by reflection with the real `prepend_rules` transform. The kernel
    produced the short write the docblock names (`fwrite(): Write of 293 bytes
    failed with errno=28`). Two cases run on that fixture: with free space the
    block lands above `# BEGIN WordPress` and the other rules survive; on a full
    filesystem `update()` returns `false` and `.htaccess` comes back
    byte-identical (sha256). The negative control is the part that matters —
    neutralising only the `self::overwrite( $handle, $contents )` line flipped
    three assertions to FAIL and left a file ending mid-directive
    (`…# a rule from another plugin\n# a rule f`) with the LiteSpeed block
    written in full above it: a syntactically broken `.htaccess`, i.e. a 500.
    The **empty-preimage** branch needs a synthetic payload, and why is worth
    recording (caught by Codex on PR #101, which spotted that the reported
    numbers could not come from the stated procedure). tmpfs allocates whole
    4 KiB pages, so a write gets a page or gets nothing, and `prepend_rules` on
    empty contents returns **292 bytes** — under one page, therefore
    all-or-nothing. Measured both ways: with no free page it writes 0 bytes onto
    an already-empty file, so the rollback is a no-op there; with one free page
    it simply succeeds. The branch was exercised instead with a ~21 KB payload
    over two free pages, where removing the rollback leaves 8192 bytes of
    half-written config and keeping it truncates to zero. So that branch is
    genuinely defensive rather than reachable by this plugin's own block on a
    page-granular filesystem — which is a reason to keep it (a larger payload, a
    quota, or finer-grained accounting all reach it), not a reason to trim it.
    **It cannot join `tests/run-tests.php`**: mounting a tmpfs needs root and CI
    does not have it, which is why this is a recorded measurement and not a test.
    Re-running it takes a tmpfs, a page-aligned fixture and a `ReflectionMethod`
    on the private `update()` — no harness is kept in the repository.
  `flock()` failing is deliberately non-fatal (as in core): on a filesystem
  without working locks, bailing out would disable the feature precisely on the
  hosts that asked for it. `WP_Filesystem` is deliberately NOT used: it may demand
  FTP/SSH credentials the user has not supplied, which would make the sync fail
  silently on exactly the hosts that need it (the PHPCS ignores carry that
  justification inline).
- **ACF** and **GenerateBlocks** panel sections: shown only when the respective
  plugin is active. ACF options are `register_setting`-ed **only when ACF is
  active**, so saving with ACF off does not wipe the field names (the Settings
  API writes every registered option of the group).
- **GenerateBlocks Dynamic Tag**: auto-registered when GB 2.x is present. For
  non-servable posts the callback returns '' → GB's "required to render" option
  hides the element (no broken links).
- **`/llms.txt` conflict detection**: only **local, stable** signals (active SEO
  plugins via constant/class + physical file in the root). No reading of third-
  party internal options, no loopback HTTP checks (removed: unreliable behind a
  WAF). It is an informational notice only; the user decides.
- **NO auto-yield of `/llms.txt`** (decided, do not propose again): the plugin
  NEVER disables itself, not even as an option. Enabling/disabling is always and
  only a manual user choice from the panel; if other handlers are active
  underneath, that is the user's responsibility. The conflict notice stays purely
  informational.
- **Custom taxonomies are opt-in and alphabetically ordered** (decided July
  2026): enabling them changes the front-matter payload of every post on an
  upgraded site, so it must be the user's explicit choice — default off, and off
  means byte-identical output *and* cache validator. Ordering is `SORT_STRING`
  (byte order) rather than locale collation, so the output never depends on the
  server locale and the golden tests stay stable across environments; the
  trade-off (accented names sort last) is accepted and documented. The block is
  appended **after `description`**, honouring the append-only rule in
  `docs/output-format.md`.
- **NEVER auto-detect which taxonomies to emit** (decided July 2026, amends the
  decision above after a real defect in 0.24.0 — do not propose auto-detection
  again, in any form): the emitted list is the site owner's **explicit
  per-taxonomy selection** in the panel, empty by default, exactly like
  `sysmda_markdown_supported_post_types`. Rationale: the registry describes how
  WordPress *routes* a taxonomy, not whether its terms belong in a
  machine-readable representation. `public => true, publicly_queryable => false`
  is the usual shape of an editorial-internal classification with no term
  archive, and the 0.24.x `public`-only check published it; conversely
  `publicly_queryable => false` does not prove secrecy (a theme may still print
  the terms), and `public => true` does not prove usefulness (plugin plumbing
  attached to public post types). Detection therefore stays **advisory**: it
  builds the candidate list, labels the not-publicly-queryable rows and seeds the
  one-time migration. Corollary, equally binding: **no taxonomy a plugin
  registers later may start publishing itself** — new candidates always appear
  unticked. An internal taxonomy is still selectable on purpose (panel row or
  filter): the plugin informs, the owner decides.
- Front matter **description**: Rank Math (`rank_math_description`) → discarded
  only when it contains an unresolved `%variable%` placeholder → excerpt fallback
  → trimmed text (~200 chars). Front matter includes `featured_image`
  (+ `featured_image_alt`).
  **The last fallback reads the post content, not the rendered body, and that
  shortcut is deliberate — so it has to re-apply the exclusion rules itself**
  (`0.38.1`): the same method builds every entry of `/llms.txt`, where rendering
  each listed post would be prohibitive, which is why it must stay cheap. But
  the exclusions live in the render pipeline, so a `md-exclude` section the body
  refuses to publish was summarised straight into the front matter of any post
  with no SEO description and no excerpt. It now runs through
  `ContentRenderer::strip_excluded_content()` first.
  **That pass has to apply BOTH exclusion rules, not just the class one**
  (`0.38.2`, found in review — the `0.38.1` version applied only the DOM class
  pass and left half the gap open). The reasoning that justified skipping the
  block-level rule was that a block excluded by name is dynamic and carries no
  text in the source: true of the names the plugin ships, **false in general**,
  because "Excluded blocks" is a settings-page field and a site can name a
  *static* block whose text sits right there in the saved markup. The same hole
  swallowed blocks excluded through `attrs.className` when the saved inner HTML
  does not repeat the class attribute — the DOM pass has nothing to match on.
  So block content is run through `BlockCleaner` and re-serialized first, and
  only then through the class pass (which still returns its input untouched when
  no class matched, so classic content and unexcluded markup are never
  round-tripped through the DOM).
  **No cheap substring guard in front of the block pass**: any guard would be
  evaluated against *this* post's markup, and a synced pattern keeps its content
  in another post, so the guard would go blind exactly where `BlockCleaner`
  follows the reference. One `parse_blocks()` per fallback description is the
  accepted price, and it is only paid when a post has neither an SEO description
  nor an excerpt.
  The rule generalizes: **anything deriving text from `post_content` instead of
  the rendered body owes the same pass — all of it.** What the body excludes is
  excluded everywhere, front matter and `/llms.txt` included. When in doubt,
  reuse the cleaner rather than reason about which exclusions "cannot matter":
  that reasoning is what failed here.
- **The `ETag` is weak (`W/"…"`) and stays weak** (decided July 2026, `0.28.0`,
  outcome of the ETag/cache review — see `docs/cache-infrastructure-notes.md`):
  the validator is computed from metadata (modification date, plugin version,
  settings salt, the two fingerprints), never from the bytes — computing it from
  the bytes would mean generating the body before deciding whether to send it,
  which is the entire point of the `304`. A strong tag promises byte-for-byte
  identity (RFC 9110 §8.8.1) and this one cannot: `sysmda_markdown_cache_dependencies`
  exists precisely because dynamic blocks, shortcodes and site filters can move
  the body on their own, and a validator with a documented escape hatch is by
  definition not byte-exact. Nothing is given up — strong comparison is only
  required by `If-Match` and `If-Range`, neither of which this endpoint
  implements, while `If-None-Match` always uses weak comparison. Do NOT "restore"
  a strong tag: it would be a promise the plugin cannot keep. Corollary in
  `etag_matches()`: compare with the `W/` flag ignored **on both sides**, and
  ignore Apache's `-gzip`/`-br` suffix as well.
- **The URLs the plugin owns say `public, max-age=0, must-revalidate`**
  (decided July 2026, `0.29.0` — **replaces** the previous "NO freshness
  `Cache-Control` on the dedicated `.md` URLs", which was withdrawn on
  evidence). Applies to the `.md` endpoint and `/llms.txt`; the negotiated
  responses keep `no-store`, see the next decision. The old rule assumed that
  sending nothing meant "always revalidate". It is wrong twice over:
  - **"No header" is not "no freshness".** RFC 9111 §4.2.2 lets a cache invent a
    lifetime when a response carries none — typically a fraction of the age
    since `Last-Modified` (weeks, on an old post), and a flat 120 s in Varnish's
    stock config. The old rule's own goal ("never serve an outdated version
    after an edit") was therefore not enforced by it.
  - **The plugin was not sending "nothing" at all.** Measured live on
    webdietrolequinte.it: every `.md` went out with
    `Cache-Control: no-cache, must-revalidate, max-age=0, no-store, private`
    and `Expires: Wed, 11 Jan 1984`, to anonymous clients too. That is
    `wp_get_nocache_headers()`: this route resolves as an error inside
    WordPress, so `WP::send_headers()` sends it long before the plugin runs, and
    the plugin — by never touching the header — simply inherited it. `no-store`
    forbids keeping a copy at all, so no client ever revalidated, the whole
    `ETag`/`304` path was dead weight, and every single hit paid for a full
    render. **A policy of omission cannot be implemented by omission**: the
    header has to be set explicitly, or WordPress's wins.
  Why this exact value: `max-age=0` makes the response stale on arrival and
  `must-revalidate` makes that binding, so a cache may store the body but must
  revalidate before serving it — a `.md` cannot outlive the article behind it.
  This matters more than it looks, because **no page cache purges a `.md`**:
  cache plugins purge the permalink on save and have no idea `permalink.md`
  exists, so correctness cannot rest on purging and has to come from
  revalidation. `public` states what the `.md` **is defined to be** — the
  anonymous representation of the post — and not something that holds by
  construction: see the decision below, which corrects the claim this paragraph
  used to make. It is enforced by only sending `public` to anonymous requests.
  Freshness is still not imposed, but it
  is now reachable: `sysmda_cache_control` may return an `s-maxage`, and whoever
  does that accepts the staleness the missing purge implies. Returning `''`
  removes the header entirely (WordPress's included).
  Do not go back to sending nothing, and do not "restore" `no-store` here: both
  were measured and both are worse.
- **The `.md` is the ANONYMOUS representation, and that is a definition the
  plugin enforces — not a property it gets for free** (decided August 2026,
  `0.36.0`, correcting a claim this guide made until then). The old wording
  said the representation "never varies by visitor" because the body is built
  from cleaned blocks rather than `the_content`. That is only true of
  `the_content` filters. The body is assembled with `render_block()` and
  `do_shortcode()`, and every stage passes through site filters, so a dynamic
  block or shortcode reading the current user, a cookie, a cart or a
  membership state renders **in the caller's context**. Two consequences
  followed, and both were real: an authenticated visitor could be the first to
  populate the per-post body cache — keyed by post ID alone, shared by
  everyone, for up to a day — and the `.md` route additionally invited shared
  intermediaries to store it. Enforced now in two places:
  - `MarkdownController::representation_is_shared()` (= `! is_user_logged_in()`)
    gates **three** things, and all three have to move together or the rule
    contradicts itself. An authenticated request (a) neither reads nor writes
    the shared body cache; (b) is answered `private, no-store, must-revalidate`
    — **deliberately not filterable**, so `sysmda_cache_control` cannot make a
    possibly personalized response publicly cacheable by accident; and (c) is
    never answered `304` and carries **no `ETag` or `Last-Modified`**. That
    third one was missed first time round and caught in review: rebuilding the
    body for a visitor and then answering that same visitor `304` on a
    validator describing the *shared* body hands them exactly what the rebuild
    avoided — their browser reuses a copy built for everyone, off an
    `If-None-Match` kept from an earlier anonymous fetch. The precondition
    lives inside `handle_conditional()` rather than at its call site, so no
    caller can forget it, and the validators are suppressed for the same reason
    the ETag is weak: do not send a claim this plugin cannot back. Anonymous
    traffic, which is the entire audience for this endpoint, is untouched and
    keeps the full shared-cache behaviour.
    `/llms.txt` uses the same definition for its **body cache** and cache-control
    policy: logged-in requests neither read nor populate the anonymous index
    entry. Its conditional path is intentionally different because its strong
    ETag hashes the rebuilt bytes themselves; a matching tag therefore describes
    that visitor's actual body rather than the anonymous cache.
  - `sysmda_post_is_servable` is the per-post **veto**, honoured by every
    consumer through `PostSupport::is_servable()`. It exists because the
    built-in checks know WordPress's own notion of access (status, the core
    password field) and nothing else: a membership or paywall plugin protects
    a published post from a later `template_redirect` callback or a
    `the_content` filter, and this plugin runs at `template_redirect` priority
    `0` and exits, so neither ever gets a say. Veto only — consulted just when
    the built-in rules already said yes, so it can never publish a draft or
    protected content.
  What this does **not** claim: `is_user_logged_in()` is the tractable half of
  visitor variance, not all of it. Anonymous output can still vary by cookie
  (cart, geolocation, A/B assignment), and no plugin can detect that. Such a
  site declares it through `sysmda_markdown_cache_dependencies` or vetoes the
  post. Equally, do **not** present that filter as an answer to personalization
  in general: it contributes validator inputs, and a validator does not
  partition a shared cache or authorize anybody. Leaving the hook at priority
  `0` is deliberate (moving it would break the route on sites where something
  else 404s first); the veto filter is how other plugins participate.
- **Negotiated Markdown and `406` responses are always no-cache** (decided,
  binding — outcome of the July 2026 LiteSpeed/Vary diagnosis on two production
  hosts): they share their URL with the HTML page, and honouring `Vary: Accept`
  is a **per-host property** — the default LiteSpeed cache keys by URL only and
  ignores the standard `Vary` (verified live with a standalone test outside WP;
  one host honoured it, one did not), and CDNs may ignore it too. The plugin
  must NEVER rely on `Vary` for safety. Therefore these responses always send
  the standard `Cache-Control: no-cache, no-store, must-revalidate, private`
  (server-agnostic: protects against any URL-keyed cache even without LSCWP in
  the middle) **in addition to** the LiteSpeed-specific signals
  (`X-LiteSpeed-Cache-Control: no-cache`, `DONOTCACHEPAGE`, LSCWP action).
  `Vary: Accept` keeps being emitted in append mode (never overwrite: sites
  already vary on `User-Agent` for mobile/desktop caches), still correct for
  browsers/CDNs that do honour it.
  **Know exactly what `no-store` buys, and do not oversell it** (clarified July
  2026 after the `0.30.0` FAQ claimed "no CDN configuration required" and a
  review correctly called it out): it is one-directional. It stops the Markdown
  variant from being *stored* and later handed to a browser — the harmful
  direction — and that protection is genuinely server-agnostic. It does nothing
  about the reverse: when a URL-keyed cache already holds the HTML for the
  permalink, the Markdown request is answered at the edge and PHP never runs, so
  no header the plugin sends can matter and the client gets HTML. Making
  negotiation *work* on such a host needs a cache bypass (the opt-in `.htaccess`
  rules on LiteSpeed, a cache rule elsewhere) or a cache that honours `Vary`.
  Safety is unconditional; functioning negotiation on a shared URL is not. Any
  user-facing text about caches must keep the two apart, and the `.md` URL is the
  answer that never depends on the host.
- **Purge the LiteSpeed cache on plugin activation and deactivation** (decided):
  entries cached before activation carry no `Vary` and produce ghost behaviour
  that is very hard to diagnose. Purge-all via the LSCWP API
  (`litespeed_purge_all`, no-op when LSCWP is absent).
- **NO Vary self-test diagnostic** (decided, do not propose again): with the
  no-cache invariant above, whether the host honours `Vary` is irrelevant to
  safety; the test would be informational only and would depend on loopback
  HTTP requests, already rejected as unreliable behind WAF/proxies (same
  reason they were removed from the conflict detector).
- **The `.htaccess` block bypasses the page cache on Markdown negotiation and on
  nothing else** (decided July 2026, `0.30.0` — prompted by the "never key on a
  raw `Accept`" argument in `acceptmarkdown.com/guides/caching-cdn`): the second
  rule, which bypassed the cache when `Accept` allowed neither HTML nor a
  wildcard so PHP could answer `406`, is **removed. Do not add it back.**
  `RewriteRule ^` matches every URL on the site, so any request carrying an
  arbitrary media type — `Accept: application/json`, or a fresh random one per
  request — skipped the page cache site-wide and paid a full WordPress boot.
  That is exactly the cache-busting vector the guide describes, shipped as a
  feature, and it was opt-in but enabled precisely on the hosts that need the
  page cache most. What it bought was a `406` for clients that
  `should_reject_unacceptable()` already documents as non-existent in practice
  (browsers, crawlers and agents always send `text/html` or a wildcard). The
  `406` behaviour itself is unchanged and still answered on every request that
  reaches PHP — `.md` URLs, cache misses, logged-in traffic; only the bypass
  that made it reachable *through an already-cached page* is gone. Narrowing the
  rule by URL instead was rejected: `.htaccess` cannot know the permalinks of
  the enabled post types, and the plugin ships no rewrite rules by design.
- **The front matter is emitted by default, and the opt-out is a filter**
  (decided July 2026, `0.30.0`): `sysmda_front_matter_enabled` (default `true`)
  suppresses the whole block, starting the document at `# Title`. It exists
  because a real convention argues the other way —
  `acceptmarkdown.com/guides/generating-markdown` lists YAML front matter under
  "what to *not* generate", as build-time input that is noise to agents — and a
  site answering to that convention should not have to post-process
  `sysmda_markdown_output` by hand. The default does **not** move: `url`,
  `date_modified` and `author` are provenance the body cannot carry, which is
  the whole point of a machine-readable representation, and that guide's own
  `.md` pages replace the block with a prose attribution footer rather than
  dropping the information. Corollary in `assemble_document()`: the blank line
  after the block belongs to the block, so suppressing it must not leave the
  document starting with an empty line (golden test).
- **Cache pre-warming is opt-in and off by default** (decided July 2026,
  `0.30.0`): `sysmda_markdown_prewarm` schedules a WP-Cron rebuild ~30 s after
  `save_post` (`PREWARM_DELAY`, also a debounce; the delay exists because the
  block editor writes terms and meta in separate REST calls and ACF saves on its
  own hook, so an immediate rebuild would cache a document missing them). Off by
  default for one reason: **cron is not a faithful stand-in for a front-end
  request.** `build_markdown()` installs the post as the loop, so anything
  reading the post is fine, but there is no main query — a dynamic block or
  shortcode inspecting `is_singular()` or the queried object can render
  differently there, and that difference is what would get cached. The payoff is
  also modest: the measured `.md` TTFB is dominated by the WordPress boot, not
  by the conversion, so pre-warming removes the cold start for the first reader
  after an edit and nothing more. Doing it inline on `save_post` instead was
  rejected: same missing request context, plus it slows every save. Queued
  events are dropped on deactivation with `wp_unschedule_hook()` — they carry a
  post-ID argument, which `wp_clear_scheduled_hook()` would not match.
- **NO automatic/configurable front-end Markdown button** (decided July 2026,
  `0.34.0` — shipped in
  `0.31.0`, reshaped twice, removed three versions later; do not propose it again
  without a concrete request): a dropdown was the wrong answer to a real problem.
  It broke the layout on mobile, added a stylesheet and a script to the front end
  for a control most readers never use, and each round of feedback bought another
  round of CSS — auto-insert removed in `0.32.0`, the cascade fixed, then twelve
  custom properties and a specificity fight with the theme in `0.33.0`. A plugin
  whose value is a clean machine-readable representation should not be shipping a
  presentational widget it cannot test against an unknown theme. The `.md` stays
  discoverable through the HTML and HTTP `rel="alternate"`, `/llms.txt`,
  negotiation and `[sysmda_md_url]`; anything visual is the theme's job.
  `MarkdownButton.php`,
  `assets/md-button.{css,js}`, the panel tab, the five filters and both options
  are gone; the options stay in `uninstall.php` as legacy keys, and
  `ShortcodeCleaner::ALWAYS_EXCLUDED` keeps stripping `[sysmda_md_button]` so a
  tag left in old content does not surface as literal text in the `.md`.
  **Narrowed in `0.40.0`, deliberately:** that stripping now stops at `<pre>`
  and `<code>`, like every other exclusion. The rule above is about a *leftover*
  — a bare tag sitting in an old paragraph — and that is unchanged. A tag inside
  a code span is an author documenting the shortcode (this plugin's own settings
  page presents both tags exactly that way), and removing it would gut an
  article about this plugin for precisely the reason it used to gut one about
  Contact Form 7. What the rule protects is untouched either way: a masked
  region is never expanded, so the control never *renders* into the Markdown.
  **Narrow exception, by concrete maintainer request (`0.39.0`):**
  `[sysmda_md_actions]` is an explicit shortcode with exactly three fixed
  actions (copy the document, open it in a new tab, download it). It does not
  resurrect the old tag, automatic insertion, panel tab, options, filters or
  twelve-property styling API. Its assets load only on pages where the
  shortcode actually renders; the minimal white/bordered CSS has namespaced
  classes for later theme work, and the menu escapes layout containers by
  moving to `document.body` and using viewport-aware placement — anchored to the
  split button as a whole, with the viewport fallbacks reserved for the cases
  that need them (`0.45.1`). That scope and
  positioning are the answer to the two concrete failures above, not a reversal
  of them. If menu opening/positioning moves toward a CSS-only implementation in
  future, evaluate declarative popovers + CSS anchor positioning; copying to the
  clipboard will still require JavaScript.
- **Downloading the `.md` is client-side only, and the link stays a bare
  anchor** (decided July 2026, `0.35.0` — read together with the button decision
  above, which it deliberately does not reopen): `[sysmda_md_download]` renders
  `<a class="sysmda-md-download" href="…/post.md" download="post.md">` and
  nothing else. Three parts, each load-bearing:
  - **A second shortcode, not attributes on `[sysmda_md_url]`.** That one always
    returns a bare URL, which is exactly what makes `<a href="[sysmda_md_url]">`
    safe in a template. A `text=` attribute would make its return type depend on
    an argument — bare URL sometimes, markup other times — and would break that
    usage the first time someone passed a label. Two shortcodes, two return
    types, no conditionals. `resolve_post()` is shared (it was already `public
    static` from the button era, and the actions shortcode now uses it too).
  - **NO `Content-Disposition`, and no request argument to trigger one**
    (decided before release, do not propose it again without a concrete case).
    A `?download=1` argument was implemented and removed within the same PR. Two
    reasons, in this order:
    - **What it cost was permanent.** Every argument read from the request is a
      public input to validate forever. Codex caught the first instance
      immediately: `?download[]=1` makes `$_GET` an array, `(string) $array`
      raises a warning, and because the check ran inside `send_headers()` after
      `status_header()`, a site with `display_errors` on would flush the
      headers sent so far and lose `ETag`, `Last-Modified` and `X-Robots-Tag`
      to "headers already sent" — from an anonymous request. The guard was a
      one-liner; the class of problem was not, and it renews itself with each
      new input.
    - **What it bought was nearly nothing.** The `download` attribute is
      reliable because the URL is same-origin, so a click already saves the
      file. The header only added the case of pasting the URL into a tab by
      hand — where, without the argument, the browser decides as it always has.
      Not a regression: a case that was never in scope.
    Corollary: the `.md` keeps exactly one representation and one behaviour, and
    the response carries no header that varies by how a client intends to store
    it. `MetadataBuilder::download_filename()` stays, because the HTML attribute
    needs a name; its strict `[A-Za-z0-9._-]` charset stays too, tested as a
    property rather than a fixed string, so reusing it in a header some day is
    safe by construction.
  - **One class, no CSS, no JS, and it stays that way.** `.sysmda-md-download`
    exists so a theme can style it; the plugin ships no stylesheet for it and
    never will. This is the same rule that removed the button, applied before
    the problem recurs — the button was also just a link once. The tests assert
    the shape (one class, no inline styles, no `data-` hooks) precisely so the
    drift is caught mechanically rather than in review. Do **not** add styling
    options, a second class, an icon or a panel tab: that is the 0.31 → 0.33
    trajectory starting over.
- **NO rate limiting on `.md` requests** (decided): do not anticipate; only
  reconsider if the hit-counter data ever shows real abuse.
- **NO synthesized homepage index** (decided, do not propose again): a
  purpose-built homepage `.md` index (site links + recent posts) would
  conceptually duplicate `/llms.txt` — which per public data is requested
  almost only by SEO tools anyway. The value of a homepage `.md` is the
  real-time assistant fetch of the actual content: if ever implemented, it is
  the converted body of the static front page only (see "Open / to do").
- **NO XML sitemap for the `.md` URLs** (decided, do not propose again): the
  `.md` responses are `noindex` by design, so listing them in a sitemap would
  send contradictory signals to search engines (Search Console: "submitted URL
  marked noindex") — exactly the SEO risk the plugin promises not to create —
  and a second sitemap generator would overlap with the SEO plugin's sitemaps
  (Rank Math & co.). Discovery for the real audience (LLMs/agents) is already
  covered by the HTML and HTTP `rel="alternate"` links and by `/llms.txt`.
  Freshness signals go into `/llms.txt` itself (see the `lastmod` item in "Open
  / to do"): no
  separate machine-index endpoint either.
- **`.md` hit counter is count-only** (decided): when enabled it stores ONLY
  aggregate daily counters split bot/human. NEVER store IP addresses, raw
  user-agent strings, timestamps finer than the day, or any per-visitor
  identifier; the user-agent is read from the request only to classify
  bot vs human and is immediately discarded. No external calls, no cookies.
  This keeps the stored data anonymous (GDPR out of scope, no consent needed)
  and within the wordpress.org "no tracking without consent" guideline.

## Identity, versioning, workflow

- Plugin **Author** = **"Diecieventi Digital Marketing"**. The author's legacy
  company name **must NEVER appear** in artifacts (code, commits, readme).
- **GitHub home**: personal account **`diecieventi`**
  (`github.com/diecieventi/system-markdown-alternate`); `Plugin URI` and
  `composer.json` point there. `Author URI` → **`https://diecieventi.com/`**
  (changed in `0.30.1`; it used to be `webdietrolequinte.it`). The reason is
  wordpress.org **entity validation**: the URI has to identify the entity named
  in `Author`, and "Diecieventi Digital Marketing" is not what
  `webdietrolequinte.it` represents — that domain is the reference *site*, which
  is why it still appears throughout the measurement notes and must stay there.
  Keep the two apart when editing: `Author URI` is the author's identity,
  `webdietrolequinte.it` is the host things were measured on.
- **wordpress.org**: `Contributors:` in `readme.txt` is set to **`system4pc`**
  (the existing account: the username cannot be renamed, only the Display Name
  can change). Publishing from a new `diecieventi` account and updating the field
  remains an option.
- Do not put the **model ID** in commits, readme, code or any other artifact.
- **Semver `0.x.y` versioning**: minor for new features, patch for fixes. On
  every release: bump `system-markdown-alternate.php` (both the `Version:` header
  **and** `SYSMDA_VERSION`), update `Stable tag` + changelog in `readme.txt`,
  `bash bin/build.sh`, commit, push the branch and open the PR (see the git
  workflow below).
  **The changelog is split, and it has to stay split** (from `0.30.2`): the
  wordpress.org readme parser truncates a `Changelog` section longer than
  **5000 characters** (`readme_parser_warnings_trimmed_section_changelog`), and
  the full history had reached ~34 000. So:
  - `readme.txt` carries only the **three most recent releases** (~2500
    characters) and closes the section with a
    `[View the full changelog](…/blob/main/CHANGELOG.md)` link — the same shape
    ACF uses, pointing at GitHub instead of a plugin site.
  - The complete history lives in **`CHANGELOG.md` at the repo root**, with
    markdown `## X.Y.Z` headings so it renders with anchors on GitHub. Root
    files are outside the plugin folder, so it is never shipped in the package —
    deliberate: the link replaces it, and a 34 KB history in every install is
    dead weight.
  - On every release: add the entry to **both** files, then drop the
    now-fourth-oldest entry from `readme.txt`.
  - **`bin/release-tag.sh` parses `CHANGELOG.md`**, not `readme.txt`, for both
    the version list and the tag notes. It used to read `readme.txt` and gate on
    finding the literal `0.17.1`; trimming that file left no such heading, so the
    version list came out empty, the loop did nothing and the workflow reported
    success **without creating the tag** (caught in review on `0.30.2`, before it
    shipped). The script now fails loudly on an empty list or a missing gate
    anchor. If the changelog ever moves again, move that parsing with it. **Tagging is automated**: merging a release PR triggers the
  `Release tag` workflow (`.github/workflows/release-tag.yml`), which runs
  `bin/release-tag.sh` and pushes the annotated `vX.Y.Z` tag with that version's
  `CHANGELOG.md` entries as notes (shown as "Notes" on the GitHub Tags page). It can
  also be started by hand from the Actions tab ("Run workflow", with a
  `dry_run` option) — handy from a phone. The script stays available locally for
  offline catch-up; agents still cannot push tags (the web proxy rejects them).
- **Git — PR workflow (decided July 2026, replaces the old "direct to `main`"
  rule)**: **no agent (Claude Code, Codex, or any other tool) ever pushes to
  `main` directly**. Every piece of work:
  1. lives on its **own branch** — the branch imposed by the harness
     (`claude/*`, `codex/*`, …) is fine as-is; create one if the environment
     does not provide it. Atomic commits there, as always.
  2. push the branch (`git push -u origin <branch>`) and **open a PR to
     `main`** with a clear English title and description.
  3. **the user merges from the GitHub UI with "Squash and merge"** — `main`
     history stays linear, one commit per PR, no merge commits. Agents do
     NOT merge PRs themselves unless the user explicitly asks in that session.
  4. CI runs on every PR and all three checks are **required** by the branch
     protection on `main`: `PHP 7.4` and `PHP 8.4` (lint + pure-logic tests)
     and `PHPCS (WordPress standards)`. A red PR cannot be merged — fix the
     branch first. PHPCS blocks on **errors** only; warnings stay annotations
     (see "Code conventions"). Adding a job to `ci.yml` does NOT make it
     blocking: the check name has to be added to the required list in
     Settings → Branches as well.
  If `main` moves while a PR is open, rebase the branch on `origin/main` and
  push with `--force-with-lease`. The user still syncs their Mac with a single
  `git pull origin main`, unchanged.
- **Every PR answers one question about the documentation, before it is
  opened** (decided August 2026 — this is the whole maintenance mechanism, and
  it applies to Claude Code, Codex and anything else):

  > **Would a user who read the documentation yesterday do something different
  > today?**

  If yes, the change to `documentation/` goes **in the same PR** as the code.
  Not a follow-up, not an issue: the same diff, reviewed and merged together.
  That is the entire reason the documentation lives in this repository, and a
  PR that alters user-facing behaviour while touching nothing under
  `documentation/` is visible as such in review.

  | Answer is yes | Answer is no |
  |---|---|
  | A new panel field, or a default that changes | Internal refactors |
  | A new shortcode or dynamic tag, or different attributes | Bug fixes restoring already-documented behaviour |
  | A change to what gets served: types, eligibility, default exclusions | Performance work |
  | A change to observable behaviour: output shape, response headers, when a 404 is returned | Build, CI, release tooling |
  | A new integration | A new filter → `docs/filters.md`, not the site |

  Worked examples from real releases: `0.42.0` added default exclusions, so the
  *Excluding content* article had to change — what lands in a `.md` moved.
  `0.41.0` rewrote a converter and `0.41.1` fixed a script-printing bug;
  neither changes how anybody uses the plugin, so neither touches the site.
  **Most PRs answer no, and that is correct** — the changelog is not the
  documentation.

  A new filter is documented in `docs/filters.md` (the developer contract) and
  reaches `documentation/` only when the *setting* behind it changed too.

  **When the answer is yes, it is yes for every surface that describes the
  behaviour — not for the first one that comes to mind** (added August 2026,
  after `0.43.0` shipped its embed change to the output-format contract and one
  article while three other files went on describing the old behaviour by
  omission). "Check the documentation" is not a reliable instruction to oneself;
  a list of places is. Walk it:

  | Surface | Carries |
  |---|---|
  | `documentation/src/content/docs/` | how the plugin is used, per article |
  | `system-markdown-alternate/readme.txt` — Key features, FAQ | the wordpress.org listing, read before installing |
  | `README.md` — Features | the same summary for GitHub |
  | `docs/output-format.md` / `docs/filters.md` | the two public contracts |
  | `docs/staging-acceptance.md` | the matrix a release is actually run from |
  | `AGENTS.md` | invariants, decisions, the acceptance list |

  Most entries stay untouched in most PRs; the point is that skipping one is a
  decision, not an oversight. **State both halves in the PR body: which
  surfaces were updated, and which were considered and deliberately skipped,
  with the reason.** That is the same discipline the catch-up procedure below
  already requires, and for the same reason — the skips are the judgement call
  worth reviewing.

  Ask before opening the PR only when a surface's answer is genuinely
  uncertain; a routine confirmation round on every PR would cost a round trip
  on the majority that change no documentation at all. Uncertainty is a
  question, not a habit.
- **On-demand catch-up: "check the documentation".** The rule above is the
  mechanism; this is the net for when it was skipped. When the user asks for a
  documentation check, in those words or any others:
  1. `php bin/docs-audit.php` — half a second, and it catches the one thing
     reading diffs can miss entirely: a filter, field or shortcode that is
     named nowhere at all. It is step zero, not the check itself.
  2. Find the window: `git log -1 --format=%H origin/main -- documentation/`.
     Everything merged after that commit has never been considered for
     documentation impact.
  3. List it: `git log --oneline <that-commit>..origin/main`. Squash-merge
     means one line per PR, with its number in the subject.
  4. Read each one's diff and apply the question above. Reading the subject is
     not enough — a commit titled as a fix can move a default.
  5. Open **one** PR with the updates, and state in its body **which PRs were
     covered and which were skipped, with the reason for each skip.** The
     skips are the part worth reviewing: they are a judgement call the user is
     entitled to check, and burying them makes the whole thing unauditable.

  **Why the window is "since the documentation last changed"** rather than a
  number of PRs or the last release tag: it needs no bookkeeping and resets
  itself. The known imprecision is that fixing a typo in an article also resets
  it, so a code PR merged just before could go unexamined — accepted, because
  the alternative is a marker file to keep in step, which is the class of thing
  this whole design exists to avoid. If precision ever matters more, the last
  release tag is the stricter anchor.

### Agent-specific notes (Claude Code web, Codex, …)

- **Claude Code (web)**: the `claude/*` branch the harness creates IS the PR
  branch — commit there, push it, open the PR (GitHub MCP tools). The old
  "consolidate onto `main` with ff-merges" procedure is **retired**: never
  push `main` from this environment. The environment's git proxy rejects tag
  pushes (403), but **no manual tagging step is needed**: the `Release tag`
  workflow creates `vX.Y.Z` when the release PR is merged, and can be re-run
  from the Actions tab if a tag was ever missed (`bin/release-tag.sh` stays the
  offline fallback). Do not tell the user to tag from their machine. Same for
  the GitHub Release: it is the `Publish release` workflow, one tap from the
  Actions tab (phone included) — not a `gh release create` on the Mac.
- **The remote branch is deleted when the PR merges**, so the next piece of work
  under the same branch name fails to push with `! [rejected] … (stale info)` —
  even with `--force-with-lease`, because the local remote-tracking ref still
  points at the branch that no longer exists. `git remote prune origin` (note: it
  takes no `-q`) or `git fetch --prune` clears it, after which the push is an
  ordinary new-branch push needing no force at all. Hit three times in one
  session before anyone wrote it down.
- **Codex and any other agent**: same rule, no exceptions — work on a
  dedicated branch (e.g. `codex/<topic>`), push it, open a PR to `main`, let
  the user merge. Code-review fixes follow the same path: a PR, never a
  commit to `main`.

## Compatibility with known plugins / test environment

Developed and tested against a stack based on **GeneratePress/GenerateBlocks
2.x**, **ACF** and **Rank Math**. When testing over HTTP, keep in mind that a
**WAF/CDN** may block non-browser User-Agents (e.g. `curl` as a "bad bot"): use
a browser User-Agent. Observed on the reference site (RunCloud 8G firewall):
`curl/*` **and `ClaudeBot`** are answered with a `302` to
`/RUNCLOUD-8G-WAF-BLOCKED`, site-wide — HTML, `.md` and `/llms.txt` alike —
while GPTBot, PerplexityBot, CCBot and the rest pass. A block page arriving
instead of Markdown is a WAF, not a plugin bug; check the `Location` header
before debugging anything else.

Worth separating from the WAF, because the two look identical from a browser
and are not: whether an AI client is *allowed* to fetch the `.md` is a
`robots.txt` question, and Cloudflare can manage that file on the site's
behalf (appending an AI-crawler section above WordPress's own rules). A site
that blocks the training crawlers while allowing the user-initiated ones
(`Claude-User`, `ChatGPT-User`, `OAI-SearchBot`, `PerplexityBot`) is not
contradicting this plugin — that second group is exactly the audience the
`.md` is for.

**Test environments**: the pure PHP suite remains the fast CI gate. For behavior
that needs real WordPress routing, hook order, emitted headers or a browser, use
the connected InstaWP sites and the repeatable checklist in
`docs/staging-acceptance.md`. There are **two**, and which one a run needs
depends on what changed: `instawp_sma` (GeneratePress, ACF, Polylang, Code Block
Pro and the link-card plugins) is the general release environment, and
`sma-bricks-instawp-co` (Bricks 2.0 as the active theme) is the only one with
page-builder content — the general one has none at all, so a builder change
cannot be exercised there. Run the matrix before a release or after changing
those integration surfaces. The latest full pass was `0.45.0` on 20 August 2026,
across both: WordPress 7.1 / PHP 8.4.7 on the Bricks environment and WordPress
7.1 / PHP 8.4.20 on the release one. It complements rather than replaces the
pure suite. Use the safe update/rollback procedure and remove transferred
packages and backups when finished.

**Verify which site a connector points at before writing to it.** The connector
names are not stable across reconnects — one came back under a different name
mid-session, still pointing at the other site, and a plugin update went to the
wrong environment because the name looked like continuity. Any call that writes
should assert `home_url()` first and refuse otherwise; it costs one line.

### Impact on defaults

- **Syntax highlighters** (e.g. Code Block Pro): do NOT convert the highlighting
  HTML. Strip the `<span>`s while preserving the `language-*` class and let the
  converter produce the fenced block (generic approach, covers any highlighter).
- **Table of Contents** (e.g. LuckyWP TOC): navigation → excluded (`lwptoc`
  shortcode, `luckywp/toc` block).
- **Gallery/image lightboxes**: just wrappers around images; no special handling,
  preserving `alt` is enough.
- **GenerateBlocks**: NEVER excluded automatically (they contain real content).
- **ACF**: implemented (subtitle/TL;DR via preamble). The
  `sysmda_markdown_source_content` / `sysmda_acf_field_keys` filters remain the
  extension points.
- **On-site search engines** (e.g. Algolia): irrelevant to the output.
- **LiteSpeed page cache**: behaviour varies per server — some installs honour
  `Vary: Accept`, others key by URL only and mix the representations. Handled by
  `LiteSpeedCompat` (see "Current state"): no-cache signals on the negotiated
  responses always on, `.htaccess` bypass rules opt-in from the panel.

## Repository structure

```
.
├── AGENTS.md                     ← this file (tool-agnostic guide, English)
├── CLAUDE.md                     ← symlink → AGENTS.md
├── README.md                     ← repo overview (GitHub, English)
├── CHANGELOG.md                   ← full release history (NOT shipped; readme.txt links here)
├── LICENSE                       ← GPL-2.0 (full text)
├── .gitignore
├── .github/workflows/ci.yml      ← CI: php -l + tests on PHP 7.4/8.4, + the docs-site build (required check)
├── .github/workflows/docs-site.yml    ← builds documentation/ and deploys it to GitHub Pages
├── .github/workflows/release-tag.yml  ← auto-creates the vX.Y.Z tag on a version bump (also manual)
├── .github/workflows/publish-release.yml  ← manual button: publishes the Release for a tag, zip attached
├── .github/workflows/deploy-wordpress-org.yml  ← SVN deploy (live: secrets configured; validates the tag before staging)
├── .wordpress-org/               ← wordpress.org listing assets (icon, banners, 5 screenshots)
├── bin/build.sh                  ← builds DIST/system-markdown-alternate.zip
├── bin/release-tag.sh            ← creates + pushes missing release tags (run by the Release tag workflow; also usable locally)
├── bin/docs-audit.php            ← on-demand report of where the documentation lags the plugin
├── DIST/                         ← build output of bin/build.sh (NOT versioned)
├── docs/                         ← public contracts, active plans and operational notes
│   ├── filters.md                ← developer extension API (public contract)
│   ├── output-format.md          ← Markdown output format (public contract)
│   ├── staging-acceptance.md     ← real-WordPress release checklist
│   ├── cache-infrastructure-notes.md
│   ├── exclusion-scanner-plan.md
│   ├── llms-txt-multilingual-plan.md
│   └── page-builders-plan.md
├── documentation/                ← user documentation site, Astro Starlight (NOT shipped)
│   ├── README.md                 ← audience split, link rules, how to write an article
│   ├── astro.config.mjs          ← sidebar, site + base path, favicon
│   ├── remark-base-paths.mjs     ← applies `base` to root-relative Markdown links
│   └── src/content/docs/<section>/<article>.md  ← 19 articles + index.md (splash)
└── system-markdown-alternate/    ← THE PLUGIN
    ├── system-markdown-alternate.php   ← header + bootstrap (Composer autoloader)
    ├── readme.txt                      ← wordpress.org format + the 3 most recent changelog entries
    ├── uninstall.php                   ← options + transients cleanup
    ├── .distignore                     ← exclusions for the WP.org package (SVN)
    ├── composer.json / composer.lock   ← league/html-to-markdown + PSR-4 (+ PHPCS dev tooling)
    ├── phpcs.xml.dist                  ← WPCS ruleset (dev only, excluded from the package)
    ├── vendor/                         ← NOT versioned, zip only
    ├── assets/admin-settings.css       ← panel style (loaded only there)
    ├── assets/admin-settings.js        ← tab client-side (vanilla, progressive enhancement)
    ├── assets/md-actions.css           ← minimal reader-action UI (shortcode pages only)
    ├── assets/md-actions.js            ← copy + disclosure + viewport placement (shortcode pages only)
    ├── tests/run-tests.php             ← pure-logic tests (php tests/run-tests.php, no WP/PHPUnit)
    └── src/
        ├── Plugin.php              ← bootstrap, registers hooks and dependencies
        ├── MarkdownController.php  ← intercepts .md + content negotiation (Vary/q-values/406), validation, headers, cache (+ opt-in pre-warm), assemble_document(), output, alternate link, invalidation
        ├── AcceptNegotiator.php    ← Accept header parser with q-values (no WP deps)
        ├── ContentRenderer.php     ← source → clean HTML (shortcodes/blocks/DOM/absolute URLs, tables/dl, code lines); render_fragment(); the builder-adapter seam (matching_builder_adapter(), builder_dependency_parts(), builder_source_text(), builder_handles()); render_appended() honours sysmda_markdown_appended_html on every branch
        ├── BlockCleaner.php        ← Gutenberg block parsing/cleaning (expands synced patterns)
        ├── BuilderDetector.php     ← per-post page-builder detection (render mode, from meta) + the veto list
        ├── BuilderCensus.php       ← what each post type is built with, for the panel (admin only, transient-cached)
        ├── BuilderAdapter.php      ← interface: a page builder that can render its own content
        ├── BricksAdapter.php       ← BuilderAdapter for Bricks (render_data(), lazy-load fix, fingerprint, source_text)
        ├── PostSupport.php         ← post eligibility (is_servable, supported types memoized per blog, excluded post formats, unsupported page builders, sanitize_types: attachment always stripped)
        ├── ShortcodeCleaner.php    ← removal of excluded shortcodes
        ├── MetadataBuilder.php     ← YAML front matter; markdown_url(), taxonomy_terms()/normalize_taxonomies()/taxonomies_fingerprint(), candidate_taxonomies()/filter_candidates()/is_public_taxonomy() for the panel list only (all static); dependencies_fingerprint() is an instance method (needs ContentRenderer's builder adapter list); collect_meta_dependencies() gates on metadata_exists()
        ├── MarkdownConverter.php   ← HTML → Markdown (league/html-to-markdown + code/paragraph safety overrides)
        ├── CodeFence.php           ← content-sized code delimiters (pure logic, no WP/library deps)
        ├── CodeElementConverter.php ← independently designed <code>/<pre> converter using public library interfaces
        ├── CodeRegions.php         ← masks <pre>/<code> around a transform; shared by shortcode expansion AND removal
        ├── SafeParagraphConverter.php   ← wraps the library's <p> converter (escapes a prose fence)
        ├── AcfIntegration.php      ← subtitle + TL;DR (preamble); ACF source fields
        ├── MetaFields.php          ← generic post-meta content (panel key list; emit() shared with AcfIntegration)
        ├── HitCounter.php          ← opt-in .md hit counter (aggregate daily bot/human buckets)
        ├── LlmsTxtController.php   ← /llms.txt endpoint (cached)
        ├── AdminSettings.php       ← settings page (Settings API)
        ├── ConflictDetector.php    ← /llms.txt conflict detection (local only)
        ├── LiteSpeedCompat.php     ← LiteSpeed page-cache compatibility (no-cache signals + optional .htaccess rules, locked/atomic writes)
        ├── Shortcodes.php          ← [sysmda_md_url] + [sysmda_md_download] (resolve_post() is shared, public static)
        ├── MarkdownActions.php     ← [sysmda_md_actions] split button + conditional asset loading
        ├── DynamicTags.php         ← {{sysmda_md_url}} (GenerateBlocks 2.x)
        └── Cache.php               ← cache helper (object cache or transients)
```

- **PHP namespace:** `Diecieventi\SystemMarkdownAlternate` (PSR-4 → `src/`).
- **Constant/hook/option prefix:** `sysmda_` / `SYSMDA_` (≥ 4 chars and
  distinctive, per the wordpress.org prefixing guideline; also used with a dash
  for slugs/handles: `sysmda-settings`, `sysmda-admin-settings`).

### User documentation (`documentation/`)

The plugin's user-facing documentation — installation, every panel field, the
endpoints, the shortcodes, the integrations, troubleshooting. Nineteen articles
plus a landing page, built with **Astro Starlight** and published to GitHub
Pages at `https://diecieventi.github.io/system-markdown-alternate/` by
`.github/workflows/docs-site.yml` on any push to `main` that touches
`documentation/`. Never shipped (root folders sit outside the plugin directory,
which is all `bin/build.sh` packages), so keeping it out of the package needs no
configuration.

**It lives in this repository for one reason, and that reason is the whole
maintenance strategy** (decided August 2026, after the alternative was tried and
abandoned): a change to the plugin and the change to its documentation travel in
the **same pull request**, reviewed and merged together. A PR that alters a
filter, a panel field or a shortcode and touches nothing under `documentation/`
is visible as such in review.

The rejected alternative was a separate repository, which is what makes the
rule worth stating rather than assuming. It needed a mechanism to connect the
two — a scheduled surface diff, a trigger, cross-repo pull requests, and a rule
in this file telling agents the other repository existed at all. Every one of
those pieces existed only to bridge the gap; in one repository there is no gap,
and none of them are needed. Do not reintroduce synchronisation tooling: there
are not two places to keep in step.

**The audience split is binding, and it is the anti-drift rule applied.**
`documentation/` is for site owners; `docs/filters.md` and
`docs/output-format.md` are contracts for developers, versioned with the code
and **linked, never restated**. Articles link them as full GitHub URLs, not
relative paths — a relative path resolves while browsing the repository and
breaks on a published site, where the contracts are not part of the content
collection.

**Keeping it current** is the rule in "Identity, versioning, workflow": every PR
asks whether a user who read the documentation yesterday would do something
different today, and if so the article changes in the same PR. The on-demand
catch-up procedure is documented there too. Both live with the git workflow
because that is where an agent is when the question arises.

`php bin/docs-audit.php` is **step zero of that procedure, not the procedure**.
It answers one narrow question mechanically — is there a filter, panel field or
shortcode that is named nowhere at all — and reports names the documentation
still explains that the source no longer contains. Reads local files only; no
network, nothing scheduled. Exit `1` when it finds something, so it can be wired
into CI later; it informs and never writes.

What it cannot do is the actual job, which is judging whether a change altered
how the plugin is used. That needs the diff read. Do not mistake a clean run for
a documentation that is up to date: `0.40.0` turned the exclusion lists from
"replace the defaults" into "add to the defaults" — same filter, same field,
same names — and this script would have said nothing.

Two things about it are load-bearing:
- **It probes panel fields by their LABEL, not their option name.** The first
  version matched the field id and reported all sixteen fields as undocumented,
  every one a false positive: user documentation speaks in the words on the
  screen ("Cache TTL"), not in option keys, and rightly so. Sixteen findings
  that are all noise is worse than no tool, because nobody reads the
  seventeenth run. `*_notice` rows are skipped by naming convention — they
  render an explanation, not a control.
- **It cannot see a behaviour change that moves no symbol**, which is the drift
  that actually hurts. `0.40.0` turned the exclusion lists from "replace the
  defaults" into "add to the defaults" — same filter, same field, same names,
  and the article became false in silence. Nothing mechanical finds that, which
  is why the run ends by printing recent `CHANGELOG.md` entries: they are the
  only record of intent, and they have to be read.

**Two build details that fail silently, both verified rather than assumed:**
- **Astro rewrites nothing in Markdown link targets.** Tested against 7.2.1 with
  four forms (relative `.md`, relative directory, root-relative, root-relative
  already carrying the base): all four reach the HTML byte-for-byte as written,
  and the build succeeds regardless. Articles therefore link each other as
  `/section/article/` with no base, and `remark-base-paths.mjs` applies the base
  at build time. Writing the base into the files instead would hardcode one
  deployment into the portable source and make a custom domain a
  find-and-replace rather than `base: '/'`. Front matter never reaches that
  pass, so the landing page's hero actions use plain relative links.
- **`docs-site.yml` has a `paths:` filter, and that is only safe because it is
  not a required check.** Branch protection gates on the three CI checks, and
  `ci.yml` deliberately has no path filters — so a documentation-only PR still
  gets them and can be merged. Adding a path filter to a *required* check would
  leave it permanently pending and block every PR that does not touch the
  filtered paths. Do not add one to `ci.yml`.
- **The Astro build itself is a pull-request gate, and it lives inside the
  `PHP 7.4` job** (`0.46.1`): `docs-site.yml` builds only on a push to `main`, so
  a malformed config, component or frontmatter entry passed every PR check and
  failed while deploying the public site — with most of #82–#93 being
  documentation work, and #91 having made multi-surface documentation a
  requirement of feature PRs. It is a *step* in an already-required check rather
  than a job of its own for the reason stated two bullets up: a new job gates
  nothing until its name is added to branch protection, and the same file already
  carries the shell lint and the release-tag regression on that argument. Verified
  to fire rather than assumed: a deliberately malformed article exits the build
  non-zero. Node 22 and `npm ci` are kept identical to the deploy workflow, so
  what passes here is what gets deployed there.

## Code conventions

- PHP `>= 7.4`, WP `>= 6.1`. No runtime dependencies beyond `league/html-to-markdown`.
- Small, single-responsibility classes.
- `defined('ABSPATH') || exit;` at the top of every PHP file.
- Strict output escaping (especially the **YAML front matter**: quote strings,
  escape `"` and `\`).
- Every filter must be **documented with a docblock**.
- After changes: `php -l` on the touched files and
  `php system-markdown-alternate/tests/run-tests.php` (pure-logic tests, no WP;
  CI runs them on PHP 7.4 and 8.4).
- **A guard is not done until it has been seen to fire** (August 2026, written
  after three of these in a single day). When what you wrote is itself a check,
  a guard, an exclusion, a cleanup or a conditional branch, construct the input
  it is supposed to catch, run it, **watch it fail**, and only then fix and
  ship. Not "read it again carefully" — actually execute the case.

  The reason this needs a rule of its own: **a guard that never fires produces
  no symptom.** Ordinary code announces its bugs; a check that does not check
  looks exactly like one that works, and the tests pass, and the build is
  green, and review sees a plausible-looking condition. It is the one category
  where "it seems fine" carries no information at all.

  All three were found in review, none by the author, and all three had the
  same shape — the branch that mattered was never executed:
  - `usort()` inside a `get_terms` filter, with a comparator returning `0` for
    non-objects. The comparator was irrelevant: `usort()` reindexes regardless,
    so the `id=>parent` shape would have had its term IDs replaced by `0,1,2…`.
    The guard was in a place where it could not do its job.
  - Dismissal and repositioning listeners registered per component instance
    instead of once, leaking a set per navigation. Unreachable today because
    the client router is off — and therefore untested, in code written
    specifically for the day it is switched on.
  - `bin/docs-audit.php` collecting only quoted hook names, so the shortcode
    registered as `add_shortcode( self::TAG, … )` was absent from its list.
    Deleting that article outright left the audit reporting success. Proving it
    took thirty seconds *after* the review comment; those thirty seconds
    belonged before the PR.

  Corollary for anything whose whole purpose is verification — an audit, a
  linter, a test helper: it gets the treatment first and hardest, because there
  its silence is the *expected* output.
- **Coding standards (PHPCS + WPCS)**: `composer phpcs` from the plugin folder;
  `composer phpcbf` auto-fixes the mechanical ones. Config in
  `system-markdown-alternate/phpcs.xml.dist` — `WordPress-Core` +
  `WordPress-Extra` + `PHPCompatibilityWP` (target `7.4-`, min WP 6.1).
  Deliberately **not** enabled: `WordPress-Docs` (its mandatory `@param` tags
  are redundant with the native type declarations used here) and
  `WordPress.Files.FileName` (conflicts with PSR-4 class filenames). CI gates on
  **errors**; warnings are annotated but do not fail the build. Genuine
  third-party names (`DONOTCACHEPAGE`, LiteSpeed hooks) carry an inline
  `phpcs:ignore` with the reason — use that mechanism, with a justification,
  rather than widening the ruleset.

## Filters (developer extension API)

The full list — every filter, its default, what changing it does and its
**stability level** — lives in **[`docs/filters.md`](docs/filters.md)**, grouped
by area (content selection, headers, caching, pipeline, front matter, ACF,
`/llms.txt`, hit counter) with the default exclusion tables and runnable
examples.

It is deliberately **not** duplicated here: a developer looking for the filter
API should not have to read the agent guide to find it, and two copies of a
contract drift. `readme.txt` (FAQ entry) and `README.md` ("Extending via
filters") carry short examples and link to the same page.

**When adding or changing a filter, update `docs/filters.md` in the same
commit** — it is the contract, and a filter that is not documented there does
not exist as far as the public API is concerned.

- **Two levels, and the axis is what the hook is anchored to, not how useful it
  is** (decided August 2026): **Stable** = anchored to a panel setting
  or to a concept the plugin is about (what may be served, what the final
  document is, what the response says about caching) — breaking one goes through
  deprecation, changelog and docs. **Advanced** = anchored to a stage of the
  *current implementation* (where the pipeline cuts, how ACF is read, how the hit
  counter classifies, how `/llms.txt` is laid out) — supported and documented,
  free to evolve pre-1.0. 24 Stable, 13 Advanced.
  The classification is deliberate on three points, all of which a naive reading
  gets backwards:
  - **The settings-transport hooks are Stable, and they are stable for free.**
    Fourteen of the 33 are how `AdminSettings::hook_filters()` feeds a saved
    option into the code (priority 20; 5 for the taxonomy slugs). They cannot be
    removed without breaking the panel, so calling them "internal, no promises"
    would buy no refactoring freedom while making them look unreliable. They last
    exactly as long as the checkbox.
  - **`sysmda_markdown_source_content`, `..._rendered_html` and
    `..._preamble` are Advanced** even though they are the most-used hooks and
    two of them carry the bundled ACF integration (`Plugin.php`). They mark where
    *this* pipeline cuts; a block-native engine would not have the same seams.
    These three are the actual reason the level split exists — classifying them
    Stable is what would mortgage the conversion engine.
    `sysmda_markdown_output` is Stable by contrast: it takes a finished document
    and returns one, so no change of engine can invalidate it.
  - **`sysmda_markdown_cache_dependencies` is Stable**, not Advanced. It is the
    documented answer to "my output changes and the `.md` does not" and the
    escape hatch that justifies the weak ETag (see "Technical notes" 6);
    declaring it free to move would undercut a durable decision already taken.
- **NO third "internal" tier** (decided with the above): anything not on that
  page is internal by definition, and a tier whose members cannot actually be
  removed is a label, not a freedom. If a hook ever needs retiring, the path is
  `apply_filters_deprecated()` → changelog → removal, not a pre-emptive
  disclaimer.
- **Before adding a filter, ask what it is anchored to.** A hook tied to a
  setting or a domain concept is cheap to keep forever; one tied to a pipeline
  stage is a promise about the pipeline. Prefer few high-level extension points
  over many hooks on internal phases, and mark a new pipeline-stage hook
  Advanced from the start. Do not add a filter merely because something *could*
  be configurable.
- **The `.md` output contract is separate and stronger**
  (`docs/output-format.md`): it is read by crawlers and agents that cannot pin a
  version, while the PHP hooks are read by code that can. Do not merge the two
  policies.

## Technical notes

1. **`.md` resolution**: on `template_redirect` (priority 0) read `REQUEST_URI`,
   detect the `.md` suffix, handle query strings and trailing slashes
   (`/slug.md/` → 301 → `/slug.md`), rebuild the permalink and use
   `url_to_postid()`. No rewrite rules → no `flush_rewrite_rules`.
2. **Content negotiation**: besides the `.md` suffix, on the canonical permalink
   the representation is decided with `AcceptNegotiator` (RFC 9110). Markdown
   only when explicitly preferred: `?format=markdown` or `text/markdown` with
   q ≥ the effective q of `text/html` (exact match > `text/*` > full wildcard).
   A wildcard or missing Accept → HTML (so curl/library `Accept: */*` stays
   HTML). Every servable content declares **`Vary: Accept`** (both when serving
   Markdown and when leaving the HTML to WP), so caches/CDNs never mix the two
   representations. When HTML wins, that same canonical request also appends a
   typed HTTP `Link: rel="alternate"` field (on both `GET` and `HEAD`) pointing
   at `MetadataBuilder::markdown_url()`. It runs in a separate
   `template_redirect` callback at the last priority, so Markdown, `406` and
   canonical/access redirects exit before it. If the Accept allows neither HTML
   nor Markdown, respond
   **`406`** (`sysmda_markdown_strict_406` filter, default on; real clients always
   send `text/html` or a wildcard, never hit). The `.md` suffix ignores the
   Accept header instead (the URL itself is the explicit Markdown request).
3. **Class exclusion**: besides `attrs.className`, a `DOMDocument` pass on the
   rendered HTML removes nested elements carrying the excluded classes.
4. **Rendering**: `render_block()` on the cleaned blocks (not the full
   `the_content`), to avoid reintroducing injected related/CTA content.
5. **Absolute URLs**: resolved against the post permalink (not `home_url('/')`).
6. **Cache**: key `sysmda_md_{post_id}`, value with a validity hash
   (`post_modified_gmt|SYSMDA_VERSION|salt`, plus the taxonomy fingerprint when
   that feature is on); `/llms.txt` cached under `sysmda_llms_txt`. Everything
   through the `Cache` helper (persistent object cache or transients). The
   **same hash is the (weak) `ETag`** of the `.md` response
   (`ETag`/`Last-Modified` + conditional `304`, `If-None-Match` over
   `If-Modified-Since`); it derives from `post_modified`, so conditional requests
   work even when the body cache is off. **Anything that can change the emitted
   Markdown without touching `post_modified_gmt` MUST be folded into this hash**
   — otherwise a client holding the old validator keeps getting `304` with stale
   content, body cache or not. Custom taxonomies were the first such case
   (`MetadataBuilder::taxonomies_fingerprint()`); deleting the cache entry alone
   would NOT have been enough. Apply the same rule to any future addition.
   **The rule was written for taxonomies and then broken by everything else**
   (0.26.3 review, H1): synced patterns, the featured image and its alt text,
   the Rank Math description and ACF fields all change the body from *outside
   the post row*, so none of them moved the validator.
   `MetadataBuilder::dependencies_fingerprint()` now covers exactly what the
   plugin itself reads, and `sysmda_markdown_cache_dependencies` is the
   documented way for a site to declare the rest (dynamic blocks, shortcodes,
   filters reading options or remote data) — that filter is the answer to
   "my output changes and the `.md` does not", not a new special case in the
   controller. Both fingerprints stay empty when they have no configured
   surface to describe, which is what keeps an upgrade from invalidating every
   plain post. Once a custom taxonomy is selected, however, its **empty state is
   still a state** and remains fingerprinted: removing the last term must not
   make `If-Modified-Since` trust a post date that did not move. The same
   disappearing state for `_thumbnail_id` and `rank_math_description` is
   recorded through a deferred salt bump on metadata deletion (or an update to
   an empty value), while non-empty edits stay in the cheaper per-post
   fingerprint. Generic ACF source fields also owe the synced-pattern traversal:
   their values join the block source before rendering, so their `core/block`
   references share the post body's transitive walk and cycle guard.
   **Two traps, both hit while fixing exactly this:** (a) synced patterns must
   be followed **transitively** — an article → pattern A → pattern B chain
   renders B, so recording only A leaves the validator stale one level down
   (cycle guard required, as in `BlockCleaner`); (b) every input added to
   `cache_version()` MUST also be reflected in `date_is_strong_validator()` —
   a client sending only `If-Modified-Since` never presents the ETag, so a
   fingerprint that lives in the ETag alone still answers `304` with a stale
   body.
   **Everything in the hash is on the every-request path, `304`s included**:
   `cache_version()` produces the ETag, so it runs before the cache lookup and
   before any header, and the filters it reads run with it —
   `sysmda_front_matter_taxonomy_slugs`, `sysmda_front_matter_taxonomies`,
   `sysmda_markdown_cache_dependencies`, `sysmda_markdown_extra_meta_keys` and —
   while ACF is active — the three `sysmda_acf_*` keys. Route eligibility gets there first, so
   `sysmda_markdown_supported_post_types` and
   `sysmda_markdown_excluded_post_formats` are on that path too. Adding
   an input therefore adds cost to responses that send no body at all, which is
   exactly what a `304` exists to avoid. Keep new inputs to values already in
   memory or cheap to read, and never do I/O there; `docs/filters.md` states
   the same rule for filter authors.
   **Not every input belongs in the hash, though** (0.28.0): some are
   *site-wide* — the author's display name (`author:`), the permalink structure
   and the home URL (`url:`, `markdown_url:`, every absolute link in the body),
   the site timezone (`date_published`/`date_modified` are printed in **local**
   time, so their offset and wall-clock reading move with it), and the terms of
   `category`/`post_tag` (always emitted under their own keys, and therefore
   the two taxonomies `taxonomies_fingerprint()` excludes — the *optional*
   custom taxonomies need no hook, that fingerprint hashes their term names).
   Reading them per request would make both fingerprints non-empty for every
   post, which invalidates the whole site on upgrade **and** permanently
   disables the `If-Modified-Since` path. They are rare, one-off events, so
   `AdminSettings` bumps the global salt instead
   (`update_option_permalink_structure`, `update_option_home`,
   `update_option_timezone_string`, `update_option_gmt_offset`, `profile_update`
   guarded on an actual display-name change, `deleted_user` for the silent
   reassignment `wp_delete_user()` performs with a direct DB write,
   `edited_term`/`delete_term` guarded on the two taxonomies above). Prefer that
   shape for anything else that is site-wide and rare. Deliberately **not**
   hooked: `set_object_terms`, which fires on every post save — assigning terms
   from the editor already moves `post_modified_gmt`, and the residue (a purely
   programmatic `wp_set_object_terms()` touching no post row) is the same
   bounded one already accepted for post formats.
   **Two rules the salt carries, both load-bearing:**
   - **It is written once, at `shutdown`** (`flush_cache_salt()`; the triggers
     only mark it pending). A Settings API save writes the group's options one
     at a time, and bumping on the first changed one let a concurrent front-end
     request cache half-old output *under the new salt*, where nothing would
     invalidate it again. Same argument that already keeps the triggers on
     post-write hooks, one level up.
   - **Its value is `<unix ts>-<random>`, never a bare `time()`.** Two genuine
     invalidations in the same second produced the same string, and
     `update_option()` short-circuits on an unchanged value, so the second
     silently did nothing. The leading timestamp is read by
     `MarkdownController::salt_changed_at()`, so keep the shape.
   **Corollary in `date_is_strong_validator()`** (0.36.0): the date is refused
   as a validator not only when either fingerprint is non-empty, but unless
   **the salt is strictly older than `post_modified_gmt`**. Strictly, not
   "not newer": both have one-second resolution, so an equal pair is ambiguous
   — a save and a bump in the same second are indistinguishable, and if the
   bump came second the date is already lying. Ambiguity resolves against the
   date. A client sending only
   `If-Modified-Since` presents no ETag, so every site-wide bump above would
   otherwise keep answering `304` with a body the salt had already invalidated,
   for every post older than the change. It becomes usable again for a post the
   next time that post is saved — which is exactly when the date starts telling
   the truth again.
7. **i18n**: **English** is the source language for runtime strings, code
   comments, DocBlocks, tests, build tooling and workflow messages. The whole
   repository is English-only. Strings with inline HTML (`<code>`, `<strong>`, …)
   go through `wp_kses_post()`. Text domain `system-markdown-alternate` (= plugin slug,
   required by wordpress.org). **No translation catalogs or manual translation
   loader belong in the plugin or repository**: WordPress automatically loads
   the language packs built by translate.wordpress.org. Translations are managed
   there once the plugin is live (see "Open / to do"). Installs from the GitHub
   zip are English-only by design until an official language pack is available.

## Notes from the reference plugin (ProgressPlanner/markdown-alternate)

GPL plugin by Joost de Valk. Same library, same PSR-4. Adopted converter config:

```php
new HtmlConverter([
    'header_style'    => 'atx',          // # Heading
    'strip_tags'      => true,
    'remove_nodes'    => 'script style iframe',
    'hard_break'      => false,
    'list_item_style' => '-',
]);
```

- **Conversion fallback**: if `convert()` throws → simple text extraction instead
  of breaking the response.
- **escape_yaml**: entity decoding + escaping of `\` and `"`.

## Build & deploy

```bash
bash bin/build.sh        # → DIST/system-markdown-alternate.zip (vendor/ bundled)
```

`DIST/` is a **local build output and is not versioned** (decided August 2026 —
do not commit the zip again). The release tag is authoritative: the `Publish
release` workflow rebuilds the package from the tag before attaching it, and the
WordPress.org deploy stages from the repository, so neither ever read a
committed zip. Keeping one only created work and risk — a whole PR was once
spent rebuilding it (#66), it silently fell behind `main` whenever a commit did
not change the version, and `publish-release.yml` needed a `git checkout
--force` purely because the tracked file was rewritten on every build.
**Where to get an installable zip**: the asset on the GitHub Release (built from
the tag, so it matches the released source by construction), or `bash
bin/build.sh` locally when you want one for a test site or to inspect what
ships. Testing an unreleased branch was never what the committed copy was for
anyway — it held the last *release*, not your working tree.

The zip includes the production Composer dependencies, so it installs without
Composer on the server. Local build environment: PHP 8.4, Composer, `rsync` and
`zip` (no wp-cli).

### Publishing to wordpress.org (SVN)

On WP.org you **deploy**, you don't develop: the GitHub repo remains the home of
development, SVN is distribution only. What goes into SVN is **the content of the
`system-markdown-alternate/` folder** (not the repo root: no `README.md`,
`AGENTS.md`, `bin/`, `DIST/`, `.github/`), with **`vendor/` bundled** (runtime
dependency). `system-markdown-alternate/.distignore` is the **single source of
package exclusions**, read by both `bin/build.sh` and the WordPress.org deploy
workflow: tests, development metadata/configuration, Composer's lock file and
the `league/html-to-markdown` CLI binaries are omitted. Those binaries are
never invoked at runtime and the wordpress.org Plugin Check flags them as
"not permitted files"; the plugin uses the library classes only. The
production package intentionally keeps `composer.json` alongside `vendor/`,
as required for dependency review by WordPress.org Plugin Check.

- Manual flow: `bash bin/build.sh`, then copy the content into `svn/trunk` and
  tag it under `svn/tags/x.y.z`.
- **Automated flow** (**live**: the `SVN_USERNAME` / `SVN_PASSWORD` secrets are
  configured and versions have already been published this way):
  `.github/workflows/deploy-wordpress-org.yml` runs
  `10up/action-wordpress-plugin-deploy`, triggered on **publishing a GitHub
  Release** (not on a bare tag push, to avoid a run without SVN credentials) or
  by hand from the Actions tab. Since `BUILD_DIR` ignores `.distignore`, the
  workflow stages a clean copy of `system-markdown-alternate/` itself by passing
  that shared exclusion file to `rsync` before handing the result to the action.
  `VERSION` is derived from the tag name (`v0.18.0` → `0.18.0`).
  **The job refuses to deploy anything that is not an existing `vX.Y.Z` tag**
  whose plugin header, `SYSMDA_VERSION`, `readme.txt` stable tag and
  `CHANGELOG.md` entry all agree, and checks out `refs/tags/…` explicitly so a
  branch cannot stand in for a tag. An SVN version number cannot be withdrawn
  once published, which is why the guard runs before anything is staged — do
  not relax it. Every `uses:` in this repository is **pinned to a full commit
  SHA** (`# vX.Y.Z` comment alongside), not to a moving `@v5`/`@stable` ref:
  this workflow hands SVN credentials to a third-party action, so what runs
  must not be able to change underneath it. Bump them through the pinned SHA,
  never back to a tag.
- **Git tags**: annotated, `vX.Y.Z` on the squashed release commit on `main`
  (e.g. `v0.18.0`); retroactively added from `v0.17.1` onward. Created and
  pushed **automatically** by the `Release tag` workflow when a push to `main`
  changes the version, and startable by hand from the Actions tab (`dry_run`
  input available). `bash bin/release-tag.sh` does the same thing locally for
  offline catch-up; it finds the missing tags itself and uses the changelog as
  the tag notes. Agents cannot push tags (the Claude Code web proxy rejects
  them). Not required for local development — only for SVN releases and for
  pinning a specific version on GitHub.
- **GitHub Releases**: optional (the tag with notes is the baseline), and
  **deliberately not automatic** — publishing stays a decision, taken with one
  tap. Run the **`Publish release`** workflow
  (`.github/workflows/publish-release.yml`) from Actions → "Run workflow"; it
  works from the GitHub mobile app, which is the point. The `tags` input defaults
  to the most recent `vX.Y.Z`, so the usual case is a single tap with nothing to
  fill in; name older tags (one or several, space-separated) to backfill. Only
  the newest tag in the repository is ever marked **"Latest"** — the API marks a
  new Release as latest by default, which would drag the badge backwards on a
  backfill. The job checks each tag out, runs
  `bin/build.sh` there and attaches the resulting
  `DIST/system-markdown-alternate.zip` (the auto-generated "Source code"
  archives are not an installable plugin), with the tag notes as the body. The
  asset is built from the tagged tree, so it always matches the released source
  — and it is **the** way to get an installable zip of a release without
  building one, now that `DIST/` is no longer committed (see "Build & deploy").
  Idempotent: a tag that
  already has a Release is reported and left alone. The manual equivalent, if
  ever needed from the Mac:
  ```bash
  git fetch origin --tags   # git pull does NOT bring tags down, and
                            # --notes-from-tag needs the tag locally
  gh release create vX.Y.Z --title "vX.Y.Z" --notes-from-tag DIST/system-markdown-alternate.zip
  ```
  (asset forgotten? `gh release upload vX.Y.Z DIST/system-markdown-alternate.zip`).
  Note: a Release published by the workflow does **not** start the SVN deploy —
  GitHub raises no workflow-starting event from the default `GITHUB_TOKEN`. That
  workflow has its own manual trigger, so the usual flow is to run it from the
  Actions tab with the tag; to chain the two, add a `RELEASE_TOKEN` secret (a
  PAT with `contents: write`), which `Publish release` already prefers when
  present. A Release published by hand from the Mac does trigger it directly.
  Banner/icon/screenshots live in the SVN `/assets` folder (not in the plugin)
  and are updated with `10up/action-wordpress-plugin-asset-update` from the
  repo's `.wordpress-org/` folder.

## Tests (acceptance)

Test posts:
1. Simple post (headings, paragraphs, list, links) → `.md` OK, correct headers, front matter, alternate link.
2. Post with images + code (with a syntax highlighter) + blockquote → correct conversion.
3. Post with an `md-exclude` section → absent from the `.md`.
4. Post with a form shortcode (`[contact-form-7 ...]`) and a TOC (`[lwptoc]`) → absent from the `.md`.
5. Disallowed content (non-enabled page/CPT, draft, password-protected post) → **404**.
6. Post with a **non-standard post format** (aside/status/quote/…) → **404**, no
   `rel="alternate"` link, absent from `/llms.txt`, empty shortcode/dynamic tag.
7. Post with a **table** and a **definition list** → GFM pipe table, `**Term**` +
   paragraphs (not glued text).
8. Post whose content carries an **unbalanced `</div>`** (Custom HTML block) →
   nothing after it is lost.
9. `/my-post/feed/` with `Accept: text/markdown` (and `?format=markdown`) → the
   **feed**, not Markdown. Same for `/my-post/embed/` and
   `/my-post/comment-page-2/`.
10. Same post through `/my-post.md` and `?format=markdown` → **byte-identical**
    bodies (the loop is set up on both routes).

11. Post still containing a stray `[sysmda_md_button]` from before 0.34.0 → the
    tag appears nowhere in the `.md`, not even as literal text, including with
    the panel's "Excluded shortcodes" textarea filled in with a custom list.

12. `[sysmda_md_download]` on a servable post → clicking the link **saves** the
    file instead of opening it, with the slug as its name. On a non-servable
    post the shortcode outputs nothing at all. `curl -sI '<permalink>.md'` must
    show **no** `Content-Disposition` (the download is client-side only), and
    the response headers must be identical with and without a `?download=1`
    that the plugin now ignores entirely.

13. `curl -sI '<permalink>'` on a servable canonical post → a typed
    `Link: <…md>; rel="alternate"; type="text/markdown"` field is present and
    any pre-existing Link relation is preserved. The field is absent from the
    `.md` response, negotiated Markdown, `406`, feed, embed, trackback, paged
    comments and `<!--nextpage-->` sub-pages.

14. Post with a registered shortcode in three places — typed into a paragraph,
    in a Custom HTML block and in the core Shortcode block → all three are
    **expanded** in the `.md`, none appears as literal `\[tag\]`. The same
    shortcode written inside a code block and inside an inline `<code>` span →
    published **verbatim**, unexpanded. Both halves again on a classic-editor
    post (no block markup), which takes the other branch.

15. `[sysmda_md_actions]` in ordinary content and in a template/secondary loop
    → the primary action copies the complete `text/markdown` response; the
    dropdown repeats copy, opens View in a new tab and downloads with the safe
    slug filename. One CSS/JS pair only, and neither appears on a page without
    the shortcode. Verify keyboard/Escape/outside-focus behaviour and place the
    component near all four viewport edges at 320, 375, 768 px and desktop: away
    from an edge the menu is aligned to the button's start edge and drops
    directly below it (never hanging off the caret), and near one it flips or
    clamps without horizontal overflow or clipping by an ancestor.
    Draft/protected/unsupported targets output nothing. The shortcode itself is
    absent from the `.md`, including when the excluded-shortcodes filter
    replaces the defaults.

16. Post with an `md-exclude` section, **no** SEO description and **no** excerpt
    → the excluded text is absent from the body *and* from the front-matter
    `description:`. A post without any excluded class → its `description:` is
    unchanged from the previous release (the pass must be a no-op there).

17. Post with a **YouTube/Vimeo embed block**, one with a caption, and a
    **tweet/Mastodon embed** whose fallback markup carries the quoted text →
    each video embed becomes an autolink to its address (never nothing, which is
    what a resolved embed used to leave), the caption follows as its own
    paragraph, and the quoted text is still published. Worth doing on staging
    specifically: which of the two shapes reaches the pipeline depends on
    whether anything resolved the embed, and that varies per provider and per
    caching setup.

18. Post carrying a **clickable link card** from a link-preview or related-posts
    plugin → the `.md` holds a link whose text is the card's name, not
    `[](url "Name")`. Worth doing on staging specifically: whether such a card
    renders as an overlay anchor with sibling text or nests its title inside the
    link is the card plugin's own choice, and only the real one settles it. A
    decorative anchor elsewhere on the page (a "back to top" link) must be
    unchanged, and a code sample quoting an empty anchor must publish verbatim.

19. **Page builder veto.** An Elementor page (`_elementor_edit_mode =
    'builder'`) → `.md` **404**, no `rel="alternate"` link and no `Link:`
    header on its HTML, absent from `/llms.txt`, and all three shortcodes plus
    the dynamic tag render nothing. Same for Divi/WPBakery/Oxygen/Beaver
    Builder/Breakdance fixtures. A Gutenberg post on the same page-builder-
    themed site is completely unaffected. In the panel, the *Enabled content
    types* rows read the real breakdown (for example *Pages — 8 Divi, 3
    Gutenberg*) with the warning on the vetoed part, and a builder page's
    **revisions** do not inflate the count.
20. **Bricks adapter (`0.46.0`).** A Bricks page
    (`_bricks_editor_mode = 'bricks'`) → `.md` **200**, `text/markdown`,
    front matter plus a body rendered through `\Bricks\Frontend::render_data()`;
    `rel="alternate"` and the `Link:` header present; listed in `/llms.txt`;
    all three shortcodes and the dynamic tag render normally. Images reference
    their **real `src`/`srcset`**, never a `data:image/svg+xml,...` placeholder
    (verify against an element referencing a real WordPress attachment — a raw
    external URL never exercises Bricks' own lazy-load filter, so it cannot
    catch a regression here). An element carrying `md-exclude` in its *CSS
    Classes* field is absent from the body; a `brxe-form`/`brxe-nav-menu`/
    `brxe-nav-nested`/`brxe-post-sharing`/`brxe-post-toc`/`brxe-breadcrumbs`
    element is absent by default with no panel configuration, and the panel's
    **Excluded builder elements** textarea can add another selector without
    losing those defaults. `curl -sI` with a matching `If-None-Match` from a
    prior response → **304**; saving the page (moving `post_modified_gmt`) or
    editing a referenced `template` element's own post both change the ETag.
    The same page switched to **"Render with WordPress"** → back to a normal
    `.md` from `post_content`, even though `_bricks_page_content_2` is still
    stored: that is the single most important fixture in the set, and the one
    a presence-based check fails — unaffected by this release's adapter, since
    `handles()` still keys on the render mode. A Gutenberg post on the same
    Bricks-themed site is completely unaffected either way.

Always verify: `Content-Type: text/markdown; charset=utf-8`,
`X-Robots-Tag: noindex, follow`; no private/draft/non-enabled content exposed.
Note: command-line HTTP tests may be blocked by a WAF/CDN
(use a browser User-Agent).
