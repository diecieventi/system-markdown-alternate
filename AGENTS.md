# AGENTS.md — System Markdown Alternate

**Tool-agnostic** operational guide for developing and maintaining this WordPress
plugin: current state, decisions, structure, conventions and workflow. The
functional state is documented here, in `README.md` and in the `readme.txt`
changelog.

> `CLAUDE.md` is a **symlink** to this file: Claude Code, Cursor, Copilot & co.
> all read the same source of truth, with no duplicates to keep aligned. The few
> notes specific to **Claude Code (web)** live in the dedicated section at the end
> of "Identity, versioning, workflow"; other agents can ignore them.
>
> **Translations**: `AGENTS.it.md` and `README.it.md` are the Italian versions of
> this file and of `README.md`. The English files are the **source of truth**;
> whenever you change one of them, update its Italian translation **in the same
> commit**. The plugin itself is English-only (see the i18n note in
> "Technical notes": translations come from translate.wordpress.org).

## What it is

A custom WordPress plugin that exposes a **clean Markdown version** of the
content (readable by LLMs, agents, technical scraping tools). Every published
post of the enabled types is reachable by appending `.md` to the permalink:

```
https://example.com/my-post/      → HTML
https://example.com/my-post.md    → Markdown (front matter + content)
```

It is **not** a generic SEO plugin: it is a technical feature. Priorities: work
well on the blog, stay easy to verify, produce clean Markdown, create no SEO
risk, stay extensible via filters.

## Commands

```bash
# Pure-logic tests (no WP/PHPUnit; CI runs them on PHP 7.4 and 8.4)
php system-markdown-alternate/tests/run-tests.php

# Lint a touched file
php -l system-markdown-alternate/src/<File>.php

# Install Composer dependencies locally (creates vendor/, required to run the plugin)
composer install --working-dir=system-markdown-alternate

# Build the distributable zip with vendor/ bundled → DIST/system-markdown-alternate.zip
bash bin/build.sh
```

## Current state (v0.21.x)

The v1 scope is done and widely exceeded. Implemented:

- **`.md` endpoint** for the enabled post types (public post/page/CPT), published,
  public, not password-protected; **content negotiation** (`Accept: text/markdown`
  or `?format=markdown`). The `Accept` header is **parsed with q-values**
  (`AcceptNegotiator`): Markdown is served only when explicitly preferred
  (q ≥ HTML); a wildcard or missing Accept stays HTML. Negotiable URLs →
  **`Vary: Accept`**; optional **`406`** when the client accepts neither HTML nor
  Markdown (`sysmda_markdown_strict_406` filter, default on).
- **`rel="alternate"` link** in the `<head>` of supported singular content.
- **HTTP headers**: `Content-Type: text/markdown; charset=utf-8`,
  `X-Robots-Tag: noindex, follow`, `Link: <permalink>; rel="canonical"`,
  `Vary: Accept` (on negotiable URLs), **`ETag` + `Last-Modified`**.
- **Conditional requests**: the `.md` response honours `If-None-Match` /
  `If-Modified-Since` and replies **`304 Not Modified`** (no body) when the client
  already holds the current version. Validator = the existing cache-version hash
  (`post_modified_gmt` + `SYSMDA_VERSION` + settings salt), so a `304` always means the
  cached body would be identical; `If-None-Match` takes priority over
  `If-Modified-Since` (RFC 9110). Works even with the body cache disabled.
- **Clean conversion**: `render_block()` on the cleaned blocks (no related/CTA),
  excluded blocks/shortcodes/classes, fenced code blocks, **absolute URLs resolved
  against the source permalink** (document-relative, `../`, root-relative).
  **Synced patterns** (`core/block`) are expanded into the referenced content and
  cleaned with the same rules (reference-cycle guard).
- **Plain permalinks** (`?p=123`): the `.md` suffix is not applicable, so
  `markdown_url()` falls back to `?format=markdown` (served via negotiation);
  notice in the settings page. Post eligibility centralized in `PostSupport`.
- **`/llms.txt`** (cached, excludes protected content) with an on/off toggle.
  Optional **enriched mode** (`sysmda_llms_txt_enriched` toggle, default off;
  off = base output unchanged): site summary, curated "Key content" section
  (IDs/URLs from the settings page), per-entry description (Rank Math → excerpt →
  trimmed chain), overflow beyond the most recent posts under `## Optional`
  (spec keyword, not translated), `sysmda_llms_txt_footer` filter as a hook for
  policy/LLM signals. Optional **last modified dates** (`sysmda_llms_txt_lastmod`
  toggle, default off; off = output unchanged): appends `(updated: YYYY-MM-DD)`
  to every entry (base and enriched, Key content and Optional included) — ISO
  date from `post_modified_gmt`, English `updated:` label never translated
  (same convention as the `Optional` spec keyword), placed in the free-text
  notes after the `:` so it stays llms.txt-spec-compatible.
- **LiteSpeed page-cache compatibility** (`LiteSpeedCompat`): some LiteSpeed
  servers key the page cache by URL only and ignore `Vary: Accept` (observed
  live: a cached Markdown variant served to HTML clients and vice versa, while
  PHP negotiated correctly). Two layers: (1) the negotiated Markdown and `406`
  responses always send `X-LiteSpeed-Cache-Control: no-cache` + define
  `DONOTCACHEPAGE` + fire the LSCache-plugin `litespeed_control_set_nocache`
  action, so URL-keyed caches never store them (`.md` URLs stay cacheable: they
  are their own key); (2) opt-in **`.htaccess` rules** (Advanced →
  `sysmda_litespeed_htaccess` checkbox, default off) wrapped in
  `<IfModule LiteSpeed>` (inert elsewhere): requests whose `Accept` mentions
  `text/markdown`, or allows neither HTML nor a wildcard (the 406 case), get
  `[E=Cache-Control:no-cache]` and bypass the LiteSpeed cache, so PHP always
  negotiates even when the HTML variant is already cached. The block is
  written at the **top** of `.htaccess` — it MUST precede `# BEGIN WordPress`,
  whose `[L]` rules end every rewrite pass, so a block appended at the bottom
  is never evaluated (verified live; do not switch back to
  `insert_with_markers`, which appends). Synced (written/removed/moved back to
  the top) on every settings-page load, comparing directive lines only (WP
  injects an instruction comment inside marker blocks); triggers an LSCache
  purge-all on change, shows the rules to copy manually when `.htaccess` is
  not writable, and is removed on uninstall.
- **Redis-aware cache** (`Cache` helper): persistent object cache when present,
  transients otherwise. Invalidation via global salt + `post_modified_gmt` +
  `SYSMDA_VERSION`; salt bump on settings save; cleanup on `save_post`/
  `deleted_post` (skips revisions/autosaves).
- **Admin panel** (single page, Settings API): General / Markdown output /
  llms.txt / Integrations / Advanced. Restyled UI (presentation only): page
  header + single Save button, native WP **tabs**, section **cards**, two-column
  layout with an at-a-glance `/llms.txt` status/conflict aside, built-in defaults
  in a `<details>` disclosure. `render_page()` iterates the registered Settings
  API sections (`$wp_settings_sections`) and wraps each in a card+tab-panel;
  **all fields stay in the single form** (tabs show/hide client-side), so saving,
  sanitization and nonces are unchanged. Admin-scoped CSS + a tiny dependency-free
  vanilla-JS enhancement (`assets/admin-settings.js`); usable without JS (all
  panels visible). Assets loaded only on the settings screen.
- **i18n**: panel strings in `__()`/`esc_html__()` (**English** source), text
  domain `system-markdown-alternate` (= plugin slug). **No bundled translations
  and no manual translation loader**: language packs come from
  translate.wordpress.org and WP loads them automatically (≥ 4.6).
- **ACF**: subtitle (text) + TL;DR (WYSIWYG, goes through the DOM pipeline) as a
  preamble between the H1 and the body; field names configurable from the panel.
- **Shortcode** `[sysmda_md_url]` (+ `id="123"`).
- **GenerateBlocks Dynamic Tag** `{{sysmda_md_url}}`: self-registers when GB 2.x is
  active (no toggle).
- `uninstall.php` (removes `sysmda_*` options + transients + the LiteSpeed
  `.htaccess` block).

## Open / to do (towards wordpress.org)

- Once live on wordpress.org: translate the strings into Italian on
  translate.wordpress.org (request PTE if needed) so the `it_IT` language pack
  gets built — no translation files live in this repo.
- Future idea: formalized **LLM signals** in `/llms.txt` once the spec
  (Cloudflare & co.) settles — the hook is already in place (`sysmda_llms_txt_footer`).
- **`.md` hit counter** (decided; plan below — next planned minor):
  count how many times the `.md` endpoint is served, split **bot vs human**,
  and nothing else. Privacy by design (see "Product decisions"): aggregate
  daily counters only → anonymous data, outside the GDPR scope (no consent,
  no banner) and within the wordpress.org "no tracking without consent"
  guideline. **Opt-in checkbox, default off.** Accepted limit: a page
  cache/CDN serving `.md` without reaching PHP undercounts — it is an
  indicator, not analytics. Implementation plan (own minor after 0.20.0):
  1. New `src/HitCounter.php` (single responsibility): `record( ?string $ua )`
     classifies the request via `public static is_bot( ?string $ua ): bool`
     (empty UA ⇒ bot; case-insensitive token list: bot, crawl, spider, curl,
     wget, python, java, http, headless, gpt, claude, perplexity, …;
     documented filter `sysmda_md_hits_bot_patterns`) and increments today's
     bucket in option `sysmda_md_hits` (autoload off, shape
     `[ 'YYYY-MM-DD' => [ 'bot' => n, 'human' => n ] ]`), pruning buckets
     older than 90 days (documented filter `sysmda_md_hits_retention_days`).
     The UA is read only to classify, never stored. The read-modify-write
     may lose an increment under heavy concurrency: accepted (indicator).
  2. `MarkdownController::serve_markdown()`: when `sysmda_md_hits_enabled` is
     on, `record()` every served response — `200` **and** `304` (an access
     is an access) — for both the `.md` suffix and the negotiated permalink.
  3. `AdminSettings.php`: "Count `.md` requests" checkbox (Advanced section)
     + read-only totals on the settings page (today / last 7 / last 30 days,
     bot vs human) with the page-cache caveat in the description.
  4. `uninstall.php`: add `sysmda_md_hits` + `sysmda_md_hits_enabled` to the list.
  5. Tests for `is_bot()` and the pruning logic; `php -l`.
  6. Filters list, docs + translations, `readme.txt` changelog,
     version bump, build, commit, push.
- **`.wordpress-org/screenshot-*.jpg` are stale**: they show the pre-0.17.0 admin
  UI (before the tabs/cards restyle). Recapture them and update the
  `== Screenshots ==` captions in `readme.txt` whenever convenient (no version
  bump needed: they live in the SVN `/assets` folder, independent of `/trunk`).

### To check next time (not urgent, parked here)

- **Filters undocumented in user-facing docs**: the plugin exposes an extensive
  filter API (see "Filters (public contract)" below) but neither `readme.txt`
  (`== Frequently Asked Questions ==`) nor `README.md`/`README.it.md` mention that
  filters exist at all. Decide where to surface this for end users (at least a
  pointer to the filter list) and fix.
- **Evaluate new integrations**: beyond ACF/GenerateBlocks, consider what else
  might be worth a dedicated integration (candidates TBD).
- **Evaluate enriching/managing `/llms.txt` further**: beyond the current enriched
  mode, consider what else is worth adding (candidates TBD, see also the LLM
  signals idea above).
- ~~Possible `.md` serving log~~ → evaluated and promoted to the planned
  **`.md` hit counter** (see "Open / to do" above and the count-only decision
  in "Product decisions").

## Product decisions (durable)

- `sysmda_markdown_supported_post_types` defaults to **empty** → the plugin is
  **inactive** until at least one type is selected in the panel. `attachment` is
  always excluded. **CPTs are supported** (all public types are shown/validated).
- **ACF** and **GenerateBlocks** panel sections: shown only when the respective
  plugin is active. ACF options are `register_setting`-ed **only when ACF is
  active**, so saving with ACF off does not wipe the field names (the Settings
  API writes every registered option of the group).
- **GenerateBlocks Dynamic Tag**: auto-registered when GB 2.x is present. For
  non-servable posts the callback returns '' → GB's "required to render" option
  hides the element (no broken links).
- **`/llms.txt` conflict detection**: only **local, stable** signals (active SEO
  plugins via constant/class + physical file in the root). No reading of third-
  party internal options, no loopback HTTP checks (removed: unreliable behind a
  WAF). It is an informational notice only; the user decides.
- **NO auto-yield of `/llms.txt`** (decided, do not propose again): the plugin
  NEVER disables itself, not even as an option. Enabling/disabling is always and
  only a manual user choice from the panel; if other handlers are active
  underneath, that is the user's responsibility. The conflict notice stays purely
  informational.
- Front matter **description**: Rank Math (`rank_math_description`) → discarded
  only when it contains an unresolved `%variable%` placeholder → excerpt fallback
  → trimmed text (~200 chars). Front matter includes `featured_image`
  (+ `featured_image_alt`).
- **NO explicit `Cache-Control` on the `.md` response** (decided, do not propose
  again): the plugin does NOT emit `Cache-Control`/`max-age`. Conditional
  requests (`ETag`/`Last-Modified` → `304`) already give efficient revalidation
  without ever serving stale Markdown. A `max-age` would risk conflicting with
  page-cache/CDN plugins and could keep serving an outdated version after an
  edit; freshness policy belongs to the infrastructure/CDN, not the plugin.
- **NO XML sitemap for the `.md` URLs** (decided, do not propose again): the
  `.md` responses are `noindex` by design, so listing them in a sitemap would
  send contradictory signals to search engines (Search Console: "submitted URL
  marked noindex") — exactly the SEO risk the plugin promises not to create —
  and a second sitemap generator would overlap with the SEO plugin's sitemaps
  (Rank Math & co.). Discovery for the real audience (LLMs/agents) is already
  covered by the `rel="alternate"` link and by `/llms.txt`. Freshness signals
  go into `/llms.txt` itself (see the `lastmod` item in "Open / to do"): no
  separate machine-index endpoint either.
- **`.md` hit counter is count-only** (decided): when enabled it stores ONLY
  aggregate daily counters split bot/human. NEVER store IP addresses, raw
  user-agent strings, timestamps finer than the day, or any per-visitor
  identifier; the user-agent is read from the request only to classify
  bot vs human and is immediately discarded. No external calls, no cookies.
  This keeps the stored data anonymous (GDPR out of scope, no consent needed)
  and within the wordpress.org "no tracking without consent" guideline.

## Identity, versioning, workflow

- Plugin **Author** = **"Diecieventi Digital Marketing"**. The author's legacy
  company name **must NEVER appear** in artifacts (code, commits, readme).
- **GitHub home**: personal account **`diecieventi`**
  (`github.com/diecieventi/system-markdown-alternate`); `Plugin URI` and
  `composer.json` point there. `Author URI` → `webdietrolequinte.it` (the site's
  domain, unchanged).
- **wordpress.org**: `Contributors:` in `readme.txt` is set to **`system4pc`**
  (the existing account: the username cannot be renamed, only the Display Name
  can change). Publishing from a new `diecieventi` account and updating the field
  remains an option.
- Do not put the **model ID** in commits, readme, code or any other artifact.
- **Semver `0.x.y` versioning**: minor for new features, patch for fixes. On
  every release: bump `system-markdown-alternate.php` (both the `Version:` header
  **and** `SYSMDA_VERSION`), update `Stable tag` + changelog in `readme.txt`,
  `bash bin/build.sh`, commit, push.
- **Git — single non-negotiable rule**: the **only destination for code is
  `main`**. Single developer, no feature branches, **NEVER** open PRs (not even
  on implicit request), **NEVER** leave work on a technical branch. Atomic
  commits. The user syncs their Mac manually with a single `git pull origin
  main`: no other steps, no "push here / merge there".

### Claude Code (web) — specific

Note valid only for the **Claude Code on the web** environment (other agents can
ignore it). Fixed procedure, **permanent permission** from the user (never ask
again): the harness forces work to start on a `claude/*` technical branch. Commit
there normally, then **at the end of the work land on `main` only**:

1. `git fetch origin main`
2. `git checkout main && git merge --ff-only origin/main` (align the local main)
3. `git merge --ff-only <technical-branch>` to bring the commits onto `main`
   (if the fast-forward is not possible because `main` moved ahead, `git rebase
   main` on the technical branch and repeat the ff-merge — history stays linear,
   **no merge commits**)
4. `git push origin main`

The technical branch is **only the staging imposed by the environment**: it is
not pushed, it creates no PRs, it is not merged via UI. Ignore it after the
consolidation.

## Compatibility with known plugins / test environment

Developed and tested against a stack based on **GeneratePress/GenerateBlocks
2.x**, **ACF** and **Rank Math**. When testing over HTTP, keep in mind that a
**WAF/CDN** may block non-browser User-Agents (e.g. `curl` as a "bad bot"): use
a browser User-Agent.

**Test environment**: a staging site with GeneratePress/GenerateBlocks, ACF and
WooCommerce on a recent WP / PHP 8.4, **without a persistent object cache**
(Cache uses the transient fallback). The full zip cannot be installed remotely:
logic is verified with the **local PHP tests** (`tests/run-tests.php`) or by
running code at the WP level.

### Impact on defaults

- **Syntax highlighters** (e.g. Code Block Pro): do NOT convert the highlighting
  HTML. Strip the `<span>`s while preserving the `language-*` class and let the
  converter produce the fenced block (generic approach, covers any highlighter).
- **Table of Contents** (e.g. LuckyWP TOC): navigation → excluded (`lwptoc`
  shortcode, `luckywp/toc` block).
- **Gallery/image lightboxes**: just wrappers around images; no special handling,
  preserving `alt` is enough.
- **GenerateBlocks**: NEVER excluded automatically (they contain real content).
- **ACF**: implemented (subtitle/TL;DR via preamble). The
  `sysmda_markdown_source_content` / `sysmda_acf_field_keys` filters remain the
  extension points.
- **On-site search engines** (e.g. Algolia): irrelevant to the output.
- **LiteSpeed page cache**: behaviour varies per server — some installs honour
  `Vary: Accept`, others key by URL only and mix the representations. Handled by
  `LiteSpeedCompat` (see "Current state"): no-cache signals on the negotiated
  responses always on, `.htaccess` bypass rules opt-in from the panel.

## Repository structure

```
.
├── AGENTS.md                     ← this file (tool-agnostic guide, English)
├── AGENTS.it.md                  ← Italian translation of this file
├── CLAUDE.md                     ← symlink → AGENTS.md
├── README.md                     ← repo overview (GitHub, English)
├── README.it.md                  ← Italian translation of README.md
├── LICENSE                       ← GPL-2.0 (full text)
├── .gitignore
├── .github/workflows/ci.yml      ← CI: php -l + tests on PHP 7.4/8.4
├── .github/workflows/deploy-wordpress-org.yml  ← SVN deploy (ready, not active: needs SVN secrets + a published Release)
├── .wordpress-org/               ← wordpress.org listing assets (icon, banners)
├── bin/build.sh                  ← builds DIST/system-markdown-alternate.zip
├── DIST/                         ← distributable zip (versioned)
└── system-markdown-alternate/    ← THE PLUGIN
    ├── system-markdown-alternate.php   ← header + bootstrap (Composer autoloader)
    ├── readme.txt                      ← wordpress.org format + changelog
    ├── uninstall.php                   ← options + transients cleanup
    ├── .distignore                     ← exclusions for the WP.org package (SVN)
    ├── composer.json / composer.lock   ← league/html-to-markdown + PSR-4
    ├── vendor/                         ← NOT versioned, zip only
    ├── assets/admin-settings.css       ← panel style (loaded only there)
    ├── assets/admin-settings.js         ← tab client-side (vanilla, progressive enhancement)
    ├── tests/run-tests.php             ← pure-logic tests (php tests/run-tests.php, no WP/PHPUnit)
    └── src/
        ├── Plugin.php              ← bootstrap, registers hooks and dependencies
        ├── MarkdownController.php  ← intercepts .md + content negotiation (Vary/q-values/406), validation, headers, cache, output, alternate link, invalidation
        ├── AcceptNegotiator.php    ← Accept header parser with q-values (no WP deps)
        ├── ContentRenderer.php     ← source → clean HTML (shortcodes/blocks/DOM/absolute URLs); render_fragment()
        ├── BlockCleaner.php        ← Gutenberg block parsing/cleaning (expands synced patterns)
        ├── PostSupport.php         ← post eligibility (is_servable, supported types)
        ├── ShortcodeCleaner.php    ← removal of excluded shortcodes
        ├── MetadataBuilder.php     ← YAML front matter; markdown_url() (static)
        ├── MarkdownConverter.php   ← HTML → Markdown (league/html-to-markdown)
        ├── AcfIntegration.php      ← subtitle + TL;DR (preamble)
        ├── LlmsTxtController.php   ← /llms.txt endpoint (cached)
        ├── AdminSettings.php       ← settings page (Settings API)
        ├── ConflictDetector.php    ← /llms.txt conflict detection (local only)
        ├── LiteSpeedCompat.php     ← LiteSpeed page-cache compatibility (no-cache signals + optional .htaccess rules)
        ├── Shortcodes.php          ← [sysmda_md_url]
        ├── DynamicTags.php         ← {{sysmda_md_url}} (GenerateBlocks 2.x)
        └── Cache.php               ← cache helper (object cache or transients)
```

- **PHP namespace:** `Diecieventi\SystemMarkdownAlternate` (PSR-4 → `src/`).
- **Constant/hook/option prefix:** `sysmda_` / `SYSMDA_` (≥ 4 chars and
  distinctive, per the wordpress.org prefixing guideline; also used with a dash
  for slugs/handles: `sysmda-settings`, `sysmda-admin-settings`).

## Code conventions

- PHP `>= 7.4`, WP `>= 6.1`. No runtime dependencies beyond `league/html-to-markdown`.
- Small, single-responsibility classes.
- `defined('ABSPATH') || exit;` at the top of every PHP file.
- Strict output escaping (especially the **YAML front matter**: quote strings,
  escape `"` and `\`).
- Every filter must be **documented with a docblock**.
- After changes: `php -l` on the touched files and
  `php system-markdown-alternate/tests/run-tests.php` (pure-logic tests, no WP;
  CI runs them on PHP 7.4 and 8.4).

## Filters (public contract)

```php
apply_filters( 'sysmda_markdown_supported_post_types', array() );             // [] = plugin inactive until a type is selected
apply_filters( 'sysmda_markdown_robots_header', 'noindex, follow', $post );   // '' = do not send the header
apply_filters( 'sysmda_markdown_strict_406', true );                          // false = no 406, always serve the default HTML
apply_filters( 'sysmda_markdown_canonical_url', get_permalink( $post ), $post ); // '' = do not send Link rel=canonical
apply_filters( 'sysmda_markdown_cache_ttl', DAY_IN_SECONDS, $post );          // 0 = cache disabled
apply_filters( 'sysmda_markdown_source_content', $post->post_content, $post );
apply_filters( 'sysmda_markdown_rendered_html', $html, $post );
apply_filters( 'sysmda_markdown_preamble', '', $post );                       // block between # Title and body (subtitle/TL;DR)
apply_filters( 'sysmda_markdown_output', $markdown, $post );
apply_filters( 'sysmda_markdown_excluded_block_names', $block_names );
apply_filters( 'sysmda_markdown_excluded_shortcodes', $shortcodes );
apply_filters( 'sysmda_markdown_excluded_classes', $css_classes );
apply_filters( 'sysmda_acf_field_keys', array(), $post );                     // ACF fields appended to the source
apply_filters( 'sysmda_acf_subtitle_key', '', $post );                       // ACF subtitle field ('' = off)
apply_filters( 'sysmda_acf_tldr_key', '', $post );                          // ACF TL;DR field ('' = off)
apply_filters( 'sysmda_llms_txt_max_posts', 500, $post_type );              // max posts per type in /llms.txt
apply_filters( 'sysmda_llms_txt_cache_ttl', DAY_IN_SECONDS );               // /llms.txt cache TTL (0 = off)
apply_filters( 'sysmda_llms_txt_enriched', false );                         // true = enriched /llms.txt output
apply_filters( 'sysmda_llms_txt_lastmod', false );                          // true = append (updated: YYYY-MM-DD) to each entry
apply_filters( 'sysmda_llms_txt_summary', '' );                             // site summary (enriched only)
apply_filters( 'sysmda_llms_txt_key_content', array() );                    // featured content: IDs or URLs (enriched only)
apply_filters( 'sysmda_llms_txt_main_posts', 25, $post_type );              // posts per type in the main section (enriched only)
apply_filters( 'sysmda_llms_txt_footer', '' );                              // free-form trailing block (enriched only)
```

Default exclusions:
- Block names: `gravityforms/form`, `contact-form-7/contact-form-selector`,
  `wpforms/form-selector`, `mailerlite/form`, `luckywp/toc`.
- Shortcodes: `contact-form-7`, `gravityform`, `wpforms`, `mailerlite_form`, `lwptoc`.
- CSS classes: `no-md`, `md-exclude`, `exclude-from-markdown`.

## Technical notes

1. **`.md` resolution**: on `template_redirect` (priority 0) read `REQUEST_URI`,
   detect the `.md` suffix, handle query strings and trailing slashes
   (`/slug.md/` → 301 → `/slug.md`), rebuild the permalink and use
   `url_to_postid()`. No rewrite rules → no `flush_rewrite_rules`.
2. **Content negotiation**: besides the `.md` suffix, on the canonical permalink
   the representation is decided with `AcceptNegotiator` (RFC 9110). Markdown
   only when explicitly preferred: `?format=markdown` or `text/markdown` with
   q ≥ the effective q of `text/html` (exact match > `text/*` > full wildcard).
   A wildcard or missing Accept → HTML (so curl/library `Accept: */*` stays
   HTML). Every servable content declares **`Vary: Accept`** (both when serving
   Markdown and when leaving the HTML to WP), so caches/CDNs never mix the two
   representations. If the Accept allows neither HTML nor Markdown, respond
   **`406`** (`sysmda_markdown_strict_406` filter, default on; real clients always
   send `text/html` or a wildcard, never hit). The `.md` suffix ignores the
   Accept header instead (the URL itself is the explicit Markdown request).
3. **Class exclusion**: besides `attrs.className`, a `DOMDocument` pass on the
   rendered HTML removes nested elements carrying the excluded classes.
4. **Rendering**: `render_block()` on the cleaned blocks (not the full
   `the_content`), to avoid reintroducing injected related/CTA content.
5. **Absolute URLs**: resolved against the post permalink (not `home_url('/')`).
6. **Cache**: key `sysmda_md_{post_id}`, value with a validity hash
   (`post_modified_gmt|SYSMDA_VERSION|salt`); `/llms.txt` cached under
   `sysmda_llms_txt`. Everything through the `Cache` helper (persistent object
   cache or transients). The **same hash is the strong `ETag`** of the `.md`
   response (`ETag`/`Last-Modified` + conditional `304`, `If-None-Match` over
   `If-Modified-Since`); it derives from `post_modified`, so conditional requests
   work even when the body cache is off.
7. **i18n**: **English** is the source language for runtime strings, code
   comments, DocBlocks, tests, build tooling and workflow messages. The only
   intentional Italian repository documents are `AGENTS.it.md` and
   `README.it.md`. Strings with inline HTML (`<code>`, `<strong>`, …) go through
   `wp_kses_post()`. Text domain `system-markdown-alternate` (= plugin slug,
   required by wordpress.org). **No translation catalogs or manual translation
   loader belong in the plugin or repository**: WordPress automatically loads
   the language packs built by translate.wordpress.org. Translations are managed
   there once the plugin is live (see "Open / to do"). Installs from the GitHub
   zip are English-only by design until an official language pack is available.

## Notes from the reference plugin (ProgressPlanner/markdown-alternate)

GPL plugin by Joost de Valk. Same library, same PSR-4. Adopted converter config:

```php
new HtmlConverter([
    'header_style'    => 'atx',          // # Heading
    'strip_tags'      => true,
    'remove_nodes'    => 'script style iframe',
    'hard_break'      => false,
    'list_item_style' => '-',
]);
```

- **Conversion fallback**: if `convert()` throws → simple text extraction instead
  of breaking the response.
- **escape_yaml**: entity decoding + escaping of `\` and `"`.

## Build & deploy

```bash
bash bin/build.sh        # → DIST/system-markdown-alternate.zip (vendor/ bundled)
```

The zip includes the production Composer dependencies, so it installs without
Composer on the server. Local build environment: PHP 8.4, Composer and `zip`
(no wp-cli).

### Publishing to wordpress.org (SVN)

On WP.org you **deploy**, you don't develop: the GitHub repo remains the home of
development, SVN is distribution only. What goes into SVN is **the content of the
`system-markdown-alternate/` folder** (not the repo root: no `README.md`,
`AGENTS.md`, `bin/`, `DIST/`, `.github/`), with **`vendor/` bundled** (runtime
dependency). The plugin-folder exclusions live in
`system-markdown-alternate/.distignore` (`tests/`, `composer.lock`). The
production package intentionally keeps `composer.json` alongside `vendor/`, as
required for dependency review by WordPress.org Plugin Check.

- Manual flow: `bash bin/build.sh`, then copy the content into `svn/trunk` and
  tag it under `svn/tags/x.y.z`.
- **Automated flow** (ready, not yet active): `.github/workflows/deploy-wordpress-org.yml`
  runs `10up/action-wordpress-plugin-deploy`, triggered on **publishing a GitHub
  Release** (not on a bare tag push, to avoid a run without SVN credentials).
  Since `BUILD_DIR` ignores `.distignore`, the workflow stages a clean copy of
  `system-markdown-alternate/` itself (same exclusions as `.distignore`) before
  handing it to the action. `VERSION` is derived from the tag name (`v0.18.0` →
  `0.18.0`). **Activation, once accepted on wordpress.org**: add the
  `SVN_USERNAME` / `SVN_PASSWORD` repository secrets, then publish a GitHub
  Release on the version tag.
- **Git tags**: annotated, `vX.Y.Z` on the commit that bumps the version (e.g.
  `v0.18.0`); retroactively added from `v0.17.1` onward. Not required for local
  development — only for SVN releases and for pinning a specific version on
  GitHub.
  Banner/icon/screenshots live in the SVN `/assets` folder (not in the plugin)
  and are updated with `10up/action-wordpress-plugin-asset-update` from the
  repo's `.wordpress-org/` folder.

## Tests (acceptance)

Test posts:
1. Simple post (headings, paragraphs, list, links) → `.md` OK, correct headers, front matter, alternate link.
2. Post with images + code (with a syntax highlighter) + blockquote → correct conversion.
3. Post with an `md-exclude` section → absent from the `.md`.
4. Post with a form shortcode (`[contact-form-7 ...]`) and a TOC (`[lwptoc]`) → absent from the `.md`.
5. Disallowed content (non-enabled page/CPT, draft, password-protected post) → **404**.

Always verify: `Content-Type: text/markdown; charset=utf-8`,
`X-Robots-Tag: noindex, follow`; no private/draft/non-enabled content exposed.
Note: command-line HTTP tests may be blocked by a WAF/CDN
(use a browser User-Agent).
