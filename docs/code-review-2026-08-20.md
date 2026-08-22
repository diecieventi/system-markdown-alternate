# Code review — 2026-08-20

## Review target

- Repository: `diecieventi/system-markdown-alternate`
- Branch: `main`
- Reviewed commit: `d12c78562785fc4776f485ea6830c908e84c98eb`
- Runtime version: `0.44.0`
- Recent pull requests reviewed in depth: #90–#93
- Wider scope: PHP runtime, request routing and headers, Markdown conversion,
  metadata and validators, `/llms.txt`, admin settings, shortcodes/actions,
  LiteSpeed integration, caching, uninstall, packaging, release automation, CI,
  and the documentation site
- Page-builder scope: `docs/page-builders-plan.md`, checked against the current
  dependency graph and official Bricks/Elementor material

The GitHub review API reports no submitted reviews or inline review threads on
#90, #91, #92, or #93. The findings below are therefore a fresh review, not a
restatement of unresolved GitHub comments.

## Executive verdict

The current runtime is in good shape. I found no critical security issue, no
access-control bypass, and no regression in the main `.md`/negotiation/cache
paths. The recent embed work in #90 is notably careful and its tests exercise
the failure modes that matter. The full local suite, coding standards, PHP lint,
documentation audit, release-script regression, dependency validation, and
documentation build are all green.

There are three current-code findings: one output-integrity bug in the optional
ACF subtitle path, one remaining edge case in #92's empty-link fix, and one CI
gap that allows a broken documentation site to merge. None blocks the current
release by itself, but the first two should be fixed in the next runtime PR.

The page-builder plan has the right product boundary and the right central veto,
but it is **not implementation-ready yet**. Two design gaps are blockers:

1. Phase 1 is described as independent of reconnaissance even though correct
   per-post detection depends on the effective render mode, which is still an
   open question in the same document.
2. The proposed adapter methods are not wired into the existing validator and
   description paths, so following the plan literally can produce correct body
   HTML with a stale ETag and an empty description.

## Findings

| ID | Priority | Area | Finding |
|---|---:|---|---|
| PB-1 | P1 | Page-builder plan | Phase 1 cannot safely ship before detector reconnaissance establishes the effective render decision for every builder it vetoes. |
| PB-2 | P1 | Page-builder plan | The proposed adapter has no defined path into `dependencies_fingerprint()` or `description()`. |
| R-1 | P2 | ACF | Subtitle text is inserted as raw Markdown between `*` delimiters and can corrupt the preamble. |
| R-2 | P2 | CI/docs | Pull requests do not build the Astro documentation site; the first build happens after merge to `main`. |
| R-3 | P3 | PR #92 | A title/ARIA-named empty link containing `&nbsp;` still converts to an unnamed Markdown link. |

P1 here means “must be resolved before page-builder implementation,” not a
currently deployed production regression.

### Status (added 2026-08-22)

All five findings are closed.

| ID | Closed by |
|---|---|
| PB-1 | `0.46.0` — Phase 0 established the render-mode decision per builder before the veto shipped (#102). |
| PB-2 | `0.46.0` — `dependencies_fingerprint()` became an instance method folding in `builder_dependency_parts()`, and `description()` gained the `source_text()` tier (#103). |
| R-1 | `0.46.1` — `MarkdownConverter::escape_inline()`, applied to the ACF subtitle. |
| R-2 | `0.46.1` — the Astro build runs inside the required `PHP 7.4` check. |
| R-3 | `0.46.1` — emptiness is decided in PHP, Unicode-aware, instead of by `normalize-space()`. |

The recommendation for R-1 offered two routes; the second was taken, and the
first was measured and rejected. Converting an escaped `<em>` loses the
delimiters for a subtitle of exactly `0`, because the library's emphasis
converter tests its value with `! trim( $value )`. The escaping is still the
library's own — the value is handed to it as a text node — so the invariant the
finding asked for holds without a second copy of the rule to keep in step.

### PB-1 — Phase 1 depends on the render-mode reconnaissance it says it can skip

**Evidence**

- `docs/page-builders-plan.md:10-14` and `:304-309` say the veto is blocked by
  nothing and can ship independently.
- `:86-101` correctly says data presence is not render-mode detection.
- `:177-178` still lists the exact Bricks render-mode signal as an unanswered
  reconnaissance question.
- `:114-118` names only two permanent-veto meta keys and leaves the
  “equivalents” for Oxygen, Beaver Builder, and Breakdance unspecified.

The official Bricks documentation makes the distinction load-bearing: switching
to “Render with WordPress” retains Bricks data while the frontend uses
`post_content`. Bricks also exposes the `bricks/render_with_bricks` filter as the
decision point for whether a post is rendered by Bricks. That means a raw meta
check can disagree not only with the stored toggle but also with site code that
changes the effective decision through the official filter.

False negatives publish empty or builder-chrome Markdown. False positives are
equally serious because the veto removes `.md`, discovery links, shortcodes,
dynamic tags, and `/llms.txt` entries from otherwise valid posts. A detector that
has not been verified in both directions is not a safe first release.

**Recommendation**

Replace the current phase ordering with:

1. **Phase 0a — detector reconnaissance for all seven builders.** Record the
   supported versions, active and inactive behavior, official API where one
   exists, exact persisted signals where it does not, and fixtures for both
   “data exists but WordPress renders” and “builder renders.”
2. **Phase 1 — veto only builders whose detector has been observed to fire and
   not fire on the required matrix.** This includes the temporary Bricks veto.
3. **Phase 0b/2 — Bricks rendering reconnaissance and adapter.** The 404 query
   state, save timestamps, vendor render call, echo/asset behavior, and Post
   Content element remain Bricks-adapter questions.

For active Bricks, prefer the vendor's effective decision seam over a duplicated
meta interpretation, provided staging confirms it is callable safely on both the
canonical permalink and the `.md` route. Persisted metadata is still useful for
inactive-plugin handling and settings-screen counts, but it should not silently
replace the frontend decision.

### PB-2 — the adapter is disconnected from validators and descriptions

**Evidence**

The proposed interface at `docs/page-builders-plan.md:236-244` includes
`fingerprint()` and `source_text()`, and the prose correctly requires both. The
current application graph, however, has no consumer for either method:

```text
Plugin
├── ContentRenderer ── renders post_content
├── MetadataBuilder ── description() reads post_content
└── MarkdownController
    └── MetadataBuilder::dependencies_fingerprint()  (static)
```

- `Plugin.php:24-29` constructs `ContentRenderer` and `MetadataBuilder` directly,
  with no shared adapter registry.
- `ContentRenderer.php:50-76` owns the body branch.
- `MetadataBuilder.php:353-393` owns a static dependency fingerprint.
- `MetadataBuilder.php:655-680` owns the description fallback.
- `MarkdownController.php:966-982` and `:1175-1183` call the static fingerprint
  independently for date-validator eligibility and cache-version generation.

A third branch in `ContentRenderer` alone would therefore render a Bricks body
without automatically moving the ETag or feeding the description used by front
matter and enriched `/llms.txt`. This is precisely the stale-`304` failure the
plan says must never happen.

Bricks Components make the graph requirement concrete: official documentation
says a component definition is stored separately and page instances retain a
reference plus overrides. Hashing only the page blob cannot cover a component
edit.

**Recommendation**

Freeze one shared seam before implementation, for example:

```text
BuilderRegistry
├── effective adapter / veto decision  ──> PostSupport
├── rendered HTML                      ──> ContentRenderer
├── source text                        ──> MetadataBuilder::description()
└── canonical dependency fingerprint  ──> cache_version() + date eligibility
```

Inject the registry into both `ContentRenderer` and `MetadataBuilder`, then make
the controller ask its `MetadataBuilder` instance for the dependency
fingerprint. An alternative based on existing filters can work, but the plan
must name the exact hooks and ordering; an interface whose methods have no
caller is not enough.

The Bricks fingerprint contract should also specify:

- a canonical encoding for render mode and page data;
- recursive, cycle-safe traversal of referenced templates/components/global
  elements;
- missing-reference markers;
- stable ordering before hashing;
- transition tests for add, edit, remove, mode switch, and plugin deactivation;
- one shared computed value per request, so `date_is_strong_validator()` and
  `cache_version()` do not traverse a large dependency graph twice.

### R-1 — ACF subtitles can break their own Markdown delimiters

`AcfIntegration.php:116-120` strips HTML and then emits:

```php
'*' . $subtitle . '*'
```

The subtitle is a text field, but ordinary text may contain Markdown punctuation.
For example, the value `A *literal* marker` currently produces:

```markdown
*A *literal* marker*
```

The user's asterisks participate in Markdown parsing rather than remaining
literal subtitle text, so the intended single emphasized line is split or
otherwise misparsed. Backslashes and underscores present related cases. There is
no test of `build_preamble()` today; the ACF coverage focuses on cache
dependencies.

**Recommendation:** produce the subtitle through the HTML-to-Markdown converter
from an escaped `<em>` element, or add a dedicated, tested Markdown text escaper.
Do not escape only `*`; the invariant is that an ACF text value remains text.
Add fixtures for `*`, `_`, backslash, ampersand, Unicode, and the string `0`.

### R-2 — the documentation site is not a pull-request gate

`.github/workflows/ci.yml` runs PHP lint/tests and PHPCS, but never runs
`npm ci`/`npm run build` in `documentation/`. `.github/workflows/docs-site.yml`
runs the Astro build only on a push to `main` (or manually). A malformed Starlight
configuration, integration, component, or frontmatter entry can therefore pass
every PR check, merge, and fail only while deploying the public site.

This risk is no longer hypothetical in scope: most of #82–#93 is documentation
work, and #91 changed the project's rule specifically to require multiple
documentation surfaces in feature PRs.

**Recommendation:** put the docs build inside an already required check, or add
a dedicated job and update branch protection in the same change. The repository
already documents why merely adding an unprotected job is insufficient. Keep
Node 22 and `npm ci`, matching the deployment workflow. The current build also
emits Astro deprecation warnings for the Markdown configuration; those are
maintenance follow-up, not a present build failure.

### R-3 — `&nbsp;` bypasses the empty-link naming pass

The #92 XPath at `ContentRenderer.php:730-733` uses
`not(normalize-space())`. XPath 1.0 normalizes XML whitespace, but not U+00A0.
Consequently this common placeholder shape is not selected:

```html
<a href="https://example.com" title="Named">&nbsp;</a>
```

The downstream converter trims the non-breaking space and the result is the
original defect:

```markdown
[](https://example.com "Named")
```

**Recommendation:** select leaf anchors first, then decide rendered emptiness in
PHP with Unicode-aware whitespace normalization. Add DOM and end-to-end fixtures
for `&nbsp;`/U+00A0. Keep the existing exclusions for element children and code
ancestors.

## Recent pull-request review

### #90 — embeds retain their address

No additional defect found. The implementation preserves text and URL rather
than treating them as competing outcomes, matches the embed class as a whole
token, reads stored URLs per text node, resolves frame references before the
general URL pass, and retains captions. The regression coverage corresponds to
the implementation's load-bearing branches.

### #91 — documentation-surfaces rule

No code defect. The rule is clear, and later changes follow it. CI does not yet
enforce the documentation site's buildability; see R-2.

### #92 — accessible names for empty overlay links

The narrow design is sound: it uses declared names only, does not invent names
from neighboring content, preserves image/icon anchors, avoids code samples, and
removes a consumed `title` to prevent duplicate output. R-3 is the remaining
whitespace case.

### #93 — page-builder implementation plan

The product decisions are strong: Bricks-first, Elementor parked, permanent veto
for the other named builders, per-post rather than per-type detection, vendor
rendering rather than tree reimplementation, no frontend loopback, and a central
`PostSupport` veto. PB-1 and PB-2 must be resolved before code starts.

## Overall architecture assessment

### Strong points

- `PostSupport::is_servable()` is genuinely central across routing, discovery,
  `/llms.txt`, shortcodes, actions, and the GenerateBlocks tag.
- The cache design understands that TTL does not protect conditional requests;
  taxonomy and out-of-post dependencies reach the ETag, and
  `If-Modified-Since` is disabled when the post timestamp is not authoritative.
- Negotiation is conservative, parses q-values, keeps wildcard clients on HTML,
  and isolates negotiated responses from URL-only caches.
- The DOM pipeline has explicit preservation rules for code, tables, embeds,
  captions, excluded regions, and malformed wrapper content.
- Authentication and password boundaries are conservative: protected posts have
  no representation, and authenticated `/llms.txt` bodies do not enter the
  shared cache.
- Settings sanitization, output escaping, uninstall cleanup, release tag guards,
  and package staging show no obvious security or destructive-operation defect.
- The documentation and filter contracts are unusually well synchronized for a
  plugin of this size.

### Residual risks

- The test suite is intentionally a pure-logic WordPress stub suite. It is broad
  (883 assertions) but cannot prove real template-loader order, actual header
  behavior, object-cache integration, cron context, or third-party builder APIs.
  The staging checklist remains a release requirement, especially for the
  page-builder work.
- `bin/build.sh` intentionally replaces the local Composer install with
  `--no-dev`; the package build was not run during this review so the PHPCS
  toolchain would remain available. The same production install and staging
  logic is exercised by release workflows; run the distributable build before a
  release candidate is published.

## Verification performed

| Check | Result |
|---|---|
| `php system-markdown-alternate/tests/run-tests.php` | 883 assertions, 0 failed |
| `composer --working-dir=system-markdown-alternate phpcs` | 0 errors, 0 warnings |
| PHP lint over plugin/bin sources (excluding `vendor`) | clean |
| `php bin/docs-audit.php` | no missing or stale symbol |
| `composer validate --strict --no-check-publish` | valid |
| `composer check-platform-reqs --no-dev` | satisfied |
| `bash -n bin/*.sh bin/tests/*.sh` | clean |
| `bash bin/tests/release-tag.sh` | passed |
| JavaScript syntax checks | clean |
| `ASTRO_TELEMETRY_DISABLED=1 npm run build --prefix documentation` | 21 pages, 20 Markdown routes, llms output, sitemap and Pagefind built successfully |
| `git diff --check` before report creation | clean |

## Primary references used for the page-builder review

- [Bricks: Edit with Bricks vs render with Bricks](https://academy.bricksbuilder.io/builder/interface/editing-with-bricks/)
- [Bricks: `bricks/render_with_bricks`](https://academy.bricksbuilder.io/developer/hooks/filters/bricks-render_with_bricks/)
- [Bricks: `bricks/frontend/render_data`](https://academy.bricksbuilder.io/developer/hooks/filters/filter-bricks-frontend-render_data/)
- [Bricks: Components and their separate data model](https://academy.bricksbuilder.io/builder/features/components/)
- [Elementor: `Document::is_built_with_elementor()` source](https://github.com/elementor/elementor/blob/main/core/base/document.php)

## Recommended next actions

1. Amend `docs/page-builders-plan.md` for PB-1 and PB-2 before opening an
   implementation PR.
2. Fix R-1 and R-3 with focused regression tests; they can share one small
   runtime release if desired.
3. Add the documentation build to a required PR check.
4. Run the revised detector and Bricks matrices on staging, recording observed
   inputs and outputs in the plan before Phase 1 or the adapter ships.
