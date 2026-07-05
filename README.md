# System Markdown Alternate

> 🇮🇹 Preferisci l'italiano? Leggi il [README in italiano](README.it.md).

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
- Optional **`406 Not Acceptable`** when the client accepts neither HTML nor Markdown (`sma_markdown_strict_406` filter, on by default; real clients are never affected).
- **`rel="alternate"` link** in the `<head>` of supported content.
- **Correct HTTP headers**: `Content-Type: text/markdown`, `X-Robots-Tag` (default `noindex, follow`), `Link: rel="canonical"` back to the HTML.
- **Clean conversion**: Gutenberg blocks rendered individually (no injected related/CTA blocks), excluded blocks/shortcodes/CSS classes removed, fenced code blocks, absolute URLs.
- **`/llms.txt` endpoint** (optional): an index of your content for LLMs and agents. An optional **enriched mode** (off by default) adds a site summary, a curated "Key content" section, a description for each entry and an `Optional` section for older posts.
- **Transient cache** with proactive invalidation (post edit, plugin update, settings save).
- **Admin panel** to choose which content types are exposed and to tune cache, exclusions and headers. No type is exposed until you pick one.
- **Shortcode** `[sma_md_url]` to print the `.md` URL anywhere.
- **Optional integrations**, shown only when the related plugin is active:
  - **Advanced Custom Fields**: subtitle and TL;DR (from ACF fields) as a preamble between the H1 and the body.
  - **GenerateBlocks 2.x**: auto-registered `{{sma_md_url}}` Dynamic Tag, usable in element fields (e.g. a Button URL).

## Repository structure

```
.
├── README.md                     ← this file (GitHub)
├── README.it.md                  ← Italian version of this file
├── AGENTS.md                     ← operational guide (tool-agnostic; CLAUDE.md is a symlink)
├── AGENTS.it.md                  ← Italian translation of the guide
├── LICENSE                       ← GPL-2.0
├── .github/workflows/ci.yml      ← CI: php -l + tests on PHP 7.4/8.4
├── .wordpress-org/               ← wordpress.org listing assets (icon, banners)
├── bin/build.sh                  ← builds DIST/system-markdown-alternate.zip
├── bin/make-i18n.sh              ← regenerates the translations
├── DIST/                         ← distributable zip (versioned)
└── system-markdown-alternate/    ← the plugin
    ├── system-markdown-alternate.php
    ├── readme.txt                ← wordpress.org-format readme
    ├── uninstall.php
    ├── composer.json
    ├── languages/                ← .pot + it_IT translation
    ├── tests/run-tests.php       ← pure-logic tests (no WP/PHPUnit)
    └── src/                      ← PSR-4 classes (SystemMarkdownAlternate namespace)
```

## Build

```bash
bash bin/build.sh        # → DIST/system-markdown-alternate.zip (vendor/ bundled)
```

The zip includes the production Composer dependencies (`league/html-to-markdown`),
so it installs straight into WordPress without Composer on the server.

Build environment: PHP ≥ 7.4, Composer and `zip`.

## Requirements

- WordPress ≥ 6.0
- PHP ≥ 7.4

## License

GPL-2.0-or-later. Full text in the [`LICENSE`](LICENSE) file.
