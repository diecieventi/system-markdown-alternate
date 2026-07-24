# Plan: translations in `/llms.txt` (dedicated section)

> English-only (repo convention). Independent of the ordered work in
> `docs/tier1-implementation-plan.md`. **Do not code before the staging
> reconnaissance below** — the current plan's central query assumption is not
> reliable and must be verified against real WPML/Polylang behavior first.

## Context

Today `LlmsTxtController` lists content with a single `get_posts()` call
(`src/LlmsTxtController.php:166`) **without any language override**. That query
does **not** currently set `suppress_filters => false`, and WordPress core
defaults `suppress_filters` to `true` for `get_posts()`. Per official WPML
guidance this default can return posts from **multiple languages**; Polylang
recommends an explicit `lang` query argument. So the present behavior is
**plugin/version-dependent** and may already be: only the default language, or
mixed languages, or duplicates. `/llms.txt` has no language prefix and runs in
the default language context.

Goal (reduced scope, agreed with the user):

- **One single `/llms.txt`, exactly as it is now.** No per-language endpoints
  (`/it/llms.txt`, `/en/llms.txt` — abandoned), no routing changes, no URL-mode
  handling.
- If the site has other languages, **list the translations in a dedicated
  section** (`## Translations`) before the footer.
- The main sections keep depending on the **CPTs enabled in the panel**
  (`PostSupport::supported_post_types()`). The translations section respects the
  same types.

## Invariant to enforce first (the audit's blocking issue)

Before any output work, define and enforce:

> **Main sections contain only default-language source posts; `## Translations`
> contains their non-default, servable translations.**

This cannot be assumed from the current query — it must be produced. The adapter
therefore needs a **default-language query strategy**, not only a
translation-lookup method:

- **Polylang:** pass an explicit `lang` argument scoped to the default language
  (or `lang => ''` deliberately when an all-language pass is wanted).
- **WPML:** set `suppress_filters => false` and switch/scope to the default
  language for the main query.

The exact, verified approach comes out of the reconnaissance below; do not
finalize the adapter API before then.

## Expected behavior

- **Monolingual site** (or no multilingual plugin): output **identical to
  today**.
- **Multilingual site** (Polylang or WPML, >1 language) with at least one
  translation of listed content: before the footer the file gains:

  ```
  ## Translations

  ### English
  - [Post title (EN)](https://site.tld/en/post.md)
  - [Page title (EN)](https://site.tld/en/page.md)

  ### Deutsch
  - [Titel (DE)](https://site.tld/de/titel.md)
  ```

  Grouped by language (`###` heading with the native name), entries in the same
  format as the rest of the file (`- [title](url.md)`). `## Translations` is a
  free English heading, like `Optional`/`updated:` (same non-translation
  convention already in use). Languages with no servable translations → skipped;
  no translations in any language → **the section is not emitted**.

## Components

### 1. New class `MultilingualAdapter` (`src/MultilingualAdapter.php`)

Minimal abstraction over Polylang/WPML, same philosophy as `ConflictDetector`
(local, stable signals only — plugin constants/classes/functions, no network
calls). Methods:

- `is_active(): bool`
  - Polylang: `function_exists('pll_get_post_translations')` or
    `defined('POLYLANG_VERSION')`.
  - WPML: `defined('ICL_SITEPRESS_VERSION')` or `class_exists('SitePress')`.
- `languages(): string[]` — slugs of all active languages (e.g. `['it','en','de']`).
  - Polylang: `pll_languages_list()`.
  - WPML: `array_keys( (array) apply_filters( 'wpml_active_languages', null ) )`
    — use the data this filter returns for both codes **and** native names; do
    not mix it with the legacy `icl_get_languages()`.
- `default_language(): string`
  - Polylang: `pll_default_language()`. WPML:
    `apply_filters( 'wpml_default_language', null )`.
- `default_language_query_args(): array` **(new — required for the invariant)** —
  the query fragment that scopes the main `get_posts()` to the default language
  (Polylang `lang`, WPML `suppress_filters => false` + language scoping).
- `language_name(string $lang): string` — native name for the `###` heading.
  Normalize/escape it before use as a heading.
- `translations(int $post_id, string $post_type): array<string,int>` — map
  `lang => post_id` of the post's translations (including its own language; the
  caller excludes the main entry's language).
  - Polylang: `pll_get_post_translations($post_id)`.
  - WPML: for each language,
    `apply_filters( 'wpml_object_id', $post_id, $post_type, false, $lang )`.

Guard every adapter API call. Define precedence if both plugins report active
(even though such an install is unsupported).

> Note: no URL-mode detection. `MetadataBuilder::markdown_url()`
> (`src/MetadataBuilder.php:61`) uses `get_permalink()`, which already returns
> the correct translated-post URL in **any** mode (directory, subdomain,
> domain). But correct `get_permalink()` output alone does not prove the whole
> `.md` request path resolves in the translated language context — **verify on
> staging** (see below).

### 2. Translations section in `LlmsTxtController::build()` (`src/LlmsTxtController.php:114`)

- Apply the **default-language query args** to the main loop so the main
  sections are default-language only (the invariant above). This **is** a change
  to the main query — the earlier "leave the main loop unchanged" note was wrong.
- During the loop, **collect the IDs (and post_type) of the listed posts**, to
  reuse them without extra queries. **Decide explicitly whether enriched Key
  content participates:** `key_content_items()` currently returns rendered
  strings before the main loop (`LlmsTxtController.php:140-149,320-347`); if Key
  content should have translations too, refactor it to return post objects /
  structured entries before rendering, then include those IDs.
- After the main loop and `Optional`, **before the footer** (the footer is the
  documented trailing free-form block, `LlmsTxtController.php:213-220` — do
  **not** append Translations after it), if
  `adapter->is_active() && count(languages) > 1`:
  1. For each listed post, `adapter->translations($id, $post_type)`.
  2. Group by language, **excluding the default language** (already in the main
     body) and non-servable posts (`PostSupport::is_servable()` — published, not
     password-protected). Deduplicate by ID.
  3. Emit `## Translations`, then a `### <native language name>` per language
     with at least one translation, entries via `item_line()` (reusing
     `MetadataBuilder::markdown_url()` and `escape_link_text()`).
- Empty resulting map → emit nothing (final gate).
- `enriched`/`lastmod` apply here too, reusing
  `item_line($post, $enriched, $with_lastmod)`.
- **Bound the work and output size.** The main limit is up to 500 posts per type;
  looking up every language for every post is ≈ `posts × languages`, and the
  section can multiply file size. Add a documented cap/filter
  (e.g. `sysmda_llms_txt_translations_max`) and **deterministic ordering**
  (language order from `languages()`; entries in the main-body order).

### 3. Cache and invalidation

- Single file → single cache key `sysmda_llms_txt` (unchanged).
- `MarkdownController::invalidate_cache()` (`src/MarkdownController.php:118-119`)
  already clears `LlmsTxtController::CACHE_KEY` on `save_post`.
- **Verify on staging** that a translation edit, a changed translation
  relationship, or a renamed language actually triggers `save_post` (or an
  equivalent hook). If not, either add targeted invalidation or accept/document
  that the cache TTL is the fallback. Do not assume `save_post` covers all three.

### 4. Registration / gating (`src/Plugin.php:42`)

- Inject the adapter:
  `new LlmsTxtController( $metadata, new MultilingualAdapter() )`.
- The section appears only with a multilingual plugin active, >1 language and
  ≥1 servable translation; otherwise output identical to today.

### 5. Optional admin note (light)

In `AdminSettings.php`, llms.txt section aside: when the adapter is active with
>1 language, "Multilingual detected: translations are listed in `/llms.txt`."

## Required reconnaissance before coding

On **one current WPML site and one current Polylang site** (the official staging
stack has neither), ≥2 languages, browser User-Agent (a WAF may block curl):

1. Record the post IDs and languages the existing `get_posts()` call returns for
   basic and enriched modes.
2. Test an explicit default-language query and an all-language query.
3. Test translated permalinks for the URL mode actually in use (directory /
   subdomain / mapped domain).
4. Fetch a translated `.md` URL and verify title, body, ACF values, canonical and
   front matter all come from the **same** translation.
5. Save a translation, change its language relationship, rename a language →
   verify cache-invalidation behavior.

Only then finalize the adapter API and tests.

## Files touched

- **New:** `system-markdown-alternate/src/MultilingualAdapter.php`
- `system-markdown-alternate/src/LlmsTxtController.php` — default-language query
  args on the main loop, listed-ID collection, `## Translations` before the
  footer, size cap.
- `system-markdown-alternate/src/Plugin.php` — adapter injection.
- `system-markdown-alternate/src/AdminSettings.php` — optional note.
- `AGENTS.md` / `README.md` — update the llms.txt bullet; if a public filter is
  added (translations on/off, size cap), document it in *Filters (public
  contract)*.
- `system-markdown-alternate/tests/run-tests.php` — see below.

## Tests

Most logic depends on Polylang/WPML runtime APIs and is not coverable with the
pure-logic harness. Testable without WP:

- grouping / dedup / default-language exclusion / cap, if extracted into a pure
  static helper like
  `group_translations( array $listed, array $translations_by_id, string $default, int $cap ): array`
  (map `lang => [post_id...]`). Add the tests to `tests/run-tests.php`, including
  monolingual/empty output.

The rest is validated on staging (both plugins).

## Known limitations (to document)

- The section lists translations of the **content already in the main index**
  (which depends on the enabled CPTs and `sysmda_llms_txt_max_posts`). It is not
  an independent crawl of every translation on the site.
- `sysmda_llms_txt_key_content` stays global config (not per-language).

## Suggested execution order

1. Staging reconnaissance (above) → produce the WPML/Polylang behavior matrix.
2. `MultilingualAdapter` (detection + languages/default/name/translations +
   `default_language_query_args`).
3. `group_translations()` static helper + tests.
4. Default-language scoping of the main loop + `## Translations` before the
   footer + adapter injection in `Plugin.php`.
5. Optional admin note + docs.
6. `php -l` + local tests; then staging validation on both plugins.
