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
  dropdown to `document.body`, positions it against the viewport, flips
  left/right and above/below, and clamps it inside an 8 px edge inset, so theme
  overflow and narrow columns cannot clip it. The root is hidden until setup, copy uses the
  Safari-safe promise-backed `ClipboardItem` path with fallbacks, and a response
  whose type is not `text/markdown` is refused rather than copied as HTML. The
  whole shortcode is in `ShortcodeCleaner::ALWAYS_EXCLUDED`: interface chrome
  never enters the `.md`.
- **GenerateBlocks Dynamic Tag** `{{sysmda_md_url}}`: self-registers when GB 2.x is
  active (no toggle).
- `uninstall.php` (removes `sysmda_*` options + transients + the LiteSpeed
  `.htaccess` block).

## Open / to do (towards wordpress.org)

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
  correctly. The single genuinely block-aware idea worth keeping is
  `core/embed` → the canonical URL rather than the rendered oEmbed markup, and
  that is one converter, not an engine.
- **Evaluate new integrations**: beyond ACF/GenerateBlocks, consider what else
  might be worth a dedicated integration (candidates TBD).
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
  moving to `document.body` and using viewport-aware placement. That scope and
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
the connected InstaWP site and the repeatable checklist in
`docs/staging-acceptance.md`. Run that matrix before a release or after changing
those integration surfaces. The latest full pass was `0.41.1` on 10 August 2026
(WordPress 7.0.3, PHP 8.4.20); it complements rather than replaces the pure
suite. Use the safe update/rollback procedure and remove transferred packages
and backups when finished.

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
├── .github/workflows/ci.yml      ← CI: php -l + tests on PHP 7.4/8.4
├── .github/workflows/docs-site.yml    ← builds documentation/ and deploys it to GitHub Pages
├── .github/workflows/release-tag.yml  ← auto-creates the vX.Y.Z tag on a version bump (also manual)
├── .github/workflows/publish-release.yml  ← manual button: publishes the Release for a tag, zip attached
├── .github/workflows/deploy-wordpress-org.yml  ← SVN deploy (live: secrets configured; validates the tag before staging)
├── .wordpress-org/               ← wordpress.org listing assets (icon, banners, 5 screenshots)
├── bin/build.sh                  ← builds DIST/system-markdown-alternate.zip
├── bin/release-tag.sh            ← creates + pushes missing release tags (run by the Release tag workflow; also usable locally)
├── DIST/                         ← build output of bin/build.sh (NOT versioned)
├── docs/                         ← public contracts, active plans and operational notes
│   ├── filters.md                ← developer extension API (public contract)
│   ├── output-format.md          ← Markdown output format (public contract)
│   ├── staging-acceptance.md     ← real-WordPress release checklist
│   ├── cache-infrastructure-notes.md
│   ├── exclusion-scanner-plan.md
│   └── llms-txt-multilingual-plan.md
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
        ├── ContentRenderer.php     ← source → clean HTML (shortcodes/blocks/DOM/absolute URLs, tables/dl, code lines); render_fragment()
        ├── BlockCleaner.php        ← Gutenberg block parsing/cleaning (expands synced patterns)
        ├── PostSupport.php         ← post eligibility (is_servable, supported types memoized per blog, excluded post formats, sanitize_types: attachment always stripped)
        ├── ShortcodeCleaner.php    ← removal of excluded shortcodes
        ├── MetadataBuilder.php     ← YAML front matter; markdown_url(), taxonomy_terms()/normalize_taxonomies()/taxonomies_fingerprint(), candidate_taxonomies()/filter_candidates()/is_public_taxonomy() for the panel list only (all static)
        ├── MarkdownConverter.php   ← HTML → Markdown (league/html-to-markdown + code/paragraph safety overrides)
        ├── CodeFence.php           ← content-sized code delimiters (pure logic, no WP/library deps)
        ├── CodeElementConverter.php ← independently designed <code>/<pre> converter using public library interfaces
        ├── CodeRegions.php         ← masks <pre>/<code> around a transform; shared by shortcode expansion AND removal
        ├── SafeParagraphConverter.php   ← wraps the library's <p> converter (escapes a prose fence)
        ├── AcfIntegration.php      ← subtitle + TL;DR (preamble)
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
  free to evolve pre-1.0. 22 Stable, 11 Advanced.
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
   `sysmda_markdown_cache_dependencies` and — while ACF is active — the three
   `sysmda_acf_*` keys. Route eligibility gets there first, so
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
    component near all four viewport edges at 320, 375, 768 px and desktop: the
    menu flips/clamps without horizontal overflow or clipping by an ancestor.
    Draft/protected/unsupported targets output nothing. The shortcode itself is
    absent from the `.md`, including when the excluded-shortcodes filter
    replaces the defaults.

16. Post with an `md-exclude` section, **no** SEO description and **no** excerpt
    → the excluded text is absent from the body *and* from the front-matter
    `description:`. A post without any excluded class → its `description:` is
    unchanged from the previous release (the pass must be a no-op there).

Always verify: `Content-Type: text/markdown; charset=utf-8`,
`X-Robots-Tag: noindex, follow`; no private/draft/non-enabled content exposed.
Note: command-line HTTP tests may be blocked by a WAF/CDN
(use a browser User-Agent).
