# Complete code review — v0.26.3 (26 July 2026)

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

### L2 — `HEAD` requests render and count a body that should not be sent

**Where:** `src/MarkdownController.php:382-396`,
`src/MarkdownController.php:399-415`.

There is no request-method branch. A `HEAD` request executes hit recording,
validator calculation, cache lookup/generation and `echo`. A web server may
discard the bytes on the wire, but WordPress/PHP still does the work; behavior
also depends on the SAPI. Monitoring probes can inflate the opt-in hit counter.

**Recommended direction:** send the same headers and conditional status as GET,
but skip body generation/output for `HEAD`; decide and document whether a HEAD
probe counts as a Markdown hit. Add GET/HEAD parity tests.

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

