# The `0.50.0` external review — findings and what was done about each

**This file is the reasoning, not the status.** What is still open, and what
unblocks it, is in [`STATUS.md`](STATUS.md) — one place, so the two cannot
disagree. Read this one when you pick an item up: every entry records the
measurement that established it, the alternatives that were rejected, and the
acceptance it owes, none of which should be re-derived.

Of the eight findings, **six are shipped** — R1, R4, R6 and R7 in `0.50.1`; R2
and R5 in `0.51.0` — along with B1, PERF1 and PERF2 in `0.52.0`. **R3** stays
parked on one SQL query and **H1** carries a recommendation to decline; both
are in `STATUS.md`, with their gate.

Finding IDs are the review's own, kept so the two documents can be read side by
side.

The rest of this file is the reasoning behind what already shipped. It is kept
because each entry records a measurement or a rejected alternative that would
otherwise be re-derived — not because any of it is pending.

## Where things live

- The source is an independent code review of `0.50.0` (commit `a5ab171`) that
  ran the pure suite, PHPCS, and a real WordPress install over HTTP. That
  document lives in the **private companion repository**
  (`private-security/code-review-0.50.0-2026-09-07.md`), where it was written
  because it carried the reproduction of an unfixed disclosure defect. That
  defect is R1, now fixed, so everything in this file is stated in the open.
- The table work has its own record in
  [`markdown-fidelity-plan.md`](markdown-fidelity-plan.md), both phases shipped
  (it lived in the private companion repository until then).
- Durable decisions from all of this are in `AGENTS.md`; the two public
  contracts are `docs/output-format.md` and `docs/filters.md`; the
  real-WordPress checks are in `docs/staging-acceptance.md` and `AGENTS.md`'s
  *Tests (acceptance)*.

Finding IDs (R2, R3, R5, B1, PERF1, PERF2, H1) are the review's own, kept so the
two documents can be read side by side.

## Shipped in `0.50.1`

Each was verified failing on the reviewed commit before being fixed — the tests
were added first, watched fail, and only then made to pass.

| ID | Fix | Where |
|---|---|---|
| R1 | A password-protected synced pattern is no longer expanded — into the body, the front-matter `description`, or the enriched `/llms.txt` | `BlockCleaner::expand_reusable()` |
| R4 | Dot segments are resolved in the path only; a trailing `..` keeps its slash; `/a//../b` resolves to `/a/b` | `ContentRenderer::absolutize()`, `resolve_dot_segments()` |
| R6 | A `<dl>` grouping its pairs in `<div>` children is flattened rather than deleted, and no unrecognized list is deleted while it still carries text | `ContentRenderer::flatten_definition_lists()` |
| R7 | `304` only on `GET`/`HEAD`; a non-read request on the canonical permalink is left to WordPress | `MarkdownController::is_read_request()`, shared with `LlmsTxtController` |

R1 also has a durable decision in `AGENTS.md` ("A password-protected synced
pattern is never expanded, anywhere"), with the corollary that generalizes it:
**a referenced object's eligibility is never implied by the referring post's.**

## Shipped in `0.51.0`

| ID | Fix | Where |
|---|---|---|
| R5 | A plugin version change bumps the cache salt, so an upgrade stops date-only revalidation | `AdminSettings::maybe_bump_for_plugin_version()` |
| — | **`Last-Modified` is withheld whenever the date is not a usable validator** — found while measuring R5, and the reason R5's own fix would otherwise have been a no-op on most hosting | `MarkdownController::advertised_modified_timestamp()` |
| R2 | A Bricks leaf's span now carries its ancestors' classes, so an exclusion on a container reaches the description fallback and the enriched `/llms.txt` | `BricksAdapter::lineage_classes()` |
| — | **Table grids** (Phase 2 of [`markdown-fidelity-plan.md`](markdown-fidelity-plan.md), not a review finding): a table with no header of its own keeps its first row as data, and `colspan`/`rowspan` are expanded so values stay in their own column | `ContentRenderer::normalize_tables()` |

**The second one was not in the review, and it changes how R5 has to be read.**
The recommended fix below works by making `date_is_strong_validator()` return
false. That predicate governs only the plugin's *own* conditional handling,
while the response went on advertising `Last-Modified` regardless — and any
intermediary may revalidate against that header without consulting PHP.
Measured on `sma-bricks.instawp.co` (nginx → Apache): an anonymous
`If-Modified-Since` on a post whose dependency fingerprint had *already*
switched the date off here still came back `304` with no body, because nginx's
always-on `not_modified` filter downgraded the plugin's fresh `200`. Proved to
be the proxy and not the plugin by emptying the body cache first and watching
that same request repopulate it **with the new content**. Both durable
decisions are in `AGENTS.md`; the acceptance check is item 24 there and a new
row in `docs/staging-acceptance.md`.

Generalise it rather than filing it under nginx: **a validator the plugin will
not honour must not be sent.** Same rule as the weak ETag, applied to the other
validator.

Two P2 findings Codex raised on PR #140 were reproduced and fixed in the same
release, both in the table pass: a header row carrying a `colspan` was emitted
as a data row (the placeholder cell no longer changes a `<th>` into a `<td>`,
and the header check now runs before the grid is filled), and `rowspan="0"` —
valid HTML meaning "the rest of this row group" — was coerced to `1`, which
reproduced the exact column shift the pass exists to prevent.

## The items in detail

Open items first in intent, but kept in the review's own order so the two
documents read side by side. Each heading says whether it is shipped or open.

### 1. B1 — nested Bricks templates — **shipped in `0.52.0`**

The measurement this plan made blocking was taken on 10 September 2026, on
`sma-bricks-instawp-co` (Bricks 2.3.12; `BricksAdapter.php` and
`MetadataBuilder.php` are byte-identical to the reviewed commit, so the result
applies to current code). A `page → outer template → inner template` chain was
built, the `.md` warmed and its `ETag` recorded, then **only the inner
template** was edited the way a Bricks editor save does (tree meta plus a post
update moving `post_modified_gmt`).

| | before | after editing only the inner template |
|---|---|---|
| Rendered document | `INNER_SENTINEL_V1` | `INNER_SENTINEL_V2_CHANGED` — **changes** |
| `BricksAdapter::fingerprint()` | `blob 016a5c…` / `templates 65d707…` | **identical** |
| `post_modified_gmt`, page and outer template | 16:44:25 | unchanged |
| Body served on `.md` | V1 | **V1 — stale** |
| `If-None-Match` with the prior `ETag` | — | **304** |

So the answer to the plan's own question — "did the body change while the
validator did not?" — is **yes**, over anonymous HTTP, and the stale body
persists for the full cache TTL (86400 s by default) because nothing invalidates
the page: saving the inner template clears *its* entry, not the page's.

The rendering half is not a surprise once `Element_Template::render()` is read:
it loads the referenced template's `_bricks_page_content_2` and renders it
through the `[bricks_template]` shortcode, which walks a nested `template`
element the same way. The recursion is real, and `referenced_template_fingerprint()`
walks only the page's own top-level elements.

**Implemented** as `BricksAdapter::collect_template_refs()`: a bounded,
deduplicated recursive walk with cycle protection, following the shape of
`MetadataBuilder::collect_pattern_refs()`. `$seen` is both the cycle guard and
the deduplicator; `MAX_TEMPLATE_DEPTH` (10) is the backstop behind it, for a
chain the set cannot describe.

**Cost, measured on the same staging rather than assumed** — this runs on every
request, `304`s included. The walk adds one `get_post()` plus one
`get_post_meta()` per *distinct* template reached: **0.005 ms warm, 0.43 ms
cold**, so 0.05 ms / 4.3 ms at the depth cap, against the ~1000-1200 ms `.md`
TTFB. A page with no `template` element pays nothing at all, which is every
page on a site that does not use Bricks templates. For scale, the existing blob
hash is ~0.09 ms on a 60-element tree, and `fingerprint()` as a whole measured
0.0143 ms on the staging's real 6-element page.

Covered in the suite: a nested edit, reassignment, a missing template being
created and then deleted, a two-template ring, a self-reference, a template
referenced twice contributing once, and both sides of the depth cap. The
negative control is the point — disabling the recursion flips exactly the four
assertions that depend on it.

This is **not** the documented `cid` component limitation. Do not close it by
pointing at that one, and do not widen the fix to components.

**One caveat on the fixture**: the chain was built by writing the Bricks meta
directly rather than through the real editor, because this environment has no
browser. The rendering path exercised is Bricks' own, and the fingerprint half
is pure plugin code, so neither depends on how the tree got there — but a
future pass through the real editor would close the last gap. The fixture was
removed from staging afterwards.

### 2. R5 — a plugin upgrade must invalidate date-only revalidation

**Shipped in `0.51.0`** — kept here in full because the reasoning is what the
durable decision in `AGENTS.md` compresses, and because the second half above
is only legible against it.

**The defect.** `cache_version()` folds in `SYSMDA_VERSION`, so an upgrade moves
every `ETag`. `date_is_strong_validator()` does not, so a client sending only
`If-Modified-Since` — no `If-None-Match` — is still answered `304` after an
upgrade that changed how content converts. The review demonstrated exactly such
a change between `0.49.3` and `0.50.0` (image-label escaping) with the post's
modification time untouched.

Narrow in practice: a client that received both validators sends both, and
`If-None-Match` takes precedence. It bites IMS-only clients — some crawlers and
proxies, `curl -z`, an intermediary that strips `ETag`.

**And narrower still, in a way worth knowing before estimating the impact.**
Codex raised this independently on PR #136 and reached the same fix, but framed
the exposure as a client keeping "the pre-fix Markdown indefinitely" after
`0.50.1`. That overstates it, because the date path is *already* off for most of
the posts this release changed. `date_is_strong_validator()` refuses the date as
soon as `dependencies_fingerprint()` is non-empty, and
`MetadataBuilder::collect_pattern_refs()` (`MetadataBuilder.php:362`, walked
first) adds a part for **every** `core/block` reference in the post —
independently of the referenced pattern's status or password
(`MetadataBuilder.php:424-446`). So a post that references a synced pattern
always has a non-empty fingerprint, and that is precisely the population of R1:
**for content the plugin itself reads, the protected-pattern disclosure fix
reaches every client.** That covers all three built-in sources — `post_content`
(`:362`), the ACF source fields named through `sysmda_acf_field_keys` (`:489`)
and the panel's extra meta keys (`:541`) — the latter two walking their own
values for references, and each adding a part per configured key regardless.

**One residual, and it is the documented one** (raised by Codex on PR #137,
verified): `ContentRenderer::render()` applies `sysmda_markdown_source_content`
*before* parsing blocks (`ContentRenderer.php:62,77-78`), and `render_appended()`
does the same for `sysmda_markdown_appended_html`, so a site whose **own
callback** injects a `core/block` gets that pattern expanded into the body while
`collect_pattern_refs()` — which reads the raw `post_content` — never sees it.
With no other dependency on the post the fingerprint stays empty and the date
survives, so such a site can still be answered `304` with a pre-fix body. This
is the contract `docs/filters.md` already states — content a site injects owes
`sysmda_markdown_cache_dependencies` — and it is **wider than R5**: that site
already gets a stale `304` whenever the injected pattern itself changes, with or
without an upgrade. Fixing R5 does not close it and should not try to; the point
here is only that "reaches every client" is a statement about the built-in
paths.

What is genuinely exposed is a post carrying *no* out-of-post dependency —
no synced pattern, no featured image, no Rank Math description, no ACF or extra
meta field, no selected taxonomy — whose salt is older than its own
modification date, and whose output an upgrade changed. For `0.50.1` that means
an R4 post (a relative link whose query or fragment contains `../`) or an R6
post (a `<dl>` grouping its pairs in `div` children). Real, and a fidelity
problem rather than a disclosure one. The fix is still worth doing on its own
terms: the guarantee in `AGENTS.md` — a `304` means the body would be identical
— does not currently survive an upgrade, and the next release that changes
conversion may well touch plain posts.

**Recommended fix — bump the cache salt when the version changes.** The
mechanism already exists and its contract is exactly this shape: a site-wide,
rare invalidation, and `date_is_strong_validator()` already refuses the date
once the salt is newer than the post. Compare a stored `sysmda_version` option
against `SYSMDA_VERSION`, and on a difference mark the existing pending salt
bump and store the new version — writing both in the same `shutdown` flush
`AdminSettings` already uses.

Why this over the review's two suggestions:

- it costs no policy change — `If-Modified-Since` stays honoured and the
  public conditional-request contract is unchanged, unlike "stop honouring IMS";
- it needs no deployment hook, so it covers a zip upload, FTP, WP-CLI, and a
  Git deploy identically: the check runs on the first request that sees the new
  files. Do **not** hang it on `upgrader_process_complete`, which only fires for
  one of those paths;
- **it costs nothing in cache terms**, which is the part worth checking before
  worrying about a site-wide bump: `SYSMDA_VERSION` is already inside both
  `MarkdownController::cache_version()` and `LlmsTxtController::cache_version()`,
  so every cached body is *already* invalidated by an upgrade. The salt bump adds
  only the missing date-guard consequence;
- the option is per-blog, so multisite behaves correctly with no extra work.

**Watch for:** the check runs on front-end requests too, so it must be a single
autoloaded option read on the hot path and a write that happens once. Two
concurrent requests immediately after an upgrade may both bump; harmless (an
extra invalidation), but do not "optimize" it with a transient lock that can
outlive the upgrade.

**Acceptance.** A conversion-changing upgrade with an untouched post must serve
the new body to an IMS-only client; `ETag` precedence unchanged; a post saved
*after* the upgrade regains the date path; settings saves, dependency removal
and same-second invalidations still behave; authenticated and cache-disabled
requests unchanged. Add the version transition to the pure suite by driving the
stored option directly.

### 3. R2 — Bricks description fallback loses ancestor exclusions — **shipped in `0.51.0`**

**The defect.** `BricksAdapter::leaves_markup()` walked the flat element array
and wrapped each text-bearing element in a span carrying only *its own* classes.
Bricks stores `parent`/`children` relationships, so a container marked
`md-exclude` never reached its text child, and
`ContentRenderer::strip_excluded_content()` had nothing to match on.

**Reproduced live before fixing**, on the staging page's real tree (Bricks
2.3.12, plugin 0.49.3 as installed), with `md-exclude` moved onto the container
that holds the text leaves:

| | result |
|---|---|
| `md-exclude` present on the rendered wrapper Bricks emits | yes |
| Body, after `strip_excluded_content()` | sentinel **absent** — correct |
| Description source, after the same pass | sentinel **present** — the defect |

The tree was restored immediately afterwards.

**The fix.** `lineage_classes()` builds a parent map once per tree and gives
each leaf's span its ancestors' classes as well as its own. Concatenating is
enough because the exclusion pass matches any element carrying the class;
rebuilding real nesting would cost more and decide nothing extra. A missing
parent, a cycle and a `MAX_ANCESTOR_DEPTH` backstop all end the walk rather
than loop, and a truncated lineage degrades to the old behaviour for that one
leaf. `element_class()` no longer emits a bare `brxe-` token for an ancestor
with no usable name.

Seen to fire: reverting `lineage_classes()` to the leaf's own classes flips
four assertions, including the end-to-end one that runs the produced markup
through the shared exclusion pass.

**Still true, and deliberately unchanged:** no hardcoded exclusion list inside
the adapter, and no rendering of posts through Bricks to obtain a description
(`/llms.txt` builds N of these). `post_content` is still never used for a
builder-handled post.


### 4. R3 — synced-pattern instance overrides — **measured, inconclusive**

**The defect.** `BlockCleaner` replaces a `core/block` node with the referenced
pattern's parsed blocks and drops the reference's own `content` attribute.
WordPress resolves pattern overrides through block *context* provided by the
`core/block` instance, so the plugin publishes the pattern's default text where
the page shows the per-instance override. Confirmed against real WordPress
output by the review.

**Why it is last despite being a genuine fidelity defect.** It is the most
invasive fix of the set, and its audience may be empty on any given site.
Overrides need WordPress 6.6+ (the plugin's declared minimum is 6.1) and a
pattern deliberately authored with `core/pattern-overrides` bindings.

**The measurement was taken on 10 September 2026, and it does not decide it.**
Three connected WordPress installs were scanned for `<!-- wp:block` carrying a
`"content":` attribute, and for `wp_block` posts declaring
`core/pattern-overrides` bindings:

| Site | Posts scanned | Referencing a pattern | With instance overrides | `wp_block` posts | Patterns declaring overrides |
|---|---|---|---|---|---|
| `sma.instawp.co` | 34 | 0 | 0 | **0** | 0 |
| `sma-bricks.instawp.co` | 34 | 0 | 0 | **0** | 0 |
| `hvf.instawp.co` | 23 | 0 | 0 | **0** | 0 |

Literally this satisfies "no occurrences, no work". **Do not close it on that
basis.** None of the three corpora contains a single synced pattern, so the
denominator is zero and the result cannot distinguish "nobody authors
overrides" from "these three sites do not use patterns at all". The corpus that
would settle it is the production reference site, which was not connected for
this measurement. Re-run there before spending anything:

```sql
SELECT ID, post_type, post_title FROM wp_posts
WHERE post_status = 'publish'
  AND post_content REGEXP '<!--[[:space:]]*wp:block[[:space:]]*\\{[^}]*"content"[[:space:]]*:';
```

R3 therefore stays **parked, not closed**, at the cost of one query to reopen.

**If it is built.** Core (`wp-includes/blocks/block.php`) attaches the parsed
pattern blocks as the `core/block` instance's inner blocks and lets its declared
context flow down. Two adaptations are possible and both need feature detection
rather than a version test:

- render the cleaned pattern blocks through a `WP_Block` constructed with
  `array( 'pattern/overrides' => $attributes['content'] )` as available context;
- or keep a wrapper node through the cleaner so the association survives to
  render time, instead of flattening the expansion into the sibling list.

**Rejected:** calling core's own `render_block_core_block()` on the original
reference. It re-reads the pattern's `post_content` itself, so the plugin's
recursive block-name and class exclusions never run inside the pattern — and it
would reintroduce R1 unless core's password check is relied on, which is a
guarantee about another component's internals.

**Acceptance.** Two instances of one pattern with different override text stay
different; non-overridden fields keep their defaults; nested patterns and several
named blocks keep their context; exclusions, code-region masking and the cycle
guard still hold; behaviour on a WordPress without overrides is unchanged.

### 5. PERF1 / PERF2 — **both shipped in `0.52.0`**

- **PERF1 (HEAD).** `serve_markdown()` renders the body even for `HEAD`, which
  the server then discards. An early exit after the headers is two lines. Do it
  as part of whatever next touches that method, and **do not present it as a
  performance feature**: measured on this project, conversion is ~8.6 ms against
  a ~1000–1200 ms TTFB dominated by the WordPress boot. One caveat worth stating
  in the commit: a filter on `sysmda_markdown_output` with side effects stops
  running on `HEAD`.
- **PERF2 (compute once).** The IMS path computes the dependency fingerprints
  in `cache_version()` and again in `date_is_strong_validator()`. The duplication
  is not the cost, it is the *drift* — R5 exists precisely because the two
  encode the same knowledge and disagreed. **`0.51.0` moved the balance and did
  not resolve it.** `serve_markdown()` now computes the advertised date once and
  hands it to both callees, which removes the drift *between the header and the
  comparison* — the one that mattered — but it also means
  `date_is_strong_validator()` runs on every request rather than only on the IMS
  path, so the fingerprints are computed twice per request where they used to be
  computed once. That was taken deliberately, and **measured rather than waved
  through**, because `dependencies_fingerprint()` is not the cheap hash it looks
  like — it runs `parse_blocks()` over the whole `post_content`, and again over
  every referenced synced pattern. Timed against real WordPress on
  `sma.instawp.co`: **0.33 ms** on a representative 18 KB article and **1.0 ms**
  on a deliberately large 60 KB one. So the doubling costs 0.03–0.1% of the
  ~1000–1200 ms `.md` TTFB the WordPress boot already dominates, `304`s
  included — small enough to accept for the correctness the single value buys,
  and large enough that it should not be doubled again without checking. A
  request-local snapshot of both fingerprints would return it to one and is now
  the whole of PERF2. Filters may be stateful, so the changed evaluation count
  has to be deliberate either way — and note that a snapshot *reduces* the
  count, which `docs/filters.md` already permits by requiring those callbacks to
  be cheap and side-effect-free.

  **What shipped is narrower than "request-local", and the narrowing is the
  finding.** A snapshot keyed by post ID and held for the whole request was
  written first, and broke fourteen existing assertions — every one of them a
  case where the configuration legitimately changes between two evaluations for
  the same post. That is not a test artefact: a memo living longer than one
  response outlives a settings change, or a save, in the same process.
  `serve_markdown()` therefore computes the pair once and **hands it to both
  callees**, the same shape `0.51.0` already used for the advertised date, and
  every caller may omit it and have it computed — which is what leaves
  `prewarm()` (one `cache_version()` call, no second reader) untouched.

### 6. H1 — literal text in the `# Title` — **recommendation: decline, or do it narrowly**

The H1 is assembled by concatenating the stripped title after `# `, so a title
reading `Literal *stars*` publishes as emphasis. The plugin's own `0.46.1` and
`0.47.1` decisions ("a text field is text") argue for escaping it.

**The review's suggested remedy does not work, and this was measured** against
`league/html-to-markdown` 5.1.1 as pinned:

```
escape_inline( 'Tips & Tricks' )     => 'Tips &amp; Tricks'
escape_inline( 'Under_scores_here' ) => 'Under\_scores\_here'
```

`escape_inline()` runs `htmlspecialchars()` before converting, so reusing it puts
an HTML entity in the H1 of every title containing an ampersand — common — and
escapes intraword underscores that CommonMark does not treat as emphasis anyway.

So the options are: leave it (the output contract already describes the current
assembly), or write a title-specific escaper covering only the characters that
are active at the start of and inside a heading line, update
`docs/output-format.md` and the golden fixtures in the same PR, and accept that
every title containing `*`, `_` or `[` changes bytes. Given the churn against a
defect nobody has reported, leaving it is defensible — but if it is taken,
it must be the narrow escaper, never `escape_inline()`.

## Working notes for whoever picks this up

- **One branch, PR to `main`, the maintainer squash-merges.** B1 is independent
  of everything else here and can go on its own.
- **Add the failing test first and watch it fail.** Every fix in `0.50.1` and
  `0.51.0` was confirmed that way, and it earned its keep three times in
  `0.51.0` alone: the table pass reproduced both mistakes the private plan had
  already recorded, plus a third (a row's width computed as
  `$column + count($occupied)`, double-counting positions a rowspan had already
  stepped over). None of the three was visible by re-reading the code.
- **A control that does not fire is information too.** Reverting the
  header-check reorder in `normalize_tables()` left its fixture passing, which
  is how it emerged that the operative fix for that defect was the placeholder
  mirroring the spanning cell's tag, not the ordering. Both are kept, each on
  its own merits — but do not claim a guard is load-bearing until a control has
  actually shown it.
- **The pure suite is the fast gate and it has blind spots.** R1 and R3 were
  both invisible to it until a fixture existed for the shape, and until
  `0.51.0` no test could see an emitted header at all — which is how
  `Last-Modified` went out on responses whose date the plugin had already
  judged unusable. `tests/namespaced-stubs.php` now shadows `header()` inside
  the plugin namespace; use it rather than inferring what a response carried.
- **Some things only real WordPress can show.** B1's rendering half, R2's live
  reproduction and the nginx `304` finding all needed a real install. The two
  staging sites are `instawp_sma` (general) and `sma-bricks-instawp-co` (the
  only one with Bricks). Assert `home_url()` before writing to either, and
  remove every fixture afterwards.
- **The acceptance lists are current.** `docs/staging-acceptance.md` and
  `AGENTS.md`'s *Tests (acceptance)* now cover the protected pattern, method
  handling, the validator rules, the upgrade invalidation, table grids and the
  Bricks container exclusion — so a release pass exercises all of them.
