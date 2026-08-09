# Complete local code review — `main` / v0.35.3 (9 August 2026)

> **Historical review — triaged after `0.36.0` and `0.37.0`.** This report
> describes commit `5203bf2`, not the current tree. Release `0.36.0` closed every
> finding except M7, the deferred real-WordPress integration suite; `0.37.0`
> subsequently extended the shared canonical-request predicate to the typed HTTP
> alternate header. The original findings remain below as the audit record and
> must not be read as the current backlog.
>
> | Findings | Outcome |
> |---|---|
> | H1–H2 | Fixed in `0.36.0` |
> | M1–M6, M8–M9 | Fixed or resolved by decision in `0.36.0` |
> | M7 | **Deferred by decision** — the pure harness remains; a real WordPress integration suite is still a residual coverage gap |
> | L1–L8 | Fixed or resolved by decision in `0.36.0` |
> | HTTP alternate-header follow-up | Shipped in `0.37.0`, using the same canonical-request predicate as negotiation and HTML discovery |

## Review target and constraints

- Repository: `diecieventi/system-markdown-alternate`
- Commit reviewed: `5203bf2568300e22173bc610a954ec56a7923290`
- Git description: `v0.35.3-3-g5203bf2`
- Review mode: local, read-only for implementation files
- Requested constraint: no code changes

This report is the only tracked-file addition made by the review. Composer
development dependencies were installed into the ignored `vendor/` directory so
that PHPCS could run; no runtime, test, configuration, or documentation file was
otherwise edited.

The review covered the plugin bootstrap, all classes under `src/`, admin assets,
uninstall behavior, the pure test harness, Composer metadata, build/release
scripts, GitHub Actions, the committed distribution archive, public documentation,
and the durable product decisions in `AGENTS.md`.

## Executive verdict

The codebase has a strong engineering baseline. The main flows are unusually well
documented, the pure test suite is broad, the output contract is explicit, and the
implementation contains several careful defenses that are often absent from small
WordPress plugins: centralized eligibility, weak ETag comparison, dependency
fingerprints, no-cache treatment of negotiated representations, locked `.htaccess`
updates with rollback, multisite uninstall cleanup, conservative DOM fallback, and
deliberate privacy limits on telemetry.

It is not, however, ready to be treated as having a closed security and cache
correctness model. Two high-priority conceptual defects remain:

1. the representation is assumed to be visitor-invariant even though dynamic
   blocks, shortcodes, and filters execute in the current visitor context; this can
   disclose access-controlled source content or cache personalized output for other
   visitors;
2. the body and validators omit known WordPress dependencies, notably categories,
   tags, and the site timezone, allowing permanently stale `304` responses.

The automated baseline is green, so these are not syntax or coding-standard
problems. They are contract/modeling problems that the current stub-based tests do
not exercise.

## Finding summary

| ID | Severity | Finding |
|---|---|---|
| H1 | High | Visitor-dependent and access-controlled output can be exposed or shared through public/internal caches |
| H2 | High | The cache validator is incomplete for categories, tags, and the site timezone |
| M1 | Medium | `Vary: Accept-Encoding` or `Vary: Accept-Language` is mistaken for `Vary: Accept` |
| M2 | Medium | A previously selected CPT can become non-public and remain servable |
| M3 | Medium | The cache salt can fail to change and can race a multi-option settings save |
| M4 | Medium | Whitespace normalization rewrites fenced code nested in blockquotes or lists |
| M5 | Medium | Any CSS class containing `line` can be misread as a code-line wrapper |
| M6 | Medium | A failed `.htaccess` read can be followed by a destructive partial rewrite |
| M7 | Medium | No real WordPress integration suite covers routing, headers, hooks, or cache invalidation |
| M8 | Medium | The future WordPress.org deployment path accepts an arbitrary ref and uses mutable action tags |
| M9 | Medium | Agent/source-of-truth documents materially contradict the current repository state |
| L1 | Low | `/llms.txt` applies its per-type limit before filtering ineligible formats |
| L2 | Low | The alternate-link guard does not mirror the negotiation guard as documented |
| L3 | Low | Any `format` query parameter disables strict `406`, not only `format=markdown` |
| L4 | Low | YAML scalar escaping does not make every possible source string parseable |
| L5 | Low | The one-time `.htaccess` backup has an unclear permission and lifecycle policy |
| L6 | Low | The `/llms.txt` status card can say “Enabled” while the endpoint is intentionally silent |
| L7 | Low | Several code comments violate the repository's English-only rule |
| L8 | Low | The committed ZIP is valid but its `readme.txt` does not match current `main` at the same plugin version |

Severity is based on impact and realistic reach, not only probability. “High” here
does not mean an unauthenticated exploit exists on every vanilla WordPress install;
H1 becomes a confidentiality defect on sites whose content or render callbacks are
visitor-dependent, while H2 is an unconditional validator design defect whenever
the affected inputs change.

## High-priority findings

### H1 — Visitor-dependent and access-controlled output can be exposed or shared

**Affected areas:**

- `src/Plugin.php:31-32`
- `src/PostSupport.php:106-110`
- `src/ContentRenderer.php:50-74`
- `src/MarkdownController.php:466-469`
- `src/MarkdownController.php:816-842`
- `src/MarkdownController.php:885-920`

**What the code assumes**

The dedicated `.md` response is marked:

```http
Cache-Control: public, max-age=0, must-revalidate
```

The rationale says the representation “never varies by visitor” because it is
built from cleaned blocks rather than `the_content`. The internal body cache is
also shared per post (`sysmda_md_<post ID>`) and its version has no visitor
dimension.

That premise is not true by construction:

- block content is rendered through `render_block()`;
- classic content and ACF fragments execute `do_shortcode()`;
- source, rendered HTML, preamble, final output, metadata, and dependency filters
  are normal WordPress callbacks;
- the global post is installed, but the current user, cookies, locale, request,
  session, and arbitrary plugin globals remain those of the caller.

WordPress itself documents that [`render_block()` runs render filters and dynamic
block code](https://developer.wordpress.org/reference/functions/render_block/)
and that [`do_shortcode()` invokes registered shortcode handlers](https://developer.wordpress.org/reference/functions/do_shortcode/).
Either kind of callback can read `is_user_logged_in()`, capabilities, account data,
cookies, geolocation, experiment state, a cart, or a membership state.

**Two separate failure modes follow.**

1. **Access-control bypass.** Eligibility checks only enabled post type, `publish`
   status, the core password field, and post format. Membership/paywall plugins
   commonly protect an otherwise published post in a later `template_redirect`
   callback or through `the_content`. This plugin runs at `template_redirect`
   priority `0`, reads raw `post_content`, and exits. WordPress explicitly notes
   that exiting from this hook prevents subsequent callbacks from running in its
   [`template_redirect` documentation](https://developer.wordpress.org/reference/hooks/template_redirect/).
   A protection callback registered later never gets a chance to deny the request,
   while a `the_content` restriction is bypassed deliberately.

2. **Cross-visitor cache contamination.** An authenticated visitor can be the
   first request that builds a dynamic block or shortcode. That output is stored
   in the shared internal cache for up to one day. An anonymous or different
   authenticated visitor then receives the same bytes. The negotiated response's
   external `no-store` policy does not protect the plugin's internal object cache;
   the dedicated `.md` route additionally permits shared intermediary storage.

The risk also applies to visitor-dependent values returned by the plugin's own
public filters. `sysmda_markdown_cache_dependencies` can invalidate time-varying
content, but it is not a complete answer to visitor variance: adding a user ID to
an ETag does not make a public URL safe in a shared cache, and disabling the
internal TTL does not change the public HTTP caching policy.

**Impact**

- disclosure of member-only or otherwise access-controlled source content;
- disclosure of personalized shortcode/dynamic-block output;
- non-deterministic `.md` bytes and ETags for one canonical URL;
- cache poisoning between anonymous, authenticated, or cookie-defined audiences.

**Recommended direction**

This needs a product-level contract before a local patch:

1. Explicitly define the `.md` representation as public/anonymous, or support
   visitor variants. The current implicit middle ground is unsafe.
2. Add a final per-post servability/access filter used by every consumer
   (`.md`, negotiation, alternate link, `/llms.txt`, shortcodes, dynamic tag), so
   membership and editorial plugins can veto individual posts.
3. Document clearly that the built-in check understands WordPress passwords only,
   not arbitrary paywalls or membership metadata.
4. At minimum, bypass shared body caching and send `private, no-store` for logged-in
   or otherwise authenticated requests. Also consider how cookie-varying anonymous
   output is declared; `is_user_logged_in()` alone is not a complete variance test.
5. Do not present `sysmda_markdown_cache_dependencies` as sufficient for
   personalization. It solves validator inputs, not HTTP cache partitioning or
   access authorization.
6. Add integration fixtures with one late access-control callback, one
   user-dependent shortcode, and one user-dependent dynamic block.

### H2 — Categories, tags, and timezone are absent from the cache validator

**Affected areas:**

- `src/MetadataBuilder.php:64-84`
- `src/MetadataBuilder.php:285-356`
- `src/MarkdownController.php:659-693`
- `src/MarkdownController.php:816-871`
- `src/AdminSettings.php:56-80`
- `docs/cache-infrastructure-notes.md` (current operational summary)

The cache version is also the weak ETag. The code correctly recognizes that every
input changing the body without changing `post_modified_gmt` must affect both
`cache_version()` and `date_is_strong_validator()`. That rule is not completely
implemented.

#### Categories and tags

`build_front_matter()` always reads and emits `category` and `post_tag`. However,
`taxonomies_fingerprint()` fingerprints only the optional selected custom
taxonomy map. `category` and `post_tag` are deliberately excluded from that map,
and `dependencies_fingerprint()` does not add them elsewhere.

A local isolated reproduction changed a category term name while leaving the post
row untouched. The generated front matter changed, but `cache_version()` remained
the same. This creates two layers of staleness:

- the internal body cache remains valid under the unchanged version until its TTL;
- more importantly, `If-None-Match` is evaluated before the body cache, so a client
  with the old ETag can receive `304` forever, even after the transient expires or
  body caching is disabled.

`If-Modified-Since` is also incorrectly left enabled when the post has no selected
custom taxonomies or other fingerprinted dependency, even though a category or tag
rename changes the bytes without changing `post_modified_gmt`.

The historical ETag review currently states that categories/tags are covered by a
fingerprint. The implementation and tests show that statement is false.

#### Site timezone

The always-present `date_published` and `date_modified` keys call
`get_post_time( 'c', false, … )` and `get_post_modified_time( 'c', false, … )`.
WordPress documents these as local-time calls; their formatted ISO offset depends
on the site's timezone. See the official references for
[`get_post_time()`](https://developer.wordpress.org/reference/functions/get_post_time/)
and [`get_post_modified_time()`](https://developer.wordpress.org/reference/functions/get_post_modified_time/).

Changing Settings → General → Timezone changes the front-matter values across the
site without editing any post. `AdminSettings` bumps the global salt for the home
URL, permalink structure, author rename, and user deletion, but not for
`timezone_string` or `gmt_offset`. The ETag therefore remains unchanged and the
same unbounded false-`304` problem applies.

#### Recommended direction

1. Add category and tag term data to a built-in fingerprint whenever the front
   matter that contains them is enabled. Cover additions, removals, assignments,
   and renames.
2. Make `date_is_strong_validator()` depend on the same complete dependency model.
3. Bump the global salt after `timezone_string` and `gmt_offset` change, following
   the existing site-wide-input pattern.
4. Audit other resolved values rather than only IDs/timestamps. In particular,
   the emitted featured-image URL can change through `_wp_attached_file`, upload
   URL configuration, or a URL filter while the current attachment fingerprint
   records only ID, attachment modification time, and alt text.
5. Turn the output dependency table into executable regression tests. Every row
   should change the body and assert whether the body cache, ETag, and
   `If-Modified-Since` behavior change.

## Medium-priority findings

### M1 — The `Vary` check matches substrings, not field tokens

**Where:** `src/MarkdownController.php:402-417`.

The code skips adding `Vary: Accept` when any existing `Vary` header contains the
substring `accept`:

```php
false !== stripos( $sent, 'accept' )
```

Consequently, both of these are incorrectly treated as already sufficient:

```http
Vary: Accept-Encoding
Vary: Accept-Language
```

They are different field names and do not partition a cache by the `Accept`
media-type header. This is particularly relevant because both are common values.
On the HTML branch, a cache can store HTML without `Vary: Accept`, then answer a
Markdown-preferring request before PHP runs. The Markdown branch's no-store policy
limits the inverse contamination, but the negotiation contract still fails.

Parse comma-separated Vary field values and compare case-insensitive tokens
exactly. `Vary: *` is the only non-exact value that already covers everything.
Add tests for `Accept-Encoding`, `Accept-Language`, multiple `Vary` fields, comma
lists, exact `Accept`, and `*`.

### M2 — A previously selected CPT can become non-public and remain eligible

**Where:** `src/AdminSettings.php:483-519`, `src/PostSupport.php:70-110`.

The settings sanitizer intentionally retains previously saved post-type slugs when
their provider is temporarily inactive. Its comment says the emission path
validates the type again. It does not: `PostSupport::sanitize_types()` checks only
string shape, duplicates, and the `attachment` exclusion, while `is_servable()`
does not inspect the registered `WP_Post_Type` object.

A local stub reproduction registered a saved `secret_records` type with
`public=false` and `publicly_queryable=false`; `PostSupport::is_servable()` still
returned `true` for a published post of that type. WordPress distinguishes these
registration flags in the official [`register_post_type()` reference](https://developer.wordpress.org/reference/functions/register_post_type/).

Depending on its query/rewrite configuration, the direct suffix route may no
longer resolve, but `/llms.txt`, shortcodes, the dynamic tag, direct ID resolution,
and a still-publicly-queryable unusual registration can continue advertising or
serving it. This contradicts the public documentation's “published, public” rule.

Preserve the saved slug in settings, but make it inert at runtime unless the
currently registered type satisfies the public policy. If deliberate non-public
extension is required, give it a separate explicit escape hatch rather than
making a stale saved option silently authoritative.

### M3 — Cache salt collisions and multi-option save race

**Where:** `src/AdminSettings.php:99-123`, `tests/run-tests.php:1709-1751`.

The salt is `(string) time()` and may be changed only once per PHP request.

Two correctness gaps follow:

1. Two genuine invalidations in the same second produce the same value.
   `update_option()` then performs no effective salt change, so an existing body
   cache and ETag remain valid after output-changing settings changed.
2. A Settings API form writes multiple options sequentially. The first changed
   `sysmda_*` option bumps the salt; later output-changing options are written
   after that bump, while the static guard forbids a final bump. A concurrent
   front-end request between those writes can cache partially old output under the
   final salt, with no later invalidation.

The test suite currently enforces “one bump per request” but does not test the
transaction boundary or same-second collisions. Use a guaranteed-changing nonce
(for example a random value or sufficiently precise monotonic token) and arrange a
single final bump after the complete settings save. Keep the existing post-write
ordering property; it is correct and important.

### M4 — Nested fenced code is normalized as prose

**Where:** `src/MarkdownConverter.php:64-143`, `tests/run-tests.php:2007-2023`,
`docs/output-format.md:199-202`.

Fence detection accepts only zero to three leading spaces followed immediately by
backticks or tildes. The converter can emit fenced code inside a blockquote or list,
where the Markdown lines begin with container syntax such as `> ````, `- ````, or
additional list indentation. Those fences are not recognized.

Local conversion checks confirmed that trailing spaces inside such nested code are
trimmed. Depending on the emitted indentation, runs of blank lines can also be
collapsed. This contradicts the documented byte-preservation guarantee.

The existing tests cover top-level fences only. Make normalization container-aware
or protect code segments before applying prose whitespace rules, then add
blockquote, ordered/unordered list, and nested-container fixtures with trailing
spaces and multiple blank lines.

### M5 — Substring `line` is too broad for syntax-highlighter wrappers

**Where:** `src/ContentRenderer.php:333-363`.

When a `<pre>` has no literal newline, any child whose complete class attribute
contains `line` is treated as a per-line wrapper. This incorrectly accepts classes
such as `inline-token`, `baseline`, `outline`, or `underline`.

A local DOM reproduction using two adjacent `<span class="inline-token">` nodes
inserted a newline between syntax tokens that were originally on the same line.
The current mixed-inline test uses spans with no class and therefore misses the
false-positive path.

Tokenize the class attribute and match exact known line-wrapper tokens or a narrow,
documented pattern. Add negative fixtures for common class names containing the
substring.

### M6 — Read failure can truncate `.htaccess` logically

**Where:** `src/LiteSpeedCompat.php:313-360`.

The locked read loop breaks when `fread()` returns `false`, but then treats the
bytes collected so far as the complete file, transforms them, backs them up, and
overwrites the live `.htaccess`. A transient or permanent read error after one or
more chunks can therefore discard the unread remainder. The excellent write-side
rollback cannot recover data that was never loaded into `$contents`.

Abort the update immediately on a read error, release the lock, and leave both the
live file and backup untouched. Extend the existing custom stream-wrapper tests to
fail a read after an initial successful chunk.

### M7 — Pure stubs cannot validate the most consequential behavior

**Where:** `.github/workflows/ci.yml`, `tests/run-tests.php`.

The 354-assertion test harness is valuable, fast, and has caught real regressions.
It is nevertheless a custom WordPress stub environment. It does not execute:

- actual request parsing and query state for suffix, feed, embed, trackback,
  comment-page, multipage, or plain-permalink routes;
- PHP/WordPress header accumulation and real `Vary` behavior;
- hook priority interactions with access-control plugins;
- Settings API multi-option writes;
- real taxonomy term rename/assignment hooks and cache invalidation;
- persistent object-cache behavior;
- HTTP methods and full conditional request flows;
- a network/multisite lifecycle under WordPress core.

This matters because the two high findings sit precisely at the boundary between
the stubs and WordPress runtime. A small WordPress core test-suite or `wp-env`
matrix should complement, not replace, the pure suite. Start with request/headers,
visitor-dependent rendering, categories/tags/timezone invalidation, Settings API
saves, and private CPT eligibility.

### M8 — Deployment workflow ref validation and action immutability

**Where:** `.github/workflows/deploy-wordpress-org.yml:22-84` and all workflows'
`uses:` entries.

The manual deploy workflow accepts `inputs.tag`, checks out that string directly,
and derives `VERSION` by removing a leading `v`. It never proves that the input is
an existing annotated `vX.Y.Z` tag or that the plugin header, constant, stable tag,
and changelog agree. A branch, lightweight tag, or malformed ref can therefore be
deployed and named as a WordPress.org version. The input is also interpolated
directly into an inline shell script instead of being passed through `env`.

This workflow is currently documented as inactive, which lowers immediate risk,
but it will receive SVN credentials once the plugin is accepted. Before enabling
it:

1. resolve and validate an exact `refs/tags/vX.Y.Z` ref;
2. verify all version surfaces against that tag;
3. pass expression values to shell through environment variables;
4. use explicit least-privilege `permissions`;
5. pin external actions, especially `10up/action-wordpress-plugin-deploy@stable`,
   to reviewed full commit SHAs.

GitHub's official [secure-use guidance](https://docs.github.com/en/actions/reference/security/secure-use)
states that a full commit SHA is the immutable way to pin an action. Current
workflows use mutable `@v2`, `@v5`, and `@stable` tags.

### M9 — The source-of-truth documents are stale and contradictory

**Where:** `AGENTS.md:63`, `AGENTS.md:293-330`, `docs/HANDOFF.md:1-55` (historical file, since removed),
`CHANGELOG.md:12-32`, `.wordpress-org/`.

This is a conceptual/process defect because agents are explicitly instructed to
treat these documents as operational authority.

- `AGENTS.md` labels the current state as `v0.26.x`; the plugin is `0.35.3`.
- `AGENTS.md` says five screenshot PNGs are still missing. All five exist, are
  tracked, are valid PNGs, and the `0.35.3` changelog says the refresh shipped.
- the former `docs/HANDOFF.md` said `main` is `0.24.0` and repeats that screenshots are
  missing.
- the former `docs/HANDOFF.md` identified multilingual `/llms.txt` as the only queued plan,
  while the main “Open / to do” section does not surface it and instead retains
  already-completed screenshot work.

An agent following the documented hierarchy can waste effort, reopen completed
work, or miss the only approved plan. Update `AGENTS.md` and either refresh,
archive, or remove the historical handoff once its useful constraints are consolidated. Add a lightweight
documentation-state check for version and required WordPress.org assets.

## Low-priority findings

### L1 — `/llms.txt` limit is applied before eligibility filtering

`LlmsTxtController.php:254-284` queries at most `sysmda_llms_txt_max_posts`
records and only then removes non-standard formats through `is_servable()`. If the
newest batch contains excluded formats, fewer than the requested number of
eligible entries are emitted and older eligible standard posts are never reached.
In the extreme, a section can disappear despite older servable content. Page or
over-fetch until the requested number of eligible posts is collected.

### L2 — Alternate-link and negotiation guards differ

`MarkdownController::is_negotiable_request()` excludes feeds, embeds, trackbacks,
paged comments, and `<!--nextpage-->` sub-pages. `print_alternate_link()` checks
only enabled singular type and servability. On variants that still run `wp_head`,
the link may be advertised even though that URL is not negotiable and does not
declare `Vary: Accept`. This can be a defensible SEO choice, but it directly
contradicts the comments and durable documentation saying the guards mirror each
other. Either share one predicate or document the intentional difference.

### L3 — Any `format` query suppresses strict `406`

`MarkdownController.php:368-375` skips strict acceptability handling whenever
`$_GET['format']` exists. Only the exact value `markdown` is an implemented format
override. Thus `?format=banana` disables a `406` for an otherwise unacceptable
`Accept` header and falls through to HTML. Check the recognized override, not mere
parameter presence.

### L4 — YAML safety claim is broader than the escaping implementation

`MetadataBuilder::scalar()` decodes entities, strips tags, collapses whitespace,
and escapes backslash and double quote. It does not explicitly escape or reject
all ASCII control characters (for example NUL, BEL, or ESC), which are not legal
raw characters in a YAML double-quoted scalar. Normal WordPress admin inputs make
this rare, but programmatic data and public filters can supply such bytes.

`docs/output-format.md` says the result is always parseable YAML “regardless of the
source text.” Either sanitize/escape the full YAML control set and test with a real
YAML parser, or narrow that guarantee.

### L5 — `.htaccess.sysmda-bak` permissions and lifecycle

The one-time backup is written beside `.htaccess` with process-default permissions
and is deliberately retained, including after uninstall. Apache/LiteSpeed commonly
deny `.ht*` files, but that is not universal across reverse proxies, static layers,
or migrated server configurations. The file can disclose directives, paths, IP
rules, or other configuration if served.

Define the recovery policy explicitly: use restrictive permissions, verify it is
not web-readable on supported stacks, show its location in the admin UI, and decide
whether successful uninstall should retain or remove it. Recovery value is valid;
the current implicit lifetime is the problem.

### L6 — `/llms.txt` UI status can be false

`AdminSettings.php:796-809` calls the endpoint “Enabled” solely from the
`sysmda_llms_txt_enabled` option. `LlmsTxtController.php:63-69` intentionally stays
silent when no content type is enabled. The status card should distinguish
“enabled and active” from “enabled but waiting for a content type.”

### L7 — English-only repository rule is not fully followed

`AdminSettings.php` contains Italian comments/headings such as “Opzioni sempre
registrate”, “Generale”, “Avanzate”, “Sanitizzazione”, and “Quick info
nell'aside”; `MetadataBuilder.php:180` contains “Chiave YAML”. They are not runtime
strings and create no user-facing bug, but they conflict with the explicit
English-only rule for code comments and agent artifacts.

### L8 — Valid release archive, but not a snapshot of current `main`

`DIST/system-markdown-alternate.zip` passes CRC validation and its runtime PHP,
assets, bootstrap, Composer metadata, uninstall file, and production vendor tree
match the v0.35.3 release content. Its `readme.txt` differs from current `main`
because the three post-tag commits refactored filter documentation without a new
version. Both still identify themselves as `0.35.3`.

This is understandable if `DIST/` is a committed release artifact rather than a
build of HEAD, but that policy is not machine-readable. Add an artifact manifest
or CI assertion recording the source tag/commit, so a reviewer can distinguish an
expected release snapshot from an accidentally stale package.

## Automated and local verification

| Check | Result |
|---|---|
| Git worktree before report | Clean, `main...origin/main` |
| PHP runtime used locally | PHP 8.5.6 |
| Pure test suite | 354 assertions, 0 failed |
| PHP syntax lint | All project PHP files passed |
| PHPCS / WPCS / PHPCompatibilityWP | Passed with no reported errors or warnings |
| Composer validation | `--strict --no-check-publish` passed |
| Composer security audit | No advisories in the lock file |
| JavaScript syntax | `node --check` passed for admin JS |
| Shell syntax | `bash -n` passed for both scripts |
| Distribution archive | `unzip -t` passed; 87 entries, production vendor bundled |
| Distribution exclusions | Tests, Composer lock, PHPCS config, and vendor CLI bins absent as intended |
| `git diff --check` before report | Clean |

The PHP 8.5 run emits nine deprecations for
`ReflectionMethod::setAccessible()`. The declared CI matrix is PHP 7.4 and 8.4,
so this is not a current compatibility failure, but the test harness should stop
using the deprecated calls before PHP 8.5 is added to CI.

`shellcheck`, `actionlint`, and `yamllint` were not installed locally, so shell and
workflow validation was limited to Bash parsing plus manual review. The workflows
were not executed because that would mutate external release state.

## Strengths worth preserving

- Eligibility is centralized, and the core password check correctly uses the
  stored password rather than visitor-cookie state.
- The negotiation parser handles explicit quality preference and wildcard fallback
  in a compact, testable class.
- Negotiated Markdown is non-cacheable independently of LiteSpeed-specific hints.
- ETags are honestly weak, compare weakly in both directions, and handle Apache
  compression suffixes.
- `/llms.txt` hashes the actual body for its ETag rather than pretending a partial
  version key is byte-complete.
- The conversion pipeline's custom root, exclusion-aware fallback, table converter,
  definition-list handling, and synced-pattern cycle guard are thoughtful defenses.
- `.htaccess` writes hold a lock across the full read/modify/write sequence and
  attempt rollback after short or failed writes.
- Settings use the WordPress Settings API and capability/nonces rather than custom
  request handling.
- Uninstall handles multisite in batches and cleans both options and database
  transients.
- The hit counter stores only daily aggregates and openly documents concurrency
  and page-cache undercount limits.
- The output and filter contracts are much more explicit than the average plugin's.

No direct SQL injection, reflected/stored admin XSS, missing Settings API nonce,
path traversal, or accidental shipping of development dependencies was found in
the reviewed paths. That statement is bounded to this source review and is not a
substitute for runtime integration/security testing.

## Recommended order of work

1. Resolve H1 as a product/security contract: anonymous representation,
   per-post access veto, and visitor-aware cache policy.
2. Close H2 with a complete executable dependency matrix, beginning with
   category, tag, timezone, and resolved featured-image URL changes.
3. Fix M1–M3 together because they define representation selection and cache
   identity.
4. Fix the two confirmed conversion regressions (M4–M5) and the `.htaccess` read
   failure (M6), each with a failing regression fixture first.
5. Add a small real-WordPress integration layer (M7) before claiming the runtime
   cache/access model is closed.
6. Harden the WordPress.org workflow before enabling its secrets (M8).
7. Reconcile the operational documentation immediately (M9); it is a low-effort
   change with a high effect on future agent correctness.
8. Address the low findings opportunistically, without reopening the durable
   decisions below.

## Deliberate decisions and residual risks not reopened by this review

The following were inspected and are not presented as new defects:

- lack of a synthesized blog-index homepage and postponement of a static-front-page
  `.md` endpoint;
- host-specific stripping of conditional headers observed on the documented nginx
  stack;
- no freshness lifetime by default for `.md` and the explicit staleness trade when
  a site adds `s-maxage`;
- the intentionally removed broad LiteSpeed `406` bypass rule;
- hit-counter undercount behind caches and lost increments under concurrent
  read/modify/write;
- pre-warm being opt-in because cron lacks request context;
- non-standard post formats being excluded by default;
- absence of a response `Content-Disposition` header;
- full document generation on `HEAD` and the previously triaged non-GET
  conditional semantics;
- server-side diagnostics remaining a parked future thought.

One nuance should be retained when H1 is addressed: the old statement that a site
can handle visitor-dependent output merely by adding cache dependencies or setting
the internal TTL to zero is insufficient for public intermediary caching and
authorization. Correcting that statement does not reopen any host-specific cache
decision; it closes a different representation-identity problem.
