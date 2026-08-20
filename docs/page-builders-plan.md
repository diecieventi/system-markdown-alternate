# Page builders — Bricks first, a veto for the rest

> Implementation plan. Status: **not started.** Written against `main @ 0.44.0`.
>
> Scope was fixed with the maintainer in August 2026 and is deliberately narrow:
> **Bricks is the one builder to support.** Elementor is parked. Divi, WPBakery,
> Oxygen, Beaver Builder and Breakdance are never to be supported at all — a
> post built with one of them simply has no Markdown representation.
>
> Phase 1 (the veto) does not depend on the reconnaissance and can ship on its
> own. **Do not write a Bricks adapter before the reconnaissance in §6** — the
> two questions that decide its shape (does an AJAX save move `post_modified`,
> and does the builder render at all with the main query in a 404 state) cannot
> be answered by reading source.

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

Staging with Bricks is available. Elementor staging would be **free-only**,
which is a further reason to park it: Theme Builder, the Template widget, global
widgets and loop grids — the out-of-post dependencies that make an Elementor
adapter hard — are all Pro, so a free-only staging cannot validate the part that
actually needs validating.

Questions to answer before any adapter code. Each one changes the design:

1. **Does the builder render with the main query in a 404 state?** The `.md`
   suffix route resolves nothing, so `$wp_query` is a 404 while
   `build_markdown()` sets only `$GLOBALS['post']` + `setup_postdata()`. Bricks
   consults the queried object. If it renders empty or wrong, the adapter needs
   a query context faked as well — invasive, and a decision to take
   deliberately.
2. **Does an AJAX save move `post_modified_gmt`?** Decides how much §5 has to
   carry.
3. **What is the exact meta key and value set for the render mode?** §3.1 rests
   on it.
4. **What is the exact signature of `\Bricks\Frontend::render_data()` on the
   installed version?** The `bricks/frontend/render_data` filter documents the
   second argument as `$area` (`header`/`content`/`footer`); snippets in
   circulation pass a post ID instead.
5. **What HTML actually comes out, and how much of it already survives the
   converter?** `MarkdownConverter` runs with `strip_tags => true`, so nested
   `brxe-*` wrappers may already collapse on their own. Measure before writing a
   chrome-unwrapping pass — do not build it on a hypothesis.
6. **Does rendering enqueue or echo assets?** The `.md` route exits before
   anything is printed, so it should be harmless; confirm under output
   buffering.
7. **Edge case 6**: how common is the Post content element in real Bricks pages,
   and is suppressing foreign `the_content` filters worth it?

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
front-end request. With a builder it is worse — theme-builder conditions and the
queried object. Keep `sysmda_markdown_prewarm` off and say so in the docs.

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
| **1** | The veto: `BuilderDetector`, the rule in `is_servable()`, a Stable escape filter. Divi/WPBakery/Oxygen/Beaver/Breakdance out permanently; Bricks and Elementor out temporarily | nothing |
| **1b** | Panel labels | nothing |
| **0** | Bricks reconnaissance (§6) | staging — available |
| **2** | Bricks adapter; Bricks leaves the unsupported list | Phase 0 |
| **3** | Elementor — only on real demand, and only with a Pro staging | — |

Phases 1 and 1b are shippable on their own and are most of the value: the
concrete risk today is not the missing Bricks adapter, it is a wrong `.md`
published without anyone noticing.

## 10. Open questions

1. In Phase 1, do Bricks and Elementor 404 **immediately**, even though the
   Bricks adapter follows shortly? Recommended yes — consistent, and better than
   the current emptiness.
2. Edge case 6: accept the related/CTA content that `the_content` reintroduces
   through the Post content element, or suppress foreign filters around the
   render? Leave open until §6.7.
