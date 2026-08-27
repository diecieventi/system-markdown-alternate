# System Markdown Alternate

A WordPress plugin that publishes a clean **Markdown representation** of your
content — generated from the post's own blocks and shortcodes, not from the
rendered page. Readable by LLMs, AI agents and technical tools.

```
https://example.com/my-post/      → HTML
https://example.com/my-post.md    → Markdown (front matter + content)
```

It is not a generic SEO plugin: it is a technical feature designed to make
content consumable by tools that prefer Markdown over rendered HTML.

**[Try it live](https://wordpress.org/plugins/system-markdown-alternate/)** —
the wordpress.org listing's Preview button boots the plugin, active, in
WordPress Playground (a full WordPress running in your browser via WASM): no
install, nothing to set up.

## How it works

The Markdown is built from the post content itself, through a pipeline the
plugin controls end to end:

```text
post content (blocks, shortcodes) + configured custom fields
      ↓
cleaning — excluded blocks, shortcodes and CSS classes removed
      ↓
render_block() on the cleaned blocks — not the_content
      ↓
HTML normalization — tables, code fences, absolute URLs
      ↓
Markdown conversion
```

Two consequences worth knowing:

- **`the_content` is skipped by design.** That filter chain is where themes and
  plugins inject related-posts blocks, CTAs and share widgets on the frontend.
  None of it reaches the Markdown.
- **No HTTP request back to the site is involved.** The conversion runs
  in-process, so the output does not depend on the theme's markup, on CSS
  selectors, or on the site being able to reach itself.

## Features

### Rendering and conversion

- Gutenberg blocks rendered individually; synced patterns expanded and cleaned
  with them.
- Shortcodes expanded on block and classic content alike — never inside a code
  sample, which is shown verbatim rather than executed or stripped.
- Excluded blocks, shortcodes and CSS classes, with defaults for contact forms
  and tables of contents that the panel adds to.
- Code fences sized to their content, GFM tables, definition lists, and URLs
  made absolute against the post's own permalink.
- Embeds always leave a usable address: the element becomes a link to what it
  embeds, or just its player frame does when the embed shows text of its own.
- Links that render nothing — the invisible overlay anchor a clickable card is
  built from — take the accessible name their markup declares, instead of
  converting to a link with no text.
- **Extra custom fields**: a list of post meta keys whose values are appended to
  the body — one setting for ACF, JetEngine, Meta Box and the native Custom
  Fields box alike, since all of them store post meta. Empty by default, never
  auto-detected, and a post without the field keeps its document and its cache
  validator byte-identical.
- **Advanced Custom Fields** (when active): a subtitle and a TL;DR as a
  preamble between the H1 and the body.

### HTTP and representations

- **`.md` endpoint** for every published, public, non-protected post of the
  enabled types.
- **Content negotiation** (RFC 9110): the same Markdown for
  `Accept: text/markdown` or `?format=markdown`, with q-values honoured — a
  wildcard or a browser's `text/html` still gets HTML.
- **`Vary: Accept`** on negotiable URLs, plus non-cacheable handling for page
  caches that key by URL only: safety never depends on `Vary` alone.
- Optional **`406 Not Acceptable`** when a client accepts neither
  representation (`sysmda_markdown_strict_406`, on by default).
- `Content-Type: text/markdown`, `X-Robots-Tag: noindex, follow`, and a
  `Link: rel="canonical"` back to the HTML.
- Weak `ETag` + `Last-Modified` with `304` support on the anonymous
  representation.

### Discovery

- `<link rel="alternate" type="text/markdown">` in the document head, and the
  same relation as a typed HTTP `Link` header (also on `HEAD`).
- **`/llms.txt`** (optional): a content index for LLMs and agents, with an
  optional enriched mode (site summary, curated "Key content", per-entry
  descriptions) and optional last-modified dates. Both off by default.
- **Shortcodes** `[sysmda_md_url]`, `[sysmda_md_download]` and
  `[sysmda_md_actions]` — the last an opt-in split button that copies, opens or
  downloads the document, whose assets load only where it renders. All accept
  `id="123"`.
- **GenerateBlocks 2.x** (when active): a `{{sysmda_md_url}}` Dynamic Tag.

### Control and safety

- No post type is exposed until you enable it, and no taxonomy reaches the
  front matter until you tick it — nothing is ever inferred.
- **Bricks pages get a real `.md`**, rendered through Bricks' own
  `\Bricks\Frontend::render_data()` rather than a re-implementation, with
  Bricks' own image lazy-loading disabled for the render (otherwise every
  image converts to a placeholder). A page switched back to *Render with
  WordPress* is served from `post_content` as usual. `md-exclude` already
  works as a Bricks element's CSS class; `sysmda_markdown_excluded_builder_elements`
  additionally strips Bricks chrome (forms, nav menus, share bars, tables of
  contents, breadcrumbs) by default, additive like the other exclusion lists.
- **Posts rendered by any other page builder have no Markdown representation**
  and return 404 rather than an empty or misleading document: Elementor, Divi,
  WPBakery, Oxygen, Beaver Builder and Breakdance keep their content outside
  `post_content`, or keep it there as their own layout shortcodes, and neither
  converts to anything worth publishing. The rule is per post and reads the
  builder's render mode, so enabling a builder does not touch posts you did
  not build with it, and it never inspects `post_content` — an article quoting
  `[et_pb_section]` is not a Divi page. Escape hatch:
  `sysmda_markdown_unsupported_builders`. The settings panel shows, per content
  type, what its published posts are actually built with.
- **WooCommerce's cart, checkout and my-account pages have no Markdown
  representation**: they are ordinary published pages, but their body is
  WooCommerce's own runtime placeholder text, not editorial content. The shop
  page is unaffected. Escape hatch: `sysmda_markdown_excluded_woocommerce_pages`.
- Logged-in requests are rebuilt in the visitor's own context: they never touch
  the shared cache and are never answered `304`.
- Object cache when a persistent one is available (transients otherwise),
  invalidated on post edits, plugin updates and settings changes.
- **LiteSpeed compatibility**: no-cache signals on the negotiated responses,
  plus opt-in `.htaccess` rules for servers that ignore `Vary: Accept`.
- Optional **hit counter** (off by default): aggregate daily bot/human totals,
  with a further breakdown naming a few known AI crawlers among the bot
  total — no IPs, no user-agent strings, no cookies, no external calls.

## Usage

After activating the plugin, open **Settings → Markdown Alternate** and enable
at least one content type (nothing is exposed until you do). From then on, the
Markdown version of any published post of that type can be reached in three
ways:

1. **`.md` suffix** — append `.md` to the permalink:
   `https://example.com/my-post.md`. Always returns Markdown, whatever the
   `Accept` header says.
2. **Content negotiation** — request the normal permalink with an
   `Accept: text/markdown` header. Markdown is served only when preferred over
   HTML (q-values are honoured).
3. **Query parameter** — append `?format=markdown`, for clients that cannot
   send custom headers (and for plain permalinks, where `.md` does not apply).

The optional content index for LLMs and agents lives at
`https://example.com/llms.txt` (enable it from the same settings page).

Full user documentation — every setting, endpoint, shortcode and integration —
is published at
**[diecieventi.github.io/system-markdown-alternate](https://diecieventi.github.io/system-markdown-alternate/)**.

## Extending via filters

Everything the settings page controls — and more — is exposed as WordPress
filters, so the plugin can be customized from a theme or site plugin:

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

// Serve the body without the YAML front matter (document starts at the H1).
add_filter( 'sysmda_front_matter_enabled', '__return_false' );
```

The complete developer extension API — every filter, its default value, what
changing it does and **how much compatibility it promises** — is documented in
**[`docs/filters.md`](docs/filters.md)**. Hooks are labelled *Stable* (anchored
to a setting or to a domain concept; changes go through deprecation) or
*Advanced* (anchored to a stage of the current implementation, and free to
evolve while the plugin is pre-1.0).

## Output format

The shape of the Markdown response — the front-matter keys, their order, the
escaping rules and the body conversion — is documented as a stable, versioned
contract in [`docs/output-format.md`](docs/output-format.md), backed by golden
conformance tests in `system-markdown-alternate/tests/run-tests.php`. It is a
separate and stronger contract than the PHP hooks above: the format is read by
crawlers that cannot pin a version, the hooks by code that can.

## Requirements

- WordPress ≥ 6.1
- PHP ≥ 7.4

## Repository structure

```
.
├── README.md                     ← this file (GitHub)
├── AGENTS.md                     ← operational guide (tool-agnostic; CLAUDE.md is a symlink)
├── CHANGELOG.md                  ← full release history (readme.txt links here)
├── docs/                         ← contracts, active plans and operational notes
│   ├── filters.md                ← developer extension API (with stability levels)
│   ├── output-format.md          ← the .md output format (public contract)
│   ├── staging-acceptance.md     ← real-WordPress release checklist
│   ├── cache-infrastructure-notes.md
│   ├── exclusion-scanner-plan.md
│   ├── llms-txt-multilingual-plan.md
│   └── page-builders-plan.md
├── documentation/                ← user documentation site (Astro Starlight, not shipped)
├── LICENSE                       ← GPL-2.0
├── .github/workflows/ci.yml      ← CI: php -l + tests on PHP 7.4/8.4
├── .wordpress-org/               ← wordpress.org listing assets (icon, banners, Playground preview blueprint)
├── bin/build.sh                  ← builds DIST/system-markdown-alternate.zip
├── DIST/                         ← build output of bin/build.sh (not versioned)
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

`DIST/` is a build output and is not committed: every published release already
carries the zip as an asset on its [GitHub
Release](https://github.com/diecieventi/system-markdown-alternate/releases),
built from the tag. Run the script when you want a package of the current
working tree — to install on a test site, or to see exactly what ships.

Build environment: PHP ≥ 7.4, Composer, `rsync` and `zip`.

## Coding standards

The plugin follows the WordPress Coding Standards, checked with PHP_CodeSniffer
(installed as a Composer dev dependency, so it is never shipped in the zip):

```bash
composer install --working-dir=system-markdown-alternate   # includes the dev tooling
cd system-markdown-alternate
composer phpcs     # report violations
composer phpcbf    # auto-fix the mechanical ones
```

The ruleset lives in [`system-markdown-alternate/phpcs.xml.dist`](system-markdown-alternate/phpcs.xml.dist)
— `WordPress-Core` + `WordPress-Extra` plus `PHPCompatibilityWP` — where every
deliberately disabled sniff carries its rationale inline. CI runs PHPCS on every
pull request and fails on errors; warnings are reported as annotations.

## License

GPL-2.0-or-later. Full text in the [`LICENSE`](LICENSE) file.
