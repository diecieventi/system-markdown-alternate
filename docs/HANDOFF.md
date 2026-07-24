# Handoff — Markdown strategy & Tier 1 (July 2026)

> For the next session (Claude Code web) and any other agent (Codex). Read
> `AGENTS.md` first (source of truth), then the two documents below. This file is
> a working note, not shipped in the plugin.

## Context

A strategic review of the "WordPress → Markdown" plugin category was evaluated
against the plugin's actual state. Outcome: the **core serving path is mature**;
the residual real value is a niche-quality play. Full reasoning and the eliminated
(already-done / already-decided / colliding) items are in:

- **`docs/strategy-review-2026-07.md`** — the evaluation, priorities and the
  parked *Future thoughts* (server-side diagnostics now lives there).
- **`docs/tier1-implementation-plan.md`** — the concrete, ordered work plan
  (sanitize fix → doc corrections → F1 → custom taxonomies → ACF later),
  incorporating the 2026-07-24 audit corrections.
- **`docs/llms-txt-multilingual-plan.md`** — user-approved plan to list
  WPML/Polylang translations in the single `/llms.txt` (`## Translations`
  section). Independent; needs a staging reconnaissance pass first.
- **`docs/output-format.md`** — the F1 output-format contract (front-matter
  keys/order, scalar escaping, body pipeline, HTTP contract), enforced by golden
  tests in `tests/run-tests.php`.

(The standalone `FIX-PLAN-sanitize-register-setting-revised.md` note was removed
once its fix shipped in v0.23.2 — the detail lives in the `readme.txt`
changelog.)

Current `main` is **0.23.2**. The repository is **English-only** (the Italian
`AGENTS.it.md` / `README.it.md` were removed in #5): do not create or expect any
`.it.md` files.

## The three durable constraints not to trip over

These come from `AGENTS.md` *Product decisions* and were re-confirmed here:

1. **`.md` hit counter is count-only** — no IP, no raw UA, no per-visitor, no
   sub-daily timestamps. This is the **only** shipped request-side telemetry and
   the plan adds nothing to it. Do not enrich request logging beyond the
   aggregate bot/human buckets.
2. **No HTTP loopback** (the "NO Vary self-test" decision): any content analysis
   runs **in-process**; the live "is the cache serving HTML?" check stays a
   **manual curl** in the readme FAQ.
3. Already decided **NO**: rate limiting, `.md` XML sitemap, synthesized homepage
   index, auto-yield of `/llms.txt`.

> **Server-side diagnostics (old "F2") is parked**, not planned — moved to
> *Future thoughts* in `docs/strategy-review-2026-07.md`. We will revisit it
> later. Do not start it.

## What to do next — active plans

Ordered work (`docs/tier1-implementation-plan.md`), each an independent PR to
`main`:

1. **Sanitize fix** for `register_setting()` — ✅ **done in v0.23.2**. Was the
   wordpress.org Plugin Check blocker.
2. **Plan & doc corrections** — ✅ **done**: noindex claim (already fixed in the
   strategy doc), `AGENTS.md` version label (`v0.22.x → v0.23.x`),
   `Vary`/cache-backend/menu wording in README/readme. No version bump.
3. **F1 — Documented, stable output format** — ✅ **done**: `docs/output-format.md`
   + golden conformance tests (116 → 124 assertions) + readme FAQ / README link.
   No `src/` change, no version bump.
4. **F3.1 — Custom taxonomies in front matter** (nested collision-safe
   `taxonomies:` mapping + term-change cache invalidation). ← **next up.**
   Runtime release.
5. **ACF structured extraction (F3.2)** — later, case-driven only; not now.

**Multilingual `/llms.txt`** (`docs/llms-txt-multilingual-plan.md`) — independent:
list WPML/Polylang translations in the single `/llms.txt`. Requires a
WPML/Polylang **staging reconnaissance** pass before coding (the main query's
default-language assumption is not reliable — see the plan).

## Not planned — future thoughts

Everything else (**server-side diagnostics**, WooCommerce, `HEAD`/multisite/
Varnish hardening, broader multilingual, benchmarks; plus the explicit "do not
build" list) is parked as **future thoughts, not plans** in
`docs/strategy-review-2026-07.md`. Do not promote any of it to a plan until real,
recurring `.md` requests show up in the logs.

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
