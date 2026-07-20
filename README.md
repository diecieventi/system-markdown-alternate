# System Markdown Alternate

A WordPress plugin that exposes a **clean Markdown version** of your content —
readable by LLMs, AI agents and technical scraping tools. Every published post
of the enabled types becomes available by appending `.md` to its permalink.

```
https://example.com/my-post/      → HTML
https://example.com/my-post.md    → Markdown (front matter + content)
```

It is not a generic SEO plugin: it is a technical feature designed to make
content consumable by tools that prefer Markdown over rendered HTML.

## Features

- **`.md` endpoint** for every published, public, non-protected post of the enabled types.
- **Content negotiation** (RFC 9110): the same Markdown is served for `Accept: text/markdown` or `?format=markdown`. The `Accept` header is parsed with q-values: Markdown is served only when explicitly preferred, so a client that prefers HTML (higher q) or sends a wildcard (`*/*`) still gets HTML.
- **`Vary: Accept`** on negotiable URLs: caches and CDNs never mix the HTML and Markdown representations of the same address.
- Optional **`406 Not Acceptable`** when the client accepts neither HTML nor Markdown (`sysmda_markdown_strict_406` filter, on by default; real clients are never affected).
- **`rel="alternate"` link** in the `<head>` of supported content.
- **Correct HTTP headers**: `Content-Type: text/markdown`, `X-Robots-Tag` (default `noindex, follow`), `Link: rel="canonical"` back to the HTML.
- **Clean conversion**: Gutenberg blocks rendered individually (no injected related/CTA blocks), excluded blocks/shortcodes/CSS classes removed, fenced code blocks, absolute URLs.
- **`/llms.txt` endpoint** (optional): an index of your content for LLMs and agents. An optional **enriched mode** (off by default) adds a site summary, a curated "Key content" section, a description for each entry and an `Optional` section for older posts. Another optional toggle appends the **last modified date** (`updated: YYYY-MM-DD`) to every entry, so crawlers can spot changed content without re-fetching each URL.
- **LiteSpeed cache compatibility**: negotiated Markdown responses are marked non-cacheable for URL-keyed page caches (`X-LiteSpeed-Cache-Control: no-cache`, `DONOTCACHEPAGE`), and an opt-in setting adds `.htaccess` rules (inert outside LiteSpeed) so Markdown-negotiating requests bypass the LiteSpeed page cache on servers that ignore `Vary: Accept`.
- **Transient cache** with proactive invalidation (post edit, plugin update, settings save).
- **Optional `.md` hit counter** (off by default): counts how many times the Markdown endpoint is served, split bot vs human. Privacy by design: only aggregate daily totals — no IPs, no user-agent strings, no per-visitor data, no cookies, no external calls.
- **Admin panel** to choose which content types are exposed and to tune cache, exclusions and headers. No type is exposed until you pick one.
- **Shortcode** `[sysmda_md_url]` to print the `.md` URL anywhere.
- **Optional integrations**, shown only when the related plugin is active:
  - **Advanced Custom Fields**: subtitle and TL;DR (from ACF fields) as a preamble between the H1 and the body.
  - **GenerateBlocks 2.x**: auto-registered `{{sysmda_md_url}}` Dynamic Tag, usable in element fields (e.g. a Button URL).

## Usage

After activating the plugin, open **Settings → System Markdown Alternate** and
enable at least one content type (nothing is exposed until you do). From then on,
the Markdown version of any published post of that type can be reached in three
ways:

1. **`.md` suffix** — append `.md` to the permalink:
   `https://example.com/my-post.md`. This always returns Markdown, regardless of
   the `Accept` header.
2. **Content negotiation** — request the normal permalink with an
   `Accept: text/markdown` header. Markdown is served only when it is preferred
   over HTML (q-values are honoured); a browser sending `text/html` or a wildcard
   still gets HTML.
3. **Query parameter** — append `?format=markdown` to the permalink, for clients
   that cannot send custom headers (and for posts with plain permalinks, where
   the `.md` suffix does not apply).

The optional content index for LLMs and agents lives at
`https://example.com/llms.txt` (enable it from the same settings page).

## Extending via filters

Everything the settings page controls — and more — is exposed as WordPress
filters, so the plugin can be customized from a theme or site plugin. A couple
of examples:

```php
// Append a custom footer to every Markdown output.
add_filter( 'sysmda_markdown_output', function ( $markdown, $post ) {
    return $markdown . "\n---\nConverted from " . get_permalink( $post ) . "\n";
}, 10, 2 );

// Exclude an extra CSS class from the conversion.
add_filter( 'sysmda_markdown_excluded_classes', function ( $classes ) {
    $classes[] = 'my-private-block';
    return $classes;
} );
```

The full public contract (every filter with its default value) is documented in
the ["Filters (public contract)"](AGENTS.md#filters-public-contract) section of
`AGENTS.md`.

## Repository structure

```
.
├── README.md                     ← this file (GitHub)
├── AGENTS.md                     ← operational guide (tool-agnostic; CLAUDE.md is a symlink)
├── LICENSE                       ← GPL-2.0
├── .github/workflows/ci.yml      ← CI: php -l + tests on PHP 7.4/8.4
├── .wordpress-org/               ← wordpress.org listing assets (icon, banners)
├── bin/build.sh                  ← builds DIST/system-markdown-alternate.zip
├── DIST/                         ← distributable zip (versioned)
└── system-markdown-alternate/    ← the plugin
    ├── system-markdown-alternate.php
    ├── readme.txt                ← wordpress.org-format readme
    ├── uninstall.php
    ├── composer.json
    ├── tests/run-tests.php       ← pure-logic tests (no WP/PHPUnit)
    └── src/                      ← PSR-4 classes (Diecieventi\SystemMarkdownAlternate namespace)
```

## Build

```bash
bash bin/build.sh        # → DIST/system-markdown-alternate.zip (vendor/ bundled)
```

The zip includes the production Composer dependencies (`league/html-to-markdown`),
so it installs straight into WordPress without Composer on the server.

Build environment: PHP ≥ 7.4, Composer and `zip`.

## Requirements

- WordPress ≥ 6.1
- PHP ≥ 7.4

## License

GPL-2.0-or-later. Full text in the [`LICENSE`](LICENSE) file.
