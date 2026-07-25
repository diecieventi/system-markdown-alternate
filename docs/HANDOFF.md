# Handoff — working notes (July 2026)

> For the next session (Claude Code web) and any other agent (Codex). Read
> `AGENTS.md` first — it is the source of truth for the plugin's state,
> decisions and workflow. This file is only a pointer to what is *not* in
> `AGENTS.md`: what is still open, and where the reasoning lives.
> Not shipped in the plugin.

## Where things stand

`main` is **0.24.0**. The repository is **English-only** (the Italian
`AGENTS.it.md` / `README.it.md` were removed in #5): do not create or expect any
`.it.md` files.

The July 2026 strategy review produced an ordered plan that is now **fully
shipped** — sanitize fix (`0.23.2`), doc corrections, the documented output
format (F1) and custom taxonomies in the front matter (F3.1, `0.24.0`). Those
plan documents were deleted once merged: the outcome lives in the code, in
`AGENTS.md` (*Current state*, *Product decisions*, *Filters*) and in
`docs/output-format.md`. Do not go looking for them.

## The documents that remain

| File | What it is |
|---|---|
| `docs/output-format.md` | **Not a plan — a live contract.** The front-matter keys and order, scalar escaping, body pipeline and HTTP contract, append-only from `0.24.0`. Enforced by golden tests in `tests/run-tests.php` and linked from `README.md` and `readme.txt`. Keep it in sync with any output change. |
| `docs/llms-txt-multilingual-plan.md` | The **only open plan**: list WPML/Polylang translations in the single `/llms.txt`. Greenlit but **not started** — needs the staging reconnaissance described inside before any code. |
| `docs/strategy-review-2026-07.md` | The reasoning, the eliminated options, and the **future thoughts** (parked, not plans): server-side diagnostics, ACF structured extraction, WooCommerce, hardening, wider multilingual. |

## What to do next

1. **Multilingual `/llms.txt`** — the only thing actually queued. Start with the
   WPML/Polylang staging reconnaissance, not with code.
2. Everything else in *Future thoughts* stays parked until the decisive signal:
   **real, recurring `.md` requests from important clients in the logs**. Do not
   promote any of it to a plan without that.
3. Housekeeping tracked in `AGENTS.md` *Open / to do*: the wordpress.org
   screenshots are stale (pre-0.17.0 UI), and Italian translation happens on
   translate.wordpress.org once the plugin is live — never in this repo.

## The four durable constraints not to trip over

From `AGENTS.md` *Product decisions*, repeated here because they are the ones an
agent is most likely to violate by accident:

1. **`.md` hit counter is count-only** — no IP, no raw UA, no per-visitor data,
   no sub-daily timestamps. The only shipped request-side telemetry; do not
   enrich it.
2. **No HTTP loopback** anywhere ("NO Vary self-test"): content analysis runs
   **in-process**; the live cache check stays a manual curl in the readme FAQ.
3. **Anything that can change the emitted Markdown without touching
   `post_modified_gmt` must be folded into the cache validator**
   (`cache_version()`), which is also the strong `ETag` — otherwise conditional
   requests answer `304` with stale content, body cache or not. Custom
   taxonomies were the first such case; `If-Modified-Since` additionally has to
   be ignored while the date is not a strong validator.
4. Already decided **NO**: rate limiting, `.md` XML sitemap, synthesized
   homepage index, auto-yield of `/llms.txt`.

## Working agreements

- Branch → push → open a PR to `main`; **the user merges** with "Squash and
  merge". Agents never push `main`.
- **Tagging is automatic**: merging a release PR triggers the `Release tag`
  workflow. Do not ask the user to tag from their machine; a missed tag is
  recovered with "Run workflow" from the Actions tab. Publishing a GitHub
  Release is still manual and needs `git fetch origin --tags` first.
- After changes: `php -l` on touched files, `php system-markdown-alternate/tests/run-tests.php`,
  and `composer phpcs` (0 errors — warnings are pre-existing).
- On a release: bump `Version:` + `SYSMDA_VERSION`, `readme.txt` (`Stable tag` +
  changelog), `bash bin/build.sh`.

## Positioning reminder

Sell *"clean, predictable, structured, machine-readable representation of
WordPress content"* — **not** *"install and gain AI visibility"*, and **not** a
*"free Cloudflare alternative"*.
