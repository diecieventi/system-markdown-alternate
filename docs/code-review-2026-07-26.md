# Complete code review — v0.26.3 (26 July 2026)

> **Status: triaged, H1/M1/M3 fixed and M2 partly fixed in `0.27.0`.** Every finding was verified
> against the code before anything was changed; the outcome is recorded inline
> under each one. Summary:
>
> | # | Outcome |
> |---|---|
> | H1 | **Fixed in 0.27.0** — reproduced first (body changed, ETag did not) |
> | M1 | **Fixed in 0.27.0** — ruled on: protected content has no Markdown representation, cookie or not |
> | M2 | **Partly fixed in 0.27.0** — site identity now versioned; the `post_format` hook declined on purpose |
> | M3 | **Fixed in 0.27.0** — reproduced |
> | L1 | **Accepted, not fixed** — real but marginal, see the note under it |
> | L2 | **Half accepted** — the wasted render is real; the counter claim was wrong and is corrected below |
> | L3 | **Not reproduced as written, and misdiagnosed** — corrected below; the underlying flakiness was real and is fixed in `0.27.0` |
> | L4 | **Accepted, not fixed** — requires a hostile/buggy site filter |
>
> Two corrections to this document are marked **[corrected]** where they occur:
> the report was wrong about the hit counter (L2) and about the cause of the
> test failure (L3). They are corrected in place with the reasoning rather than
> quietly deleted, as with the `rename` advice in the previous review.

## Scope and outcome

This review covers the functionality that is implemented and shipped today. It
deliberately excludes every future/project item in `AGENTS.md`. No production
code was changed.

The implementation is generally careful and substantially better defended than
the earlier v0.25.0 review: eligibility is centralized, negotiated requests are
properly narrowed, dangerous filter values are sanitized, the DOM wrapper and
table/code edge cases are covered, multisite cleanup exists, and the
LiteSpeed `.htaccess` update has explicit concurrency and rollback handling.

The review nevertheless found **one high, three medium, and four low-priority
issues**. The release should not describe its ETag as a strong guarantee that
the body is identical until H1 is addressed. M1 is also a mismatch between the
documented privacy/eligibility contract and WordPress password-cookie behavior.

## Method

- Read all runtime PHP files, the bootstrap and uninstall path, admin assets,
  build/release scripts, package metadata, public documentation, and tests.
- Traced each public route (`.md`, negotiation, `/llms.txt`), cache lifecycle,
  conditional requests, post eligibility, block/shortcode rendering, admin
  saves, multisite uninstall, and LiteSpeed file writes.
- Checked extension points with hostile or malformed filter return values.
- Considered WordPress 6.1+ behavior, plain/subdirectory permalinks, multisite,
  persistent object caches, password cookies, post-meta/term-only updates,
  synced patterns, `HEAD`, and PHP 7.4 through the current runtime.
- Ran Composer installation, the project test runner, PHPCS/WPCS and the release
  build. The exact results are recorded below.

## Findings summary

| ID | Severity | Finding | Primary location |
|---|---|---|---|
| H1 | High | ETag/cache version omits several inputs that can change the body | `MarkdownController.php:598-603` |
| M1 | Medium | A password-protected post becomes servable after its password cookie is set | `PostSupport.php:91-96` |
| M2 | Medium | `/llms.txt` cache is stale after several non-`save_post` changes | `LlmsTxtController.php:92-115` |
| M3 | Medium | Malformed Accept `q` values are promoted to `1.0` | `AcceptNegotiator.php:35-50` |
| L1 | Low | `/llms.txt` headings/site metadata are not normalized as Markdown structure | `LlmsTxtController.php:132-146,162-164` |
| L2 | Low | `HEAD` performs a complete render and is counted as a hit | `MarkdownController.php:382-396` |
| L3 | Low | PHP 8.5 makes the test suite noisy and one CLI header assertion fail | `tests/run-tests.php` |
| L4 | Low | Negative list-limit filter values turn into an unlimited query | `LlmsTxtController.php:166-180` |

---

## High priority

### H1 — The strong ETag and body cache do not include every body dependency

**Where:** `src/MarkdownController.php:542-603`,
`src/MarkdownController.php:617-641`, `src/ContentRenderer.php:50-74`,
`src/BlockCleaner.php:126-153`, `src/MetadataBuilder.php`.

`cache_version()` hashes only:

1. the parent post's `post_modified_gmt`;
2. the plugin version;
3. the global settings salt; and
4. the selected-taxonomy fingerprint.

The generated representation has more mutable inputs. Examples that do not
reliably update the parent post's modification time or settings salt include:

- the content of an expanded synced pattern (`wp_block`); only the referenced
  post changes;
- featured-image assignment/alt text and Rank Math description post meta;
- ACF fields appended by the bundled integration;
- output of dynamic blocks, shortcodes, and the documented source/render/output
  filters when it depends on options, remote data, time, or another post;
- term/meta changes made through APIs that do not save the parent post.

The first four are not hypothetical extension abuse: synced patterns, featured
image metadata, Rank Math, ACF and dynamic blocks are explicitly supported by
the plugin. A cached response can therefore remain stale until TTL expiry, and
more importantly a client presenting the old ETag can receive `304 Not
Modified` even though a freshly generated body would differ. That contradicts
the comments and public contract that call this a strong validator.

**Impact:** stale Markdown for up to the configured TTL (one day by default),
and arbitrarily longer client staleness through false `304` responses. Disabling
the body cache does not fix the incorrect conditional response.

**Recommended direction:** distinguish between dependencies the plugin can
fingerprint and inherently dynamic output.

- Add stable fingerprints for bundled, knowable dependencies (selected image
  and relevant metadata, configured ACF values, referenced `wp_block` IDs plus
  their modification times/content hashes, description metadata).
- Invalidate referring posts when a synced pattern changes, or make the
  references part of the validator.
- Provide a filter for sites/integrations to contribute a validator salt.
- Consider making the ETag weak, disabling `304`, or marking the representation
  non-cacheable when output is knowingly dynamic and no complete validator can
  be produced. A weak ETag alone does **not** make a false `304` correct; it only
  describes semantic rather than byte equality.
- Add integration tests that change each dependency without touching the parent
  post and assert that both the body cache and ETag change.

**Outcome: confirmed by reproduction, fixed in `0.27.0`.** Reproduced against
the real `BlockCleaner` before any change: an article referencing a synced
pattern, the pattern edited, the article untouched — the body went from
`Prezzo: 100 euro` to `Prezzo: 250 euro` while the validator stayed
`c939d126b27b31c26f89a28aba1e4d4c`. A client holding that ETag keeps being told
`304`.

`MetadataBuilder::dependencies_fingerprint()` now folds in the synced patterns
(nested ones included), the featured image plus its modification time and alt
text, the Rank Math description, and the ACF fields the bundled integration
reads — through the same filters, so the validator cannot drift from what is
emitted. Inherently dynamic output cannot be fingerprinted and gets an explicit
extension point instead: `sysmda_markdown_cache_dependencies`. The fingerprint
is empty when a post has none of them, so plain posts keep the validator they
already had. Four regression tests, all failing against `0.26.3`.

## Medium priority

### M1 — Password-cookie state defeats the “not password-protected” rule

**Where:** `src/PostSupport.php:91-96`.

Eligibility uses `! post_password_required( $post )`. In WordPress that function
does not mean “the post has no password”; it means “this visitor still needs to
provide it.” Once a valid `wp-postpass_*` cookie exists, it returns false and the
post is considered servable by every shared consumer: `.md`, negotiated output,
alternate link, shortcode, and dynamic tag.

This conflicts with the stated invariant that password-protected content is not
served. `/llms.txt` separately uses `has_password => false`, so the index and the
endpoint also apply different definitions of eligibility.

**Impact:** after entering the password in WordPress, the clean machine-readable
representation and its discovery link become available. This is not an
unauthenticated bypass—the cookie is required—but it violates the documented
product/privacy rule and can surprise an owner who expects protected material
never to be exposed as Markdown.

**Recommended direction:** if the durable rule is truly “never protected,” test
`'' === (string) $post->post_password` in the centralized eligibility method.
If cookie-authorized Markdown is intended instead, document it explicitly and
align `/llms.txt` terminology/tests with that choice.

**Outcome: ruled on by the maintainer and fixed in `0.27.0`.** The mechanics
were confirmed as described: `post_password_required()` means "this visitor
still has to supply it", so a valid `wp-postpass_*` cookie made `is_servable()`
true while `/llms.txt`, filtering on `has_password => false`, disagreed.

The ruling is the strict reading: **protected content has no Markdown
representation at all**, whether or not the reader knows the password. The test
is now `'' === $post->post_password`, which also makes the endpoint and the
index agree. See the durable decision in `AGENTS.md`.

Worth recording why this survived a test suite with coverage of exactly this
rule: the stub for `post_password_required()` returned
`! empty( $post->post_password )`, i.e. it encoded the assumption the production
code was making rather than WordPress's actual behaviour. A test built on a stub
that shares the code's misconception cannot fail. The stub now models the
cookie, and the new assertion fails against `0.26.3`.

### M2 — `/llms.txt` invalidation misses output-changing WordPress events

**Where:** `src/LlmsTxtController.php:85-115`,
`src/MarkdownController.php:147-161`.

The index cache version contains only plugin version and settings salt, while
proactive deletion occurs only on `save_post`/`deleted_post`. The cached file
also depends on values that can change without those hooks invalidating it:

- site name and tagline (`update_option( 'blogname'/'blogdescription' )`);
- post-format term assignment/removal, which changes `is_servable()`;
- description, featured integration, or other post meta updated directly;
- filters such as maximum/main post count, footer, summary, descriptions, and
  supported types when their backing state changes outside this settings page.

**Impact:** `/llms.txt` can advertise a newly ineligible post, omit a newly
eligible one, or show old metadata for up to one day. Persistent object caching
does not alter this behavior.

**Recommended direction:** include deterministic output dependencies in the
version and invalidate on relevant option/meta/term hooks (particularly
`set_object_terms` for `post_format`, and site identity option changes). Expose a
documented cache-version filter for third-party dynamic inputs. Tests should
exercise changes that do not call `save_post`.

**Outcome: partly fixed in `0.27.0`, the rest declined on purpose.** The site
identity is now part of the index's cache version: the name is its `# ` heading
and the tagline the blockquote under it, and both are edited in Settings →
General, which never fires `save_post`. Two regression tests, both failing
against `0.26.3`.

The `set_object_terms` hook for `post_format` was **declined**. The gap is real
but narrow: a format is set from the editor, where saving already clears this
cache, so only programmatic term writes escape it — and post formats are
explicitly not part of how this site classifies content (they are excluded from
being served at all). Paying a hook on every term write for that is not worth
it; the exposure is bounded by the TTL. Recorded as a durable decision in
`AGENTS.md` so it is not "fixed" later by someone reading this finding alone.

### M3 — Invalid Accept quality values are interpreted as maximum preference

**Where:** `src/AcceptNegotiator.php:35-50`.

The parser starts with `q = 1.0` and leaves it there when a `q` parameter is not
numeric. Thus `text/markdown;q=banana` or an empty `q=` is treated as explicitly
preferred Markdown. RFC quality values have a constrained numeric grammar; an
invalid weight must not silently become the strongest possible preference.

**Impact:** malformed or buggy clients can unexpectedly receive Markdown rather
than HTML. Under strict negotiation, invalid values can also produce surprising
accept/reject decisions.

**Recommended direction:** reject the malformed media range, or conservatively
treat its quality as zero. Also validate the full q-value grammar rather than
accepting every PHP `is_numeric()` form (for example exponent notation), and add
cases for empty, alphabetic, signed, exponent, over-precision, and duplicated q
parameters.

**Outcome: fixed in `0.27.0`, reproduced first.** Run against the real parser:
`text/html,text/markdown;q=banana` and `…;q=` both gave Markdown `q=1.00` versus
HTML `q=1.00`, i.e. Markdown won. Negative values were already handled by the
clamp, so the defect was exactly the non-numeric case. A media range with a
non-numeric weight is now dropped; numeric weights keep their existing
behaviour, out-of-range values clamped as before, because `q=7` still expresses
a preference while `q=banana` expresses nothing.

Dropping a range can leave an `Accept` header with nothing parseable in it, so
`should_reject_unacceptable()` now treats that case like a missing `Accept` — a
broken client gets the HTML page, never a `406`. Four regression tests plus
three guarding the `406` path.

## Low priority

### L1 — `/llms.txt` structural text is insufficiently normalized

**Where:** `src/LlmsTxtController.php:132-146,162-164,202-223`.

Entry titles and descriptions have dedicated single-line/Markdown escaping, but
the blog name, tagline, post-type labels, and summary do not all use the same
normalization. A plugin-provided post-type label containing a newline or
Markdown control syntax can create extra headings or list structure. Site
metadata is normally sanitized by core, so the practical risk is mostly a
third-party registration/filter edge case rather than a security issue.

**Recommended direction:** normalize all generated heading/blockquote text to a
single line and escape Markdown structural characters consistently. Keep the
footer raw because it is intentionally documented as a free-form block.

**Outcome: accepted, not fixed.** Confirmed by inspection and genuinely
inconsistent — the summary paragraph is collapsed to a single line, the site
name and tagline are not. But `blogname`/`blogdescription` are sanitized by
core, so reaching it takes a third-party post-type label or filter containing a
newline. Worth folding into the next `/llms.txt` change (M2 touches the same
file) rather than a release of its own.

### L2 — `HEAD` requests render and count a body that should not be sent

**Where:** `src/MarkdownController.php:382-396`,
`src/MarkdownController.php:399-415`.

There is no request-method branch. A `HEAD` request executes hit recording,
validator calculation, cache lookup/generation and `echo`. A web server may
discard the bytes on the wire, but WordPress/PHP still does the work; behavior
also depends on the SAPI.

**[corrected]** This finding originally added that "monitoring probes can
inflate the opt-in hit counter", and that part was wrong. `AGENTS.md` settles it
in the opposite direction: the counter deliberately records every served
response, `200` and `304` alike, because *an access is an access*. A `HEAD` that
reaches the endpoint is an access, so counting it is the documented behaviour,
not inflation. Only the wasted body generation is a defect.

**Recommended direction:** send the same headers and conditional status as GET
but skip body generation/output for `HEAD`, **keeping the hit recorded**. Add
GET/HEAD parity tests.

**Outcome: accepted, not fixed in 0.27.0.** The waste is real but bounded (the
body is usually a cache hit) and `HEAD` traffic on `.md` URLs is negligible
today. Revisit if the hit counter ever shows meaningful `HEAD` volume.

### L3 — The current PHP runtime exposes test-suite compatibility debt

**Where:** `tests/run-tests.php:434,867,917,1204,1386` and the conditional-header
test near those helpers.

On PHP 8.5, five uses of `ReflectionMethod::setAccessible()` emit deprecation
notices because it has had no effect since PHP 8.1. The suite also reports one
failure (`conditional: 304 actually sent`) because the CLI header/status stub
does not observe the status in this runtime. The production minimum remains PHP
7.4, so simply deleting compatibility calls without a version guard is not the
right fix.

**Impact:** noisy CI/local output on PHP 8.5 and no clean green result on the
current environment; no demonstrated production failure.

**Recommended direction:** wrap reflection compatibility by PHP version and make
the response-status assertion independent of CLI header behavior (or use a
purpose-built status stub). Add PHP 8.5 to CI when available while retaining the
declared minimum job.

**[corrected] Outcome: not reproduced as written; the diagnosis was wrong, and
the real defect is worse.** On PHP 8.4 — the production runtime, and the top of
the CI matrix — the suite is **270 assertions, 0 failed**. So this is not a
plugin defect and not even a PHP 8.5 defect: PHP 8.5 is simply outside the
declared matrix (7.4 and 8.4), which is a decision to take, not a bug to fix.

The failure it points at is real but has nothing to do with 8.5. Under the CLI
SAPI `headers_sent()` becomes true as soon as **any** output reaches the SAPI,
and `MarkdownController::send_not_modified()` returns early when it does. So
*any* line printed before the conditional-request tests — a deprecation notice,
or simply an earlier failing assertion — silently stops the `304` from being
recorded and produces a second, phantom failure. That is why the deprecation
notices and the "304 actually sent" failure appeared together: the notices
caused it. It reproduces on 8.4 the moment any earlier assertion fails.

Fixed in `0.27.0` by buffering the runner's own output, so the status stays
observable regardless of what ran before. The `setAccessible()` deprecations
remain, harmless and out of the supported matrix; they should be addressed
whenever PHP 8.5 is added to CI.

### L4 — Negative max-post values become an unbounded WordPress query

**Where:** `src/LlmsTxtController.php:166-180`.

`sysmda_llms_txt_max_posts` is cast to integer but not clamped. WordPress treats
`posts_per_page => -1` as “all posts”; other negative filter results can also
produce unintended query semantics. A malformed site filter can therefore turn
a bounded index build into a large memory/time operation. The enriched
`main_posts` limit is likewise unclamped and can move every result to Optional.

**Recommended direction:** define and enforce filter bounds (`0` should mean
none or a documented minimum; negatives should never mean unlimited unless that
is explicitly supported). Test zero, negative, huge, and main-limit-greater-than-
maximum cases.

**Outcome: accepted, not fixed.** Confirmed by inspection: the value is cast
but never clamped, and WordPress reads `posts_per_page => -1` as "everything".
Reaching it requires a site filter that returns a negative number, i.e. a bug in
someone else's code — the same class of hostile-filter hardening already applied
to the header and CSS-class filters, so it is legitimate, just not urgent. Clamp
it the next time `LlmsTxtController` is opened.

## Validation results

### Passed

- `composer install --working-dir=system-markdown-alternate` completed with the
  locked runtime and development dependencies.
- `composer --working-dir=system-markdown-alternate phpcs` completed with no
  reported coding-standard errors or warnings.
- `bash bin/build.sh` completed and produced the distributable archive. The
  generated tracked ZIP was restored afterward because this task changes only
  the review report.

### Failed / environment-sensitive

- `composer --working-dir=system-markdown-alternate test` completed **280
  assertions with 1 failure** on PHP 8.5. The failing assertion is the CLI
  observation of the `304` status; five reflection deprecation notices were also
  emitted. Markdown conversion tests did run after the full Composer install.

The failed suite is itself recorded as finding L3 rather than hidden or treated
as a successful validation.

## Positive observations

- Dedicated and negotiated URLs share centralized eligibility and output code.
- The canonical singular negotiation guard excludes feeds, embeds, trackbacks,
  comment pages and paginated post sub-pages.
- Negotiated Markdown and `406` responses apply the documented no-cache safety
  invariant, while dedicated `.md` URLs avoid imposing freshness policy.
- Header filter outputs are sanitized before reaching `header()`.
- The DOM pipeline has defensive fallback behavior without re-publishing nodes
  deliberately removed by exclusion rules.
- Table, definition-list, fenced-code and line-wrapper edge cases have explicit
  handling and tests.
- `.htaccess` updates match WordPress's in-place locking model and attempt
  rollback after failed or short writes.
- Multisite uninstall loops through sites and clears both options and transient
  rows; persistent cache group cleanup is attempted when supported.
- The hit counter stores aggregate daily buckets only, never raw user agents,
  addresses, or per-visitor identifiers.

## Suggested fix order

1. **H1:** make cache validators honest and complete for bundled dependencies;
   define behavior for inherently dynamic extensions.
2. **M1:** settle and enforce the password-protection contract.
3. **M2:** broaden `/llms.txt` versioning/invalidation.
4. **M3:** make malformed Accept weights conservative.
5. Address HEAD/query-bound/normalization edges and make the PHP 8.5 suite clean.

