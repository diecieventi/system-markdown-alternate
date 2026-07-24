# F3.1 — Custom taxonomies in the front matter (implementation plan)

> Concrete plan for the next runtime feature, expanding the PR 4 sketch in
> `docs/tier1-implementation-plan.md`. Written after reading the current cache
> and metadata code; the two open questions from the sketch are **resolved**
> (see *Decisions*). One PR, one minor release.

## Goal

Emit a post's **custom (non-core) taxonomies** in the YAML front matter, so the
Markdown carries the site's own semantics — the origin-native edge over a
generic HTML→Markdown conversion. Core `categories` and `tags` are already
emitted and stay exactly as they are.

## Decisions (settled — do not re-open)

1. **Opt-in, default off.** Enabling it changes the front-matter payload of
   every post on an upgraded site; that must be the user's explicit choice.
2. **Alphabetical ordering**, both for taxonomy slugs and for term names —
   required for deterministic golden tests.
3. **Appended last**, after `description`. `docs/output-format.md` states the
   contract as *append-only*; putting the block at the end is unambiguously an
   append and matches the wording already published there.

## Output schema

A single nested mapping, so a taxonomy slug can never collide with a stable
top-level key (present or future):

```yaml
---
title: "Hello World"
url: "https://example.com/hello-world/"
markdown_url: "https://example.com/hello-world.md"
date_published: "2026-07-01T08:30:00+00:00"
date_modified: "2026-07-10T12:00:00+00:00"
categories:
  - "News"
tags:
  - "alpha"
description: "…"
taxonomies:
  genre:
    - "Ambient"
    - "Techno"
  topic:
    - "Privacy"
---
```

- The whole `taxonomies:` key is **omitted** when no eligible taxonomy yields
  terms — same convention as `categories`/`tags`.
- A taxonomy with no terms on this post is omitted (no empty list).
- Term names go through the existing `MetadataBuilder::scalar()` (quoted,
  entity-decoded, escaped), like every other scalar.

## Which taxonomies are eligible

Iterate `get_object_taxonomies( $post->post_type, 'objects' )` and keep those
that are:

- **public** (`$tax->public === true`), and
- **not** in the always-excluded list: `category`, `post_tag` (already emitted
  as `categories`/`tags`) and **`post_format`** — it is registered public but is
  a presentational flag (`post-format-aside`), pure noise in this context.

Then the list passes through a curation filter (below), so a site can restrict
or extend it.

**Slug safety:** a filter can return arbitrary strings. Emit a taxonomy only if
its slug matches `/^[a-z0-9_-]+$/i` (WP's own rule); skip anything else rather
than risk producing invalid YAML keys.

## Ordering

- **Taxonomy slugs:** `sort( $slugs, SORT_STRING )`.
- **Term names:** `sort( $names, SORT_STRING )`.

`SORT_STRING` is a **byte-order** comparison, not locale collation. This is
deliberate: locale-aware collation would make the output depend on the server's
locale and break the golden tests across environments. Document it in
`docs/output-format.md` — consumers get a *stable* order, not a
human-alphabetical one for accented characters (`Ähnlich` sorts after `Zeta`).

## The hard part: cache and ETag invalidation

**This is the part to get right; it is not just "delete the cache entry".**

Today (`MarkdownController::cache_version()`):

```php
md5( $post->post_modified_gmt . '|' . SYSMDA_VERSION . '|' . $salt )
```

That value is **both** the cache-validity hash **and** the strong `ETag`.
Assigning or renaming a term does **not** touch `post_modified_gmt`, so:

1. the cached body is never dropped (`invalidate_cache()` only runs on
   `save_post`/`deleted_post`) → stale Markdown; and, worse,
2. the **ETag does not change**, so a client holding the old validator gets a
   `304 Not Modified` and keeps its outdated copy — **even with the body cache
   disabled**. Deleting the cache entry alone does not fix this.

This latent bug already affects core categories and tags; custom taxonomies just
make it visible.

### Recommended: fold a terms fingerprint into the validator

When the feature is enabled, extend `cache_version()`:

```php
md5( $post->post_modified_gmt . '|' . SYSMDA_VERSION . '|' . $salt . '|' . $terms_fingerprint );
```

where the fingerprint is a hash of the same taxonomy/term data the front matter
would emit (built by one shared, side-effect-free helper in `MetadataBuilder`,
reused by both the front matter and the fingerprint — never two code paths that
can disagree).

Why this one:

- **Self-correcting for all three cases** — assignment, rename, deletion — with
  no hooks to register and none to miss (direct DB writes, WP-CLI, imports and
  bulk edits are covered too).
- Fixes the `304` problem by construction: the validator *is* derived from the
  emitted data.
- **Cost is opt-in**: when the toggle is off, nothing extra is computed and
  `cache_version()` is byte-identical to today, so existing caches and ETags
  stay valid on upgrade.
- Cost when on: `get_the_terms()` per eligible taxonomy per request. WordPress
  caches term relationships, so this is cheap with a persistent object cache and
  ~1 query per taxonomy without one. Acceptable for `.md` traffic volumes.

### Alternatives considered (document, do not implement)

| Approach | Why not |
|---|---|
| Hook `set_object_terms` → delete that post's cache | Does not change the ETag, so `304`s still serve stale content. Also fires on **every** normal post save, so it needs an `$tt_ids` vs `$old_tt_ids` diff to avoid pointless work. |
| Hook `edited_term` → bump the global `sysmda_cache_salt` | Correct but nukes every post's cache and ETag site-wide on any term rename. Acceptable only as a coarse fallback. |
| Per-post terms version in post meta | Precise and cheap to read, but a term **rename** affects an unbounded number of posts, so it needs a query-and-update sweep; plus a new meta key to clean up on uninstall. |

If the fingerprint's per-request cost ever proves to be a problem, the meta
variant is the fallback — not the other way round.

### `/llms.txt`

Its cache key uses only `SYSMDA_VERSION|salt` and its entries do **not** include
taxonomies, so this feature does not change it. **Leave it alone** — no
invalidation work needed there.

## Settings and filters

Follow the existing option→filter bridge (the one used by
`sysmda_llms_txt_enriched`): the filter is the public contract, the panel
checkbox supplies its value.

- **Option** `sysmda_front_matter_taxonomies` — checkbox in the **Markdown
  output** section (`sysmda_markdown`), `sanitize_checkbox`, default off.
- **Filter** `sysmda_front_matter_taxonomies` (bool, default `false`) — master
  toggle.
- **Filter** `sysmda_front_matter_taxonomy_slugs` (array, default = the eligible
  list above, `$post` as second argument) — curation; returning `array()` opts
  out for that post.

Both need a docblock and an entry in the *Filters (public contract)* list in
`AGENTS.md`. The option must be added to `uninstall.php` and is covered by the
existing settings-save salt bump (so toggling it invalidates caches — which is
exactly what we want).

## Tests (`tests/run-tests.php`)

The taxonomy assembly must be a **pure, testable helper** (same style as
`HitCounter::prune` / `PostSupport::sanitize_types`), so the golden fixtures can
drive it without WordPress:

- toggle **off** → front matter byte-identical to the current golden fixtures
  (the strongest regression guard: existing assertions must stay green
  untouched);
- toggle **on**, one custom taxonomy, multiple terms → exact golden block;
- **ordering**: taxonomies and terms given out of order come out alphabetical;
- `category`/`post_tag`/`post_format` never appear under `taxonomies:`;
- non-public taxonomy skipped; invalid slug skipped;
- `get_the_terms()` returning `false` / `WP_Error` / `array()` → taxonomy
  omitted; all omitted → no `taxonomies:` key at all;
- term names needing escaping (quotes, backslashes, entities, tags);
- **fingerprint**: same terms → same hash; any change (added, removed, renamed,
  reordered input) → different hash; feature off → fingerprint not applied and
  the validator string is unchanged.

New stubs needed: `get_object_taxonomies`; `get_the_terms` already exists (added
for the F1 fixtures) and may need to return `WP_Error` — add a minimal
`WP_Error` stub class.

## Documentation to update

- **`docs/output-format.md`** — new `taxonomies:` section: schema, eligibility,
  the byte-order sorting note, and that it is **conditional and opt-in**. This
  is the first exercise of the append-only rule, so state explicitly that the
  block is appended after `description`.
- **`AGENTS.md`** — *Current state* entry, the two filters in the contract list,
  the `MetadataBuilder`/`MarkdownController` notes in the structure section, and
  a line in *Product decisions* recording the opt-in + alphabetical choices.
- **`readme.txt`** — changelog, the developer-filters list, and a short FAQ
  ("Can I include my custom taxonomies?").
- **`README.md`** — one bullet in the features list.

## Release

Runtime feature → **minor bump to `0.24.0`**: `Version:` **and**
`SYSMDA_VERSION`, `readme.txt` `Stable tag` + changelog, then `bash bin/build.sh`
and commit `DIST/`.

Pleasant alignment worth keeping: `docs/output-format.md` declares the
compatibility policy to apply **from `0.24.0`**, and this is the first appended
key — the contract starts exactly where the first addition lands.

After merge, the user runs `bash bin/release-tag.sh` from the Mac for `v0.24.0`.

## Acceptance criteria

1. Toggle off (default): front matter and `cache_version()` **byte-identical**
   to 0.23.3; all existing golden assertions pass unmodified.
2. Toggle on: a CPT with a custom taxonomy shows the nested `taxonomies:` block,
   appended after `description`, alphabetically ordered.
3. Reassigning or renaming a term changes the `ETag` and serves fresh Markdown —
   verified at WP level with a conditional request (`If-None-Match` must yield
   `200`, not `304`).
4. `php -l`, `composer phpcs` (0 errors) and the full test suite green on PHP
   7.4 and 8.4.

## Out of scope

Per-post-type Markdown templates, taxonomy **descriptions** or term URLs in the
front matter, term hierarchy (parent/child nesting), and ACF structured
extraction (F3.2) — all stay parked.
