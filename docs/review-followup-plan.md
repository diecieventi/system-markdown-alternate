# External review follow-up — what shipped in `0.50.1` and what is left

**Status (7 September 2026): four findings fixed and shipped in `0.50.1`; five
remain, none of them started.** This document is the handoff: it records what
was done and why, and gives each remaining item a scope, a recommended
approach, the alternatives that were considered and rejected, and an acceptance
list. It is a plan, not a decision to build everything in it — two items below
close with "measure before writing code" and one closes with "probably decline".

The source is an independent code review of `0.50.0`
(commit `a5ab171`) that ran the pure suite, PHPCS, and a real WordPress install
over HTTP. The review document itself lives in the private companion
repository (`private-security/`), where it was written because it carried the
reproduction of an unfixed disclosure defect; that defect is R1 below and is now
fixed, so everything in this file is stated in the open.

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

## Remaining work, in the order it should be done

### 1. R5 — a plugin upgrade must invalidate date-only revalidation

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
**the protected-pattern disclosure fix is not affected by this defect at all**,
for any client.

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

### 2. R2 — Bricks description fallback loses ancestor exclusions

**The defect.** `BricksAdapter::leaves_markup()` walks the flat element array and
wraps each text-bearing element in a span carrying only *its own* classes. Bricks
stores `parent`/`children` relationships, so a container marked `md-exclude`
never reaches its text child, and `ContentRenderer::strip_excluded_content()`
has nothing to match on. The excluded subtree survives in the front-matter
`description` and in the enriched `/llms.txt`, while the body correctly drops it.

Scope: the fallback tier only — no Rank Math description and no excerpt. It is an
exclusion-contract inconsistency, not an authorization defect.

**Recommended fix.** Build a parent map once, then give each leaf's span the
classes of its ancestors as well as its own. Concatenating is enough because the
exclusion pass matches any element carrying the class; nesting real spans would
also work and costs more. Guard against a missing parent and a cycle with a
depth cap, and do not re-scan the tree per leaf.

**Do not:** duplicate a hardcoded exclusion list inside the adapter, or render
posts through Bricks to obtain a description (`/llms.txt` builds N of these).

**Acceptance.** Parent and grandparent exclusions; each built-in exclusion class;
a class added through the filters; excluded builder-element classes; a directly
excluded leaf; visible siblings; missing and cyclic ancestry; description and
enriched index agree with the body. `post_content` still never used for a
builder-handled post.

### 3. B1 — nested Bricks templates: **verify before writing any code**

`BricksAdapter::referenced_template_fingerprint()` records the modification date
of each `template` element's referenced post, and does not follow templates
referenced by *those* templates. In a synthetic `page → outer → inner` chain the
page's fingerprint did not move when the inner template changed.

The review is explicit that the rendering half was never verified: an unchanged
adapter hash is not proof that the rendered document changes. **So the first step
is the measurement, not the fix**, and the environment for it exists —
`sma-bricks-instawp-co`, the only staging with Bricks:

1. build the chain in the real editor;
2. warm the `.md` and record the `ETag`;
3. edit only the inner template through Bricks;
4. re-fetch: did the body change while the validator did not?

If the body does not change, the item closes with a note and no code. If it does,
implement a bounded, deduplicated recursive walk with cycle protection, following
the shape of the synced-pattern dependency walk in `MetadataBuilder`. Measure the
added cost: this runs on every request, `304`s included, and the existing budget
(~0.09 ms on a 60-element tree) is the baseline to compare against.

This is **not** the documented `cid` component limitation. Do not close it by
pointing at that one, and do not widen the fix to components.

### 4. R3 — synced-pattern instance overrides — **measure demand first**

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

**The measurement that decides it**, in the spirit of every other feature in this
project: look for `<!-- wp:block` carrying a `"content":` attribute in the real
corpus. No occurrences, no work — record the measurement and move on.

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

### 5. PERF1 / PERF2 — small, and only after R5

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
  encode the same knowledge and disagreed. Resolve R5 first, then decide whether
  a request-local snapshot still earns its keep. Filters may be stateful, so a
  changed evaluation count has to be deliberate.

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

- Branch per item, PR to `main`, the maintainer squash-merges. R5 and R2 are
  independent and can go in parallel; B1 depends on its own measurement; R3
  depends on the corpus measurement.
- Add the failing test first and **watch it fail** on the current commit — every
  one of the four `0.50.1` fixes was confirmed that way, and R1's own regression
  test proves it by asserting the description path, not just the cleaner.
- The pure suite (`php system-markdown-alternate/tests/run-tests.php`) is the
  fast gate, but R1 and R3 were both invisible to it until a fixture existed for
  the shape. When a fix concerns WordPress semantics, ask what the stubs are
  quietly asserting.
- The protected pattern and the method handling are now in both real-WordPress
  checklists — `docs/staging-acceptance.md`'s matrix and `AGENTS.md`'s *Tests
  (acceptance)* items 22 and 23 — so a release pass exercises them.
