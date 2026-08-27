# Cloudflare "Markdown for Agents" — review and candidates

> **STATUS: review only, parked. Nothing in this document has been
> implemented and no plugin source file has been touched.** Written 27 August
> 2026 against `main @ 0.49.2`, in response to a "look at Cloudflare's
> Markdown-for-Agents feature and see if there's anything worth taking,
> especially on JSON and Markdown validation — don't change any code" request.
> Recorded here so the comparison is not redone from scratch next time
> Cloudflare's feature changes, in the same spirit as
> `docs/llms-txt-v2-plan.md` before it was greenlit.

## 1. What was reviewed

Cloudflare's **Markdown for Agents**
(`https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/`),
a **zone-level, edge** feature (Pro/Business/Enterprise/SSL-for-SaaS, no
extra cost) that converts an origin's HTML response to Markdown on the fly
when a request's `Accept` header includes `text/markdown`. It is not a
WordPress plugin and not something this repository ships or depends on — it
is a CDN feature a *site owner* can flip on for their whole zone, independent
of what runs at the origin. It matters here for two reasons: (a) some of its
design choices are worth comparing against this plugin's, and (b) a site
running both Cloudflare and this plugin now has two things trying to do the
same job on the same URL — see §6.

## 2. What it actually does

- **Trigger**: `Accept: text/markdown` on any request; content negotiation at
  the edge, HTML origin responses only, capped at 2 MB.
- **Discovery**: a site's `llms.txt` (Cloudflare's own docs are discoverable
  at `https://developers.cloudflare.com/fundamentals/llms.txt`) and, per the
  page, the standard `Vary: Accept` handling so caches keep the two
  representations apart.
- **Output shape**, three parts:
  1. **YAML front matter** (optional) built from the page's `<meta>` tags —
     `title`, `description`, `image`.
  2. **Body Markdown**, HTML converted with non-content elements (nav,
     `<script>`, `<style>`) stripped.
  3. **A trailing JSON-LD block** (optional): any `<script
     type="application/ld+json">` found in the source page is preserved
     verbatim and appended at the end of the document inside a fenced ` ```json `
     code block.
- **Response headers**:
  - `x-markdown-tokens` / `x-original-tokens` — estimated token counts for
    the converted Markdown and the original HTML, added to every converted
    response.
  - `Content-Signal` — when the origin doesn't already send one, Cloudflare
    adds a default `Content-Signal: ai-train=yes, search=yes, ai-input=yes`.
  - Security headers (HSTS, CSP, `X-Frame-Options`, CORS) and cache
    directives are preserved from the origin response; `ETag`,
    `Last-Modified` and `Content-Encoding` are **removed**, because they no
    longer describe the converted body.
- **Limitations**: HTML-only conversion today; 2 MB origin-response cap.
- **Adjacent APIs**, listed on the same page as alternatives for other use
  cases: Workers AI's `env.AI.toMarkdown()` (multi-format document
  conversion + summarization) and the Browser Rendering API's `/markdown`
  endpoint (for converting a dynamically rendered page/application). Both are
  Cloudflare Workers-bound products, irrelevant to a WordPress plugin as
  such, noted only because the page frames them as "the JSON-returning
  alternative" when a caller needs conversion as an API call rather than as
  content negotiation on the page itself.

No page in Cloudflare's documentation set describes a validation mechanism,
schema, or conformance test for this output — see §5.

## 3. Where this plugin already matches or exceeds it

| Area | Cloudflare Markdown for Agents | This plugin | Verdict |
|---|---|---|---|
| Negotiation trigger | `Accept: text/markdown` | Same, plus q-value comparison against `text/html` (`AcceptNegotiator`) and an explicit `?format=markdown` override | We do more: Cloudflare's page does not document q-value comparison against `text/html`'s own preference, only presence of `text/markdown` in Accept. |
| `Vary` handling | "modified to include `Accept`" | `Vary: Accept`, appended not overwritten, only on negotiable URLs (`is_negotiable_request()` excludes feeds/embeds/paged comments/sub-pages) | We are narrower and more correct: a generic edge feature cannot know which URLs are "the canonical singular page" the way an origin plugin can. |
| Front matter | `title`, `description`, `image` from `<meta>` tags | `title`, `url`, `markdown_url`, `date_published`, `date_modified`, `author`, `featured_image[_alt]`, `categories`, `tags`, `description`, optional `taxonomies` — see `docs/output-format.md` | We already exceed this; nothing to take. |
| Validators (`ETag`/`Last-Modified`) | Removed — a generic converter has no way to compute a meaningful one for arbitrary origin HTML | Computed (weak `ETag` + `Last-Modified`), with `304` support and a documented dependency-fingerprint discipline (`docs/output-format.md`, `AGENTS.md` §6) | We already exceed this, and structurally must: Cloudflare converts *whatever HTML the origin already sent*, so there is nothing left to validate against; we control the whole pipeline and can. |
| Content-type framing | Implied `text/markdown` | `Content-Type: text/markdown; charset=utf-8` + `X-Robots-Tag: noindex, follow` + `Link: rel="canonical"` | Already covered. |
| Discovery of the index file | `llms.txt` at the zone root | `/llms.txt`, plus `rel="describedby"` from every negotiable page (shipped `0.49.0`) | Already covered, and more explicit. |
| JSON-LD passthrough | Yes, appended as a fenced code block | Not implemented | **Real gap — see §4.1.** |
| Token-count headers | `x-markdown-tokens` / `x-original-tokens` | Not implemented | **Candidate — see §4.2.** |
| Content-Signal header | Default `ai-train=yes, search=yes, ai-input=yes` when origin sends none | Not implemented (the closest existing surface is the per-crawler hit-counter breakdown, which counts, never declares intent) | **Emerging, track only — see §5.2.** |
| Output validation / schema | Not documented anywhere on the reviewed page | `docs/output-format.md` (append-only contract) + golden conformance tests in `tests/run-tests.php` | We already exceed this — see §5.1 for the one thing worth adding on top. |

## 4. JSON-related candidates

### 4.1 JSON-LD passthrough

**Why it isn't a small port of what Cloudflare does.** Cloudflare converts
the *entire rendered HTML page*, `<head>` included, so a
`<script type="application/ld+json">` printed by an SEO plugin in `wp_head()`
is right there in the DOM it converts. This plugin's pipeline is deliberately
**not** that: `ContentRenderer::render()` builds the body from the post's
cleaned blocks (`render_block()`) or `post_content`, precisely to avoid
pulling in theme chrome and injected related/CTA content (`AGENTS.md`,
"Technical notes" §4). Structured data emitted by Rank Math or a similar
plugin lives in `wp_head`/`wp_footer` output, generated from post *meta*
(title, schema type, breadcrumbs), not from `post_content` — it is not
present anywhere in the DOM this plugin already parses. So "preserve
JSON-LD" cannot be done by simply not stripping `<script>` from the existing
pass (`MarkdownConverter`'s `remove_nodes` config already excludes `script`
for a reason unrelated to this — it would still find nothing, because the
post body never contained the schema markup to begin with).

Two structurally different ways to get there, both unbuilt and both needing
real reconnaissance before either is chosen:

1. **Generic: buffer `wp_head()`/`wp_footer()` output during the `.md`
   render, parse it, and keep only `<script type="application/ld+json">`
   nodes.** This is the only approach that works for *any* plugin printing
   structured data, matching Cloudflare's own generality. It is also the
   riskier of the two: it means firing `wp_head`/`wp_footer` a second time,
   outside the real page request, purely to harvest a side effect —
   precisely the caution `AGENTS.md` already states for cache pre-warming
   (`sysmda_markdown_prewarm` stays default-off because "cron is not a
   faithful stand-in for a front-end request": no guarantee every hooked
   callback behaves identically without the real request context). A
   tracking pixel, a nonce-bearing inline script, or a plugin that assumes
   `wp_head` fires once per request could all be affected by triggering it
   again inside the same request that already renders the page (on the
   negotiated route) or from a synthetic context (on the `.md` suffix
   route, which sets up the loop but does not render a real page). Buffering
   and then discarding everything except the `ld+json` scripts limits what
   *leaks into the document*, but does not limit what *executing the hooks a
   second time does elsewhere* (analytics counters, rate-limited APIs, a
   plugin with global state).
2. **Narrow: read a known SEO plugin's own schema data directly, without
   rendering anything.** Rank Math (this plugin's own reference SEO plugin,
   per "Compatibility with known plugins") exposes its generated schema
   through filterable hooks rather than only through direct output;
   `MetadataBuilder` already reads `rank_math_description` meta directly for
   the description fallback, in the same spirit as `BricksAdapter` "reads
   the tree only to decide" instead of re-rendering. An analogous
   `RankMathIntegration` that hooks the relevant filter and serializes the
   resulting schema array to JSON would be **cheap, safe, and exactly this
   plugin's existing shape for third-party integrations** (`AcfIntegration`,
   `MetaFields`) — but it is per-plugin by construction, the opposite of the
   "one generic mechanism, not N per-plugin integrations" principle that
   shaped `MetaFields` (`AGENTS.md`, `0.47.0`). It would also need the exact
   hook name and payload shape **verified live** against a real Rank Math
   install before any code is written — nothing here should be assumed from
   documentation alone (the repo's own "a guard is not done until it has
   been seen to fire" discipline applies just as much to a claim about a
   third-party plugin's filter surface).

Neither is close to buildable today. This is the same shape as the parked
`docs/llms-txt-multilingual-plan.md` — a real idea gated on staging
reconnaissance that has not happened. **Recommendation: park, do not build.**
If it is ever picked up, resolve the generic-vs-narrow question with a real
`wp_head` capture experiment on `instawp_sma` (which already carries Rank
Math) before writing any integration code, and decide there whether the
side-effect risk in option 1 is real or theoretical on that stack.

### 4.2 Token-count headers

Cheaper and lower-risk than §4.1. Two design questions, both answered by the
plugin's own existing cost discipline for the request path:

- **`x-original-tokens` has no natural analogue here.** Cloudflare has "the
  original HTML" because it converts an already-fully-rendered page; this
  plugin never builds the full rendered HTML page as part of the `.md`
  pipeline (`render_block()` on cleaned blocks, not `the_content()` — see
  §4.1 above), so there is nothing to measure it against without adding a
  second, otherwise-pointless render. Not worth doing for a header nobody
  asked for.
- **`x-markdown-tokens` fits the existing cost rule, with one condition.**
  `AGENTS.md` "Technical notes" §6 is explicit that anything added to the
  request path — `304`s included — must avoid I/O and stay cheap, because
  the whole point of the weak-ETag design is answering a `304` without
  building the body. A token-count header computed from the **finished
  body** would violate that if it ran unconditionally. It does not have to:
  restrict it to `200` responses only (a `304` carries no body, and RFC 9110
  does not require this header to repeat on one — the client already has
  the count from the prior `200`), and compute it from the body **that is
  already in memory** to be sent (from cache or freshly rendered) — no
  extra fetch, no I/O, a cheap `strlen()`-based estimate (Cloudflare's own
  header is described only as an "estimated" count, so an approximation
  such as `ceil( strlen( $body ) / 4 )` is consistent with what the feature
  actually promises, not a claim of tokenizer-exact accuracy).

This is a small, self-contained, on-brand candidate: an **Advanced** filter
(anchored to a pipeline detail — the estimation formula — not a domain
concept), off the hot `304` path, no new I/O. **Not recommended to build
without a stated reason**, though: nothing in this plugin's existing
telemetry (the count-only `.md` hit counter) suggests demand for it, and the
repo's own rule is real demand before a header, same as every other parked
idea. Recorded here as a candidate that would clear the design bar cheaply
*if* demand ever appears — not proposed for the next release.

### 4.3 A JSON representation of the document (the larger idea)

The most direct read of "the JSON angle" is not passthrough of someone
else's JSON, but exposing this plugin's **own** front matter + body as JSON
instead of YAML-fenced Markdown — i.e., a third negotiable representation
(`Accept: application/json` / `?format=json`) returning something like:

```json
{
  "title": "…",
  "url": "https://example.com/my-post/",
  "markdown_url": "https://example.com/my-post.md",
  "date_published": "2026-08-27T00:00:00+00:00",
  "date_modified": "2026-08-27T00:00:00+00:00",
  "description": "…",
  "content_markdown": "…the same body…"
}
```

This is worth naming because it is the one idea that ties both halves of the
request together — a JSON representation whose shape can be published and
validated as a schema (§5.1) — but it is **substantially bigger** than it
looks, and should not be sized like the two candidates above:

- `AcceptNegotiator` itself is generic (any media range, q-values, RFC 9110
  rules) and would cost nothing to extend. The cost is everywhere *else*:
  `MarkdownController::prefers_markdown()` and `should_reject_unacceptable()`
  are written as genuinely binary decisions (Markdown vs. HTML, `?format=`
  recognizing exactly one override value) — see `MarkdownController.php`
  ~L419-476. A third representation is a third branch through both methods,
  plus a priority rule for a client that weights `application/json` and
  `text/markdown` equally, plus a third `?format=` value, plus a decision on
  whether `.md`-style URL suffix routing gets a `.json` sibling or JSON is
  negotiation-only.
- It is a **new public output contract**, not an extension of the existing
  one — `docs/output-format.md`'s compatibility policy (additions are
  appended, an existing key's shape is never silently changed) would need
  its own JSON-shaped equivalent, versioned separately from day one.
- It has no stated demand. The repo's own decision history is consistently
  "real demand first" for anything this size — the parked homepage index,
  the parked exclusion scanner, the declined per-plugin JSON-LD detector
  idea in §4.1 — and a second full representation of the document is at
  least as large as any of those.

**Recommendation: do not build.** Park as a named idea in case demand
surfaces (e.g. from the `.md` hit counter ever showing programmatic JSON
requests, which it currently has no way to distinguish — another reason not
to build this speculatively). If it is ever picked up, §5.1 below is the
cheap first step that gets most of the *validation* benefit without any of
this cost.

## 5. The validation question

### 5.1 What Cloudflare has, what this plugin already has, and the one cheap addition

The reviewed page documents **no validation mechanism** for its own output —
no schema, no conformance test, no versioned contract a consumer could check
a response against. This plugin already has something stronger:
`docs/output-format.md` states an explicit, versioned, append-only contract
for the front matter and body, and `system-markdown-alternate/tests/run-tests.php`
pins it with golden conformance fixtures that fail CI on a silent reorder or
drop. That is real validation — of the plugin's own output, in CI, on every
PR — and Cloudflare's page gives no comparable claim to catch up to.

The one thing worth adding, cheaply, without touching the wire format or
building §4.3: **a JSON Schema for the front matter**, published as
`docs/output-format.schema.json`, describing exactly the key table already
in `docs/output-format.md` (types, which keys are conditional, the
`taxonomies:` nested shape). YAML front matter parses to the same data model
JSON Schema validates, so this needs no new representation and no server
cost — it is a documentation artifact, not a runtime feature:

- It gives an external consumer (an agent, a script, a monitoring job) a
  machine-checkable definition of the front matter shape, instead of having
  to read the Markdown table.
- It could tighten the plugin's *own* golden tests: instead of only
  asserting exact fixture strings, a schema-validate pass over each golden
  fixture's parsed front matter would catch a key that is present but
  malformed (wrong type, unexpected nesting) in a way a string comparison
  alone might not, for the same reason the repo already distinguishes
  "reasoned about it" from "watched it fail" (`AGENTS.md`, "A guard is not
  done until it has been seen to fire").
- It costs nothing at request time: no negotiation change, no header, no new
  filter — a JSON file next to `output-format.md`, generated once and kept
  in sync by hand the same way the Markdown table already is (both describe
  the same `MetadataBuilder::build_front_matter()` output, so they can only
  drift by the same kind of oversight `bin/docs-audit.php` already exists to
  catch for other surfaces).

**This is the smallest, most on-brand answer to "the JSON and validation
question" in the whole review** — it needs no new negotiable representation,
no new endpoint, and no new response header; it only publishes, in a
machine-readable form, a contract the plugin already enforces on itself.
Sized right for a small PR if the maintainer wants to take one thing from
this review.

### 5.2 Content-Signal header — track, do not implement yet

Cloudflare's default `Content-Signal: ai-train=yes, search=yes, ai-input=yes`
is an emerging, Cloudflare-associated proposal for expressing AI-usage
permissions as a response header (a companion to, not a replacement for,
`robots.txt`-level rules) — it is not an IETF standard and its adoption
outside Cloudflare's own products is not established at review time.
`AGENTS.md`'s "Open / to do" already carries a matching placeholder for
exactly this shape ("Future idea: formalized LLM signals in `/llms.txt` once
the spec … settles — the hook is already in place,
`sysmda_llms_txt_footer`"). Nothing here changes that assessment: the spec
has not settled, and the existing hook is already the parked answer for the
day it does. **No new work — this section exists so the Cloudflare header is
recorded as "the same open item, not a new one" rather than rediscovered as
new.**

## 6. A compatibility risk worth documenting, found during this review

Not in the original request, but surfaced by reading how the feature works:
Markdown for Agents operates at Cloudflare's edge, in front of the origin,
and is triggered by the same `Accept: text/markdown` header this plugin
negotiates on at `template_redirect`. A site that has **both** Cloudflare's
zone-level feature turned on **and** this plugin active has two independent
converters keyed off the same signal, on the same canonical HTML URL, with
no defined precedence between them documented on either side:

- If Cloudflare intercepts before the request reaches the origin (typical
  for an edge feature triggered purely by the request's own headers), the
  origin never sees the negotiation at all, and the visitor gets
  Cloudflare's generic conversion — page chrome/meta-tag front matter, no
  taxonomy/ACF/builder-adapter awareness, no `304` support — instead of this
  plugin's. That silently defeats most of what this plugin exists to do,
  with no error and no obvious symptom (the same "a guard that never fires
  produces no symptom" shape `AGENTS.md` warns about, one layer up the
  stack).
- If Cloudflare instead only converts the *cached/stored* HTML response
  (fetching from origin as if Accept were plain HTML, converting after the
  fact), the two could disagree in a more confusing way: `curl -H
  "Accept: text/markdown"` would get one plugin's opinion of the page and a
  browser's DevTools "copy as curl" a different one, depending on cache
  state — genuinely hard to debug without knowing both mechanisms exist.

This was not verified live (no Cloudflare zone with both configured was
available in this review), so the exact precedence is not established here —
only the risk. **Recommendation, if this is ever picked up**: a short
`readme.txt` FAQ / `documentation/` note along the lines of the existing
LiteSpeed and WAF compatibility notes ("Compatibility with known plugins"),
telling a site owner running both to disable Cloudflare's Markdown for
Agents for this plugin's supported paths (or disable it site-wide and rely
on this plugin, which already does per-post eligibility, page-builder
detection, taxonomies, ACF and caching that Cloudflare's generic converter
cannot know about) rather than running both unknowingly. This is a
**documentation-only** fix (per the "would a user who read the documentation
yesterday do something different today" rule) — it requires no code change,
only requires the precedence to actually be verified live first, which this
review did not do.

## 7. Explicitly not recommended

Recorded so these are not re-proposed without new information, in the same
spirit as the plugin's other closed decisions:

- **Do not build a full JSON representation (§4.3) speculatively.** No
  demand; would be at least as large a feature as anything currently parked
  in "Open / to do", and duplicates most of its value with the much
  cheaper §5.1.
- **Do not build generic `wp_head` buffering for JSON-LD (§4.1, option 1)**
  without first running the same kind of live reconnaissance the Bricks
  adapter and the multilingual `llms.txt` plan both required before code was
  written. The side-effect risk is the same class the pre-warm decision
  already declined to accept casually.
- **Do not adopt `Content-Signal` emission now.** The spec has not settled;
  `sysmda_llms_txt_footer` is already the parked hook for this family of
  idea, and nothing about Cloudflare's specific header changes that.
- **Do not treat Cloudflare's own conversion as a substitute for, or a
  reason to simplify, this plugin's origin-side negotiation.** The
  comparison in §3 shows this plugin is already ahead on almost every axis
  that matters for this plugin's stated audience (LLMs/agents fetching a
  specific WordPress site's content, not a generic edge feature working
  from HTML alone).

## 8. Summary for the maintainer

Two independently reviewed questions, two different-sized answers:

- **"Anything to take on JSON?"** — Mostly no, at the size the request
  implied. JSON-LD passthrough (§4.1) needs reconnaissance this review
  didn't do; a full JSON representation (§4.3) is a bigger feature than a
  passing comparison justifies. The one thing actually worth doing is small
  and not really about content negotiation at all: publish a **JSON Schema
  for the existing front matter** (§5.1) — cheap, additive, strengthens the
  plugin's own conformance testing, and ships no new runtime behavior.
- **"Anything to take on validation?"** — Cloudflare's page documents none;
  this plugin's `docs/output-format.md` + golden tests already exceed it. §5.1
  is the only concrete gap worth closing, and it closes a documentation gap,
  not a functional one.
- **One unplanned finding** (§6): a documentation-only compatibility note
  about running Cloudflare's Markdown for Agents and this plugin on the same
  zone is worth adding once the precedence question is verified live — this
  review could not verify it.

Nothing here is greenlit. If the maintainer wants to take one item from this
review, §5.1 is the smallest, safest, most on-brand starting point.

## Sources

- `https://developers.cloudflare.com/fundamentals/reference/markdown-for-agents/`
  (fetched 27 August 2026 — the page reviewed here; content/sections may
  change without notice, as is normal for CDN vendor documentation).
