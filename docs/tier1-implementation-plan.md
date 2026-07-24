# Tier 1 — Implementation plan

> Companion to `docs/strategy-review-2026-07.md`. Concrete, ordered plan for the
> three Tier 1 items. Written for the next session and for other agents (Codex).
> Each item is an independent PR to `main` on its own branch (per the git
> workflow in `AGENTS.md`). Do the items in order: F1 unblocks the benchmark and
> anchors the format that F2/F3 diagnose and extend.

## Ground rules (from AGENTS.md — do not violate)

- **No HTTP loopback** anywhere in diagnostics (durable *"NO Vary self-test"*
  decision). Everything must run **in-process**.
- **No new per-visitor data** (durable *"hit counter is count-only"*). Diagnostics
  operate on posts/content, never on visitor requests.
- Prefix everything `sysmda_` / `SYSMDA_`. `defined('ABSPATH') || exit;` on every
  file. Small single-responsibility classes. Escape output (YAML: quote/escape).
- Every new filter gets a docblock and an entry in the *Filters (public
  contract)* list in `AGENTS.md` (+ `AGENTS.it.md`).
- After changes: `php -l` on touched files + `php system-markdown-alternate/tests/run-tests.php`.
- Per release/PR: bump `system-markdown-alternate.php` (`Version:` **and**
  `SYSMDA_VERSION`), update `readme.txt` (`Stable tag` + changelog), run
  `bash bin/build.sh`, keep `README.md`/`README.it.md` + `AGENTS.md`/`AGENTS.it.md`
  in sync in the same commit.

---

## F1 — Documented, stable output format (do first)

**Goal:** turn "it converts to Markdown" into a versioned, testable contract.
No code behaviour change — documentation + conformance tests that pin current
output so future changes are deliberate.

**Deliverables**
1. `docs/output-format.md` (English, source of truth) + `docs/output-format.it.md`.
   Document, from the code as it is today:
   - **Front matter** keys and order, exactly as emitted by
     `MetadataBuilder::build_front_matter()`: `title`, `url`, `markdown_url`,
     `date_published`, `date_modified`, `author` (if any), `featured_image` +
     `featured_image_alt` (if any), `categories`, `tags`, `description` (Rank Math
     → excerpt → trimmed chain, ~200 chars). Note the YAML scalar escaping rules
     (`MetadataBuilder::scalar()`: entity-decode, strip tags, escape `\` and `"`).
   - **Body**: `# Title`, optional preamble (ACF subtitle/TL;DR), then
     `render_block()` on cleaned blocks. Conversion config (atx headers,
     `list_item_style '-'`, fenced code, absolute URLs resolved against the
     permalink, synced patterns expanded). Default exclusions (blocks/shortcodes/
     classes) with the three filter hooks named.
   - **HTTP contract** (brief, link to AGENTS.md): `Content-Type`, `X-Robots-Tag`,
     `Link rel=canonical`, `Vary`, `ETag`/`Last-Modified`/`304`, negotiation rules,
     `406`, no-cache invariant on negotiated responses.
   - A **"format version"** note: state that the front-matter key set is stable
     within `0.x`; additions are backwards-compatible (append keys, never reorder
     silently).
2. **Conformance tests** in `tests/run-tests.php`: extend the existing
   `MetadataBuilder` coverage to assert the full front-matter block for a fixture
   post (title/url/markdown_url/dates/author/categories/tags/description) — a
   golden-string assertion so any accidental reorder/removal fails CI. Reuse the
   existing WP stubs; add stubs only for missing functions
   (`get_the_terms`/`wp_list_pluck`/`get_the_author_meta`/`get_post_thumbnail_id`
   etc. if not already stubbed).
3. `readme.txt` FAQ entry: "What does the Markdown output look like?" pointing at
   the documented format.

**Touched:** `docs/*` (new), `tests/run-tests.php`, `readme.txt`, `README*.md`
(link the doc). No `src/` change → low risk.

**Acceptance:** tests green on PHP 7.4/8.4; doc matches actual output for a
real post verified at WP level.

---

## F2 — Server-side diagnostics (no loopback)

**Goal:** a read-only **Diagnostics** admin tab that runs the pipeline in-process
for a chosen post and surfaces quality signals. This is the differentiator; keep
it strictly in-process.

**New class:** `src/Diagnostics.php` (`Diecieventi\SystemMarkdownAlternate\Diagnostics`).
Given a post ID (must pass `PostSupport::is_servable`), compute:
- **Servable? / why not** — reuse `PostSupport`; if not servable, show the reason
  (type not enabled, draft, password-protected, plain-permalink note).
- **Size delta** — rendered HTML bytes vs Markdown bytes; show bytes and a rough
  token estimate (chars/4 heuristic, labelled as an estimate — no external calls).
- **Stripped / unconverted elements** — during the DOM pass, collect a list of
  elements removed by excluded classes/blocks/shortcodes and of tags that survived
  into the Markdown as raw HTML (league leaves unknown tags inline) — report both
  so the user sees what was dropped and what did not convert cleanly.
- **Unresolved internal links** — parse links in the produced Markdown; flag
  those still relative/anchor-broken after absolute-URL resolution, and internal
  links whose `url_to_postid()` is 0 (likely broken). No HTTP — resolution only.
- **`.md` URL** for the post (via `MetadataBuilder::markdown_url()`), shown as a
  copyable field, plus the **manual curl** diagnostic snippet (the live cache
  check stays manual, per the no-loopback decision).

**Admin integration (`AdminSettings.php`):** register a `sysmda_diagnostics`
settings section (render callback only, **no `register_setting` fields** — it is
read-only). `render_page()` already iterates `$wp_settings_sections` and wraps
each in a card+tab-panel, so the tab appears automatically. Add a small post
picker (dropdown of recent servable posts, or an ID input) that re-renders the
panel; keep it usable without JS. Enqueue nothing new beyond the existing admin
assets if avoidable.

**Refactor note:** to expose stripped/unconverted data, `ContentRenderer` /
`BlockCleaner` should optionally **return a report** alongside the HTML (e.g. a
`render_fragment_with_report()` or a collected-notices accessor) without changing
the existing hot path used by `MarkdownController`. Do not slow the normal
response.

**Filters:** none strictly required. If a token divisor is exposed, document it
(`sysmda_diagnostics_token_divisor`, default 4).

**Tests:** unit-test the pure helpers (size delta, token estimate, link
classification) in `tests/run-tests.php` with stubs. The admin rendering is not
unit-tested (WP-dependent) — verify at WP level.

**Touched:** `src/Diagnostics.php` (new), `src/AdminSettings.php`,
possibly `src/ContentRenderer.php` / `src/BlockCleaner.php` (report hook),
`tests/run-tests.php`, `AGENTS.md`/`AGENTS.it.md` (Current state + structure),
`readme.txt` (changelog + FAQ), `README*.md`.

**Acceptance:** no loopback anywhere (grep for `wp_remote_`/`curl` in the new
code → none); panel renders for a servable post and correctly reports a
known-broken-link fixture; normal `.md` response timing unchanged.

---

## F3 — ACF structured extraction + custom taxonomies in front matter

**Goal:** raise output quality above a plain HTML→MD conversion — the
origin-native edge. **Incremental**, smallest-first.

> Reality check: `MetadataBuilder` **already** emits `author`, `date_published`,
> `date_modified`, `categories`, `tags`, `featured_image`. So the front-matter
> work left is **custom (non-core) taxonomies**, not the core set.

**Step 1 — Custom taxonomies in front matter (small).**
In `MetadataBuilder`, after categories/tags, iterate the post type's public
custom taxonomies (`get_object_taxonomies($post, 'objects')`, skip `category`/
`post_tag` and non-public) and emit each as its own YAML list keyed by the
taxonomy name. Guard behind a filter `sysmda_front_matter_taxonomies` (array of
taxonomy slugs; default = all public custom ones) so sites can opt out/curate.
Add conformance coverage in `tests/run-tests.php`.

**Step 2 — ACF structured rendering (larger).**
Extend `AcfIntegration` so complex ACF field types render as clean Markdown
instead of being dumped/ignored:
- **Repeater** → repeated block/list per row.
- **Flexible Content** → section per layout.
- **Relationship / Post Object** → Markdown links to the related items' titles
  (+ `.md` URL where servable).
- **Gallery** → image list with `alt`.
Drive it from user-configured field keys (build on the existing
`sysmda_acf_field_keys` filter and the panel's ACF fields). Keep each field type
in a small dedicated renderer method; unknown types fall back to the current
text behaviour. Everything still flows through the DOM/Markdown pipeline so
exclusions and absolute-URL resolution apply.
- **Per-post-type Markdown template**: park as a **later** sub-step behind a
  filter (`sysmda_markdown_template`, post-type-keyed) — do not build UI for it in
  this PR; note it in AGENTS.md *Open / to do* instead of bloating the panel.

**Tests:** ACF logic is hard to unit-test without ACF; extract the pure
transformation (row/layout → Markdown string) into testable helpers with array
fixtures, and verify the wiring at WP level on the staging stack (GeneratePress/
GenerateBlocks + ACF).

**Touched:** `src/MetadataBuilder.php`, `src/AcfIntegration.php`,
`tests/run-tests.php`, `AGENTS.md`/`AGENTS.it.md` (Current state + Filters),
`readme.txt`, `README*.md`.

**Acceptance:** custom taxonomy appears in front matter for a CPT with a custom
taxonomy; a Repeater/Flexible/Gallery/Relationship field renders as readable
Markdown; core front matter unchanged (F1 golden test still green).

---

## Suggested sequencing

1. **PR 1 = F1** (docs + conformance tests) — patch bump. Unblocks the rest.
2. **PR 2 = F2** (diagnostics) — minor bump.
3. **PR 3 = F3 Step 1** (custom taxonomies) — patch/minor bump.
4. **PR 4 = F3 Step 2** (ACF structured) — minor bump.

Each PR: branch → push → open PR to `main` → user squash-merges → user runs
`bash bin/release-tag.sh` from the Mac. Do not push `main`, do not push tags
(web proxy rejects tag pushes).
