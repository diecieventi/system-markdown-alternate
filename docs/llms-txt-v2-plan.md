# llms.txt v2 — review and update plan

> Research/review, recorded. Status: **committed, tracked in `AGENTS.md`
> ("Open / to do") — implementation not started.** Written against
> `main @ 0.48.0`, 25 August 2026, in response to a "check llms.txt v2 and
> tell me what to update, don't build anything" request; this plan document
> itself was committed and merged the same day (no plugin code touched). The
> maintainer has not yet greenlit implementing `rel="describedby"` (§3) —
> that decision is expected to be revisited within days rather than parked
> indefinitely, per the `AGENTS.md` entry.
>
> **§7 (26 August 2026) adds the implementation plan** for §3, written after
> reading the code rather than from the review's summary of it — which turned
> up two divergences, one of them a trap (§7.1). Still no plugin source
> touched, and still not greenlit: §7 is what to build *if* the answer to §5.1
> is yes.

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

**Gating, proposed:** *(amended — the first bullet is incomplete, see §7.1.2)*
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
  than a second copy of the parsing logic. *(Amended: the helper already
  matches on `rel` and target and never on `type` — see §7.1.1. The
  generalisation is one parameter, not a rewrite.)* Two header lines
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

## 7. Implementation plan for `rel="describedby"`

Written 26 August 2026 against `main @ 0.48.0` (`10a8b46`), by reading
`MarkdownController.php`, `LlmsTxtController.php` and `tests/run-tests.php`
rather than the review's summary of them. Not greenlit, not built: this is
what to do if §5.1 is answered yes.

### 7.1 Two corrections to §3, found by reading the code

**7.1.1 — The duplicate-detection helper is already relation-generic, and
never looked at `type`.** §3 estimates a "small generalisation (matching on
`rel` and target URL, not on `type`)". That work is already done:
`link_header_has_alternate()` matches the target URI and then delegates to
`link_value_has_relation( $link, 'alternate' )`, a helper that **already
takes the relation as a parameter**, and the `type` parameter is never
consulted anywhere in the path. The suite proves the second half rather than
leaving it to inspection — `Link alternate: an untyped alternate still
deduplicates` asserts that `rel=alternate` with no `type` is detected.

So the change is: add a `string $relation = 'alternate'` parameter to
`link_header_has_alternate()` and forward it. The default keeps every
existing call site and all twelve existing assertions valid. Do **not**
rewrite `split_link_header_value()` or the `rel` parser: they are already
correct for both relations, and they carry the comma-inside-a-URI and
comma-inside-a-quoted-parameter cases the tests pin down.

A rename is worth considering in the same edit (`link_header_has_relation()`),
since the name would no longer describe what it does. It is `public static`
only so it can be tested under CLI, and it is not part of the documented
filter or output contract, so renaming costs nothing beyond the call sites
and the test labels.

**7.1.2 — "The option is on" does NOT mean `/llms.txt` resolves, and this is
the one trap in the change.** §3's gating list names a single condition:
`sysmda_llms_txt_enabled`. But `maybe_render_llms_txt()` has a **second**
gate after it — `empty( PostSupport::supported_post_types() )` returns
without serving, implementing the durable decision "`/llms.txt` stays silent
until a content type is enabled". A `describedby` link gated on the option
alone would therefore advertise a target that **404s** on a fresh install:
the option defaults to `'1'`, and the supported-types option defaults to
empty, so the broken state is the *default* state, not an edge case.

The invariant to hold is: **never advertise a `describedby` target that
would not be served.** Reusing `is_negotiable_request()` satisfies it
transitively — that predicate already returns `false` when
`supported_post_types()` is empty — which turns §3's "reuse the same guard"
from a stylistic preference into a correctness requirement.

Record it as such in the code, because the two conditions are only in step
by construction and nothing enforces it: this is exactly the shape
`AGENTS.md` already warns about for the HTML-link vs. header guards ("two
guards written to mirror each other did not"). Anyone later broadening
`describedby` beyond negotiable requests — which is the natural thing to
want, see D1 — **must re-add the `supported_post_types()` check explicitly**
at that moment.

### 7.2 Design decisions to settle before writing code

**D1 — Which predicate gates it? → `is_negotiable_request()`, unchanged.**

The honest tension, stated so it is not rediscovered as a bug: `describedby`
describes *the site's index*, not this URL's Markdown twin, so the spec's
intent is broader than "pages that have a `.md`". Reusing the predicate
means a page vetoed by the page-builder rule, a non-enabled post type, an
`aside`-format post or a password-protected post advertises no `describedby`
even though `/llms.txt` exists and describes the site.

Take the narrow reading anyway, for two reasons that outrank the spec's
breadth here: it is the only gating that satisfies 7.1.2 without a second
copy of the `/llms.txt`-servability rule, and it keeps the plugin's own
discipline — the plugin speaks on pages where it already speaks. A static
front page of an enabled type still gets the link (`is_singular( 'page' )`
is true there), which is the single highest-value placement for an agent
landing on the site root, so the narrow reading is not as narrow as it
sounds in practice.

Broadening it later is additive and needs no deprecation. Broadening it
*carelessly* reintroduces the 404. Write that down next to the guard.

**D2 — New filter, or `sysmda_llms_txt_enabled` alone? → No new filter.**

§5.2 leaves this open. `AGENTS.md` answers it: "Do not add a filter merely
because something *could* be configurable", and "prefer few high-level
extension points". The independent control a `sysmda_markdown_llms_txt_link`
filter would buy — advertise `/llms.txt` from pages while still serving it,
or the reverse — has no stated demand behind it, and the split it models
(serve vs. advertise) is a distinction no user has asked for.

Adding a filter later is backward-compatible; removing one is not. Ship
without it and let demand justify it, which is the same order of operations
the homepage `.md`, the multilingual index and the exclusion scanner are all
waiting on. This also keeps §5.3's `docs/filters.md` surface untouched.

**D3 — Does the `.md` response advertise it too? → No, explicitly.**

Considered and declined, recorded so it is not re-litigated. The `.md`
response's header set is a documented contract (`docs/output-format.md`),
currently `Content-Type`, `X-Robots-Tag`, `Link: rel="canonical"`,
`Vary`, `ETag`/`Last-Modified`. Adding a relation there is a separate
decision about a separate contract, with its own conditional-request and
`304` implications (a `304` carries no body but does carry headers), and
v2 frames `describedby` as a property of the *page*, whose `.md` is already
reachable from that page's own alternate. Out of scope for this change.

**D4 — Version bump → `0.49.0`.** New observable HTTP and HTML behaviour,
purely additive, no existing output changes shape. Minor per semver rule.

### 7.3 The change, file by file

`MarkdownController.php`:

1. **A single source for the target URL.** Add one private helper returning
   `home_url( '/llms.txt' )` — never a hardcoded `/llms.txt`, which breaks
   on a subdirectory install, and which `maybe_render_llms_txt()` itself
   avoids by computing the home path. Both emitters call it, so the HTML
   `<link>` and the `Link:` header cannot drift.
2. **A single predicate for "should this response advertise the index?"**
   Add one private method combining `is_negotiable_request()` with the
   `sysmda_llms_txt_enabled` option check, carrying the 7.1.2 note in its
   docblock. Both emitters call it. One predicate, two emitters — the same
   shape as the alternate link, and for the same reason.
3. `print_alternate_link()` — one additional `printf()` guarded by (2),
   emitting `<link rel="describedby" href="…" />`. No `type` attribute:
   `/llms.txt` is `text/plain` and the spec's example carries no type on
   this relation.
4. `send_alternate_link_header()` (or its caller) — one additional
   `header( 'Link: <…>; rel="describedby"', false )`, guarded by (2) and by
   the duplicate check from 7.1.1 with `'describedby'` passed in. `false`
   appends, so the existing alternate field survives; repeated `Link:`
   fields are equivalent to one comma-joined field.
5. `link_header_has_alternate()` — the `$relation` parameter from 7.1.1.

Note that (3) and (4) are guarded by (2), which *contains*
`is_negotiable_request()`. The `headers_sent()` and `esc_url_raw()`
handling in `send_alternate_link_header()` already covers the new field if
the emission is placed inside that method rather than beside it — prefer
that, so there is one `headers_sent()` early return rather than two.

No changes to `LlmsTxtController.php`, `Plugin.php` (both hooks are already
registered) or any option.

### 7.4 Tests

Pure suite (`tests/run-tests.php`), which is where the mechanical part
belongs:

- The twelve existing `link_header_has_alternate` assertions must pass
  **unchanged** — that is what proves the default parameter is
  backward-compatible.
- The same matrix again for `describedby`: not-yet-sent, wrong field name,
  wrong relation, different target, token list (`rel="describedby next"`),
  case-insensitivity, repeated and comma-joined fields.
- **The cross-relation cases are the ones with new information in them**,
  because they are the only ones the existing matrix cannot already tell you
  about: an alternate field for target X must **not** satisfy a
  `describedby` query for target X, and vice versa. Same URL, different
  relation — the exact confusion a shared helper invites.

Per `AGENTS.md`'s "a guard is not done until it has been seen to fire": the
new duplicate check is a guard whose silence is its expected output, so
construct the case where another plugin has already emitted
`Link: <…/llms.txt>; rel="describedby"`, watch a second field get emitted
without the check, then add it. Do not ship it having only reasoned about
it.

What the pure suite **cannot** cover, and what therefore belongs in the
staging run: hook ordering, the 404-on-default-install case from 7.1.2, and
whether the field survives to the wire.

### 7.5 Documentation surfaces

Per the per-PR rule, in the same PR. §5.3's list, with D2 resolved:

| Surface | Change |
|---|---|
| `docs/output-format.md`, HTTP contract (~L435-452) | The new `Link:` relation next to the existing example, plus the gating sentence. This is the primary contract change. |
| `documentation/src/content/docs/endpoints/the-llms-txt-index.md` | Pages advertise the index via `rel="describedby"`. |
| `AGENTS.md`, "Current state" | One line on the existing discovery bullet; move the v2 item out of "Open / to do" into a closed review. |
| `docs/filters.md` | **No change** — D2 adds no filter. |
| `readme.txt` / `README.md` | **No change** — a discovery refinement, not a listed feature. Same judgement as `0.41.0`. |
| This plan | Status header → implemented, with the version. |

### 7.6 Acceptance

Extends test #13 in `AGENTS.md` rather than adding a numbered case (§5.5),
since it is the same `curl -sI` on the same URL:

- Servable canonical post → **both** relations present, alternate and
  `describedby`, and any pre-existing `Link` relation preserved.
- `describedby` absent from: the `.md` response, negotiated Markdown, `406`,
  feed, embed, trackback, paged comments, `<!--nextpage-->` sub-pages — i.e.
  wherever the alternate is already absent.
- **`/llms.txt` toggled off → the link disappears, the alternate stays.**
  The two are independently gated and nothing else asserts that.
- **The 7.1.2 case, on a fresh install: `/llms.txt` enabled but no content
  type selected → no `describedby` anywhere.** This is the default state of
  an unconfigured plugin and the one that would ship a link to a 404. It is
  the single most important fixture in the set; run it first.
- `home_url()` correctness: on the subdirectory staging, the advertised
  target is `/subdir/llms.txt` and it resolves.

### 7.7 Out of scope

Unchanged from §4, plus D3 (`describedby` on the `.md` response) and D2
(the suppression filter). None of the four other v2 changes require code.

## Sources

- https://llmstxt.org/ (v2 specification)
- https://llmstxt.org/changes.html (v1 → v2 changelog)
