# The `0.50.0` external review — the record, and the one finding still open

**This file is the reasoning, not the status.** What is open, and what unblocks
it, is in [`STATUS.md`](STATUS.md) — one place, so the two cannot disagree.

Of the review's eight findings plus the two performance notes, **nine are
shipped and one is declined**. Exactly one remains open: **R3**, parked on a
single SQL query. Finding IDs are the review's own.

| ID | Outcome | Where |
|---|---|---|
| R1 | Shipped `0.50.1` — a password-protected synced pattern is no longer expanded, into the body, the front-matter `description` or the enriched `/llms.txt` | `BlockCleaner::expand_reusable()` |
| R4 | Shipped `0.50.1` — dot segments resolve in the path only; a trailing `..` keeps its slash; `/a//../b` → `/a/b` | `ContentRenderer::absolutize()` |
| R6 | Shipped `0.50.1` — a `<dl>` grouping its pairs in `<div>` children is flattened rather than deleted | `ContentRenderer::flatten_definition_lists()` |
| R7 | Shipped `0.50.1` — `304` only on `GET`/`HEAD` | `MarkdownController::is_read_request()` |
| R5 | Shipped `0.51.0` — a plugin version change bumps the cache salt | `AdminSettings::maybe_bump_for_plugin_version()` |
| — | Shipped `0.51.0` — `Last-Modified` is withheld whenever the date is not a usable validator. Not a review finding: found while measuring R5, and the reason R5's own fix would otherwise have been a no-op on most hosting | `MarkdownController::advertised_modified_timestamp()` |
| R2 | Shipped `0.51.0` — a Bricks leaf's span carries its ancestors' classes | `BricksAdapter::lineage_classes()` |
| B1 | Shipped `0.52.0` — a nested Bricks template is a cache dependency, followed transitively | `BricksAdapter::collect_template_refs()` |
| PERF1 / PERF2 | Shipped `0.52.0` — no document is built for a `HEAD`; the dependency fingerprints are computed once per response and handed to both validators | `MarkdownController` |
| H1 | **Declined** — see *Markdown syntax in the `# Title` stays unescaped* in `AGENTS.md`, and the measurement in [`evaluations.md`](evaluations.md) | — |
| R3 | **Open** — below | — |

Every durable decision that came out of these is in `AGENTS.md`; the public
contracts are `docs/output-format.md` and `docs/filters.md`; the real-WordPress
checks are in `docs/staging-acceptance.md` and `AGENTS.md`'s *Tests
(acceptance)*, items 22–26. The source review itself was an independent code
review of `0.50.0` (commit `a5ab171`); it carried the reproduction of R1 while
that was unfixed, which is why it was written privately. R1 shipped, so
everything here is stated in the open and the private copy no longer exists.

Two generalisations from the shipped half are worth more than the fixes, and
both are already durable decisions in `AGENTS.md`:

- **A referenced object's eligibility is never implied by the referring
  post's** (R1). Ask it.
- **A validator the plugin will not honour must not be sent** (the
  `Last-Modified` finding). Same rule as the weak ETag, applied to the other
  validator. Measured on `sma-bricks.instawp.co` (nginx → Apache): an anonymous
  `If-Modified-Since` on a post whose dependency fingerprint had *already*
  switched the date off inside PHP still came back `304` with no body, because
  nginx's always-on `not_modified` filter downgraded the plugin's fresh `200`.
  Proved to be the proxy rather than the plugin by emptying the body cache
  first and watching that same request repopulate it **with the new content**.

## R3 — synced-pattern instance overrides — the one open finding

**The defect.** `BlockCleaner` replaces a `core/block` node with the referenced
pattern's parsed blocks and drops the reference's own `content` attribute.
WordPress resolves pattern overrides through block *context* provided by the
`core/block` instance, so the plugin publishes the pattern's default text where
the page shows the per-instance override. Confirmed against real WordPress
output by the review.

**Why it is not simply fixed.** It is the most invasive change of the set, and
its audience may be empty on any given site: overrides need WordPress 6.6+ (the
plugin's declared minimum is 6.1) and a pattern deliberately authored with
`core/pattern-overrides` bindings.

**The measurement was taken on 10 September 2026, and it does not decide it.**
Three connected installs were scanned for `<!-- wp:block` carrying a
`"content":` attribute, and for `wp_block` posts declaring
`core/pattern-overrides` bindings:

| Site | Posts scanned | Referencing a pattern | With instance overrides | `wp_block` posts |
|---|---|---|---|---|
| `sma.instawp.co` | 34 | 0 | 0 | **0** |
| `sma-bricks.instawp.co` | 34 | 0 | 0 | **0** |
| `hvf.instawp.co` | 23 | 0 | 0 | **0** |

Literally this satisfies "no occurrences, no work". **Do not close it on that
basis.** None of the three corpora contains a single synced pattern, so the
denominator is zero and the result cannot distinguish "nobody authors
overrides" from "these three sites do not use patterns at all". The corpus that
would settle it is the production reference site, which is not connected to
these sessions. Run this there before spending anything:

```sql
SELECT ID, post_type, post_title FROM wp_posts
WHERE post_status = 'publish'
  AND post_content REGEXP '<!--[[:space:]]*wp:block[[:space:]]*\\{[^}]*"content"[[:space:]]*:';
```

Zero rows on a corpus that *does* use synced patterns closes it for good.

**If it is built.** Core (`wp-includes/blocks/block.php`) attaches the parsed
pattern blocks as the `core/block` instance's inner blocks and lets its declared
context flow down. Two adaptations are possible, both needing feature detection
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
different; non-overridden fields keep their defaults; nested patterns and
several named blocks keep their context; exclusions, code-region masking and the
cycle guard still hold; behaviour on a WordPress without overrides is unchanged.

## Two working notes the shipped half earned

Kept because they are about this suite specifically, and neither is obvious
from reading it:

- **A control that does not fire is information too.** Reverting the
  header-check reorder in `normalize_tables()` left its fixture passing, which
  is how it emerged that the operative fix for that defect was the placeholder
  mirroring the spanning cell's tag, not the ordering. Both are kept, each on
  its own merits — but do not claim a guard is load-bearing until a control has
  actually shown it.
- **The pure suite could not see an emitted header at all until `0.51.0`**,
  which is how `Last-Modified` went out on responses whose date the plugin had
  already judged unusable. `tests/namespaced-stubs.php` now shadows `header()`
  inside the plugin namespace; use it rather than inferring what a response
  carried.
