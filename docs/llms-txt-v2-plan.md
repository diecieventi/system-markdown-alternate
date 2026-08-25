# llms.txt v2 — review and update plan

> Research/review, recorded. Status: **committed, tracked in `AGENTS.md`
> ("Open / to do") — implementation not started.** Written against
> `main @ 0.48.0`, 25 August 2026, in response to a "check llms.txt v2 and
> tell me what to update, don't build anything" request; this plan document
> itself was committed and merged the same day (no plugin code touched). The
> maintainer has not yet greenlit implementing `rel="describedby"` (§3) —
> that decision is expected to be revisited within days rather than parked
> indefinitely, per the `AGENTS.md` entry.

## 1. What actually changed in v2

Jeremy Howard / Answer.AI published `llms.txt` v2 on **10 August 2026** —
the first revision since the original spec. Source: `https://llmstxt.org/`
and its `changes.html` page. The framing given in the announcement is
"updated based on what I learned from two years of adoption"; it is a
tightening of an existing spec, not a rewrite. Five changes matter here:

1. **New discovery link relation: `rel="describedby"`.** A page can now
   point at the `llms.txt` file that describes it, the same way it already
   points at its own Markdown alternate:
   ```
   Link: </docs/page.html.md>; rel="alternate"; type="text/markdown",
         </docs/llms.txt>; rel="describedby"
   ```
   Both relations may be expressed as an HTML `<link>` element or an HTTP
   `Link:` response header. `rel="alternate" type="text/markdown"` **already
   existed** as the way to point from a page to its Markdown twin — v2 keeps
   it as-is. `rel="describedby"` pointing at the `llms.txt` file is **new in
   v2**.
2. **Markdown URL pattern is now either shape.** v1 mandated appending `.md`
   to the full URL (`page.html.md`). v2 also allows replacing the extension
   (`page.md`), because "some publishing tools instead replace the
   extension." Sites without a file extension in their permalinks (the
   normal WordPress case) produce the same URL either way.
3. **Path coverage is now explicit.** "A file covers the pages under its
   path, and the most specific file applies" — formalises support for more
   than one `llms.txt` at different subpaths of a site (e.g. `/llms.txt` and
   `/docs/llms.txt`), each covering its own subtree.
4. **Context-expansion tooling is dropped from the spec.** `llms_txt2ctx`
   and the associated "hand this file to a tool that expands the links for
   you" story are no longer part of the proposal.
5. **`## Optional` loses its mechanical meaning.** In v1 the `Optional` H2
   heading was more than a label: an expansion tool was expected to treat
   that section as droppable when trimming to a context budget. v2 keeps the
   heading as an allowed, conventional section name, but "they no longer
   carry mechanical semantics" — nothing is supposed to auto-process it any
   differently from any other H2.

The consumption model is reframed as a consequence of (4): "agents view or
search the `llms.txt` to find what they need, then follow the relevant
links, which should point to LLM-friendly content" — i.e. `llms.txt` is a
navigation aid an agent reads and follows links from, not an input to an
automatic pre-processing pipeline. That is a framing change with no spec
mechanics attached to it.

`llms-full.txt` (a single file concatenating a site's whole content) is not
part of the formal spec in either version — it is a separate community
convention some tools produce. v2 does not mention it, so there is nothing
to reconcile here.

## 2. Where the plugin already stands

Checked against the current code (`MarkdownController.php`,
`LlmsTxtController.php`, `docs/output-format.md`):

| v2 point | Current plugin behaviour | Gap |
|---|---|---|
| `rel="alternate" type="text/markdown"` (page → its `.md`) | Implemented twice: `print_alternate_link()` (HTML `<link>` in `wp_head`) and `send_alternate_link_header()` (HTTP `Link:` header, `template_redirect` last priority) — both gated on the same `is_negotiable_request()` predicate. | None. Already matches the spec's example verbatim in both HTML and header form. |
| `rel="describedby"` (page → the `llms.txt` that describes it) | **Not implemented anywhere.** Neither `print_alternate_link()` nor `send_alternate_link_header()` emits it. | **Real gap** — see §3. |
| `.md` URL pattern (append vs. replace extension) | WordPress permalinks are extensionless (`/my-post/`), so `MetadataBuilder::markdown_url()` always produces `/my-post.md` — the same string under either reading of the v2 rule. **Exception: Plain permalinks** (`?p=123`). There the `.md` suffix has nothing to attach to, so `markdown_url()` falls back to `?format=markdown` — a query-string representation, which is neither of v2's two URL shapes (`page.html.md` / `page.md`). This is not a v2 regression (v1's single pattern didn't cover a query-string form either), and it is **already documented**: `docs/output-format.md` line 72 states the `markdown_url` fallback explicitly, and its HTTP contract section (~L450-452) confirms the same URL is what the alternate link points at for plain permalinks. | None. The "pretty permalinks match either v2 reading" verdict only covers that one case, but the Plain-permalink exception is already an accurate, standing part of the output-format contract — nothing to write, nothing to implement. |
| Path coverage / multiple `llms.txt` files per subtree | The plugin generates exactly one `/llms.txt` at the site root, covering the whole site. That is a valid, fully spec-compliant single-file case (a lone root file trivially "covers everything under its path"). | None. Multi-file coverage would only matter for a site wanting a *second*, section-scoped `llms.txt` — no such feature exists or has been requested; same shape as the already-declined homepage-index and multilingual-`llms.txt` items in "Open / to do" (real demand first). |
| `llms_txt2ctx` / context-expansion tooling | Never implemented, never referenced. | None. |
| `## Optional` semantics | `LlmsTxtController` emits `## Optional` for enriched-mode overflow, with a code comment calling it "an untranslated llms.txt specification keyword" (`LlmsTxtController.php` ~L17, ~L296, ~L443) — but the plugin was never doing any *mechanical* processing tied to that heading; it is already just a label the plugin puts overflow posts under. | **Cosmetic only.** Nothing to change in behaviour. The code comments describe the heading as carrying spec meaning it never actually acted on — worth a one-line correction so a future reader doesn't infer mechanics that neither v1 nor v2 required *this plugin* to implement. |

So: four of five v2 changes require no code change at all, and the plugin's
existing Markdown-alternate discovery already matches the v2 example
byte-for-byte. The one substantive gap is `rel="describedby"`.

## 3. The one real gap: `rel="describedby"`

**What it would do:** on every canonical singular URL where the plugin
already advertises `rel="alternate" type="text/markdown"` (i.e. wherever
`print_alternate_link()` / `send_alternate_link_header()` fire today), also
advertise a `rel="describedby"` link pointing at the site's `/llms.txt` —
in both the HTML `<link>` and the HTTP `Link:` header, mirroring the
existing alternate exactly.

**Why it fits the plugin's own stated philosophy:** the whole point of the
existing alternate-link machinery is "one predicate, everything else by
construction" (`is_negotiable_request()` decides once; both the header and
the `<link>` follow it). `describedby` is the same shape one level up: it
tells a crawler/agent landing on *any* page "the file that indexes this
site for you lives at `/llms.txt`" — which is exactly what `/llms.txt`
is *for* (`LlmsTxtController`), and currently undiscoverable except by an
agent already knowing to probe `/llms.txt` by convention.

**Gating, proposed:**
- Only when `/llms.txt` is enabled (`get_option( 'sysmda_llms_txt_enabled', '1' )`
  — same option `LlmsTxtController::maybe_render()` already checks).
- Reuse the exact same `is_negotiable_request()` guard the alternate link
  uses, for the same reason documented in `AGENTS.md` ("what declares
  `Vary: Accept` and what advertises a Markdown alternate must stay in
  step" — a third, differently-gated predicate is exactly the kind of fork
  that guide already warns against).
- **Deliberately not conditioned on the current post actually being listed
  inside `/llms.txt`.** `describedby` describes the *resource* (the whole
  site's LLM-facing index), not a promise that this exact URL has its own
  entry — `/llms.txt` caps entries per post type
  (`sysmda_llms_txt_max_posts`, default 500) and a post beyond that cap
  would otherwise never get a `describedby` header under a stricter
  reading, which is not what the relation means.

**Mechanics:**
- HTML: one more `printf()` line in `print_alternate_link()`, or a second
  `<link>` tag alongside the existing one.
- HTTP header: `send_alternate_link_header()` already has
  `link_header_has_alternate()` for duplicate-detection against
  already-sent `Link:` fields (handles multiple/comma-joined fields and
  quoted parameters). A `describedby` companion needs the analogous
  duplicate check — the existing helper is written narrowly for
  `rel="alternate"` + `type="text/markdown"`, so it would need a small
  generalisation (matching on `rel` and target URL, not on `type`) rather
  than a second copy of the parsing logic. Two header lines
  (`header( '…', false )` twice) is fine — WordPress and browsers both
  treat repeated `Link:` fields the same as one comma-joined field.
- New filter: something like `sysmda_markdown_llms_txt_link` (or reuse
  `sysmda_llms_txt_enabled` as the sole gate with no new filter at all —
  open question, see §5) to let a site suppress just this relation without
  touching the alternate link or `/llms.txt` itself.

**Scope check against the plugin's own filter-classification rule**
(`docs/filters.md`, "Two levels, and the axis is what the hook is anchored
to"): a `describedby` toggle would be anchored to the `/llms.txt` on/off
setting, i.e. **Stable**, same class as the settings-transport hooks
already documented there.

## 4. What this is *not*

Consistent with the plugin's own "don't propose the same abandoned idea
twice" durable decisions, this review does **not** reopen or suggest:

- A synthesized homepage `.md` (parked, gated on real hit-counter demand).
- A second, section-scoped `/llms.txt` under path coverage (§1.3) — no
  demand, no multi-section content structure exists on the reference site.
- `llms-full.txt` — not part of either spec version; not evaluated further.
- Any change to `## Optional`'s *content* (which posts overflow into it,
  how `sysmda_llms_txt_main_posts` splits main vs. overflow) — v2 changes
  what an external tool is allowed to assume about that heading, not what
  the plugin should put under it.

## 5. Open questions for the maintainer

1. **Ship `rel="describedby"` at all?** It is the only functional gap found;
   everything else is already compliant or genuinely out of scope. Recorded
   in `AGENTS.md` as a live candidate to revisit within days — not yet
   greenlit to implement. If yes:
2. **New filter, or fold into `sysmda_llms_txt_enabled`?** A dedicated
   `sysmda_markdown_llms_txt_link` filter (Stable) gives a site "advertise
   `/llms.txt` on-page but keep discovery off `.md` pages" independent
   control; reusing the existing enabled flag is simpler but couples two
   decisions that are conceptually separate (serve `/llms.txt` vs.
   advertise it from every page).
3. **`docs/output-format.md` / HTTP contract section** would need the new
   `Link:` example added next to the existing `rel="alternate"` one (this
   is the kind of change the "would a user who read the documentation
   yesterday do something different today" rule in `AGENTS.md` answers
   "yes" to — a new advertised header is observable behaviour). Candidate
   surfaces, following that same rule's checklist:
   - `docs/output-format.md` — HTTP contract section (primary). The
     Plain-permalink query-string exception from §2 needs **no** addition
     here: it is already documented (line 72, and the HTTP contract section
     around L450-452).
   - `documentation/src/content/docs/endpoints/the-llms-txt-index.md` —
     mention that pages now link to it via `rel="describedby"`.
   - `docs/filters.md` — the new filter's entry, if one is added.
   - `AGENTS.md` — a line in "Current state" under the existing
     `rel="alternate"`/`Link:` bullet, matching how every other discovery
     mechanism is documented there.
   - `system-markdown-alternate/readme.txt` / `README.md` — likely **no**
     change; this is a discovery-mechanism refinement, not a new
     user-facing feature the "Key features" summaries need to list (same
     judgement call as `0.41.0`'s converter rewrite, which touched neither).
4. **Version bump size:** a minor bump (new observable HTTP/HTML behaviour,
   additive, no default changes to existing output) — e.g. `0.49.0`, not a
   patch — consistent with "minor for new features, patch for fixes."
5. **Acceptance test:** would extend test #13 in `AGENTS.md` ("Tests
   (acceptance)") rather than add a new numbered case — same `curl -sI`
   check, asserting the second `Link:` relation alongside the existing one.

## 6. Recommendation

Small, self-contained, and on-brand: implement `rel="describedby"` as a
direct extension of the existing alternate-link mechanism (§3), update the
five documentation surfaces in §5.3 in the same PR per `AGENTS.md`'s
per-PR documentation rule, and treat everything else in v2 as "already
compliant, nothing to do" — worth recording in `AGENTS.md` as a closed
review (same pattern as the other "reviewed, closed" entries in "To check
next time") so this comparison is not redone from scratch next time v2 (or
a v3) comes up.

**Nothing in this plan has been implemented.** This document and the
`AGENTS.md` pointer to it are committed; no plugin source file has been
touched, and `rel="describedby"` itself has not been built.

## Sources

- https://llmstxt.org/ (v2 specification)
- https://llmstxt.org/changes.html (v1 → v2 changelog)
