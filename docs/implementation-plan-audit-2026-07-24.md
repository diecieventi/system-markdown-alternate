# Implementation plan audit — 2026-07-24

## Purpose and scope

This report reviews the current repository, handoff, status notes, strategy, and
implementation plans without changing plugin code.

Reviewed:

- all tracked first-party documentation, configuration, workflows, scripts,
  plugin source, assets, and tests;
- the distributable ZIP structure and its first-party files;
- the Composer dependency manifest/lock and the relevant
  `league/html-to-markdown` behavior;
- the four wordpress.org screenshots at the metadata/dimensions level;
- official WordPress, WPML, Polylang, llms.txt, and Cloudflare documentation
  where an external premise affected the plans.

The bundled `vendor/` library was treated as a third-party dependency rather
than audited line by line. Binary artwork was not visually redesigned or
modified.

No plugin code or existing artifact was modified. This report is the only new
file.

## Executive verdict

The strategic direction is mostly sound: the serving path is mature, a bare
`.md` endpoint is becoming a commodity, and the plugin should compete on
predictability, correctness, and origin-aware output rather than promise
visibility in AI products.

The active plans are **not ready to implement verbatim**:

| Workstream | Verdict | Value | Main action |
|---|---|---:|---|
| F1 — stable output contract | Proceed after small revision | High | Correct the compatibility promise and strengthen the golden fixtures |
| F2 — diagnostics | Reduce to an MVP and defer the expensive parts | Medium | Preview, servability, URL, and size first; remove false/brittle checks |
| F3.1 — custom taxonomies | Proceed if CPTs are a real use case | Medium-high | Use a collision-safe schema and add taxonomy cache invalidation |
| F3.2 — complex ACF | Do not build generically yet | Low-medium without real fixtures | Split deterministic types from schema-dependent Repeater/Flexible Content |
| Multilingual `/llms.txt` | Block pending plan rewrite and staging reconnaissance | Medium when a multilingual site exists | Fix the default-language query premise, footer ordering, coverage, and scale |
| Parked WooCommerce/broad hardening | Keep parked | Unproven | Revisit only with real demand |
| wordpress.org screenshots | Do soon, before more feature work if publication is near | High presentation ROI | Recapture the current admin UI and preferably show real Markdown output |

Recommended order:

1. Correct the planning/documentation contradictions listed below.
2. Implement F1, but do not create a plugin release solely for a docs/test-only
   PR.
3. If custom taxonomies are used on target sites, implement F3.1 as a small,
   independent change with cache invalidation.
4. Run a WPML + Polylang reconnaissance pass and rewrite the multilingual plan
   from observed behavior before coding it.
5. Build only a small diagnostics MVP if users will actually use it.
6. Implement complex ACF support only from concrete field-group fixtures.

## Verified repository state

- Branch: `main`, aligned with `origin/main`, version `0.23.1`.
- Worktree was clean before this report.
- Local pure-logic suite: **108 assertions, 0 failures**.
- PHP lint: all first-party PHP files passed.
- `DIST/system-markdown-alternate.zip` contains the expected plugin and excludes
  the two CLI binary directories introduced in the `0.23.1` packaging fix.
- First-party files in the ZIP match the source tree. Four generated Composer
  metadata files differ from the current local `vendor/`, which is expected
  after Composer regeneration and is not a source mismatch.
- The test suite is useful but narrow: it does not exercise a real WordPress
  request, admin rendering, full `MetadataBuilder::build_front_matter()`,
  `LlmsTxtController::build()`, `ContentRenderer` DOM processing, or HTTP
  headers end to end.

## Cross-document findings to fix first

### 1. The strategy incorrectly says that source `noindex` gating is implemented

`docs/strategy-review-2026-07.md:55` says that protected/private/password/
`noindex` gating is done through `PostSupport::is_servable()`.

The implementation at `system-markdown-alternate/src/PostSupport.php:31-41`
checks only:

- supported post type;
- `publish` status;
- password protection.

It does not inspect Rank Math, Yoast, core robots directives, or any source-page
`noindex` state. The Markdown response itself receives its own
`X-Robots-Tag: noindex, follow`, but that is different from refusing to expose a
post whose HTML page is marked `noindex`.

Recommendation: correct the strategy table. Do not silently add source-noindex
gating without a separate product decision because SEO metadata is not an
access-control boundary and third-party integrations would expand the scope.

### 2. The repository is declared English-only, but active material is still Italian

The handoff says the repository is English-only
(`docs/HANDOFF.md:20-22,73-74`), while
`docs/llms-txt-multilingual-plan.md` is entirely Italian.

There are also pre-existing Italian comments in first-party files, including:

- `.claude/agents/Explore.md` (the tracked agent definition is entirely Italian);
- `system-markdown-alternate/src/AdminSettings.php:153,176,205,226`;
- `system-markdown-alternate/src/MetadataBuilder.php:119`;
- `system-markdown-alternate/src/PostSupport.php:14`;
- `system-markdown-alternate/tests/run-tests.php:362`.

Recommendation: translate the multilingual plan before handing it to an
implementation agent. Clean the remaining comments in a small documentation/
maintenance PR; no runtime change is required.

### 3. Status and user-facing documentation contain stale or absolute claims

- `AGENTS.md:36` still labels the current state as `v0.22.x`, although current
  `main` and the handoff are `0.23.1`.
- `README.md:19` and `readme.txt` say that `Vary: Accept` means caches and CDNs
  “never” mix representations. The repository's own durable LiteSpeed decision
  explains why that guarantee is false. `Vary` is correct metadata; the
  no-cache invariant and optional LiteSpeed bypass are the safety measures.
- `README.md:26` and the readme FAQ describe the cache as a transient, while
  `Cache.php` uses a persistent object cache when available and transients only
  as fallback.
- `README.md:36` names the menu “System Markdown Alternate”; the registered menu
  label and `readme.txt` use “Markdown Alternate”.
- The four screenshots are explicitly stale and show the pre-`0.17.0` UI.

These are low-risk corrections with higher immediate credibility value than
adding another feature.

### 4. The stated go/no-go signal cannot come from the built-in counter alone

The handoff uses “real recurring `.md` requests from important clients in the
logs” as the commercial signal (`docs/HANDOFF.md:64-67`). The built-in counter
intentionally stores only daily bot/human totals and cannot identify an
important client.

Recommendation: keep the privacy decision. Clarify that:

- the built-in counter measures aggregate demand;
- client-level importance can only be evaluated from infrastructure/CDN/server
  logs already controlled by the site owner, not by expanding plugin storage.

## Plan review: F1 — documented, stable output format

### Verdict

**Worth doing and the best first implementation task**, after revising the
contract language.

It is small, makes future output changes deliberate, improves developer trust,
and supports meaningful regression testing. This is more defensible than
another generic “AI optimization” feature.

### Corrections required

1. Do not promise stability “within `0.x`” retroactively
   (`docs/tier1-implementation-plan.md:50-52`). Versions before `0.23.1` already
   changed the format. State a clear starting point, for example:
   “This compatibility policy applies from version 0.24.0,” or introduce a
   separately documented format-contract version.

2. Reconcile F1 with F3. F1 says additions are appended and keys are never
   silently reordered, while F3.1 inserts custom taxonomy keys after
   categories/tags and before description (`tier1-implementation-plan.md:135-141`).
   Either:

   - append one namespaced `taxonomies:` mapping after the existing keys; or
   - explicitly version the intentional schema change and update the golden.

3. The golden fixture described at lines 53-59 omits
   `featured_image`/`featured_image_alt` even though the documented contract
   includes them. Add:

   - one full fixture with author, featured image, alt, categories, tags, and
     description;
   - one fixture covering absent optional fields;
   - scalar escaping cases for quotes, backslashes, entities, tags, and
     multiline whitespace.

4. Document guarantees separately from implementation details:

   - guaranteed front-matter key semantics and conditional presence;
   - current ordering;
   - body assembly and newline policy;
   - extension filters that can intentionally change the result;
   - dedicated `.md` caching versus negotiated-response no-cache behavior.

5. Document that unknown HTML tags are stripped by the current converter
   (`MarkdownConverter.php:29-36` has `strip_tags => true`). Their text may
   remain and structural boundaries can be lost; raw unknown tags are not a
   supported stable output.

6. A docs/test-only PR should not automatically bump the plugin version. A
   version bump changes `SYSMDA_VERSION`, invalidates every Markdown cache and
   ETag, and rebuilds the distributable despite no runtime/output change.
   `AGENTS.md` requires a bump for a **release**, not for every PR. Merge F1 as
   documentation/tests, or batch its release note with the next runtime change.

### Suggested acceptance criteria

- Exact golden front matter for full and minimal fixtures.
- Intentional test demonstrating optional-key behavior.
- Documentation matches a manually captured real WordPress output.
- Existing 108 assertions remain green on PHP 7.4 and 8.4.
- No plugin version bump unless the PR is deliberately being released.

## Plan review: F2 — server-side diagnostics

### Verdict

**Feasible only after reducing the scope.** The full proposal is substantially
more invasive than the plan suggests and is not clearly the best next
differentiator.

Current WordPress competitors already advertise previews, endpoint dashboards,
and crawler diagnostics. The useful differentiator is reliable origin-aware
output, not the existence of a diagnostics tab by itself.

### Incorrect or brittle assumptions

1. “Tags that survived into Markdown as raw HTML” is incorrect
   (`tier1-implementation-plan.md:83-86`). `MarkdownConverter` enables
   `strip_tags`. A direct check against the bundled library confirmed that
   unknown tags are removed while their text remains; in some cases adjacent
   text is concatenated. A diagnostic can report **unsupported HTML elements
   before conversion** and possible structure loss, but not raw tags surviving
   in the final Markdown.

2. `url_to_postid() === 0` does not prove that an internal URL is broken.
   Archives, uploads, custom routes, query endpoints, fragments, and plugin
   routes may all be valid without mapping to a post. Rename this signal to
   “unresolved internal post links,” exclude known non-post routes, and never
   present it as a broken-link verdict without an HTTP request.

3. “Bytes/tokens saved vs HTML” is overstated. In-process diagnostics can
   compare Markdown with the plugin's cleaned/rendered fragment, not with the
   final public page containing theme chrome, scripts, and injected output.
   A true public-HTML comparison requires the forbidden loopback. Label the
   result “clean rendered fragment vs Markdown,” not site-wide savings.

4. Plain permalinks are not a servability failure. They are served via
   `?format=markdown`; show this as a URL-mode note.

### Admin integration problem

Every settings section is rendered inside one POST form targeting `options.php`
(`AdminSettings.php:733-801`). The proposed no-JS post picker needs its own GET
submission. Adding a nested form is invalid HTML; submitting it through the
existing form risks saving settings or polluting the query.

Choose one explicit design:

- a separate Diagnostics admin page;
- a diagnostics panel outside the Settings API form;
- or a carefully designed nonce-protected async picker with a no-JS link-based
  fallback.

A separate page is the cleanest if interactive diagnostics grow beyond a
single post-ID query parameter.

### Pipeline design problem

Diagnostics must not duplicate the private assembly logic in
`MarkdownController::build_markdown()` (`MarkdownController.php:532-551`), or
preview and served output will drift.

Before diagnostics, extract a small shared document builder or expose a
side-effect-free build service used by both the controller and diagnostics.
Instrumentation should use an optional report object passed through
`ShortcodeCleaner`, `BlockCleaner`, and `ContentRenderer`; avoid mutable
“last report” accessors and keep the normal path allocation-free when reporting
is disabled.

### Recommended MVP

Build only:

- post ID/URL selection;
- explicit servability reason;
- `.md` URL and URL mode;
- exact Markdown preview produced by the shared builder;
- Markdown byte count and approximate tokens;
- cleaned-fragment byte count, clearly labelled;
- manual cache-negotiation curl snippet.

Defer:

- complete stripped-element census;
- automatic link classification;
- unsupported-component analysis;
- any score or pass/fail grade.

Proceed with the deferred items only after users show that the MVP changes how
they configure or fix content.

## Plan review: F3.1 — custom taxonomies in front matter

### Verdict

**Technically feasible and worthwhile for CPT-heavy sites.** Keep it separate
from complex ACF support.

`get_object_taxonomies( $post, 'objects' )` is valid on supported WordPress
versions, so that API choice in the plan is correct.

### Required design changes

1. Avoid arbitrary top-level YAML keys. A custom taxonomy can collide with a
   stable front-matter key or another future field. Prefer:

   ```yaml
   taxonomies:
     topic:
       - "Example"
   ```

   This is collision-safe, makes the schema extensible, and permits one appended
   top-level key consistent with F1.

2. Decide whether “all public custom taxonomies” is an automatic default or an
   opt-in. Automatic output is reasonable for this product, but it is still a
   format and payload change for every upgraded site. Document it and keep the
   filter able to return an empty list.

3. Define stable ordering:

   - taxonomy slugs in registration order or sorted order;
   - term names in WordPress order or sorted order.

   Golden tests require deterministic behavior.

4. Add cache invalidation for taxonomy-only changes. Current Markdown cache
   validity is based on `post_modified_gmt`, plugin version, settings salt, and
   `save_post`. Term assignment or term renaming can happen without a reliable
   post-content modification. This already affects core categories/tags and
   becomes more visible with custom taxonomies.

   The implementation plan should cover object-term assignment and term edits,
   invalidating the affected post cache(s) and `/llms.txt` as appropriate.

5. Test `WP_Error`/empty terms, non-public taxonomies, filter curation, key
   collisions, and scalar escaping.

## Plan review: F3.2 — structured ACF extraction

### Verdict

**Do not implement Repeater/Flexible Content as a generic feature without real
field-group fixtures.** Relationship/Post Object and Gallery are more
deterministic and can be evaluated separately.

### Problems in the current plan

1. The panel does not configure the general `sysmda_acf_field_keys` list.
   `AdminSettings` exposes only subtitle and TL;DR field names. The general list
   is developer-only through a filter
   (`AcfIntegration.php:13-22,55-62`). The plan's reference to “the panel's ACF
   fields” is therefore misleading.

2. Repeater and Flexible Content have no universal semantic rendering.
   Field labels, nested field types, layout names, desired headings, and row
   meaning vary per site. A generic dump can be worse than omitting the data.

3. The plan says values should flow through the HTML/DOM/Markdown pipeline, but
   its test design proposes “row/layout → Markdown string.” Markdown appended to
   HTML source is treated as text, not parsed as Markdown. Pure helpers should
   produce:

   - escaped semantic HTML fragments; or
   - a structured intermediate representation consumed by one HTML renderer.

4. ACF return formats are configurable:

   - Relationship/Post Object may return IDs or `WP_Post` objects;
   - Gallery may return IDs, URLs, or arrays;
   - Link/Image subfields have multiple shapes;
   - nested Repeater/Flexible fields can contain any of the above.

   The plan must specify normalization and escaping for every supported shape.

5. “Unknown types fall back to current text behavior” is inaccurate. The
   current code accepts only non-empty strings and skips arrays/objects
   (`AcfIntegration.php:67-80`).

### Recommended approach

- Keep the existing developer filter as the escape hatch.
- Add examples/documentation for site-specific structured extraction first.
- If real demand exists, implement in this order:

  1. Link/URL and basic scalar fields;
  2. Relationship/Post Object as title + canonical/Markdown link;
  3. Gallery/Image with alt text;
  4. Repeater only with an explicit template/callback contract;
  5. Flexible Content only with per-layout render callbacks.

- Do not add a large generic UI until the data model and actual use cases are
  known.

## Plan review: multilingual `/llms.txt`

### Verdict

**The feature is reasonable for a real multilingual site, but the current plan
must not be implemented as written.** Its central query premise is not reliable,
and several output/cache cases are missing.

### Blocking issue: the main query cannot remain unchanged

The plan claims that the current `get_posts()` query is filtered to the default
language (`llms-txt-multilingual-plan.md:5-9`) and says to leave the main loop
unchanged (`:81-82`).

Core WordPress sets `get_posts()`'s `suppress_filters` default to `true`. The
current call at `LlmsTxtController.php:166-178` does not override it.
Official WPML guidance says this default can return posts from multiple
languages unless `suppress_filters => false` is set. Polylang recommends an
explicit `lang` query argument, including `lang => ''` to disable filtering.

Consequences on current installations may include:

- only the default language;
- mixed languages in the main sections;
- duplicates in both the main section and the proposed Translations section;
- plugin/version-dependent differences between WPML and Polylang.

The implementation must first define and enforce the invariant:

> Main sections contain only default-language source posts; Translations
> contains their non-default, servable translations.

The adapter therefore needs a default-language query strategy, not just
translation lookup methods.

### Other required corrections

1. Collecting IDs “during the main loop” omits enriched Key content because
   `key_content_items()` currently returns rendered strings before the main
   loop (`LlmsTxtController.php:140-149,320-347`). Decide explicitly whether
   Key content participates. If it does, refactor it to return post objects or
   structured entries before rendering.

2. Do not append Translations after `sysmda_llms_txt_footer`.
   The footer is documented and implemented as the trailing free-form block
   (`LlmsTxtController.php:213-220`). Insert Translations before the footer, or
   intentionally rename/redefine the public footer contract.

3. Add an output-size and work bound. The current limit is up to 500 posts per
   type; looking up every language for every post is approximately
   `posts × languages`, and the translation section can multiply file size.
   Add a documented cap/filter and deterministic ordering.

4. Use the data returned by `wpml_active_languages` for both codes and native
   names instead of mixing it with the legacy `icl_get_languages()` function.
   Pass the documented second argument and guard all adapter API calls.

5. Define precedence if both multilingual plugins appear active, even if that
   installation is unsupported.

6. Language-list changes, renamed languages, or translation-relationship
   changes may not always align with the assumed `save_post` invalidation.
   Verify actual plugin hooks on staging. Either add targeted invalidation or
   accept/document that the cache TTL is the fallback.

7. Normalize/escape native language names used as headings.

8. Verify that every generated translated `.md` URL actually resolves and
   renders the translated post through this plugin. Correct `get_permalink()`
   output alone does not prove the whole `.md` request path and language context.

9. Translate the plan itself to English before it becomes an active repository
   implementation document.

### Required reconnaissance before coding

On one current WPML site and one current Polylang site:

1. Record the post IDs and languages returned by the existing `get_posts()`
   call for basic and enriched modes.
2. Test an explicit default-language query and an all-language query.
3. Test translated permalinks for directory, subdomain, or mapped-domain mode
   actually in use.
4. Fetch the translated `.md` URL and verify title, body, ACF values, canonical,
   and front matter are from the same translation.
5. Save a translation, change its language relationship, rename a language,
   and verify cache invalidation behavior.

Only then finalize the adapter API and tests.

### Suggested revised implementation shape

- `MultilingualAdapterInterface` with one concrete active adapter selected by a
  factory.
- Adapter methods for:

  - active/default languages and native names;
  - default-language query arguments or scoped language switching;
  - translation map for one post;
  - optional cache/invalidation hooks.

- `LlmsTxtController` builds structured entries first, renders lines second.
- Main/default entries, Optional entries, Key content, and Translations have an
  explicit inclusion and deduplication policy.
- Translations render before the footer.
- Pure tests cover grouping, ordering, deduplication, default exclusion, caps,
  and empty/monolingual output.
- Staging tests remain mandatory for both plugins.

## Strategy and “is it worth it?”

### What remains correct

- The product should promise clean, predictable machine-readable content, not
  improved ranking or guaranteed AI visibility.
- Cloudflare officially supports `Accept: text/markdown`, returns a predictable
  Markdown structure, and exposes token-related headers. This validates the
  technical direction while also commoditizing basic conversion.
- Cloudflare's Agent Readiness publication reported Markdown negotiation on
  3.9% of the measured sites, confirming that adoption is early rather than a
  settled crawler standard.
- The llms.txt proposal remains a lightweight convention, not a mature search
  or discovery guarantee.
- The WordPress directory now contains multiple plugins advertising `.md`,
  front matter, `/llms.txt`, previews, dashboards, custom taxonomies, and ACF
  integrations. The strategy is right that endpoint presence alone is not a
  moat.

### Best use of effort

Highest expected return:

- output contract and conformance tests;
- correctness/documentation cleanup;
- real output screenshots;
- taxonomy semantics where target CPTs use them;
- targeted fixes found from real Markdown traffic and real content.

Conditional return:

- multilingual `/llms.txt`, only for an identified WPML/Polylang deployment;
- a small preview/servability diagnostics tool;
- deterministic ACF types backed by actual fields.

Low expected return today:

- generic Flexible Content rendering;
- a large diagnostics/scoring suite;
- WooCommerce support without a target merchant;
- broad proxy/multisite projects without a failing deployment;
- any claim-oriented AI/GEO feature.

## Proposed coder handoff

The coder should not start from the existing “do in order” list. Use this
sequence instead:

### PR A — plan and documentation correction

- Translate the multilingual plan to English.
- Correct the false noindex-gating statement.
- Update `AGENTS.md` current version label.
- Correct `Vary`, cache-backend, and menu-name wording.
- Reconcile the output contract with the taxonomy schema.
- No plugin version bump; no runtime change.

### PR B — F1 contract and conformance

- Add `docs/output-format.md`.
- Add full/minimal golden front-matter fixtures and escaping cases.
- State the compatibility-policy start version.
- Document extension points and caching distinctions.
- No runtime version bump unless deliberately bundled into a release.

### PR C — custom taxonomy metadata, only if currently needed

- Add a nested `taxonomies:` mapping.
- Add deterministic ordering and filter behavior.
- Add term-change cache invalidation.
- Add tests and release as a runtime feature.

### Spike, then PR D — multilingual `/llms.txt`

- First produce the WPML/Polylang behavior matrix from staging.
- Rewrite the query/inclusion/cache section of the plan.
- Implement only after the main/default-language invariant is proven.

### Later PR — diagnostics MVP

- Shared side-effect-free Markdown document builder.
- Separate valid admin interaction design.
- Preview, servability reason, URL mode, and labelled size estimates only.

### Case-driven PRs — ACF

- Start from real field-group exports and expected Markdown fixtures.
- Implement deterministic types first.
- Require callbacks/templates for Repeater and Flexible Content.

## External references used

- WordPress `get_posts()` reference, including `suppress_filters => true`:
  <https://developer.wordpress.org/reference/functions/get_posts/>
- WordPress `get_object_taxonomies()` reference:
  <https://developer.wordpress.org/reference/functions/get_object_taxonomies/>
- Polylang query/language guidance:
  <https://polylang.pro/documentation/support/developers/developpers-how-to/>
- Polylang function reference:
  <https://polylang.pro/documentation/support/developers/function-reference/>
- WPML hooks reference (`wpml_active_languages`, `wpml_object_id`, language
  switching):
  <https://wpml.org/documentation/support/wpml-coding-api/wpml-hooks-reference/>
- WPML guidance on `get_posts()` and `suppress_filters`:
  <https://wpml.org/forums/topic/wpml-custom-query/>
- llms.txt format proposal:
  <https://llmstxt.org/>
- Cloudflare Markdown for Agents:
  <https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/>
- Cloudflare Agent Readiness:
  <https://blog.cloudflare.com/agent-readiness/>
- Current WordPress directory examples used for feature/competition checks:
  <https://wordpress.org/plugins/llm-friendly/> and
  <https://wordpress.org/plugins/one-v-llm-serve/>
