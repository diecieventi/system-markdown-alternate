# Real-WordPress staging acceptance

This is the compact release check for behavior that the pure PHP harness cannot
prove: real WordPress routing and hook order, emitted HTTP headers, browser-side
actions and interaction with the active staging stack. It complements
`tests/run-tests.php`; it does not replace that suite or CI.

Use the connected `instawp_sma` site and its safe plugin-update workflow. Never
leave update packages, rollback archives, credentials or diagnostic output on
the server.

## Before changing the site

1. Confirm the staging URL, WordPress/PHP versions, active plugin version and
   active integrations.
2. Check the homepage, one canonical post, its `.md` URL and `/llms.txt`.
3. Create a rollback archive outside the plugin directory and record its size
   and SHA-256.
4. Verify the local distributable's size and SHA-256 before transfer.

## Acceptance matrix

- Install the exact distributable, confirm the expected version and verify that
  the plugin remains active.
- Canonical HTML stays HTML; explicit Markdown negotiation returns Markdown;
  an unacceptable `Accept` value returns `406`; feeds and embeds never
  negotiate or advertise a Markdown alternate.
- Dedicated `.md` returns `text/markdown`, the canonical link, validators and
  `public, max-age=0, must-revalidate`. Negotiated Markdown remains private and
  non-storable. An exact `If-None-Match` request returns `304` when the host
  forwards the validator.
- Password-protected and non-standard-format posts remain unavailable as
  Markdown.
- **`/llms.txt` discovery (`rel="describedby"`, since `0.49.0`)**: a servable
  canonical post carries the relation in both the HTML head and the `Link:`
  header, alongside the Markdown alternate, and any pre-existing Link relation
  survives. It is absent wherever the alternate is absent (`.md`, negotiated
  Markdown, `406`, feed, embed, trackback, paged comments, sub-pages). Turning
  `/llms.txt` off removes it while the alternate stays — the two are gated
  independently and nothing else exercises that. **Run the unconfigured case
  first**: a fresh install has `/llms.txt` off by default, so confirm no
  `describedby` appears anywhere in that state; then enable the toggle with no
  content type selected yet — the one where a naive gate would advertise a
  404 — and confirm it still stays silent. On a subdirectory install, confirm
  the advertised target is the endpoint's real path under `home_url()` and
  that it resolves.
- Excluded shortcodes are absent from prose but survive literally inside inline
  and fenced code examples.
- Embed blocks leave a usable address: a video embed becomes a link to it,
  a captioned one keeps its caption as the following paragraph, and an embed
  showing text of its own (a quoted post) keeps that text as well as its link.
  Worth doing here specifically: whether the player is already resolved when
  the pipeline sees it varies per provider and per caching setup, and only one
  of the two shapes can be reproduced offline.
- A clickable link card — a link-preview or related-posts block, whichever the
  site actually uses — converts to a link carrying the card's name rather than
  `[](url "Name")`. Worth doing here specifically: whether such a card renders
  as an overlay anchor with sibling text, or nests its title inside the link,
  is the plugin's own choice and only the real one can settle it.
- Posts rendered by an unsupported page builder (Elementor, Divi, WPBakery,
  Oxygen, Beaver Builder, Breakdance) stay unavailable as Markdown: the `.md`
  returns 404, the HTML page advertises no `alternate` link or `Link:` header,
  the post is absent from `/llms.txt`, and all three shortcodes and the
  dynamic tag render nothing.
- **Bricks pages now produce a real `.md`** (Phase 2, since `0.46.0`): a
  Bricks-mode page serves `text/markdown` built through `\Bricks\Frontend::render_data()`,
  with real image `src`/`srcset` values (never a `data:image/svg+xml`
  placeholder — the lazy-load flag must actually be exercised: render an
  element referencing a **real WordPress attachment**, not a raw external
  URL, or the bug never triggers to begin with), `md-exclude` on a Bricks
  element's *CSS Classes* field removed as usual, and the default excluded
  builder elements (`brxe-form`, `brxe-nav-menu`, `brxe-nav-nested`,
  `brxe-post-sharing`, `brxe-post-toc`, `brxe-breadcrumbs`) stripped without
  any panel configuration. A second request with `If-None-Match` from the
  first answers `304`; saving the page (moving `post_modified_gmt`) or editing
  a referenced `template` element's own post must both invalidate it. The
  fixture that matters most is still the **inverse** one: a page holding
  Bricks data but switched back to *Render with WordPress* must serve an
  ordinary `.md` built from `post_content`, because that is the case a
  presence-based check gets wrong and no other check catches. Gutenberg and
  classic posts on the same site must be entirely unaffected — the rule is per
  post, not per type. Requires the second connected site,
  `sma-bricks-instawp-co` (Bricks 2.0 as the active theme); the release
  environment carries no builder content.
- The *Enabled content types* rows show the real per-type breakdown (for example
  *Pages — 1 Bricks, 3 Gutenberg*) with the warning on the builder part, and
  revisions of a builder page do not inflate its count.
- **Extra custom fields** (since `0.47.0`). Needs a fixture that does not exist
  on either site yet: **neither staging has a single ACF field group**
  (`acf_get_field_groups()` returned `[]` on both, August 2026), so create one
  with a text, a WYSIWYG, an image and a repeater field, and separately write a
  plain non-ACF meta key with `update_post_meta()` so the non-ACF read path is
  exercised on a real install too. Then: list the text and WYSIWYG keys in the
  panel and confirm both land at the end of the body, in the listed order, with
  the WYSIWYG's links and lists converted; list the image and repeater keys and
  confirm they are **skipped** rather than rendered as an ID or a count; confirm
  the plain meta key behaves the same with ACF active as the ACF ones do. Then
  the validator half, which is the point of the design: `curl -sI` a post that
  has none of the configured keys and confirm its `ETag` is unchanged from
  before the keys were configured and that `If-Modified-Since` still answers
  `304`; edit a configured value on a post that has it and confirm its `ETag`
  moves; delete that value entirely and confirm the `ETag` moves again.
- **WooCommerce utility pages** (`WooCommerceCompat`). **Neither connected
  staging site has WooCommerce installed** (checked August 2026), so this was
  verified on `instawp_sma` with a simulated fixture rather than a real
  WooCommerce install: three ordinary pages created, WooCommerce's own
  `woocommerce_{cart,checkout,myaccount}_page_id` options pointed at them by
  hand, `PostSupport::is_servable()` and a real `/llms.txt` HTTP round-trip
  both confirmed all three excluded and an unrelated page unaffected, the
  filter both re-included and narrowed the exclusion, and the
  `wc_get_page_id()`-active branch (WooCommerce genuinely installed) was
  checked in-process with a request-scoped shim, since the function cannot
  otherwise exist without the plugin. Fixtures and options were removed and
  the patched files reverted afterward. **What this does NOT cover**: a real
  WooCommerce install's own page-creation flow, and whatever WooCommerce
  itself does to `wc_get_page_id()`'s filter beyond the raw option — install
  WooCommerce for real on a future pass to close that gap, the same way the
  ACF fixture above is still owed a real field group.
- `/llms.txt` is healthy and excludes ineligible content.
- Render `[sysmda_md_actions]` through the real `wp_footer` both before and
  after WordPress's footer-script printer (representative priorities 10 and 25).
  In both cases markup, localization and exactly one script must be emitted.
- In a real browser, verify actions are visible, the menu opens aligned to the
  button and directly below it, Escape closes it, copy reports success and the
  console has no plugin JavaScript errors. Near a screen edge, and at 320 px,
  check the fallback placements do not overflow or clip.
- Search WordPress debug and PHP error logs for new plugin warnings or fatals.

## Cleanup and record

Always delete the transferred package and rollback archive when testing
finishes. After a failed run, keep them only until rollback and any required
diagnostics are complete, then remove them as well.
Record only the release version, date, platform versions and pass/fail outcome
in this file; keep transient URLs, hashes and verbose diagnostics out of the
repository.

## Latest full pass

- **2026-08-26 — System Markdown Alternate 0.49.0 (`rel="describedby"`) — targeted, not the full matrix**

  Platforms: **both** staging sites, upgraded in place from `0.47.1` (neither
  had received `0.48.0`) — `sma-bricks.instawp.co`, WordPress 7.1, PHP 8.4.7,
  Bricks 2.3.11; and `sma.instawp.co`, WordPress 7.1, PHP 8.4.20,
  GeneratePress. Run on the PR branch before merge, not on a release tag.

  Only five non-`vendor/` files differ between `0.47.1` and `0.49.0`, and
  `vendor/` was byte-identical on both sites (verified by comparing a SHA-256
  of every installed file against the built package), so the update was applied
  as those five files rather than a full package install: each was fetched by
  the site itself from the branch at `a3d3582`, **verified against its expected
  SHA-256 before anything was written**, and written only once all five had
  verified — so a bad download could not leave a half-updated plugin. A
  rollback archive of the prior install was taken outside the plugin directory
  first and removed after the run on both sites; `opcache_reset()` was needed
  for the new files to take effect.

  | Check | Result |
  |---|---|
  | Both relations on a servable canonical post — `Link: rel="alternate"; type="text/markdown"` **and** `Link: <…/llms.txt>; rel="describedby"`, plus `<link rel="describedby">` in the head | passed on both |
  | Pre-existing `Link` relations preserved (`api.w.org`, `shortlink`, the JSON alternate) | passed |
  | `/llms.txt` toggled off → `describedby` gone, Markdown alternate still present | passed on both — the two are gated independently |
  | **Unconfigured install: `/llms.txt` enabled but no content type selected → no `describedby` anywhere** | passed on both, and `/llms.txt` itself confirmed **404** in that state — this is the case a gate on the option alone would have advertised, and the reason the predicate is a conjunction |
  | Absent from feed, embed, the `.md` response and negotiated Markdown | passed |
  | No regression: `.md` `200`, `text/markdown; charset=utf-8`, `noindex, follow`, `public, max-age=0, must-revalidate`, weak `ETag`, `If-None-Match` → `304` | passed on both |
  | Bricks page still renders real image `src` (no `data:image/svg+xml` placeholder) after the upgrade | passed |
  | `/llms.txt` `200` and non-empty | passed on both |

  Not covered here: a subdirectory install (neither staging is one), so the
  `home_url()` path handling in the advertised target is still only argued from
  the code and the pure suite.

- **2026-08-23 — System Markdown Alternate 0.47.1 (extra custom fields × the Bricks adapter) — targeted, not the full matrix**

  Platform: `sma-bricks-instawp-co`, WordPress 7.1, PHP 8.4.7 (unchanged from
  the `0.46.0` entry below — same site, in-place plugin upgrade only).

  Closes the one scenario the `0.47.0`/`0.47.1` release notes flagged as
  never exercised against a real builder: extra custom fields appended
  through a Bricks-mode post, where `render_appended()`'s builder seam (the
  PR #107 fix) and the freeform-block `wpautop()` fix (PR #108) both have to
  hold at once. Updated `sma-bricks-instawp-co` in place from `0.46.0` to
  `0.47.1` (zip downloaded from the GitHub Release, SHA-256 verified against
  the release asset digest before install; a rollback archive of the prior
  `0.46.0` install was made first and removed after the run). The existing
  page-18 Bricks fixture (`_bricks_editor_mode = bricks`, the same tree used
  for the Phase 2 pass below, still carrying its `md-exclude` element) was
  reused rather than rebuilt, including its standing `sysmda_supported_post_types`
  option (`['page']`, set during that earlier pass and correctly left in place
  — this run neither set nor removed it).

  | Check | Result |
  |---|---|
  | Bricks `.md` unaffected by the update: `200`, `text/markdown`, real image `src` (no `data:image/svg+xml` placeholder), front matter, weak `ETag`, `Last-Modified`, `public, max-age=0, must-revalidate` | passed |
  | `If-None-Match` with the prior `ETag` | passed — `304`, empty body |
  | Extra-meta keys named in the panel option but **absent** on the post | passed — body and `ETag` byte-identical to before the option was set (the `metadata_exists()` gate holds on a builder-rendered post, not only on a classic one) |
  | One plain-text value (`A *literal* marker…`) **and** one Gutenberg-block-markup value configured together, both as plain (non-ACF) post meta with ACF active | passed — both appended after the Bricks-rendered body, in the listed order; `ETag` moved |
  | Escaping | passed — `A \*literal\* marker for the plain field.`, asterisks literal |
  | Mixed block + plain-text separation (PR #108) | passed — the block-valued sibling ran through real `parse_blocks()`/`render_block()` and stayed its own paragraph, not glued to the plain-text line above it |
  | Deleting both meta values | passed — appended content gone, body back to the pre-test bytes; `ETag` changed again (the deferred site-wide salt bump for a *deleted* dependency key, not a revert to the earlier per-post value — expected) |
  | A Gutenberg/classic page on the same site, carrying neither extra-meta key | passed — completely unaffected |
  | `md-exclude` on the existing Bricks element, post-update | passed — still absent from the body |
  | Debug log after the run | clean — no plugin warnings or fatals |

  `instawp_sma` was not re-checked in this run, so this does not supersede the
  `0.45.0` two-site entry below for anything outside this scenario. Cleanup:
  `sysmda_extra_meta_keys` was not present before this run and was removed
  afterward with `delete_option()` rather than reset to empty; the test meta
  keys and the rollback archive were removed as well. (One unrelated,
  harmless row — `sysmda_markdown_supported_post_types`, the *filter* name
  rather than the option `AdminSettings` actually reads — was created and
  deleted in the same run by a mistaken assumption that the site had no
  enabled content type; it never affected anything, since nothing reads an
  option under that name, and no trace of it remains. Caught by Codex on
  PR #111.) The plugin itself was left at `0.47.1` — that upgrade is the
  intended outcome, not a leftover.

- **2026-08-21 — System Markdown Alternate 0.46.0 (Bricks adapter, Phase 2) — targeted, not the full matrix**

  Verified only the Bricks-adapter acceptance criteria above, on
  `sma-bricks-instawp-co` (WordPress 7.1, PHP 8.4.7) alone. This was not a
  full pre-release pass across both connected sites — `instawp_sma` was not
  re-checked in this run — so it does not supersede the `0.45.0` two-site
  entry below for anything outside the adapter itself.

  | Check | Result |
  |---|---|
  | `.md` on a Bricks-mode page: `200`, `text/markdown`, front matter, weak `ETag`, `Last-Modified`, `public, max-age=0, must-revalidate` | passed |
  | Image `src`/`srcset` against a real attachment: placeholder (`data:image/svg+xml`) reproduced with the lazy-load flag off, real URL confirmed with it on, both via `\Bricks\Frontend::render_data()` directly and through the live `.md` response | passed |
  | `md-exclude` on a Bricks element's *CSS Classes* field | passed — excluded text absent from the body |
  | Default excluded builder elements (tested via the `brxe-form` class) | passed — absent with no panel configuration |
  | `If-None-Match` with the prior `ETag` | passed — `304`, empty body |
  | Cache validator moves when the stored tree changes | passed — `ETag` differed after the fixture edit |
  | `rel="alternate"` in both the HTML head and the `Link:` HTTP header | passed |
  | `/llms.txt` includes the Bricks page | passed |
  | **The inverse fixture** — page 130, `_bricks_editor_mode = wordpress` with a full Bricks tree still stored — serves its `.md` from `post_content`, not through the adapter | passed |

  Fixture note: page 18 (`_bricks_editor_mode = bricks`) started as a minimal
  section/container/heading tree with no image or excluded element, so it was
  extended in place with an image element (real attachment), an element
  carrying `md-exclude`, and an element carrying `brxe-form`, to make every
  row above exercisable. The enriched tree is left in place as a standing
  fixture rather than reverted, matching what page 130 already is for the
  inverse case.

- **2026-08-20 — System Markdown Alternate 0.45.0 (page-builder veto)**

  | Environment | Platform | Role in this run |
  |---|---|---|
  | `sma-bricks-instawp-co` — Bricks 2.0 | WordPress 7.1, PHP 8.4.7 | The veto itself: it is the only environment with page-builder content |
  | `instawp_sma` — GeneratePress 3.6.1 | WordPress 7.1, PHP 8.4.20 | The general matrix, and the "no builder content, nothing changes" property (0 of 31 published posts claimed) |

  Both were needed and neither is redundant: the release environment has no
  builder content at all, so it cannot exercise the veto, while the Bricks clone
  lacks Rank Math and GenerateBlocks. The two also happen to sit on different
  PHP patch releases, which is worth keeping rather than levelling.
- Veto, both directions, both fixtures holding the **same** Bricks tree and
  differing only in render mode: **passed**
- Panel breakdown, revision exclusion, census cache not bumping the salt,
  shortcodes and discovery links: **passed**
- No fatals in either log. Installed from the wordpress.org package, so no
  transferred artifacts existed to remove.

### What the pre-veto state actually looked like

Worth recording, because it was worse than the plan predicted and is the
clearest argument the feature has. Under `0.44.0` the Bricks page did **not**
serve an empty document: it served front matter plus six paragraphs of prose
from `post_content`, while the page itself rendered a single Bricks heading and
nothing else. That text appeared nowhere in the rendered page except inside
`og:description`. So the `.md` was neither empty nor obviously chrome — it was
plausible, well-formed, and describing a page that does not exist. `/llms.txt`
advertised it, with the same text as its description.

The plan anticipated two failure modes, empty and wrong. This is a third and
the worst of them: **confidently wrong**, with nothing about it visible from
the admin side.

### The fixtures, and why they are a pair

Two pages carrying the **identical** `_bricks_page_content_2` value, differing
only in `_bricks_editor_mode`:

| Page | Mode | `.md` | Discovery links | `/llms.txt` | Shortcodes |
|---|---|---|---|---|---|
| Bricks page | `bricks` | **404** | absent | absent | empty |
| Same tree, switched back | `wordpress` | **200**, built from `post_content` | present | present | full output |

The second row is the one a presence-based check fails, and copying the real
tree rather than inventing one is what makes it evidence. Keep both when
refreshing this environment.

Two further properties, both measured rather than assumed:

- **The census counts render modes, not payloads.** With two pages holding the
  tree and two revisions of one of them also holding it, the panel reported
  *Pages — 1 Bricks, 4 Gutenberg*: right on both halves at once.
- **Writing the census does not move the cache salt.** Asserted live, not only
  in the suite.
