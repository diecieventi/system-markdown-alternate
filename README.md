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
- **`Vary: Accept`** on negotiable URLs, so caches and CDNs that honour it keep the HTML and Markdown representations of the same address apart. Because some page caches key by URL only and ignore `Vary`, the negotiated Markdown (and `406`) responses are additionally sent as non-cacheable — safety never depends on `Vary` alone.
- Optional **`406 Not Acceptable`** when the client accepts neither HTML nor Markdown (`sysmda_markdown_strict_406` filter, on by default; real clients are never affected).
- **`rel="alternate"` link** in the `<head>` of supported content.
- **Correct HTTP headers**: `Content-Type: text/markdown`, `X-Robots-Tag` (default `noindex, follow`), `Link: rel="canonical"` back to the HTML.
- **Clean conversion**: Gutenberg blocks rendered individually (no injected related/CTA blocks), excluded blocks/shortcodes/CSS classes removed, fenced code blocks, absolute URLs.
- **`/llms.txt` endpoint** (optional): an index of your content for LLMs and agents. An optional **enriched mode** (off by default) adds a site summary, a curated "Key content" section, a description for each entry and an `Optional` section for older posts. Another optional toggle appends the **last modified date** (`updated: YYYY-MM-DD`) to every entry, so crawlers can spot changed content without re-fetching each URL.
- **LiteSpeed cache compatibility**: negotiated Markdown responses are marked non-cacheable for URL-keyed page caches (`X-LiteSpeed-Cache-Control: no-cache`, `DONOTCACHEPAGE`), and an opt-in setting adds `.htaccess` rules (inert outside LiteSpeed) so Markdown-negotiating requests bypass the LiteSpeed page cache on servers that ignore `Vary: Accept`.
- **Custom taxonomies in the front matter** (optional, nothing selected by default): tick the taxonomies you want and their terms are appended as a `taxonomies:` block, alphabetically ordered. Nothing is ever published automatically — a taxonomy registered by another plugin shows up in the panel unticked, and internal taxonomies (no public term archive) are labelled as such. Categories and tags keep their own keys.
- **Object cache** with proactive invalidation (post edit, plugin update, settings save): a persistent object cache is used when one is available, falling back to transients otherwise.
- **Optional `.md` hit counter** (off by default): counts how many times the Markdown endpoint is served, split bot vs human. Privacy by design: only aggregate daily totals — no IPs, no user-agent strings, no per-visitor data, no cookies, no external calls.
- **Admin panel** to choose which content types are exposed and to tune cache, exclusions and headers. No type is exposed until you pick one.
- **Shortcode** `[sysmda_md_url]` to print the `.md` URL anywhere.
- **Optional Markdown button** for human readers: a small dropdown that copies the `.md` link, opens it in a new tab, downloads the file, or copies the Markdown itself to the clipboard, ready to paste into an AI assistant. Placed with `[sysmda_md_button]`, wherever you want it. Neutral styling driven by CSS custom properties, and it degrades to plain working links without JavaScript.
- **Optional integrations**, shown only when the related plugin is active:
  - **Advanced Custom Fields**: subtitle and TL;DR (from ACF fields) as a preamble between the H1 and the body.
  - **GenerateBlocks 2.x**: auto-registered `{{sysmda_md_url}}` Dynamic Tag, usable in element fields (e.g. a Button URL).

## Usage

After activating the plugin, open **Settings → Markdown Alternate** and
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

To point *readers* at the Markdown, add the button: place `[sysmda_md_button]`
wherever you want it — in the post, a template or a widget — optionally with
`id="123"` to target a specific post. It only ever renders on content that
actually has a `.md`, and it is stripped from the Markdown itself.

Its look is controlled with CSS custom properties, so no selector fights:

```css
/* Appearance → Customize → Additional CSS, or your child theme */
.sysmda-md-button {
  --sysmda-btn-fg: inherit;                     /* text                */
  --sysmda-btn-bg: transparent;                 /* background          */
  --sysmda-btn-hover-fg: inherit;               /* text on hover/focus */
  --sysmda-btn-hover-bg: transparent;           /* bg on hover/focus   */
  --sysmda-btn-border: 1px solid currentColor;  /* border              */
  --sysmda-btn-radius: 0.375em;                 /* corner radius       */
  --sysmda-btn-padding: 0.45em 0.85em;          /* padding             */
  --sysmda-btn-font-size: 0.9em;                /* font size           */

  /* Dropdown — omit any of these to reuse the button's value */
  --sysmda-btn-menu-fg: inherit;                /* entry text          */
  --sysmda-btn-menu-bg: #fff;                   /* dropdown backdrop   */
  --sysmda-btn-menu-hover-fg: inherit;          /* entry on hover      */
  --sysmda-btn-menu-hover-bg: transparent;      /* entry bg on hover   */
}
```

Padding and font size are shared with the dropdown entries, so one value moves
the button and its menu together, and focus reuses the hover colours so there is
no third state to style. The four `menu` properties fall back to the button's
own, so a menu only needs its own values when you want it to differ.

The plugin **never declares these properties itself**; it only reads them, with
the defaults baked in as `var()` fallbacks. So your rule always wins, from the
Customizer or a child theme alike, regardless of which stylesheet the browser
loads last. The same list is shown as a copy-ready snippet under **Settings →
Markdown Alternate → Markdown button**.

Not everything gets a Markdown version: drafts, private and password-protected
content, media attachments, and posts with a **non-standard post format** (aside,
status, quote, link, gallery, image, video, audio, chat) are excluded — the last
of these is filterable through `sysmda_markdown_excluded_post_formats`. Markdown
is also never served at URL *variants* of a post (its feed, oEmbed view,
trackback endpoint, paged comments or `<!--nextpage-->` sub-pages), only at the
canonical permalink and its `.md` URL.

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

// Serve every post format again, including asides and statuses.
add_filter( 'sysmda_markdown_excluded_post_formats', function () {
    return array();
} );

// Serve the body without the YAML front matter (document starts at the H1).
add_filter( 'sysmda_front_matter_enabled', '__return_false' );

// Rebuild a post's Markdown cache in the background after each save, so the
// first reader after an edit does not pay for the conversion.
add_filter( 'sysmda_markdown_prewarm', '__return_true' );

// Style the Markdown button yourself: skip the plugin stylesheet entirely.
add_filter( 'sysmda_md_button_enqueue_style', '__return_false' );

// Or keep it and restyle through the custom properties, no stylesheet needed:
//   .sysmda-md-button { --sysmda-btn-bg: #111; --sysmda-btn-fg: #fff; }

// Offer only the two entries that need no clipboard access.
add_filter( 'sysmda_md_button_items', function () {
    return array( 'view', 'download' );
} );
```

The full public contract (every filter with its default value) is documented in
the ["Filters (public contract)"](AGENTS.md#filters-public-contract) section of
`AGENTS.md`.

## Output format

The shape of the Markdown response — the front-matter keys, their order, the
escaping rules and the body conversion — is documented as a stable, versioned
contract in [`docs/output-format.md`](docs/output-format.md), backed by golden
conformance tests in `system-markdown-alternate/tests/run-tests.php`.

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

## Requirements

- WordPress ≥ 6.1
- PHP ≥ 7.4

## License

GPL-2.0-or-later. Full text in the [`LICENSE`](LICENSE) file.
