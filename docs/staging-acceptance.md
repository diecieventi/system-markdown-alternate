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

### Note for the multilingual work

Not a defect, but it belongs in `docs/llms-txt-multilingual-plan.md` before any
code is written there. `BuilderCensus` counts with raw SQL, so on the Polylang
site it reports the **whole corpus**; a `get_posts()`-based count returns only
the current language's slice (31 published against 20, measured). For an
advisory breakdown describing what a content type is built with, the corpus is
arguably the right answer — but the two numbers differ, and whichever the
multilingual work picks should be a decision rather than an accident.
