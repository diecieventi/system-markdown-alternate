# Strategy & future thoughts — Markdown serving as a niche technical product (July 2026)

> Working note, not shipped in the plugin. Evaluation of an external strategic
> analysis of the "WordPress → Markdown" plugin category, cross-referenced
> against this project's actual state (`AGENTS.md`: *Current state*, *Open / to
> do*, *Product decisions*). Written to preserve the reasoning for future
> sessions and for other agents (Codex included).
>
> **Scope of this file:** the *reasoning* plus the **future thoughts** (parked,
> not planned). The work this review committed to has since shipped — see the
> table below; the only plan document still open is
> `docs/llms-txt-multilingual-plan.md`. Nothing below the "Future thoughts"
> heading is an implementation plan; do not treat it as one.

## TL;DR

- The **core serving path is mature** (negotiation, q-values, `Vary`, LiteSpeed
  no-cache invariant, permalinks, 404 gating, canonical redirects). The external
  analysis adds little here.
- The **residual, genuinely new value** is, in priority order:
  **(F) documented/stable output format → (B) origin-native semantic
  extraction** (custom taxonomies, then ACF). Server-side diagnostics was
  considered and **parked** — see *Future thoughts*.
- Positioning is already right: *"clean, predictable, structured representation
  of WordPress content"*, **not** *"install this and gain AI visibility"*. Do not
  reposition as a "free Cloudflare alternative" (fragile).
- Treat this as a **reputational/technical niche investment**, not the main
  commercial bet. The decisive signal is **real, recurring `.md` requests from
  important clients in the logs** — not new blog posts claiming Markdown is "the
  future of SEO".

## Market frame (from the analysis, kept for context)

- Cloudflare shipped *Markdown for Agents* (Feb 2026) and treats
  `Accept: text/markdown` support as an "Agent Readiness" signal. As of Apr 2026,
  only ~3.9% of 200k analysed domains passed Markdown content negotiation → early
  tech, not an acquired standard.
- The WordPress directory is filling with plugins doing `.md` URLs, negotiation,
  `rel="alternate"`, front matter, `llms.txt` — including Joost de Valk's. Bare
  presence of `/post.md` is now a **commodity**.
- No public documentation from major crawlers (GPTBot, ClaudeBot, PerplexityBot)
  confirms they routinely send `Accept: text/markdown`. So the honest promise is
  *"cleaner/predictable/efficient representation ready for crawlers, agents, RAG
  and Markdown-consuming integrations"* — not guaranteed AI visibility.
- Decision window: now → end of 2027.

## What is already done or already decided (eliminate)

Already implemented (see `AGENTS.md` *Current state*):

| Analysis point | Status |
|---|---|
| q-values, HTML>Markdown preference, `Vary: Accept`, configurable 406 | Done — `AcceptNegotiator`, `sysmda_markdown_strict_406` |
| LiteSpeed / cache-plugin compatibility | Done — `LiteSpeedCompat` + server-agnostic no-cache invariant on negotiated responses |
| Odd/plain permalinks | Done — `?format=markdown` fallback |
| Protected / private / password gating | Done — `PostSupport::is_servable` (supported type + `publish` + not password-protected). **Note:** it does **not** inspect source-page `noindex`; the Markdown response carries its own `X-Robots-Tag: noindex, follow`, which is a different thing. Source-`noindex` gating is intentionally **not** implemented (SEO metadata is not an access boundary). |
| Canonical redirects (`/slug.md/` → 301 → `/slug.md`) | Done |
| Gutenberg rendered semantically; builder visual-only exclusion | Done — `render_block()` on cleaned blocks |
| Source authority (post_content / rendered / ACF field / custom callback / exclude promo) | Done — `sysmda_markdown_source_content` + excluded classes/blocks |
| Bot-vs-human request counting | Done — `HitCounter` (count-only) |
| `llms.txt` as optional discovery, not core | Already the stance |
| "Not an AI-SEO plugin" positioning | Already the stance (`README`) |

Already planned/parked: homepage `.md` serving, further `/llms.txt` enrichment,
screenshot recapture.

### Analysis points that COLLIDE with durable decisions (do NOT re-propose)

1. **Automatic self-test of "is the cache serving HTML by mistake?" / "test the
   home and each post type live"** → needs HTTP **loopback**, already rejected
   twice (unreliable behind WAF/proxy) and covered by the durable *"NO Vary
   self-test diagnostic (do not propose again)"*. Keep it as the **documented
   manual curl** diagnostic (already in the readme FAQ).
2. **Rich per-client request logging** (identifying "important clients") →
   collides with the durable *"`.md` hit counter is count-only"* (no IP, no raw
   UA, no per-visitor, no sub-daily timestamps; GDPR out of scope). Do not enrich
   request logging beyond the aggregate bot/human buckets.
3. **Rate limiting, `.md` XML sitemap, synthesized homepage index** → already
   decided NO.

## What genuinely remains (deduplicated)

- **B. Advanced origin-native semantic extraction** (the real edge vs Cloudflare):
  ACF Repeater / Flexible Content / Relationship / Gallery rendered structurally;
  **custom taxonomies + relations** into front matter (author, dates, core
  categories/tags are **already emitted** by `MetadataBuilder`); per-post-type
  Markdown template; controlled textual substitution of complex components.
- **C. WooCommerce** (products → structured Markdown).
- **D. Multilingual** WPML / Polylang (correct per-language `.md` + cross-language
  alternates).
- **E. Minor technical gaps**: `HEAD` requests, multisite / subdirectory
  verification, explicit Varnish / generic reverse-proxy compatibility.
- **F. Documented, stable Markdown output format** + optional benchmark
  (HTML vs Cloudflare vs origin-native).

## What came out of this review — all shipped (July 2026)

The ordered plan this review produced has been fully executed; its plan
documents were removed once merged, since the outcome now lives in the code and
in `AGENTS.md`. For the record:

| Item | Shipped in |
|---|---|
| Sanitize fix for `register_setting()` (wordpress.org Plugin Check blocker) | `0.23.2` |
| Plan/doc corrections (`Vary` wording, cache backend, menu label, version label) | docs only |
| **F1** — documented, stable output format + golden conformance tests | `docs/output-format.md` (contract, still live) |
| **F3.1** — custom taxonomies in the front matter (+ the cache/ETag fingerprint) | `0.24.0` |

Two side effects worth remembering, both now recorded in `AGENTS.md`: custom
taxonomies are **opt-in and byte-identical when off**, and anything that can
change the emitted Markdown **without touching `post_modified_gmt`** must be
folded into the cache validator — otherwise conditional requests answer `304`
with stale content.

Still open and greenlit: **multilingual `/llms.txt`** →
`docs/llms-txt-multilingual-plan.md`, scoped to listing WPML/Polylang
translations in the single `/llms.txt`. Independent of everything else and not
started; it needs the staging reconnaissance described in that document first.

---

## Future thoughts (NOT implementation plans)

Parked on purpose. **Do not turn these into plans** until the decisive signal
appears: real, recurring `.md` requests from important clients in the logs. Kept
here so the reasoning is not lost, nothing more.

- **Server-side diagnostics** (in-process, no loopback) — a read-only admin view
  of per-post servability, `.md` preview, size/token estimates, stripped/
  unconverted markup and unresolved internal links. Was scoped as "F2" and
  **removed from the active plan** (July 2026): competitors already ship
  previews/dashboards, the useful differentiator is origin-aware output not a
  diagnostics tab, and the audit found several of its signals brittle
  (`strip_tags` means no raw tags "survive" into the Markdown;
  `url_to_postid()===0` is not a broken-link proof; token/byte "savings" can't be
  measured in-process against the real public page). If revisited, build only a
  small MVP (servability reason, `.md` URL/mode, exact preview via a **shared,
  side-effect-free** builder, labelled size estimates) on a **separate admin
  page** — the settings panel is a single `options.php` form and cannot host a
  nested picker form. **We will revisit this later.** Do not promote it back to a
  plan without that decision.
- **ACF structured extraction (the old "F3.2")** — Repeater / Flexible Content /
  Relationship / Gallery rendered structurally instead of the current
  text-only handling. Deferred until there is concrete demand **and real ACF
  exports to work from**: do not build Repeater/Flexible Content generically
  without fixtures. Findings from the 2026-07-24 audit, kept because they are
  easy to get wrong:
  - The panel configures **only** the subtitle and TL;DR field names. The general
    `sysmda_acf_field_keys` list is **developer-only via filter**
    (`AcfIntegration.php`), never a panel field.
  - Repeater / Flexible Content have **no universal semantic rendering**; a
    generic dump can be worse than omitting the data. They need an explicit
    template/callback contract, per site.
  - ACF **return formats are configurable** (Relationship/Post Object → IDs or
    `WP_Post`; Gallery → IDs/URLs/arrays; Link/Image → several shapes; nested
    fields → any of these). Every supported shape needs defined normalization
    and escaping.
  - "Unknown types fall back to the current text behaviour" is **false**: the
    current code accepts only non-empty strings and skips arrays/objects.
  - Helpers must produce **escaped semantic HTML fragments** (or a structured
    intermediate consumed by one renderer) — never a Markdown string appended to
    the HTML source, which would be treated as text rather than parsed.
  - Sensible order if it ever starts: scalars/links → Relationship/Post Object
    (title + canonical or `.md` link where servable) → Gallery/Image with `alt`
    → Repeater **only** with a template contract → Flexible Content **only**
    with per-layout callbacks. Keep the developer filter as the escape hatch and
    ship docs/examples before any UI.
- **WooCommerce** (products → structured Markdown). Real potential but heavy, and
  the audience is unconfirmed. Revisit when the logs justify it.
- **Technical hardening** — `HEAD` requests, multisite / subdirectory,
  explicit Varnish / generic reverse-proxy compatibility. Low visibility; `HEAD`
  is cheap HTTP correctness and could be pulled forward on its own if ever
  needed, the rest is "fix when it surfaces", not a project.
- **Broader multilingual** beyond the `/llms.txt` slice — a per-language `.md`
  correctness audit and cross-language alternates. The scoped `/llms.txt` piece
  already covers the immediate need; this is only if multilingual becomes a real
  use case.
- **Per-post-type Markdown template** and controlled textual substitution of
  complex components — only if real content demands it (parked inside the F3 plan
  as a later, filter-only step).
- **Benchmark** HTML vs Cloudflare vs origin-native — worth doing as a
  **marketing article/asset**, not as a plugin feature.

### Explicitly out (do not build)

- **Loopback-based live cache self-test** → collides with the durable "NO Vary
  self-test" decision; the manual curl in the readme FAQ stays the answer.
- **Rich per-client request logging** → collides with the count-only hit counter.
- **MCP / WebMCP / GEO score / AI content generation** → avoid (the analysis
  agrees); would turn a clear technical plugin into yet another "AI optimization"
  package with no verifiable promise.

## One-line summary

The core serving is mature. Everything this review committed to has shipped —
the sanitize fix, the doc corrections, the documented output format and custom
taxonomies (`0.24.0`) — leaving the **multilingual `/llms.txt`** slice as the
only open plan. Everything else — **server-side diagnostics**, **ACF structured
extraction**, WooCommerce, broad hardening, wider multilingual — is a **future
thought**, not a plan, gated on real `.md` traffic in the logs.
