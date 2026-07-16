# PLAN — Cache hardening (v0.21.4) + `.md` hit counter & filter docs (v0.22.0)

Implementation plan for the next two releases. Written to be executed in a
fresh session: read `AGENTS.md` first (conventions, workflow, durable
decisions), then follow this file top to bottom. Delete this file in the final
commit once everything below is done and `AGENTS.md` has been updated.

**Non-negotiables (from `AGENTS.md`, do not re-discuss):**

- Only destination for code is `main`; never open PRs. In Claude Code (web),
  commit on the technical branch and consolidate on `main` with ff-only merges
  at the end (fixed procedure in `AGENTS.md` → "Claude Code (web) — specific").
- English is the source language everywhere; `AGENTS.it.md`/`README.it.md`
  are updated **in the same commit** as their English counterparts.
- Every new filter gets a docblock + an entry in the `AGENTS.md`
  "Filters (public contract)" list.
- After changes: `php -l` on touched files +
  `php system-markdown-alternate/tests/run-tests.php`.
- On each release: bump `Version:` header **and** `SYSMDA_VERSION` in
  `system-markdown-alternate/system-markdown-alternate.php`, update
  `Stable tag` + changelog in `system-markdown-alternate/readme.txt`,
  `bash bin/build.sh`, annotated git tag `vX.Y.Z` on the bump commit.
- Do not put the model ID in commits, code or docs.

---

## Phase 1 — Cache hardening (patch release v0.21.4)

Background (decisions recorded in `AGENTS.md` → "Product decisions", July
2026 LiteSpeed/Vary diagnosis): honouring `Vary: Accept` is a per-host
property — the default LiteSpeed cache keys by URL only and ignores `Vary`,
and CDNs may too. The negotiated Markdown and `406` responses must therefore
never be cacheable by anything. Today the plugin only sends LiteSpeed-specific
signals (`LiteSpeedCompat::mark_nocache()`); the standard `Cache-Control`
header seen in live tests was added by the LSCWP plugin, so the invariant
currently depends on LSCWP being active. Close that gap.

### 1.1 Standard no-cache header on negotiated + 406 responses

- File: `system-markdown-alternate/src/MarkdownController.php`.
- `LiteSpeedCompat::mark_nocache()` is called in exactly the two places that
  must become no-cache (and nowhere else): the negotiated-Markdown branch and
  the `406` branch of `maybe_render_markdown()` (currently lines ~67 and ~72).
- Add a small private helper, e.g. `send_no_cache_headers(): void`, that:
  1. sends `Cache-Control: no-cache, no-store, must-revalidate, private`
     (exact string; server-agnostic security invariant), guarded by
     `headers_sent()`;
  2. then calls `LiteSpeedCompat::mark_nocache()` (LiteSpeed-specific signals
     stay in `LiteSpeedCompat`, which keeps its single responsibility).
- Replace the two `LiteSpeedCompat::mark_nocache()` call sites with the
  helper. Calling it **before** `serve_markdown()` keeps the invariant on both
  `200` and the conditional `304` exits of the negotiated path.
- **Must NOT touch the `.md` suffix branch**: dedicated `.md` URLs stay
  cacheable with `ETag`/`Last-Modified` → `304` and no `Cache-Control` at all
  (durable decision, scope clarified in `AGENTS.md`).
- Update the docblocks that describe the negotiated no-cache behaviour
  (`MarkdownController` class docblock comment near the call sites, and the
  `LiteSpeedCompat` class docblock which currently frames no-cache as
  LiteSpeed-only).

### 1.2 Purge LiteSpeed cache on plugin activation/deactivation

- Decision: entries cached before activation carry no `Vary` and cause ghost
  behaviour; purge-all on both activation and deactivation.
- Make `LiteSpeedCompat::purge_litespeed_cache()` **public** (currently
  private, line ~346).
- File: `system-markdown-alternate/system-markdown-alternate.php` — register
  `register_activation_hook( __FILE__, … )` and
  `register_deactivation_hook( __FILE__, … )` callbacks that call
  `LiteSpeedCompat::purge_litespeed_cache()` (it is already a no-op when the
  LSCWP plugin is absent: it only fires `do_action( 'litespeed_purge_all' )`).

### 1.3 Release chores (v0.21.4)

- `php -l` on touched files; run the pure-logic tests (no new pure-logic
  surface here — header emission needs WP — so no new tests required; do not
  break existing ones).
- Manual verification checklist (staging, browser UA — WAF blocks curl UAs):
  - `GET /post/` with `Accept: text/markdown` → Markdown +
    `Cache-Control: no-cache, no-store, must-revalidate, private` +
    `X-LiteSpeed-Cache-Control: no-cache` + `Vary: Accept`.
  - Same URL, browser Accept → HTML, **no** plugin `Cache-Control`.
  - `GET /post.md` → Markdown, `ETag` present, **no** `Cache-Control`.
  - Conditional negotiated request → `304` still carries the no-cache header
    path (header sent before the conditional exit).
- `AGENTS.md`: update the "Current state" LiteSpeedCompat bullet (layer 1 now
  includes the standard header; activation/deactivation purge) and the "HTTP
  headers" bullet; move the "Cache hardening" item out of "Open / to do".
  Mirror in `AGENTS.it.md` (same commit).
- `readme.txt` changelog entry + `Stable tag` 0.21.4; version bump (header +
  `SYSMDA_VERSION`); `bash bin/build.sh`; commit
  (`fix: server-agnostic no-cache on negotiated responses + activation purge (v0.21.4)`
  or similar); annotated tag `v0.21.4`.

---

## Phase 2 — `.md` hit counter (minor release v0.22.0)

Spec already decided and frozen in `AGENTS.md` ("Open / to do" → "`.md` hit
counter" + the count-only durable decision). Summary — follow the AGENTS.md
plan verbatim:

1. New `system-markdown-alternate/src/HitCounter.php`, single responsibility:
   - `public static is_bot( ?string $ua ): bool` — empty UA ⇒ bot;
     case-insensitive token list (bot, crawl, spider, curl, wget, python,
     java, http, headless, gpt, claude, perplexity, …); documented filter
     `sysmda_md_hits_bot_patterns`.
   - `record( ?string $ua )` — increments today's bucket in option
     `sysmda_md_hits` (autoload off, shape
     `[ 'YYYY-MM-DD' => [ 'bot' => n, 'human' => n ] ]`), prunes buckets older
     than 90 days (documented filter `sysmda_md_hits_retention_days`).
   - The UA is read only to classify, never stored (count-only privacy
     decision: no IPs, no raw UAs, no per-visitor identifiers, day
     granularity only). Lost increments under heavy concurrency are accepted.
2. `MarkdownController::serve_markdown()`: when option `sysmda_md_hits_enabled`
   is on, `record()` every served response — `200` **and** `304` — for both
   the `.md` suffix and the negotiated permalink.
3. `AdminSettings.php`: "Count `.md` requests" checkbox (Advanced section,
   default off) + read-only totals on the settings page (today / last 7 /
   last 30 days, bot vs human) with the page-cache undercount caveat in the
   description.
4. `uninstall.php`: add `sysmda_md_hits` + `sysmda_md_hits_enabled`.
5. Pure-logic tests in `tests/run-tests.php` for `is_bot()` and the pruning
   logic; `php -l` on touched files.
6. New filters added to the `AGENTS.md` "Filters (public contract)" list;
   docs + `AGENTS.it.md` translation; `readme.txt` changelog.

## Phase 3 — Surface the filter API in user-facing docs (with v0.22.0)

- `system-markdown-alternate/readme.txt`: add an FAQ entry
  (`== Frequently Asked Questions ==`) stating the plugin is
  developer-extensible via filters, with 2–3 representative examples
  (e.g. `sysmda_markdown_output`, `sysmda_markdown_excluded_classes`,
  `sysmda_llms_txt_enriched`) and a pointer to the full list in the GitHub
  repository.
- `README.md`: short "Extending via filters" section (or pointer) — mirror in
  `README.it.md` in the same commit.
- No version implication of its own: ship inside the v0.22.0 round (separate
  docs commit or the release commit, keep commits atomic).

### Phase 2+3 release chores (v0.22.0)

- Tests green on PHP CLI; `readme.txt` changelog + `Stable tag` 0.22.0;
  version bump (header + `SYSMDA_VERSION`); `bash bin/build.sh`; commit;
  annotated tag `v0.22.0`.
- `AGENTS.md`: move the hit counter + filter-docs items from "Open / to do"
  into "Current state"; mirror in `AGENTS.it.md`.

---

## Wrap-up

1. Delete this `PLAN-cache-hardening-and-hit-counter.md` (work done).
2. Consolidate on `main` per the fixed procedure (ff-only, no merge commits,
   no PRs) and `git push origin main`.
3. Out of scope, do not touch: homepage `.md` (postponed pending hit-counter
   data), Vary self-test (rejected), rate limiting (rejected), synthesized
   homepage index (rejected) — see "Product decisions" in `AGENTS.md`.
