# Closed evaluations and measurements

Questions that were answered for good. Each entry records a measurement, a
review or a rejected alternative, kept so the work is not redone from scratch —
not because any of it is pending. **Nothing in this file is open work**; the
backlog is [`STATUS.md`](STATUS.md), and the decisions that must not be
reopened at all are in `AGENTS.md` under *Product decisions*.

## Reviews of other people's work

- **llms.txt v2: reviewed, implemented, closed.**
  Reviewed against the spec Jeremy Howard/Answer.AI published 10 August 2026;
  the one gap it found shipped in `0.49.0` (see `rel="describedby"` in "Current
  state"). Recorded so the comparison is not redone from scratch next time v2 —
  or a v3 — comes up. **Four of the five v2 changes needed no code at all**, and
  each for its own reason worth keeping: the `.md` URL pattern now allows
  extension-replacement as well as appending, which produces the identical
  string for WordPress's extensionless permalinks; path-coverage semantics for
  multiple `llms.txt` files per subtree have no demand and a single root file is
  already a valid case; `llms_txt2ctx` context-expansion tooling was dropped
  from the spec and was never implemented here; and `## Optional` lost its
  mechanical meaning, which changes nothing because this plugin only ever used
  it as a label. The plugin's `rel="alternate" type="text/markdown"` discovery
  already matched the v2 example verbatim in both forms before any of this.
  The implementation work also caught two things the initial review got wrong
  by reading its own summary of the code rather than the code, both worth
  remembering as a class: a helper described as needing generalisation
  already had it, and a gate described as sufficient would have advertised a
  404 on every default install.

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

## Architecture proposals that were measured and set aside

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

- **Server-side diagnostics** (parked, *future thought* — we will revisit):
  a read-only, in-process admin view of per-post servability, `.md` preview,
  size/token estimates, stripped/unconverted markup and unresolved internal
  links. Removed from the active plan in July 2026: `strip_tags()` cannot detect
  all conversion loss, `url_to_postid() === 0` does not prove a link is broken,
  and an in-process comparison cannot measure the public response through its
  cache/proxy layers. Do not promote it back to a plan without real demand and a
  deliberately small, read-only MVP on a separate admin page. The only shipped
  request-side telemetry remains the count-only `.md` hit counter above.

## Infrastructure measurements

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
