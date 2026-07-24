# Strategy & future thoughts — Markdown serving as a niche technical product (July 2026)

> Working note, not shipped in the plugin. Evaluation of an external strategic
> analysis of the "WordPress → Markdown" plugin category, cross-referenced
> against this project's actual state (`AGENTS.md`: *Current state*, *Open / to
> do*, *Product decisions*). Written to preserve the reasoning for future
> sessions and for other agents (Codex included).
>
> **Scope of this file:** the *reasoning* plus the **future thoughts** (parked,
> not planned). The work that has actually been committed to lives in its own
> plan documents — `docs/tier1-implementation-plan.md` and
> `docs/llms-txt-multilingual-plan.md`. Nothing below the "Future thoughts"
> heading is an implementation plan; do not treat it as one.

## TL;DR

- The **core serving path is mature** (negotiation, q-values, `Vary`, LiteSpeed
  no-cache invariant, permalinks, 404 gating, canonical redirects). The external
  analysis adds little here.
- The **residual, genuinely new value** is, in priority order:
  **(F) documented/stable output format → (A) server-side diagnostics →
  (B) origin-native semantic extraction**.
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
| Protected / private / password / noindex gating | Done — `PostSupport::is_servable` |
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

- **A. Built-in diagnostics — server-side computable parts only** (no loopback):
  per-post `.md` preview, bytes/tokens saved vs HTML, list of stripped/
  unconverted markup, broken internal links in the Markdown, "servable? why not?"
  badge.
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

## What is actually being planned (separate documents)

These items moved from "ideas" to concrete, ordered plans. They are **not** in
this file — see their own documents:

- **Tier 1 — the quality/rigor play** → `docs/tier1-implementation-plan.md`.
  In order: **F1** documented, stable output format → **F2** server-side
  diagnostics (in-process, no loopback) → **F3** custom taxonomies in front
  matter + ACF structured extraction. This is the residual real value the
  analysis points to, and it is coherent with the existing plugin.
- **Multilingual `/llms.txt`** → `docs/llms-txt-multilingual-plan.md`. Greenlit,
  scoped: list WPML/Polylang translations in the single `/llms.txt`
  (`## Translations` section). Independent of everything else.

---

## Future thoughts (NOT implementation plans)

Parked on purpose. **Do not turn these into plans** until the decisive signal
appears: real, recurring `.md` requests from important clients in the logs. Kept
here so the reasoning is not lost, nothing more.

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

The core serving is mature. The committed work is **Tier 1**
(documented output format → diagnostics → semantic extraction) and the
**multilingual `/llms.txt`** slice, each in its own plan. Everything else —
WooCommerce, broad hardening, wider multilingual — is a **future thought**, not a
plan, gated on real `.md` traffic in the logs.
