# Handoff — Markdown strategy & Tier 1 (July 2026)

> For the next session (Claude Code web) and any other agent (Codex). Read
> `AGENTS.md` first (source of truth), then the two documents below. This file is
> a working note, not shipped in the plugin.

## Context

A strategic review of the "WordPress → Markdown" plugin category was evaluated
against the plugin's actual state. Outcome: the **core serving path is mature**;
the residual real value is a niche-quality play. Full reasoning and the eliminated
(already-done / already-decided / colliding) items are in:

- **`docs/strategy-review-2026-07.md`** — the evaluation and priorities.
- **`docs/tier1-implementation-plan.md`** — the concrete, ordered Tier 1 plan.
- **`docs/llms-txt-multilingual-plan.md`** — pre-existing, user-approved plan to
  list WPML/Polylang translations in the single `/llms.txt` (`## Translations`
  section). This is the ready-to-build slice of the Tier 2 "multilingual" item.

Current `main` is **0.23.1**. The repository is **English-only** (the Italian
`AGENTS.it.md` / `README.it.md` were removed in #5): do not create or expect any
`.it.md` files.

## The three durable constraints not to trip over

These come from `AGENTS.md` *Product decisions* and were re-confirmed here:

1. **No HTTP loopback** in any diagnostic (the "NO Vary self-test" decision).
   Diagnostics run **in-process**; the live "is the cache serving HTML?" check
   stays a **manual curl** in the readme FAQ.
2. **`.md` hit counter is count-only** — no IP, no raw UA, no per-visitor, no
   sub-daily timestamps. Do not enrich request logging beyond the aggregate
   bot/human buckets.
3. Already decided **NO**: rate limiting, `.md` XML sitemap, synthesized homepage
   index, auto-yield of `/llms.txt`.

## What to do next — active plans

Two committed plans, each an independent PR to `main`:

**Tier 1** (`docs/tier1-implementation-plan.md`) — do in order:

1. **F1 — Documented, stable output format** (`docs/output-format.md` + golden
   conformance tests in `tests/run-tests.php`). Do this first; no `src/` change.
2. **F2 — Server-side diagnostics** (new `src/Diagnostics.php` + read-only
   Diagnostics admin tab; in-process only).
3. **F3 — ACF structured extraction + custom taxonomies in front matter**
   (note: `author`/dates/`categories`/`tags`/`featured_image` are **already** in
   the front matter — only custom taxonomies + ACF complex types remain).

**Multilingual `/llms.txt`** (`docs/llms-txt-multilingual-plan.md`) — greenlit,
independent: list WPML/Polylang translations in the single `/llms.txt`.

## Not planned — future thoughts

Everything else (WooCommerce, `HEAD`/multisite/Varnish hardening, broader
multilingual, benchmarks; plus the explicit "do not build" list) is parked as
**future thoughts, not plans** in `docs/strategy-review-2026-07.md`. Do not
promote any of it to a plan until real, recurring `.md` requests show up in the
logs.

## Positioning reminder

Sell *"clean, predictable, structured, machine-readable representation of
WordPress content"* — **not** *"install and gain AI visibility"*, and **not** a
*"free Cloudflare alternative"*. The go/no-go signal is real recurring `.md`
requests from important clients in the logs.

## Working agreements (from AGENTS.md)

- Branch → push → open PR to `main`; **user** squash-merges; **user** runs
  `bash bin/release-tag.sh` from the Mac. Agents never push `main` or tags.
- The repository is **English-only** — do not create `.it.md` translations
  (removed in #5).
- After changes: `php -l` on touched files + `php system-markdown-alternate/tests/run-tests.php`.
- On release: bump `Version:` + `SYSMDA_VERSION`, `readme.txt` (`Stable tag` +
  changelog), `bash bin/build.sh`.
