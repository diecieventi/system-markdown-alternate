# Strategy review — Markdown serving as a niche technical product (July 2026)

> Working note, not shipped in the plugin. Evaluation of an external strategic
> analysis of the "WordPress → Markdown" plugin category, cross-referenced
> against this project's actual state (`AGENTS.md`: *Current state*, *Open / to
> do*, *Product decisions*). Written to preserve the reasoning for future
> sessions and for other agents (Codex included).

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
  taxonomies / author / dates / relations into front matter; per-post-type
  Markdown template; controlled textual substitution of complex components.
- **C. WooCommerce** (products → structured Markdown).
- **D. Multilingual** WPML / Polylang (correct per-language `.md` + cross-language
  alternates).
- **E. Minor technical gaps**: `HEAD` requests, multisite / subdirectory
  verification, explicit Varnish / generic reverse-proxy compatibility.
- **F. Documented, stable Markdown output format** + optional benchmark
  (HTML vs Cloudflare vs origin-native).

## Recommendation (if / what / how)

### Tier 1 — Do (high leverage, defensible, coherent with the existing plugin)

1. **F — Documented, stable output format.** Cheapest, highest trust value.
   `docs/output-format.md` (guaranteed front matter, conversion rules,
   exclusions) turns "it converts to Markdown" into a verifiable contract, and is
   the base for benchmarks. How: documentation + a few conformance tests in
   `tests/run-tests.php` that pin the format.
2. **A (no-loopback subset) — Server-side diagnostics.** Strongest
   differentiator per the analysis, and the safe subset avoids the loopback ban.
   How: a `Diagnostics` class + admin tab that runs the pipeline **in-process**
   for a given post and shows: HTML vs MD bytes, stripped/unconverted elements,
   unresolved internal links. No loopback → no conflict. The "is the cache
   serving HTML?" check stays a documented manual curl.
3. **B (incremental) — ACF structured extraction + richer front matter.** The
   "new competitive minimum". Start with taxonomies/author/dates in front matter
   (easy, high value), then ACF Repeater/Flexible/Gallery (build on
   `sysmda_acf_field_keys`). Per-type template is a later step, behind a filter,
   to avoid bloating the UI.

### Tier 2 — Only if data/demand justifies (gate on real `.md` request logs)

4. **E — HEAD / multisite / Varnish gaps.** Real hardening, low visibility. Do
   `HEAD` anyway (HTTP correctness, minimal cost). Multisite/Varnish: targeted
   verification + fixes when they surface, not a standalone project.
5. **C — WooCommerce** and **D — WPML/Polylang.** Valid but heavy, audience
   unconfirmed. Gate on the signal the analysis itself names as decisive: real,
   recurring `.md` requests in the logs.

### Tier 3 — Skip / not now

- Loopback-based live cache self-test → collides with a durable decision; keep as
  manual curl.
- Rich per-client logging → collides with count-only.
- Benchmark as a plugin *feature* → do it as a marketing article/asset, not code.
- MCP / WebMCP / GEO score / AI content generation → avoid (analysis agrees).

## One-line summary

The core serving is mature; residual real value is
**(F) documented output format → (A) server-side diagnostics →
(B) origin-native semantic extraction**, in that order. WooCommerce/multilingual
are Tier 2, gated by the logs.
