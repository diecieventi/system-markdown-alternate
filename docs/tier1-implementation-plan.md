# Implementation plan — ordered work

> Companion to `docs/strategy-review-2026-07.md` (reasoning + future thoughts).
> This is the concrete, ordered plan for the committed work. Each item is an
> independent PR to `main` on its own branch (per the git workflow in
> `AGENTS.md`). Do them in order. Incorporates the corrections from the
> 2026-07-24 plan audit.
>
> **Scope note (2026-07):** server-side diagnostics (the old "F2") has been
> **removed from the plan** and parked as a future thought — see
> `docs/strategy-review-2026-07.md` → *Future thoughts*. The only shipped
> request-side telemetry is the already-implemented, count-only `.md` hit
> counter (`HitCounter`, documented in `AGENTS.md` *Current state*); nothing
> here adds to it.

## Ground rules (from AGENTS.md — do not violate)

- **No new per-visitor data** (durable *"hit counter is count-only"*): no IP, no
  raw UA, no per-visitor, no sub-daily timestamps. Do not enrich request logging.
- **No HTTP loopback** anywhere (durable *"NO Vary self-test"*): any content
  analysis must run **in-process**; the live cache check stays a manual curl in
  the readme FAQ.
- Prefix everything `sysmda_` / `SYSMDA_`. `defined('ABSPATH') || exit;` on every
  file. Small single-responsibility classes. Escape output (YAML: quote/escape).
- Every new filter gets a docblock and an entry in the *Filters (public
  contract)* list in `AGENTS.md`.
- After changes: `php -l` on touched files + `php system-markdown-alternate/tests/run-tests.php`.
- A **version bump is for a release, not for every PR**: bump
  `system-markdown-alternate.php` (`Version:` **and** `SYSMDA_VERSION`), update
  `readme.txt` (`Stable tag` + changelog), run `bash bin/build.sh` **only** when
  the PR is a runtime change deliberately being released. A docs/test-only PR
  must **not** bump the version (it would invalidate every Markdown cache/ETag
  for nothing).
- The repository is **English-only** (Italian docs removed in #5): do not create
  `.it.md` files.

---

## PR 1 — Sanitize fix for `register_setting()` (do first)

**Why first:** it is a concrete wordpress.org Plugin Check finding that blocks a
clean validation — higher priority than any strategic feature below.

Full detail in `FIX-PLAN-sanitize-register-setting.md` (repo root). Summary:

- `AdminSettings.php:157` registers `sysmda_excluded_classes` with the generic
  `sanitize_lines()` (→ `sanitize_text_field`). CSS classes need the
  class-specific `sanitize_html_class`.
- Add a dedicated `sanitize_class_lines()` sanitizer (splits lines, applies
  `sanitize_html_class` per entry, drops empties, deduplicates) and switch **only**
  line 157's callback. The block/shortcode/key_content options keep
  `sanitize_lines()` (they legitimately contain `/`, `:`).
- Open decision to confirm: split on whitespace (robust for multi-class lines)
  vs strict one-class-per-line.
- Add a `sanitize_class_lines` case to `tests/run-tests.php` (sanitizers have no
  coverage today).

**Touched:** `src/AdminSettings.php`, `tests/run-tests.php`, `readme.txt`
(changelog + `Stable tag`), `system-markdown-alternate.php` (patch bump — this
one **is** a release). Low risk, output unchanged.

---

## PR 2 — Plan & documentation corrections (no runtime change, no version bump)

Cross-document factual fixes surfaced by the audit. Cheap, high credibility ROI.

1. **False noindex-gating claim.** `docs/strategy-review-2026-07.md` said
   protected/private/password/**noindex** gating is done via
   `PostSupport::is_servable()`. The implementation checks only supported type,
   `publish` status, and password protection — no source-page `noindex`
   inspection. Correct the wording. Do **not** silently add source-noindex
   gating: SEO metadata is not an access-control boundary; that would be a
   separate product decision. *(Corrected in this pass in the strategy doc.)*
2. **`AGENTS.md` current-state label.** Says `v0.22.x`; current `main` is
   `0.23.1`. Update the heading to match.
3. **`README.md` / `readme.txt` wording:**
   - `Vary: Accept` does **not** mean caches/CDNs "never" mix representations —
     the durable LiteSpeed decision explains why. `Vary` is correct metadata;
     the no-cache invariant + optional LiteSpeed bypass are the safety measures.
     Soften the "never" claim.
   - The cache is described as a transient; `Cache.php` uses a **persistent
     object cache when available**, transients only as fallback. Correct it.
   - Menu label: docs say "System Markdown Alternate"; the registered menu label
     and `readme.txt` use "Markdown Alternate". Make them consistent.
4. The four wordpress.org screenshots are stale (pre-0.17.0 UI). Recapture is
   tracked in `AGENTS.md` *Open / to do*; do it before publication, not here.

**Touched:** `docs/*`, `AGENTS.md`, `README.md`, `readme.txt`. No `src/`, no
version bump.

---

## PR 3 — Documented, stable output format (F1)

**Goal:** turn "it converts to Markdown" into a versioned, testable contract.
No code behaviour change — documentation + conformance tests that pin current
output so future changes are deliberate.

**Deliverables**
1. `docs/output-format.md` (English). Document, from the code as it is today:
   - **Front matter** keys and order, exactly as emitted by
     `MetadataBuilder::build_front_matter()`: `title`, `url`, `markdown_url`,
     `date_published`, `date_modified`, `author` (if any), `featured_image` +
     `featured_image_alt` (if any), `categories`, `tags`, `description` (Rank Math
     → excerpt → trimmed chain, ~200 chars). Document the YAML scalar escaping
     rules (`MetadataBuilder::scalar()`: entity-decode, strip tags, escape `\`
     and `"`) and which keys are conditional.
   - **Body**: `# Title`, optional preamble (ACF subtitle/TL;DR), then
     `render_block()` on cleaned blocks. Conversion config (atx headers,
     `list_item_style '-'`, fenced code, absolute URLs resolved against the
     permalink, synced patterns expanded). Default exclusions (blocks/shortcodes/
     classes) with the three filter hooks named. **Document that unknown HTML
     tags are stripped** (`MarkdownConverter` `strip_tags => true`): their text
     may remain and structural boundaries can be lost — raw unknown tags are
     **not** a supported stable output.
   - **HTTP contract** (brief, link to AGENTS.md): `Content-Type`, `X-Robots-Tag`,
     `Link rel=canonical`, `Vary`, `ETag`/`Last-Modified`/`304`, negotiation
     rules, `406`, and the dedicated-`.md` caching vs negotiated-response
     no-cache distinction.
   - A **compatibility-policy version.** Do **not** claim stability "within
     `0.x`" retroactively — versions before `0.23.1` already changed the format.
     State an explicit start: *"this compatibility policy applies from version
     0.24.0"* (or introduce a separate format-contract version). Additions are
     backwards-compatible: **append** keys, never reorder silently. Reconcile
     with PR 4: custom taxonomies are added as a single appended nested
     `taxonomies:` mapping, consistent with this append-only rule.
2. **Conformance tests** in `tests/run-tests.php`: assert the full front-matter
   block against golden strings, so any accidental reorder/removal fails CI:
   - one **full** fixture (title/url/markdown_url/dates/author/**featured_image +
     featured_image_alt**/categories/tags/description);
   - one **minimal** fixture with the optional fields absent (proves conditional
     presence);
   - **scalar-escaping** cases: quotes, backslashes, entities, embedded tags,
     multiline/whitespace.
   Reuse existing WP stubs; add stubs only for missing functions
   (`get_the_terms`/`wp_list_pluck`/`get_the_author_meta`/`get_post_thumbnail_id`
   etc.).
3. `readme.txt` FAQ entry: "What does the Markdown output look like?" pointing at
   the documented format.

**Touched:** `docs/output-format.md` (new), `tests/run-tests.php`, `readme.txt`,
`README.md` (link the doc). **No `src/` change, no version bump** (merge as
docs/tests, or batch the release note with the next runtime change).

**Acceptance:** tests green on PHP 7.4/8.4; doc matches actual output for a real
post captured at WP level; existing assertions stay green.

---

## PR 4 — Custom taxonomies in front matter (F3.1)

**Goal:** raise output quality above a plain HTML→MD conversion — the
origin-native edge. Small, self-contained. Only worth doing if target sites use
CPTs with custom taxonomies.

> Reality check: `MetadataBuilder` **already** emits `author`, `date_published`,
> `date_modified`, `categories`, `tags`, `featured_image`. The remaining
> front-matter work is **custom (non-core) taxonomies only**.

**Design (with audit corrections):**

1. **Collision-safe nested schema.** Do **not** emit arbitrary top-level YAML
   keys (a taxonomy could collide with a stable key or a future field). Emit a
   single appended mapping — consistent with F1's append-only rule:

   ```yaml
   taxonomies:
     topic:
       - "Example"
   ```

2. In `MetadataBuilder`, after categories/tags, iterate
   `get_object_taxonomies($post, 'objects')`, skipping `category`/`post_tag` and
   non-public taxonomies. Guard behind a filter
   `sysmda_front_matter_taxonomies` (array of taxonomy slugs; the filter can
   return an empty list to opt out). Decide and **document** whether "all public
   custom taxonomies" is the automatic default or opt-in (automatic is
   reasonable, but it is a payload change for every upgraded site).
3. **Deterministic ordering** (required for golden tests): fix taxonomy-slug
   order and term-name order (registration or sorted — pick one, document it).
4. **Cache invalidation for taxonomy-only changes.** Current cache validity is
   `post_modified_gmt` + `SYSMDA_VERSION` + settings salt + `save_post`. Term
   assignment/renaming can happen **without** a post-content modification, so the
   cached `.md` (and `/llms.txt`) can go stale. Hook `set_object_terms` /
   `edited_term` (and equivalents) to invalidate the affected post cache(s) and
   `/llms.txt`. This already latently affects core categories/tags and becomes
   visible with custom taxonomies.
5. **Tests:** `WP_Error`/empty terms, non-public taxonomies, filter curation,
   key-collision handling, scalar escaping; extend the F1 golden fixtures.

**Touched:** `src/MetadataBuilder.php`, cache-invalidation wiring
(`MarkdownController.php` or `Cache` helper), `tests/run-tests.php`,
`AGENTS.md` (Current state + Filters), `readme.txt`, `README.md`. Runtime
feature → version bump + `bin/build.sh`.

**Acceptance:** a CPT with a custom taxonomy shows the nested `taxonomies:` block
in front matter; term reassignment invalidates the cache; core front matter
unchanged (F1 golden still green).

---

## Later / case-driven — ACF structured extraction (F3.2)

**Do not build Repeater/Flexible Content generically without real field-group
fixtures.** This is deferred until concrete demand and real ACF exports exist.

**Corrections to the earlier plan (from the audit):**

- The panel configures **only** the subtitle and TL;DR field names. The general
  `sysmda_acf_field_keys` list is **developer-only via filter**
  (`AcfIntegration.php`), not a panel field — earlier wording ("the panel's ACF
  fields") was misleading.
- Repeater / Flexible Content have **no universal semantic rendering**; a generic
  dump can be worse than omitting the data. They require an explicit
  template/callback contract, per site.
- ACF **return formats are configurable** (Relationship/Post Object → IDs or
  `WP_Post`; Gallery → IDs/URLs/arrays; Link/Image → multiple shapes; nested
  fields → any of these). Every supported shape needs defined normalization and
  escaping.
- "Unknown types fall back to current text behaviour" is **inaccurate**: the
  current code accepts only non-empty strings and skips arrays/objects
  (`AcfIntegration.php`).
- Pure helpers must produce **escaped semantic HTML fragments** (or a structured
  intermediate consumed by one renderer), not a "Markdown string" appended to
  HTML source (that would be treated as text, not parsed).

**Recommended order when demand appears:**
1. Link/URL and basic scalar fields.
2. Relationship/Post Object → title + canonical/`.md` link where servable.
3. Gallery/Image with `alt`.
4. Repeater **only** with an explicit template/callback contract.
5. Flexible Content **only** with per-layout render callbacks.

Keep the existing developer filter as the escape hatch; add docs/examples for
site-specific extraction first. No large generic UI until the data model and use
cases are known.

---

## Suggested sequencing

1. **PR 1 = sanitize fix** — patch release (wordpress.org blocker).
2. **PR 2 = plan/doc corrections** — no version bump.
3. **PR 3 = F1** (output-format doc + golden tests) — no version bump.
4. **PR 4 = F3.1** (custom taxonomies + cache invalidation) — minor/patch release.
5. **Multilingual `/llms.txt`** (`docs/llms-txt-multilingual-plan.md`) —
   independent; requires the WPML/Polylang staging reconnaissance first.
6. **ACF structured (F3.2)** — later, case-driven only.

Each PR: branch → push → open PR to `main` → user squash-merges → user runs
`bash bin/release-tag.sh` from the Mac. Do not push `main`, do not push tags
(web proxy rejects tag pushes).

---

## External references (from the 2026-07-24 audit)

- WordPress `get_posts()` (incl. `suppress_filters => true` default):
  <https://developer.wordpress.org/reference/functions/get_posts/>
- WordPress `get_object_taxonomies()`:
  <https://developer.wordpress.org/reference/functions/get_object_taxonomies/>
- Polylang query/language guidance:
  <https://polylang.pro/documentation/support/developers/developpers-how-to/>
- Polylang function reference:
  <https://polylang.pro/documentation/support/developers/function-reference/>
- WPML hooks reference (`wpml_active_languages`, `wpml_object_id`, switching):
  <https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/>
- WPML on `get_posts()` and `suppress_filters`:
  <https://wpml.org/forums/topic/wpml-custom-query/>
- llms.txt format proposal: <https://llmstxt.org/>
- Cloudflare Markdown for Agents:
  <https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/>
- Cloudflare Agent Readiness: <https://blog.cloudflare.com/agent-readiness/>
