# noindex-aware `/llms.txt`, and a `## Sitemaps` section

> Implementation plan. Status: **designed, not started.** Scope was fixed with
> the maintainer in August 2026 and is deliberately narrow. Written against
> `main @ 0.49.3`.
>
> **The `.md` endpoint does not change.** Not one rule of `is_servable()` moves,
> and no `.md` that resolves today stops resolving. The whole feature lives in
> `LlmsTxtController`.
>
> §8 lists seven measurements that have **not** been taken yet. They are
> blocking: this feature is, in its entirety, a guard, and the rule "a guard is
> not done until it has been seen to fire" applies to it directly.

## 1. What the code already guarantees, and why it settles the first question

The obvious design — teach `PostSupport::is_servable()` about noindex, so a
noindex post loses its Markdown representation everywhere at once — is **wrong**,
and the reason is a property the plugin already has.

Every Markdown body leaves through one exit. `MarkdownController::serve_markdown()`
(`src/MarkdownController.php:911`) is the single shared tail of both routes — the
`.md` suffix and the negotiated permalink — and it calls `send_headers()`
(`:1333`), which emits:

```php
$robots = apply_filters( 'sysmda_markdown_robots_header', 'noindex, follow', $post );
```

So a noindex article's Markdown twin cannot re-enter a search index: it is
`noindex` itself, by default, on every path that emits a body. Serving it is
correct, and withdrawing it would remove a representation from the audience this
plugin exists for — AI agents and technical clients — to solve a search-engine
problem that is already solved.

Three caveats, none of which weakens the conclusion:

- **A `304` carries no `X-Robots-Tag`** (`send_not_modified()`, `:1162`). It does
  not need one: a `304` is only reachable by a client that already received a
  `200` carrying the header, since it needs that response's `ETag` to ask. It is
  reusing a stored copy, headers included.
- **`/llms.txt` sends the header hardcoded** (`src/LlmsTxtController.php:154`),
  not through the filter. That is a deliberate asymmetry and stays.
- **The header is a default, not an invariant.** The Advanced panel field
  `sysmda_robots_header` and the filter above can both empty it, and
  `send_headers()` then sends nothing. A site that empties it has explicitly
  asked for an indexable `.md`; that is its decision, not a hole in this design.

**Therefore `is_servable()` is not touched.** Whatever is servable today stays
servable.

## 2. What changes, and why it is the right place

`/llms.txt` **is** an index. Honouring noindex there is the direct meaning of the
directive rather than an analogy, and it is the only lever on which "do not
promote this" and "answer if asked" can both be true at once. The `.md` keeps
answering; the index stops advertising.

This introduces the first divergence in the plugin's history between **servable**
and **listed**. Today the two coincide exactly, at both places `/llms.txt`
decides what to print. The new rule therefore sits **beside** `is_servable()`,
never inside it — `is_servable( $post ) && ! is_noindex( $post )` — and that
distinction is the single most important line in this document. Folding it into
`is_servable()` would silently 404 the `.md`, which is the one thing the scope
forbids.

## 3. Decisions

All taken with the maintainer in August 2026.

| Question | Decision |
|---|---|
| Per-post noindex | **Not listed** |
| Post-type-wide noindex default (SEO plugin settings) | **Not listed** — posts of that type leave the index |
| Site-wide noindex (`blog_public = 0`) | **No `.md` links at all**, but the endpoint still answers **200** with the site identity, summary, `## Sitemaps` and footer |
| `## Key content` (the curated list in the panel) | **Stays.** A hand-written entry wins over noindex |
| SEO plugins covered | **Rank Math and Yoast** only for now |
| Activation | **On by default**, with a filter to switch it off. No new panel checkbox |

### 3.1 Why `200` and not `404` under a site-wide noindex

Answering `404` was proposed and rejected, and the reason is worth recording
because the `404` looks more principled than it is.

`maybe_render_llms_txt()` already has a precedent for silence: with no content
type enabled it returns and leaves the `404` (`:68-70`). A globally noindexed
site producing a heading plus zero entries has the same shape, so the analogy is
real.

What kills it is a coupling the analogy hides. `MarkdownController::should_advertise_llms_txt()`
(`:167`) gates the `rel="describedby"` discovery on exactly two things — the
request being negotiable, and `sysmda_llms_txt_enabled`. **Neither turns false
under `blog_public = 0`.** Posts stay servable, the HTML still renders, so every
page on the site would go on advertising a `/llms.txt` that answers `404`. Its
own docblock warns about this class of mistake, which was caught in review once
already before `0.49.0` shipped.

So the `404` costs a second gate that must stay in step with the first, forever.
The `200` costs nothing and keeps the discovery link honest.

There is a second argument, independent of the coupling. `blog_public = 0` is
overwhelmingly *"this site is being built"*, not an editorial judgement about
content. Removing the index there protects nothing — the `.md` stay reachable
either way, by the scope decision — and hands a confusing `404` to whoever is
mid-setup. A per-post noindex is the opposite: somebody decided, about that
specific piece of content, that it does not belong in indexes. That is the signal
worth honouring, and it is honoured.

### 3.2 Why the curated list is exempt

`## Key content` is the one section whose entries a human typed into the panel by
ID or URL. That is a deliberate act about specific content, and it outranks a
site-wide preference expressed elsewhere.

The cost is a documented divergence between the two call sites: `servable_posts()`
applies the noindex rule and `key_content_items()` does not. It has to be stated
in the code, because two guards written to mirror each other and then not
mirroring each other is a defect this codebase has already shipped once (the
`0.37.0` alternate-link guard).

Note the interaction with §3.1: under a **site-wide** noindex no `.md` links are
printed at all, Key content included. The exemption is for the per-post and
per-type levels only.

### 3.3 Why on by default, with no checkbox

Every *exclusion* rule in this plugin is on by default with a filter as the
escape hatch and no panel field: non-standard post formats, the page-builder
veto, WooCommerce's cart/checkout/my-account pages. Panel checkboxes are reserved
for *additions* — optional taxonomies, `lastmod` dates, enriched mode — where the
promise is "off means byte-identical output **and** byte-identical validator".

Filtering noindex content out of an index is a removal, like the other three.

The accepted cost is that an existing site's `/llms.txt` can lose entries on the
first update after this ships, silently. That is the point of the change, and the
direction is right: a site that *wants* the behaviour gets it without doing
anything, and only a site that rejects it needs to write PHP. A checkbox reverses
that, and the majority who would want it would never learn it exists.

### 3.4 Rejected, and not to be proposed again

- **Wiring noindex into `is_servable()`.** Breaks the scope decision in §1: the
  `.md` would `404`.
- **Suppressing `/llms.txt` under `blog_public = 0`.** See §3.1.
- **A panel checkbox.** See §3.3.
- **Mirroring Rank Math exactly.** Rank Math lists everything under a site-wide
  noindex (observed on a real install, August 2026). The behaviour above is more
  coherent than theirs, and "another plugin does it" was never the argument.

## 4. Integration points

Exactly two places decide what `/llms.txt` prints, plus one short-circuit.

**4.1 The listing** — `LlmsTxtController::servable_posts()`, the filter inside the
batch loop (`:384-387`):

```php
foreach ( $batch as $post ) {
    if ( ! PostSupport::is_servable( $post ) ) {
        continue;
    }
```

becomes a conjunction with the new predicate. Beside, never inside — §2.

**Cost: zero extra queries.** That loop already primes the meta cache
unconditionally (`'update_post_meta_cache' => true`, `:379`), which is where an
SEO plugin's per-post robots meta lives. The priming is asserted in the suite
rather than assumed, because it was silently `false` on the basic path once
before and the regression had no symptom (Codex, PR #97).

**4.2 The curated list** — `key_content_items()` (`:518`) does **not** apply the
per-post or per-type noindex rule, per §3.2. It needs a comment saying so, and
why, because two guards written to mirror each other and then not mirroring each
other is a defect this codebase has shipped before.

**4.3 The site-wide short-circuit** — read `blog_public` once, before any query,
and skip **both** producers of `.md` links: the whole `servable_posts()` loop
**and** the `key_content_items()` call.

**Both, not just the listing.** `build()` calls `key_content_items()` at `:249`,
inside the `$enriched` branch and *before* the per-type loop at `:262`, so a
short-circuit that only skipped `servable_posts()` would still print every
curated `.md` link on a site-wide-noindexed site — contradicting §3.1, §3.2's own
closing note and acceptance fixture 4. The Key content exemption is for the
**per-post and per-type** levels only; the site-wide level admits no exceptions,
because there "nothing is promoted" is the whole statement. Caught by Codex on
PR #132, in this document, before any code existed.

The short-circuit is not just correctness, it is also the cheapest path.
`servable_posts()` pages up to `MAX_QUERY_PAGES` (5) times per content type,
asking for `$limit` rows each — so on a site about to print no entries at all,
one option read saves up to 2500 rows fetched per content type. The existing
`if ( empty( $posts ) ) { continue; }` in `build()` (`:277`) already suppresses
the `##` heading, and `key_content_items()`'s own emptiness check already
suppresses that section, so nothing prints an orphan heading.

## 5. Detecting noindex

### 5.1 The three levels and their precedence

1. **Site-wide** — core's `blog_public` option. Wins over everything: core forces
   `noindex` site-wide when it is off, so a per-post *index* override cannot beat
   it. Checked once per request, never per post.
2. **Per-post override** — the SEO plugin's own post meta. Beats the type default.
3. **Post-type default** — the SEO plugin's per-type Robots Meta setting.

The trap, and the single easiest way to under-protect a site: **an absent
per-post meta row means "inherit the type default", never "index".** Most sites
that exclude anything do it from the type default and never touch individual
posts.

### 5.2 What counts

`noindex` — and **`none`**, which is the robots vocabulary's shorthand for
`noindex, nofollow`. A check that looks only for the literal string `noindex`
lets a post set to `none` through.

### 5.3 What does not count, and must not be conflated

| Signal | Why not |
|---|---|
| `nofollow`, `noarchive`, `nosnippet`, `max-snippet` | Not noindex |
| A `canonical` pointing elsewhere | A duplicate-content signal, not an exclusion |
| A `robots.txt` disallow | A different mechanism entirely — a disallowed URL can still be indexed — and site config this plugin has no business reading |
| Taxonomy / author / date-archive noindex settings | `/llms.txt` lists singular content only, never archives |
| Password-protected posts (core noindexes them) | Already excluded by `is_servable()`; no interaction |
| Translations (Polylang) | Each translation is its own post with its own meta. No special handling |

### 5.4 What cannot be reached, stated rather than worked around

A robots meta emitted by the **theme or by site code** through the `wp_robots`
filter is stored nowhere readable. Reconstructing it would mean simulating the
main query for each post, on a route that iterates up to 2500 of them per type —
disqualified on cost, not on difficulty.

This is the feature's declared margin: *not in noindex, as far as the plugin can
tell*. The escape-hatch filter is the answer for those sites, and the
documentation must say so rather than implying completeness.

### 5.5 Shape

A new predicate reachable from `LlmsTxtController`, with a filter over its
result so a site can both correct it and switch the whole feature off (§3.3).
Detection of *which* SEO plugin is active reuses
`ConflictDetector::known_providers()` (`src/ConflictDetector.php:26`), which
already identifies all four by constant or class — the project's standing rule of
local, stable signals only: no reading of a third party's internal options
beyond the documented storage keys, and no loopback HTTP.

The exact meta keys, value shapes and per-type option layouts are **not settled
in this document**: see §8.

## 6. The `## Sitemaps` section

Independent of noindex in origin, coupled to it in effect: under a site-wide
noindex this is the only substantial thing left in the file.

Motivated by observing Rank Math's own `/llms.txt`, which emits:

```
## Sitemaps
[XML Sitemap](https://example.com/sitemap_index.xml): Includes all crawlable and indexable pages.
```

### 6.1 Where the URL comes from

| Situation | URL |
|---|---|
| A known SEO plugin is active | `home_url( '/sitemap.xml' )`, trusting that plugin's own redirect |
| No SEO plugin active | Core's sitemap, **only if core is actually serving it** |
| Either, overridden | Whatever the filter returns; empty string = no section at all |

Using the bare `/sitemap.xml` for every SEO plugin is deliberate and is the
cheapest part of this design: **it means never maintaining a table of per-plugin
sitemap paths.** If a plugin moves its sitemap, its own redirect absorbs the
change and this plugin never notices. (Yoast redirects `/sitemap.xml` to
`/sitemap_index.xml`; AIOSEO already serves `/sitemap.xml` directly. Rank Math
and SEOPress are unconfirmed — §8.)

Core is the case that makes a bare `/sitemap.xml` insufficient on its own: core
serves `/wp-sitemap.xml` and does **not** claim `/sitemap.xml`, so on a site with
no SEO plugin that URL is a plain `404`. This plugin does not require an SEO
plugin, so that is an ordinary configuration, not an edge case.

Whether core is serving is answerable **in-process**, with no HTTP request — the
one self-verifying gate available here. Everything else emitted in this section
is an unverified claim by construction, because loopback probes were already
rejected as unreliable behind a WAF, which is exactly why the override filter is
not optional.

### 6.2 The physical-file trap

A `sitemap.xml` sitting in the site root as a real file — left by a migration, a
static generator or an old plugin — is served by the web server before WordPress
runs, so no redirect fires and the section would advertise stale content. The
codebase already reasons this way for its own endpoint
(`ConflictDetector::physical_file_exists()`, which deliberately looks in the
*home* directory rather than `ABSPATH`, because on a subdirectory install the
shadowing file sits beside the site root). The override filter is the remedy.

### 6.3 What this does not reopen

The durable decision **"no XML sitemap for the `.md` URLs"** stands untouched.
That one forbids *generating* a sitemap of this plugin's own `noindex` URLs,
which would tell Search Console two contradictory things. This section links the
site's existing HTML sitemap, produced by somebody else, so an agent can find the
full page index. Different artefact, different audience, different direction.

## 7. Cache invalidation

`/llms.txt` is cached under one key for up to a day, so every input below must
delete `LlmsTxtController::CACHE_KEY` or the change is invisible until the TTL
expires.

**Targeted deletion, never a global salt bump.** The salt is shared with every
`.md` validator (`MarkdownController::cache_version()`), so bumping it would
rebuild every Markdown body on the site for a change that no `.md` can see. The
correct precedent is `MarkdownController::invalidate_cache()` (`:252`), which
already deletes both the per-post entry and the index entry.

| Event | Covered today? |
|---|---|
| noindex set on a post from the editor | **Yes, free.** The SEO plugins store it as post meta saved with the post, so `save_post` fires and `invalidate_cache()` already clears the index entry |
| `blog_public` toggled in Settings → Reading | **No.** No hook exists (zero occurrences of `blog_public` in the repository today) and no post row is touched |
| Post-type default changed in the SEO plugin's settings | **No.** It lives in that plugin's own options; no post is touched, nothing invalidates |
| A known SEO plugin activated or deactivated | **No** — and this one is introduced by §6. See §7.1 |

The two uncovered rows take the same shape as the three `woocommerce_*_page_id`
hooks in `AdminSettings::boot()` (`:117-123`), **including the lesson recorded
there**: register `add_option_*` **and** `update_option_*` **and**
`delete_option_*`, because `update_option()` delegates to `add_option()` when the
row does not yet exist — which is precisely the first time a setting is saved —
and fires `add_option_{$option}` instead, which `update_option_{$option}` never
sees. Caught by Codex on PR #122 before it shipped.

### 7.1 The sitemap URL is a new cached input, and it needs its own answer

§6.1 makes the emitted sitemap URL depend on **which plugin is active**, not on
any option this plugin owns. Deactivate Rank Math and the correct answer flips
from `/sitemap.xml` to core's `/wp-sitemap.xml` — while `render()` goes on
serving the cached body for up to a full TTL, advertising a URL that is now a
plain `404`. Nothing in `cache_version()` covers it, and the repository has no
plugin-activation invalidation hook at all. Raised by Codex on PR #132.

**Fold the resolved sitemap URL into `cache_version()`**, rather than hooking
activation. That is not a coin flip between two remedies: it is the shape the
method's own docblock already argues for. `cache_version()` covers
`get_bloginfo('name')` and `get_bloginfo('description')` precisely because they
are **printed in the file itself** and are edited somewhere that never fires
`save_post`. The sitemap URL is now a third string with exactly that property,
so it belongs in the same hash for the same reason.

It is also cheap enough to sit on the every-request path, including `304`s, which
is the standing constraint on anything entering a validator: resolving the
provider is the constant/class checks `ConflictDetector` already performs, plus
`home_url()`. No I/O, nothing new read from the database.

Hooking `activated_plugin`/`deactivated_plugin` instead was considered and is
worse on two counts: it would have to be registered outside the admin-only
`AdminSettings` to catch a WP-CLI or programmatic activation, and it still leaves
the URL wrong for any site whose provider changed before the hook existed. A
validator input is self-correcting; a hook only catches the transitions it is
present for.

## 8. Measurements not yet taken — blocking

Both project staging sites carry one of the two SEO plugins (Rank Math on one,
Yoast on the other), so all of these are measurable. **None of them has been
measured.** Everything below is belief, and belief is not what this plan gets to
ship on.

| # | Question | Why it decides something |
|---|---|---|
| 1 | Rank Math's per-post storage (`rank_math_robots`) | Believed to be a serialized array of directives, so the test is "does it contain `noindex`", not "is the value truthy" |
| 2 | Yoast's per-post storage (`_yoast_wpseo_meta-robots-noindex`) | Believed to be three-state, with `'1'` = noindex and **`'2'` = index**. Read as a boolean, that inverts the answer on exactly the value that matters |
| 3 | The per-post-type default's storage in both | §5.1's most common case; there is no implementation without it |
| 4 | Does an explicit per-post *index* beat a noindex type default? | Expected yes; precedence must be seen, not assumed |
| 5 | Can either plugin emit `none`? | §5.2 |
| 6 | Does core disable its own sitemap under `blog_public = 0`? | Believed yes (the `wp_sitemaps_enabled` default is tied to that option). If so, on a site with no SEO plugin the `## Sitemaps` section would point at a `404` in exactly the scenario where it is the only thing left in the file — §6.1 meets §3.1 |
| 7 | Does Rank Math redirect `/sitemap.xml`? Does SEOPress? | The premise of §6.1 |

Rules for that reconnaissance: assert `home_url()` before any call that writes
(connector names are not stable across reconnections, and a plugin update once
went to the wrong environment because the name looked like continuity); record
the starting state and restore it; remove every fixture; and **read the stored
value** rather than inferring it from how the admin UI behaves.

## 9. Documentation surfaces

When the code ships, these move in the same PR:

| Surface | Change |
|---|---|
| `documentation/` | The `/llms.txt` article: which content is listed, and the new Sitemaps section |
| `readme.txt` | Key features; an FAQ entry on why a noindex post is missing from the index but its `.md` still resolves |
| `README.md` | Features summary |
| `docs/filters.md` | The new filters, with stability levels |
| `docs/staging-acceptance.md` | A new numbered fixture (§10) |
| `AGENTS.md` | The new durable decisions, **and the rewrite of the July 2026 one** below |

### 9.1 A durable decision this changes

**"`/llms.txt` stays silent until a content type is enabled"** (July 2026) is
amended: with a `## Sitemaps` section the endpoint now has something to say even
before any content type is selected, and it answers `200` there. The decision's
text must be rewritten with the new reasoning rather than left contradicting the
code.

## 10. Acceptance fixtures

For `docs/staging-acceptance.md`, on a site with an SEO plugin active:

1. One post set to noindex from the SEO plugin → **absent** from `/llms.txt`,
   while its `.md` still answers `200 text/markdown` with
   `X-Robots-Tag: noindex, follow`, and its `rel="alternate"` link and `Link:`
   header are unchanged. The `.md` half is the one that must not regress.
2. A whole post type set to noindex from the SEO plugin's settings, with no
   individual post touched → every post of that type leaves the index; other
   types are unaffected. This is the fixture a per-post-only implementation
   fails.
3. A post carrying an explicit *index* override inside a noindexed type →
   **listed**.
4. `blog_public = 0` → `/llms.txt` answers **200** with the heading, tagline,
   summary, `## Sitemaps` and footer, and **no `.md` link anywhere**, Key content
   included. Every page's `rel="describedby"` still resolves to that `200` — the
   check that would have failed under the rejected `404` design.
5. A noindexed post named in the panel's **Key content** box, with
   `blog_public = 1` → **still listed** (§3.2).
6. Toggling `blog_public`, and changing the type default in the SEO plugin's
   settings, are each reflected on the **next request** with no post save
   involved (§7).
7. `## Sitemaps` present with the SEO plugin active and pointing at a URL that
   resolves; with every SEO plugin deactivated it points at core's sitemap, or
   the section is absent when core is not serving one.
8. **What each switch actually restores** — stated per feature, because this plan
   ships three changes and no single filter undoes all of them. The blanket
   "off means byte-identical output" claim that an earlier draft carried here was
   false, and was raised by Codex on PR #132: it was imported from the optional
   *additions* (taxonomies, `lastmod`, enriched mode), where one toggle governs
   one self-contained block of output. That does not hold here.
   - noindex filter off → **the set of listed posts is identical to the current
     release**. This is the claim worth testing, and the only one the noindex
     filter is responsible for.
   - Sitemap override filter returning `''` → **no `## Sitemaps` section**, so the
     file is byte-identical to the current release apart from the listing.
   - Both off together → byte-identical, **except** on an install with no content
     type enabled, where the endpoint now answers `200` instead of `404`
     (§9.1). That change is deliberate and has no off switch of its own; the
     existing `sysmda_llms_txt_enabled` checkbox is the way to silence the
     endpoint entirely. It is also unobservable on any configured site, since it
     only concerns installs where the rest of the plugin is inactive.
