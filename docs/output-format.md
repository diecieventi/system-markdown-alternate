# Markdown output format (contract)

The stable, documented shape of what the `.md` endpoint returns. It describes
the format **as the code emits it today** and states a compatibility policy so
future changes are deliberate rather than accidental. The golden conformance
tests in `system-markdown-alternate/tests/run-tests.php` pin the front matter
against these rules; a change that reorders or drops a key fails CI.

This document is the reference for the *format*. Behavioural details
(negotiation, caching, LiteSpeed) live in `AGENTS.md`; this file only links to
them.

## Compatibility policy

- **This policy applies from version `0.24.0` onward.** Versions up to and
  including `0.23.x` predate the policy and are not covered — the format did
  change across earlier `0.x` releases, so no retroactive stability is claimed.
- **Additions are backwards-compatible.** New front-matter keys are **appended**
  in a defined position; existing keys are never silently reordered or removed.
  The first exercise of this rule is the `taxonomies:` block added in `0.24.0`:
  a single nested mapping appended after `description` (never arbitrary
  top-level keys, which could collide with a stable or future key).
- A **breaking** change to the shape (reordering, removing or renaming an
  existing key; changing the escaping rules) is a deliberate, noted change, not a
  side effect.

## Document structure

A `.md` response is a single UTF-8 text document, assembled as:

```
<YAML front matter>

# <Title>

<preamble><body>
```

- The **front matter** block (`--- … ---`) comes first, followed by a blank line.
- Then the **H1**: `# ` + the post title (tags stripped, entities decoded,
  whitespace collapsed), followed by a blank line.
- Then an optional **preamble** (the `sysmda_markdown_preamble` filter — used by
  the ACF integration for a subtitle / TL;DR), immediately followed by the
  **body**.
- The whole document is right-trimmed and terminated with a single trailing
  newline.

The final document also passes through the `sysmda_markdown_output` filter, so a
site can post-process it.

## Front matter

Built by `MetadataBuilder::build_front_matter()`. Keys are emitted **in this
exact order**; conditional keys are omitted entirely when they have no value
(they are not emitted empty):

| Key | Always present? | Source |
|---|---|---|
| `title` | yes | `get_the_title()` |
| `url` | yes | `get_permalink()` (the canonical HTML permalink) |
| `markdown_url` | yes | `MetadataBuilder::markdown_url()` — the `.md` URL, or `?format=markdown` for plain permalinks / query-string URLs |
| `date_published` | yes | `get_post_time( 'c' )` (ISO 8601) |
| `date_modified` | yes | `get_post_modified_time( 'c' )` (ISO 8601) |
| `author` | only if non-empty | `get_the_author_meta( 'display_name' )` |
| `featured_image` | only if a thumbnail resolves to a URL | `wp_get_attachment_image_url( …, 'full' )` |
| `featured_image_alt` | only if `featured_image` is present **and** alt text exists | `_wp_attachment_image_alt` meta |
| `categories` | only if the post has categories | term names of `category`, as a YAML list |
| `tags` | only if the post has tags | term names of `post_tag`, as a YAML list |
| `description` | only if non-empty | see *Description* below |
| `taxonomies` | only when the feature is enabled **and** at least one eligible taxonomy has terms | see *Custom taxonomies* below |

List-valued keys (`categories`, `tags`) are emitted as a YAML block sequence:

```yaml
categories:
  - "News"
  - "Updates"
```

### Description fallback chain

`description` is resolved in order (`MetadataBuilder::description()`):

1. **Rank Math** (`rank_math_description` meta) — used as-is, **unless** it still
   contains an unresolved Rank Math placeholder (`%variable%` /
   `%variable(args)%`), in which case it is discarded. A plain `%` (e.g.
   `20% off`) is **not** treated as a placeholder.
2. **Excerpt** — the manual excerpt, when set and non-empty.
3. **Trimmed content text** — the post content with shortcodes/blocks/tags
   stripped, whitespace collapsed, truncated at a word boundary to ~200
   characters with a trailing `…`.

If all three yield an empty string, the `description` key is omitted.

### Custom taxonomies

**Opt-in, nothing selected by default** (since `0.24.0`; the on/off checkbox
became a per-taxonomy selection in `0.25.0`). Tick one or more taxonomies under
*Custom taxonomies* in **Markdown output** — or supply slugs through
`sysmda_front_matter_taxonomy_slugs` — and a nested `taxonomies:` mapping is
**appended after `description`**:

```yaml
taxonomies:
  genre:
    - "Ambient"
    - "Techno"
  topic:
    - "Privacy"
```

- **The list is the explicit selection, never inferred.** Only the taxonomies
  ticked in the panel are emitted. A taxonomy that is merely *registered* —
  including one registered `public => true` — is never published on its own, and
  a taxonomy a newly installed plugin registers appears in the panel unticked.
  `category`, `post_tag` (already emitted as `categories`/`tags`) and
  `post_format` (presentational) can never be selected.
- **Curation**: `sysmda_front_matter_taxonomy_slugs` receives the saved selection
  and may both narrow it (return an empty array to opt out for a post) **and
  extend it**. Naming a taxonomy that is registered but not public, or not
  publicly queryable, is a deliberate, supported choice — a site may legitimately
  want an editorial-internal taxonomy in its machine-readable output, and the
  panel labels such a row rather than hiding it. What the filter can never do is
  override the always-excluded set above or emit an invalid slug: both are
  stripped *after* it runs, so a filter can neither duplicate
  `categories`/`tags` nor break the YAML.
- **Kill switch**: `sysmda_front_matter_taxonomies` still gates the whole block.
  Its default is now "at least one taxonomy is selected", so returning `false`
  suppresses the block whatever the selection is. Code that supplies slugs
  purely through the filter above needs nothing extra: a non-empty list turns the
  block on by itself.
- **Conditional**: a taxonomy with no terms on the post is omitted, and if no
  selected taxonomy has terms the `taxonomies:` key is not emitted at all. A
  selected slug whose taxonomy is not registered is inert.
- **Ordering**: taxonomy slugs and term names are both sorted with PHP's
  `SORT_STRING`, i.e. **byte order, not locale collation**. This is deliberate —
  the output must not depend on the server's locale — and it means accented
  names sort after unaccented ones (`Ähnlich` after `Zeta`). The order is
  stable, not human-alphabetical.
- Term names go through the same scalar escaping as every other string.

With nothing selected, the front matter is byte-identical to `0.23.x`.

> **`0.25.0` note.** Replacing the checkbox with a selection can make the block
> *absent* where `0.24.x` emitted it — the emitted set narrows to what the site
> owner ticked. That is a settings-driven change, not a change to the format:
> no key was reordered, renamed or removed, and the compatibility policy above
> still holds. Upgrading from `0.24.x` with the checkbox on seeds the selection
> with the taxonomies that are public **and** publicly queryable, so only the
> editorial-internal ones drop out.

### Scalar escaping (YAML safety)

Every string scalar goes through `MetadataBuilder::scalar()`, which produces a
double-quoted YAML string after, in order:

1. HTML entity decoding (`&amp;` → `&`, `&quot;` → `"`, …);
2. tag stripping (`wp_strip_all_tags`);
3. whitespace collapsing (any run of whitespace → a single space) and trimming;
4. escaping backslashes (`\` → `\\`), then double quotes (`"` → `\"`).

So a title `He said "hi"` becomes `title: "He said \"hi\""`, a title `a\b`
becomes `title: "a\\b"`, and a multiline title collapses to a single line. This
guarantees the front matter is always parseable YAML regardless of the source
text.

## Body

The body is produced from the post content, not from `the_content`, so
theme/plugin-injected related-posts and CTA blocks are not reintroduced:

1. **Block pipeline** — Gutenberg blocks are parsed and cleaned
   (`BlockCleaner`): excluded blocks are dropped, synced patterns
   (`core/block`) are expanded and cleaned with the same rules (with a
   reference-cycle guard), and elements carrying excluded CSS classes are
   removed. Cleaned blocks are then rendered with `render_block()`.
2. **HTML cleanup** — excluded shortcodes are stripped; absolute URLs are
   resolved against the **post permalink** (document-relative, `../` and
   root-relative links all become absolute); syntax-highlighter markup is reduced
   to its `language-*` class so the converter can emit a fenced code block.
3. **HTML → Markdown** (`MarkdownConverter`, `league/html-to-markdown`) with:
   - ATX headings (`# Heading`);
   - `-` list markers;
   - fenced code blocks;
   - **GFM pipe tables** (since `0.26.0`) — `|` is escaped inside cells;
   - `script` / `style` / `iframe` nodes removed;
   - `strip_tags => true` — see the note below.
4. **Whitespace normalization** — trailing whitespace is trimmed and runs of blank
   lines are collapsed to one, **outside fenced code blocks only**. Inside a fence
   the content is preserved byte-for-byte: trailing spaces and blank-line runs are
   meaningful there (Markdown hard breaks, REPL transcripts, diffs).

If conversion throws, the response falls back to a plain-text extraction rather
than breaking.

### Structures with a defined conversion

Since `0.26.0` these are part of the documented output rather than incidental:

| Source | Emitted as |
|---|---|
| `<table>` (including the core table block, with or without `<thead>`) | GFM pipe table; a `<caption>` becomes a line above it |
| `<dl>` / `<dt>` / `<dd>` | `**Term**` as its own paragraph, each definition as a following paragraph |
| `<figure>` around an image | paragraph (so images and captions get blank-line separation) |
| `<figure>` around a block element (table, `<pre>`, list, …) | left as-is; the inner element converts on its own |
| `<pre>` from a syntax highlighter | fenced block, with the `language-*` class preserved as the info string and line breaks reconstructed when the highlighter relies on CSS for them |

### Default exclusions

Removed from the body unless the corresponding filter changes them:

- **Blocks** (`sysmda_markdown_excluded_block_names`): `gravityforms/form`,
  `contact-form-7/contact-form-selector`, `wpforms/form-selector`,
  `mailerlite/form`, `luckywp/toc`.
- **Shortcodes** (`sysmda_markdown_excluded_shortcodes`): `contact-form-7`,
  `gravityform`, `wpforms`, `mailerlite_form`, `lwptoc`.
- **CSS classes** (`sysmda_markdown_excluded_classes`): `no-md`, `md-exclude`,
  `exclude-from-markdown`.

### Unknown HTML tags are not a stable surface

Because the converter runs with `strip_tags => true`, any HTML tag it does not
know how to convert is **removed**: its text content may survive, but the tag
and the structural boundary it implied are lost. Do **not** rely on raw/unknown
HTML tags passing through into the Markdown — they are not part of this stable
output. Custom structures should be expressed through the block/shortcode
pipeline or a filter, not by embedding raw HTML and expecting it to round-trip.

## What gets a `.md` at all

The format above is only produced for content the endpoint considers servable
(`PostSupport::is_servable()`): an enabled post type, `publish` status, not
password-protected, not an attachment, and — since `0.26.0` — **not carrying a
non-standard post format** (aside, audio, chat, gallery, image, link, quote,
status, video; filterable through `sysmda_markdown_excluded_post_formats`).

Markdown is served at the `.md` URL and, through negotiation, at the canonical
permalink. It is **never** served at URL variants of the post — its feed, its
oEmbed view, its trackback endpoint, paged comments, or the sub-pages of a post
split with `<!--nextpage-->` — regardless of the `Accept` header.

## HTTP contract (summary)

The transport behaviour is documented in full in `AGENTS.md`; in brief, a
successful `.md` response carries:

- `Content-Type: text/markdown; charset=utf-8`
- `X-Robots-Tag: noindex, follow` (filterable; the Markdown representation is
  intentionally non-indexable)
- `Link: <permalink>; rel="canonical"` back to the HTML
- `Vary: Accept` on negotiable URLs (appended, never overwritten)
- `ETag` + `Last-Modified`, with conditional `304 Not Modified` support
  (`If-None-Match` takes priority over `If-Modified-Since`). When the
  `taxonomies:` block is emitted, `If-Modified-Since` is **ignored**: the body
  can then change without `post_modified_gmt` moving, so only the
  taxonomy-aware `ETag` can prove a cached copy is still current.
  `Last-Modified` is still sent, as information.

Two request paths reach the same Markdown:

- the **`.md` suffix** (`/my-post.md`) — always Markdown, ignores `Accept`;
- **content negotiation** on the canonical permalink — Markdown only when
  explicitly preferred (`Accept: text/markdown` with q ≥ the effective q of
  `text/html`, or `?format=markdown`). A wildcard or missing `Accept` stays HTML.
  When the client accepts neither HTML nor Markdown, an optional `406 Not
  Acceptable` is returned (`sysmda_markdown_strict_406`, default on).

**Caching distinction:** the dedicated `.md` URLs send **no** `Cache-Control`
(they are their own cache key; revalidation is via `ETag`/`304`). The
**negotiated** Markdown and `406` responses — which share their URL with the
HTML page — are always sent no-cache
(`Cache-Control: no-cache, no-store, must-revalidate, private`, plus the
LiteSpeed-specific signals), because honouring `Vary` is a per-host property and
safety must never depend on it.

## Related

- `AGENTS.md` → *Current state*, *Product decisions*, *Filters (public
  contract)* for the full behavioural spec and every filter hook.
- `system-markdown-alternate/tests/run-tests.php` → the golden conformance tests
  that enforce this front-matter contract.
