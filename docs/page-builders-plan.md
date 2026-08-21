# Page builders — Bricks first, a veto for the rest

> Implementation plan. Status: **Phases 1, 1b, 0 and 2 are all shipped**
> (`0.46.0`) — `bricks` has left `BuilderDetector::AWAITING_ADAPTER` and a
> Bricks-mode post now produces a real `.md` through `BricksAdapter`. Written
> against `main @ 0.45.1`, updated through `0.46.0`.
>
> Scope was fixed with the maintainer in August 2026 and is deliberately narrow:
> **Bricks is the one builder to support.** Elementor is parked. Divi, WPBakery,
> Oxygen, Beaver Builder and Breakdance are never to be supported at all — a
> post built with one of them simply has no Markdown representation.
>
> Phase 1 (the veto) does not depend on the reconnaissance and shipped on its
> own. The reconnaissance in §6 closed with one genuinely new finding —
> **Bricks' own image lazy-loading silently breaks every image conversion
> unless the adapter disables it around the render call** — which Phase 2 (§7)
> then implemented as `BricksAdapter`, verified live against a real WordPress
> attachment (a raw external image reference never triggers Bricks' own
> lazy-load filter, so reproducing the bug at all requires one — see
> docs/staging-acceptance.md). §10's open question was also resolved during
> Phase 2: foreign `the_content` filters are suppressed while Bricks' Post
> Content element renders, behind a maintainer-reversible filter default on.

## 1. The problem

The pipeline assumes the content is in `post_content`: `ContentRenderer::render()`
filters the source, strips excluded shortcodes, and then branches on
`has_blocks()` (`src/ContentRenderer.php:58`). Page builders break that
assumption, in two different ways.

| Builder | Where the content lives | `.md` today |
|---|---|---|
| Bricks | meta `_bricks_page_content_2` (serialized) | **Empty** — front matter + `# Title` and nothing else |
| Elementor | meta `_elementor_data` (JSON) | **Empty** |
| Beaver Builder | meta `_fl_builder_data` | **Empty** |
| Oxygen | meta `ct_builder_shortcodes` | **Empty** |
| Breakdance | meta `_breakdance_data` | **Empty** |
| Divi 4 | `post_content` as `[et_pb_*]` shortcodes | **Wrong** — layout chrome converted as prose |
| WPBakery | `post_content` as `[vc_*]` shortcodes | **Wrong** — same |

The second class is the worse one. An empty `.md` is useless; a `.md` full of
builder wrappers is actively misleading to the audience this plugin exists for,
and nothing about it looks broken from the admin side.

The damage is not confined to the body. `MetadataBuilder::description()` falls
back to `post_content`, so on a builder site without Rank Math descriptions or
excerpts the front-matter `description:` **and every `/llms.txt` entry** come
out empty too.

## 2. The one mechanism: a veto, not a feature

An unsupported builder needs no new plumbing. `PostSupport::is_servable()`
(`src/PostSupport.php:106`) is already the single source of truth every consumer
goes through, so a post built with an unsupported builder is made **not
servable** and everything follows by construction:

- the `.md` URL **404s**;
- no `<link rel="alternate">` in the head and no `Link:` header;
- the post is absent from `/llms.txt`;
- `[sysmda_md_url]`, `[sysmda_md_download]`, `[sysmda_md_actions]` and the
  GenerateBlocks dynamic tag all render nothing.

This is the same shape as the non-standard post format rule, and it should be
built the same way: a built-in list with a Stable escape-hatch filter.

**The same mechanism carries the phasing.** Bricks and Elementor sit in the
unsupported list *until their adapter exists*; each adapter, when it lands,
moves its builder out of the list. One mechanism, incremental coverage, and no
window in which an empty or wrong `.md` is published.

Behaviour change to document: posts that today serve a near-empty `.md` will
start returning 404. Per the documentation rule in `AGENTS.md` that touches
`readme.txt`, `README.md`, an article under `documentation/` and
`docs/staging-acceptance.md`, in the same PR.

## 3. Detection is per-post. Never per-site, never per-type

The observation that shapes the whole design: sites are commonly built with
Bricks (or Elementor) for pages while **articles keep using the normal editor**,
Gutenberg or classic. Mixed post types are the normal case, not the exception.

So the rule, which belongs in `AGENTS.md` as a durable decision alongside "never
auto-detect which taxonomies to emit":

> **The post type determines nothing.** Panel labels inform; per-post detection
> decides.

Its most important consequence, and the scenario worth stating explicitly
because it is the one that sounds dangerous and is not: **activating Bricks on a
site with 150 Gutenberg articles changes nothing.** None of those posts carries
Bricks render data of its own, so none is claimed by the adapter and all stay on
the existing `has_blocks()` branch. Only a post actually rebuilt in Bricks moves.

### 3.1 Detect the render mode, not the presence of data

Detecting on "does the builder blob exist" is **wrong**, and Bricks documents
why: the *Render with Bricks / Render with WordPress* toggle switches the mode
per post, and a post set to render with WordPress **keeps its Bricks data stored
while the front end serves the WordPress content**.

A post can therefore hold a full `_bricks_page_content_2` and legitimately
render Gutenberg. Keying on the blob would publish a representation no visitor
ever sees — the same class of error as the old `post_password_required()` check,
where the question asked ("does the data exist / has this visitor unlocked it?")
was not the question that mattered ("what is actually served?").

The same argument applies to Elementor and is an extra reason to call
`is_built_with_elementor()` rather than read `_elementor_edit_mode` directly:
the official method already accounts for "Back to WordPress Editor".

**Phase 1 reads the meta anyway, and that is not an oversight.** §3.2 requires
the veto to hold with the plugin deactivated, so a vendor call cannot be the
primary source and a meta read has to exist regardless. Elementor's own
`is_built_with_elementor()` is a truthiness test on that same
`_elementor_edit_mode` row, which the "Back to WordPress Editor" action deletes,
so the two agree today; adding a vendor-preferred branch on top would have
bought nothing except a code path the pure suite cannot exercise. **The adapter
is where the vendor call belongs** — it requires the plugin active by
construction, so there the API is free and strictly better.

### 3.2 A deliberate asymmetry: veto and adapter have different preconditions

- **Adapter** (Bricks, later Elementor) → requires the plugin to be **active**.
  With no renderer there is nothing to render, and with Bricks deactivated the
  visitor sees `post_content`, so the ordinary pipeline is the *correct* answer.
- **Veto** (Divi, WPBakery, …) → applies **whether the plugin is active or
  not**. With Divi deactivated its `[et_pb_*]` shortcodes stay in `post_content`
  unregistered and would be published as literal text — the worst outcome of all.

### 3.3 Detect the veto from meta, never by sniffing `post_content`

Use `_et_pb_use_builder`, `_wpb_vc_js_status` and the equivalents. Do **not**
substring-match `[et_pb_` in the content: an article documenting Divi and
quoting the shortcode in a code sample would be made unservable by its own
example. That is exactly the defect `CodeRegions` exists to prevent, one level
up.

## 4. Edge cases

| # | Scenario | Expected behaviour |
|---|---|---|
| 1 | Bricks activated on a site of 150 Gutenberg posts | Nothing changes; no post is claimed |
| 2 | Post authored in Gutenberg, later rebuilt in Bricks | Current render mode wins |
| 3 | Bricks post switched to "Render with WordPress" | Back to the `post_content` pipeline — **data present ≠ data used** (§3.1) |
| 4 | Bricks deactivated, post still holds Bricks data | Ordinary pipeline on `post_content`, matching what the visitor sees |
| 5 | Bricks single-post template, article in Gutenberg | Ordinary pipeline. The template's author box / related / CTA are ignored, which is the intended behaviour and the same reason `the_content` is skipped |
| 6 | Bricks page containing the **Post content** element | Bricks calls `the_content`, which reintroduces injected related/CTA content — precisely what the pipeline avoids. Open, see §6 |
| 7 | `bricks_template` CPT | Should already be excluded by the public-type policy — confirm |
| 8 | Mixed type (some pages Bricks, some Gutenberg) | Handled natively; this is why the panel must show a breakdown, not a label |
| 9 | Post transitions servable → not servable | `is_servable()` runs per request before the cache, so the 404 is immediate. `/llms.txt` is cached: the residue is bounded by the TTL, the same one already accepted for post formats |
| 10 | **Bricks saves over AJAX** | If it does not move `post_modified_gmt`, the `.md` stays stale and answers `304`. **The top correctness risk** — see §5 |
| 11 | Global element / component / referenced Bricks template | Lives outside the post row → must enter `dependencies_fingerprint()` |

## 5. The cache validator

Per "Technical notes" 6: anything that can change the emitted Markdown without
touching `post_modified_gmt` **must** be folded into `cache_version()`, or a
client holding the old validator keeps receiving `304` with stale content, body
cache or not.

For Bricks that means the fingerprint must cover, at minimum:

- a hash of the render data blob;
- **the render mode**, or edge cases 2 and 3 — the two most likely transitions —
  pass the validator invisibly;
- referenced templates, global elements and components (edge case 11).

Two consequences to accept up front:

- A non-empty fingerprint already disables the `If-Modified-Since` path, so
  **every Bricks post permanently loses it**. Consistent with the existing
  design; state it in the docs.
- `cache_version()` is on the every-request path, `304`s included. Hashing a
  large blob there is measurable — **measure it, do not assume it**. The same
  rule `docs/filters.md` states for filter authors applies to the plugin itself.

## 6. Reconnaissance (Phase 0, Bricks only)

Elementor staging would be **free-only**, which is a further reason to park it:
Theme Builder, the Template widget, global widgets and loop grids — the
out-of-post dependencies that make an Elementor adapter hard — are all Pro, so a
free-only staging cannot validate the part that actually needs validating.

### 6.1 The environment

The connected site **`sma-bricks-instawp-co`** — a clone of the existing
staging, taken in August 2026. Named by its connected-site alias, not by its
hostname, for the reason `docs/staging-acceptance.md` already gives: staging
URLs are transient and belong outside the repository, which is why that file
names the release environment `instawp_sma` rather than its address. A clone
because the original stays the release acceptance environment; converting it
would have burned that baseline.

**Bricks is a theme, not a plugin.** It replaces GeneratePress rather than
coexisting with it, which is why the clone was necessary at all.

**The theme barely matters to the `.md`.** The pipeline renders cleaned blocks
directly and never goes through `the_content` or the theme's template, so
Gutenberg posts convert identically under Bricks. Three surfaces do depend on
the theme and must be re-verified here: the `<link rel="alternate">` printed on
`wp_head`, the `Link:` response header, and `[sysmda_md_actions]` rendering
through `wp_footer`.

**Never alternate the active theme during a test run.** The scenario to exercise
is Bricks and Gutenberg *coexisting* — which is the whole reason detection is
per-post — not one replacing the other.

And if a theme is ever switched anyway, **purge explicitly: nothing does it for
you.** There is no `switch_theme` hook anywhere in the plugin; the global salt
is bumped only for the site-wide inputs `AdminSettings::boot()` lists
(permalink structure, home URL, timezone, author display name, the two
front-matter taxonomies) and per-post entries are dropped only on `save_post` /
`deleted_post`. So a switch leaves both layers intact and a body built under the
previous theme keeps being served, validator included. The theme is *usually*
irrelevant to the output, per the paragraph above — but `render_block()` and
`do_shortcode()` still run through whatever filters a theme registers, which is
exactly the difference a stale entry would hide.

State verified on that site at setup:

| | |
|---|---|
| Active theme | Bricks 2.0 (GeneratePress and its child theme present but inactive) |
| Plugin | System Markdown Alternate 0.44.0, active |
| Also active | ACF 6.8.8 |
| **Not installed** | **GenerateBlocks, Rank Math** |
| Bricks content | one page (ID 18), plus two revisions of it |

Two consequences. The GenerateBlocks dynamic tag and the Rank Math description
path cannot be exercised there, so they stay on the original staging —
note that the dynamic tag keys on the *plugin* class
(`GenerateBlocks_Register_Dynamic_Tag`), not on the theme, so installing the
plugin here would restore it if ever needed. And the Bricks corpus still has to
be built: Bricks pages, **one Bricks page switched to "Render with WordPress"**
(edge case 3, the most important single fixture), alongside the Gutenberg and
classic posts the clone already carries.

Divi and WPBakery are deliberately **not** installed. Phase 1 only needs their
meta keys, which the pure suite can stub.

### 6.2 The questions

Questions to answer before any adapter code. Each one changes the design.
**All seven are now answered** (August 2026, `sma-bricks-instawp-co`, Bricks
2.0) — Phase 0 is closed; see §9.

1. ~~**Does the builder render with the main query in a 404 state?**~~
   **Answered: no dependency at all.** `\Bricks\Frontend::render_data()` was
   called directly against page 18's real `_bricks_page_content_2` tree under
   three query contexts: (a) `$wp_query->set_404()` + `$GLOBALS['post']` +
   `setup_postdata()` — the exact shape `build_markdown()` produces on the
   `.md` suffix route; (b) a genuine singular `WP_Query` — the shape the
   negotiated-permalink route produces; (c) a real loop with `the_post()`,
   which is the one thing neither route ever does and the only way
   `in_the_loop()` becomes true. Output was byte-identical across all three,
   including for dynamic tags (`{post_title}`, `{post_modified}`) and for the
   **Post Content** element, and no PHP warning or notice was raised in the
   404 case. `render_data()` reads dynamic data off `$post`/the loop, never off
   `get_queried_object()` or `$wp_query`. **No faked query context is needed —
   `render_data()` can be called exactly as `build_markdown()` already sets
   things up.**
2. ~~**Does an AJAX save move `post_modified_gmt`?**~~ **Answered: yes**
   (August 2026, same site). The one Bricks page has `post_date 2026-01-02` and
   `post_modified 2026-08-20`, moved by a save from the Bricks editor. Edge case
   10 — "the top correctness risk" — therefore does **not** apply to ordinary
   saves, and §5 shrinks accordingly: the fingerprint still has to cover the
   render mode (edge cases 2 and 3 are transitions, and a mode flip need not
   touch the post row) and the out-of-post dependencies of edge case 11, but not
   the everyday edit.
3. ~~**What is the exact meta key and value set for the render mode?**~~
   **Answered** (August 2026, on `sma-bricks-instawp-co`, Bricks 2.0). The page
   carries three meta rows: `_bricks_editor_mode = "bricks"`,
   `_bricks_template_type = "content"` and `_bricks_page_content_2` holding the
   serialized tree. The mode row is the one to key on, and Phase 1 does.

   **Revisions carry the payload but not the mode.** Measured on the same site:
   two `post_type = 'revision'` rows hold `_bricks_page_content_2` while only the
   published page holds `_bricks_editor_mode`. Anything counting the payload —
   the Phase 1b census in particular — would report one Bricks page three times.
   A second reason to key on the mode, beyond §3.1, and the reason `BuilderCensus`
   constrains `post_status` and `post_type` as well.
4. ~~**What is the exact signature of `\Bricks\Frontend::render_data()` on the
   installed version?**~~ **Answered** (Bricks 2.0, via `ReflectionMethod`):
   `render_data( $elements = [], $area = 'content' )`, `public static`. The
   second argument is confirmed to be `$area`, never a post ID — the plan's
   suspicion about circulating snippets was correct to flag, and is now settled
   rather than assumed. The adapter calls it as
   `\Bricks\Frontend::render_data( $post_meta['_bricks_page_content_2'], 'content' )`.
5. ~~**What HTML actually comes out, and how much of it already survives the
   converter?**~~ **Answered, with a real defect found.** A representative
   tree (section → container → heading, rich `text-basic`, image, button,
   list, Post Content) was rendered and piped through the actual
   `MarkdownConverter::convert()` used in production. The `brxe-*`/section/
   container chrome collapses cleanly under `strip_tags` exactly as hoped —
   heading, rich inline text, links and Post Content all convert correctly,
   confirming the "measure before building a chrome-unwrapping pass" instinct:
   **no unwrapping pass is needed.**

   **But images are broken by default.** Bricks' own JS lazy-loading
   (`bricks-lazy-hidden`) replaces `src` with an inline `data:image/svg+xml`
   placeholder and moves the real URL to `data-src`/`data-srcset`; the library
   only ever reads `src`, so every Bricks image converts to
   `![](data:image/svg+xml,...)` — meaningless, and silently so (no error, no
   empty output to notice). Root cause and fix both verified in
   `includes/elements/base.php::lazy_load()`: it returns `false` — real `src`
   emitted — when `Database::$page_settings['disableLazyLoad']` (or the global
   equivalent) is set. Confirmed live: setting
   `\Bricks\Database::$page_settings['disableLazyLoad'] = true;` immediately
   before `render_data()` (and restoring the prior value after, same discipline
   as `build_markdown()`'s own `$GLOBALS['post']` save/restore) makes every
   image emit its real `src`/`srcset`, and the Markdown comes out as a normal
   `![](url)`. **The adapter MUST set this flag around the render call — this
   is not optional cleanup, it is the difference between a working and a
   silently-broken image in every Bricks post.**

   Two secondary findings, lower priority than the lazy-load fix: adjacent
   inline elements (an image immediately followed by a button, both inline in
   the source) convert with no blank line between them, which is a pre-existing
   library/converter interaction rather than anything Bricks-specific — worth a
   look once the adapter has real pages to test against, not a blocker. The
   `list` element's item-text field name was not confirmed (the test guessed
   wrong and produced empty bullets); a low-traffic element, deferred to
   adapter implementation rather than reconnaissance.
6. ~~**Does rendering enqueue or echo assets?**~~ **Answered: no.** Rendering a
   tree containing Post Content (the one element that pulls in Gutenberg core
   block styles via `the_content`) under output buffering produced zero
   echoed bytes and enqueued only stylesheet handles (`wp-block-library` and
   friends) — never scripts, never inline output. Confirmed harmless as
   expected: the `.md` route exits at `template_redirect` before `wp_head`/
   `wp_footer` ever print the queue, on either route.
7. **Edge case 6, mechanism confirmed; frequency still unknowable from a
   synthetic corpus.** The Post Content element does call the full
   `the_content` filter chain — verified by giving page 18 real `post_content`
   and confirming the Post Content element's output tracks `the_content()`
   byte-for-byte, unaffected by `in_the_loop()`. This is exactly the class of
   interference `render_block()` was chosen over `the_content` to avoid
   elsewhere in this plugin (Technical notes §4), so **the recommendation is to
   suppress foreign `the_content` filters when the adapter's Post Content
   element renders**, for consistency with that existing principle — but no
   staging site can manufacture the "how common" half of the question, since
   that depends on which plugins a real Bricks site runs. Carried into Phase 2
   as a design input with a recommendation, not as a blocking unknown: this is
   the one question of the seven that reconnaissance cannot fully close, and
   waiting for it to become "answerable" would block the adapter indefinitely.

## 7. The adapter, once §6 is answered

Approach: **the builder renders its own content; the plugin never
re-implements it.** Read the builder's tree only to *decide* — detection, cache
fingerprint, description text — never to *produce*. Same shape as
`candidate_taxonomies()`, which builds the panel list and never gates output.

The rejected alternative is mapping Bricks element types to semantic HTML. It is
the "block-native Markdown engine" already evaluated and rejected in `AGENTS.md`,
with the surface multiplied: anything unmapped disappears silently, which is the
worst possible failure mode, and dynamic data would go unresolved. Rendering
through the vendor also means third-party elements work for free.

Also rejected, per existing doctrine: fetching the front end over HTTP and
scraping it. Loopback requests were already rejected twice as unreliable behind
a WAF or proxy.

### 7.1 The seam

A **third branch** in `ContentRenderer::render()`, before the `has_blocks()`
test:

```
render():
  1. sysmda_markdown_source_content
  2. strip excluded shortcodes
  3. ┌ builder claims the post? → $adapter->render( $post )      ← NEW
     ├ has_blocks()?            → parse/clean/render_block + expand_shortcodes
     └ otherwise                → wpautop( expand_shortcodes() )
  4. process_dom() + element-type exclusion pass
  5. sysmda_markdown_rendered_html
```

Do **not** hang the integration off `sysmda_markdown_source_content`, which is
the seam that looks obvious and is wrong: already-rendered HTML would fall into
the classic branch and get `wpautop()` plus a second `do_shortcode()` pass over
output that has already been expanded.

One new hook on the new stage, classified **Advanced** — it is anchored to a
pipeline stage, exactly like `sysmda_markdown_rendered_html`.

### 7.2 Shape

```php
interface BuilderAdapter {
    public function is_active(): bool;                  // plugin present
    public function handles( \WP_Post $post ): bool;    // render mode, not data presence
    public function render( \WP_Post $post ): string;   // vendor API
    public function fingerprint( \WP_Post $post ): array;
    public function source_text( \WP_Post $post ): string;
    public function element_selectors(): array;
}
```

For Bricks, `render()` is not a bare call to `\Bricks\Frontend::render_data()` — per
§6.2.5, it MUST bracket the call with the lazy-load flag, save/restore the prior
value the same way `MarkdownController::build_markdown()` already save/restores
`$GLOBALS['post']`:

```php
$previous = \Bricks\Database::$page_settings['disableLazyLoad'] ?? null;
\Bricks\Database::$page_settings['disableLazyLoad'] = true;
try {
    $html = \Bricks\Frontend::render_data( $tree, 'content' );
} finally {
    if ( null === $previous ) {
        unset( \Bricks\Database::$page_settings['disableLazyLoad'] );
    } else {
        \Bricks\Database::$page_settings['disableLazyLoad'] = $previous;
    }
}
```

Skipping this silently ships `![](data:image/svg+xml,...)` for every image on
every Bricks post — no error, no empty output, nothing that looks broken in
review. Per "a guard is not done until it has been seen to fire" in `AGENTS.md`,
the adapter's test suite must construct an image element, render it with the
flag OFF, and see the placeholder — not just see the flag ON case pass.

### 7.3 Exclusions degrade, and the replacement is real

Shortcode-level exclusion cannot work on builder content: the builder renders a
`[contact-form-7]` inside its own text element before the plugin can see it. Two
replacements, both usable:

- **The existing `md-exclude` class already works**, because Bricks exposes a
  CSS-classes field per element. Cheapest win in the whole plan — document it
  first.
- A new **excluded builder elements** list, applied as a DOM pass on the
  attributes builders emit (`brxe-*` for Bricks, `data-widget_type` for
  Elementor). Per the `0.40.0` decision it must **add to the defaults, not
  replace them**. Conservative defaults only: forms, nav/menu, share, TOC,
  breadcrumbs. Not post lists — those are often real content.

### 7.4 `description()` and `/llms.txt`

The fallback needs `source_text()` from the adapter. But `/llms.txt` **cannot
afford to render N builder posts** to build N descriptions. The first two rungs
(Rank Math, excerpt) already cover most builder sites, which nearly always run
an SEO plugin; for the fallback, extract string leaves from the builder data
structure with no semantic mapping — crude, may pick up a button label, but
cheap, and it is for 200 characters.

The rule in `AGENTS.md` still binds: anything deriving text from stored data
rather than the rendered body owes the same exclusion pass.

### 7.5 Prewarm

The existing decision already warns that cron is not a faithful stand-in for a
front-end request. §6.2.1 showed `render_data()` itself does not depend on the
queried object, so that specific risk is narrower than originally assumed —
but element-level **visibility conditions** (a Bricks element can be scoped to
"only on archive", a device, a query var, …) are unverified under cron and
remain the open risk. Keep `sysmda_markdown_prewarm` off and say so in the
docs.

## 8. Panel labels (Phase 1b)

When choosing which post types to enable, show what each type is actually built
with — a **breakdown**, not a single label, because mixed types are normal:

```
☑ Posts       — 148 Gutenberg, 2 classic
☐ Pages       — 12 Bricks, 3 Gutenberg
☐ Case study  — 8 Divi  ⚠ not supported: no .md
```

Three constraints, each dictated by an existing precedent:

- **Advisory only.** It never filters, enables or disables anything.
- **Computed on the settings screen only**, never on the front end, cached in a
  transient.
- If it is ever stored in an option, that option must be **excluded from the
  settings-save cache-salt bump**, like the hit-counter buckets.

It is worth its own phase because it makes what Phase 1 does visible: the answer
to "are my articles affected?" should take three seconds, not an audit.

## 9. Phases

| Phase | Content | Blocked by |
|---|---|---|
| **1** ✅ | The veto: `BuilderDetector`, the rule in `is_servable()`, the Stable `sysmda_markdown_unsupported_builders`. Divi/WPBakery/Oxygen/Beaver/Breakdance out permanently; Bricks and Elementor in `AWAITING_ADAPTER` | nothing |
| **1b** ✅ | Panel labels — `BuilderCensus`, one query, transient-cached, admin only | nothing |
| **0** ✅ | Bricks reconnaissance (§6) — all seven questions in §6.2 answered, including the lazy-load image defect and fix | nothing |
| **2** ✅ | Bricks adapter (`BricksAdapter`); `bricks` left `AWAITING_ADAPTER` in `0.46.0` | nothing — done |
| **3** | Elementor — only on real demand, and only with a Pro staging | — |

Phases 1 and 1b are shippable on their own and are most of the value: the
concrete risk today is not the missing Bricks adapter, it is a wrong `.md`
published without anyone noticing.

## 10. Open questions

1. ~~In Phase 1, do Bricks and Elementor 404 immediately?~~ **Resolved: yes.**
   Both shipped in `AWAITING_ADAPTER`. Consistent with the rest, and better than
   the current emptiness.
2. ~~Edge case 6: accept the related/CTA content that `the_content` reintroduces
   through the Post content element, or suppress foreign filters around the
   render?~~ **Resolved in Phase 2, as a maintainer-reversible default rather
   than a settled answer.** `BricksAdapter::maybe_suppress_content_filters()`
   removes every callback foreign to WordPress core from `the_content` for the
   duration of the render, but only when the tree actually contains a
   `post-content` element (the common case — a page with no Post Content
   element pays nothing) and only when the new
   `sysmda_markdown_builder_suppress_content_filters` filter (Advanced,
   default `true`) allows it. Verified live on `sma-bricks-instawp-co`: a
   foreign `the_content` callback appending a "SUBSCRIBE NOW" block is present
   in the Post Content element's output without the suppression and absent
   with it, while `wpautop`/`do_shortcode` still run either way — confirming
   the mechanism does what §6.2.7 predicted without breaking ordinary content
   processing. The snapshot-and-restore has one sharp edge, caught by testing
   rather than reasoning about it: `$wp_filter['the_content']` is a `WP_Hook`
   object, so the snapshot must be a `clone`, not a bare property read — a
   bare read shares the same object `remove_all_filters()` then empties,
   silently turning the restore into a no-op. If a real Bricks site's
   experience argues the default should be reversed, that is one filter call
   away, not a code change.
