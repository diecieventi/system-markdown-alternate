# Status — what is open, and what unblocks it

**This file is the backlog, and it is the only place a status lives.** Plans,
reviews and `AGENTS.md` carry the reasoning; the state of the work is here.
A plan that disagrees with this file is stale — fix the plan, not this table.

> **A "what's outstanding" review is not complete without the private companion
> repository.** Some plans live only in
> [`diecieventi/system-markdown-alternate-dev`](https://github.com/diecieventi/system-markdown-alternate-dev)
> under `private-plans/`, whose own `README.md` is the private half of this
> index. They are deliberately not named here (see "This repository is public"
> in `AGENTS.md`): a pointer that listed them would defeat the point of keeping
> them out of the public repository. List that folder — do not infer its
> contents from this file.

Released: **0.52.0**. Nothing is in flight; no open pull request, no open issue.

## Open work

| # | Item | State | What unblocks it | Detail |
|---|---|---|---|---|
| 1 | **R3** — synced-pattern instance overrides | Parked, measured but inconclusive | **One SQL query** on the production reference site | [review-followup-plan.md §4](review-followup-plan.md) |
| 2 | **Exclusion scanner** | Parked, designed, not started | A real content corpus to point it at | [exclusion-scanner-plan.md](exclusion-scanner-plan.md) |
| 3 | **noindex-aware `/llms.txt` + `## Sitemaps`** | Designed, not started | Seven storage-shape measurements, all blocking | [llms-txt-noindex-plan.md](llms-txt-noindex-plan.md) |
| 4 | **Elementor adapter** | Parked | Real demand, then an Elementor **Pro** staging — in that order | [page-builders-plan.md](page-builders-plan.md) |
| 5 | **H1** — Markdown syntax in the `# Title` | Open, **recommended decline** | A decision; the obvious fix was measured and is worse | [review-followup-plan.md §6](review-followup-plan.md) |
| 6 | **Homepage `.md`** | Postponed | Demand data from the `.md` hit counter | below |
| 7 | Italian translation on translate.wordpress.org | Waiting | The plugin being live on wordpress.org | below |

Everything the `0.50.0` external review found is otherwise **shipped**: R1, R4,
R6 and R7 in `0.50.1`; R2 and R5 (plus the `Last-Modified` finding that came
out of measuring R5) in `0.51.0`; B1, PERF1 and PERF2 in `0.52.0`.

## The parked items, in one paragraph each

**1. R3 — pattern overrides.** `BlockCleaner` drops a `core/block`
instance's own `content` attribute, so the plugin publishes a synced pattern's
default text where the page shows the per-instance override. Real, and the most
invasive fix of the set. Three connected installs scanned clean — but none of
them holds a single synced pattern, so the denominator is zero and the result
carries no information. The SQL is in the plan; run it on the production
reference site before spending anything.

**2. Exclusion scanner.** An admin page inventorying the shortcode tags and
block names actually present in the servable corpus, so the three exclusion
lists can be filled from evidence. The *damage* half shipped in `0.40.0`
(lists accumulate, code samples are safe); *discovery* is what remains, and it
waits on a corpus worth scanning.

**3. noindex-aware `/llms.txt`.** The first divergence in this plugin between
**servable** and **listed**: the `.md` endpoint does not change and
`is_servable()` is not touched. Every measurement it depends on is still
untaken, and the whole feature is a guard — "a guard is not done until it has
been seen to fire" applies to it directly.

**4. Elementor.** The only builder still in `BuilderDetector::AWAITING_ADAPTER`.
Divi, WPBakery, Oxygen, Beaver Builder and Breakdance are **never** to be
supported; Bricks shipped in `0.46.0`. A free-only staging cannot validate the
Pro features that make Elementor hard.

**5. H1 escaping.** A title reading `Literal *stars*` publishes as emphasis.
The review's suggested remedy was measured and does not work — `escape_inline()`
puts `&amp;` in the H1 of every title containing an ampersand. Either leave it
(the output contract already describes the current assembly) or write a narrow,
title-specific escaper and change bytes for every title containing `*`, `_`
or `[`. Given the churn against a defect nobody has reported, leaving it is
defensible.

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

**7.** Once live on wordpress.org: translate the strings into Italian on
  translate.wordpress.org (request PTE if needed) so the `it_IT` language pack
  gets built — no translation files live in this repo.

## Ideas recorded, not planned

Not a backlog: nobody has asked for these, and none is greenlit. They are here
so the thinking behind them is not redone from scratch.

- **LLM signals.** Future idea: formalized **LLM signals** in `/llms.txt` once the spec
  (Cloudflare & co.) settles — the hook is already in place (`sysmda_llms_txt_footer`).
- **Evaluate enriching/managing `/llms.txt` further**: beyond the current enriched
  mode, consider what else is worth adding (candidates TBD, see also the LLM
  signals idea above).
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
    What IS worth evaluating, because it stays inside both boundaries — a
    per-known-bot-name breakdown of the bot total — **shipped in `0.48.0`**:
    see `HitCounter::named_bot()`/`named_totals()` in "Current state". Real
    demand, not a guess: raised again after a real production panel showed
    13 uncounted bot hits in a single day with no way to say which crawlers
    they were. The two questions this item left open were both resolved
    during that work, deliberately on the simpler side of each: a **fixed,
    curated name list** (`ClaudeBot`, `GPTBot`, `PerplexityBot`, `CCBot`),
    not a bucket per distinct UA ever seen — the dynamic alternative would
    key the option on request-derived text, which is a bigger step than a
    small breakdown needs and the kind of scope creep the count-only decision
    exists to head off; and the panel shows **only names seen at least once**
    in the last 30 days, not a fixed table padded with always-zero rows for
    crawlers a site never gets. `sysmda_md_hits_bot_patterns` itself is
    untouched — naming is a new, separate filter
    (`sysmda_md_hits_named_bot_patterns`), not a widened reach of the
    existing bot/human one.

## A known non-change

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

## Closed — do not redo

The measurements and evaluations that answered a question for good live in
[`evaluations.md`](evaluations.md): llms.txt v2, the caching and `304` host
measurement, the `acceptmarkdown.com` guides, the block-native Markdown engine,
and server-side diagnostics. Read that file before proposing any of them again.

Durable product decisions — the ones that must not be reopened at all — stay in
`AGENTS.md` under *Product decisions*.
