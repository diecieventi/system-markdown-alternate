# Changelog

Full release history of **System Markdown Alternate**, newest first.

The plugin's `readme.txt` carries only the three most recent releases: the
wordpress.org readme parser truncates a `Changelog` section longer than 5000
characters, so the complete history lives here and `readme.txt` links to it.

Versions from `0.17.1` onward also have an annotated `vX.Y.Z` git tag, whose
notes are generated from the entries in this file by `bin/release-tag.sh`.

## 0.32.0

* **Removed the Markdown button's automatic placement**, one version after it
  shipped. A button is a presentational decision: it has to sit where the design
  wants it, which the plugin cannot know, and stamping it onto every enabled post
  was the wrong default shape. The `the_content` route also needed a guard for
  each way WordPress re-runs that filter — feeds, oEmbed views, trackbacks,
  secondary loops, a once-per-post flag for themes that render the content twice,
  and `wp_trim_excerpt()`, which builds an automatic excerpt through
  `the_content` from inside the main loop of a singular view. That is a lot of
  surface for something `[sysmda_md_button]` already does correctly, once,
  exactly where it is written. The "Automatic placement" setting, the
  `sysmda_md_button_position` filter and `maybe_auto_insert()` are all gone;
  the option remains in `uninstall.php` as a legacy key so it is cleaned up.
* **Fixed: the button could not be restyled at all.** Setting
  `--sysmda-btn-bg` in the Customizer did nothing. The plugin declared its
  defaults on `.sysmda-md-button`, the override used the same selector, and with
  identical specificity source order decides — while the stylesheet is printed in
  the *footer* whenever the button comes from a template or a page-builder
  element, landing after the Customizer's CSS and quietly winning. The stylesheet
  now **declares nothing**: every value is a `var()` fallback, so your rule is the
  only declaration and applies from the Customizer or a child theme alike,
  whatever the load order.
* **The stylesheet is down to what the component cannot work without**, and the
  whole styling surface is seven custom properties: text colour, background,
  border, corner radius, padding, font size, and the dropdown backdrop (which has
  to be opaque). The menu entries reuse the same values, so setting the padding or
  the font size once moves the button and its menu together. Gone with the rest:
  the hover tint, the shadow, the transition, and the `Canvas`/`CanvasText`
  system colours that were meant to follow the OS dark mode. The settings page
  lists all seven as a copy-and-paste snippet and names where to paste it.
* **Fixed: `sysmda_md_button_items` ignored site code once the settings were
  saved.** The panel selection was bridged in at priority 20, so it overwrote
  anything a theme or site plugin returned at the ordinary priority 10, and the
  documented "may reorder and narrow" contract silently stopped holding — the
  example in `README.md` included. The selection is now fed in at priority 5 as
  the filter's *default*, exactly like `sysmda_front_matter_taxonomy_slugs`.

## 0.31.0

* **New: an optional Markdown button for readers.** Until now the `.md` version
  was discoverable only by machines — the `rel="alternate"` link, `/llms.txt`,
  content negotiation. A human reading the article had no way to reach it. The
  new `[sysmda_md_button]` shortcode adds a small **Markdown** dropdown offering
  four actions: copy the `.md` link, view it in a new tab, download it as a
  `.md` file, and copy the Markdown *itself* to the clipboard, ready to paste
  into an AI assistant. A new **Markdown button** panel tab can also place it
  before and/or after the content of every enabled post automatically
  (**disabled by default** — nothing appears until you choose to place it).
* The button is a **disclosure**, not a `role="menu"` widget: two of its entries
  are ordinary links, and the menu role would have captured the arrow keys and
  taken away "open in a new tab" and "copy link address". It is keyboard
  operable (arrows, Home/End, Escape), announces the result of a copy through a
  polite live region, and closes on an outside click.
* **It works without JavaScript**, and never shows a control that would do
  nothing: the toggle and the two clipboard entries are rendered hidden and
  revealed by the script only once the browser has been found to support the
  action, so a reader without JavaScript sees a plain list of the two entries
  that are genuinely links. On plain HTTP, where the Clipboard API does not
  exist, copying falls back to the legacy path.
* **The button never appears in the Markdown.** `[sysmda_md_button]` is stripped
  from the source unconditionally, *after* the exclusion filter runs — putting
  it in the default "Excluded shortcodes" list would not have been enough, since
  a saved list replaces those defaults, and any site that had customized that
  field would have published the button's HTML inside its own `.md`.
* Styling is neutral and inherits from the theme, driven by CSS custom
  properties (`--sysmda-btn-bg`, `--sysmda-btn-fg`, `--sysmda-btn-radius`, …) so
  it can be restyled without fighting selectors. The dropdown surface follows the
  document's `color-scheme`, and the layout uses logical properties, so dark
  themes and RTL need no extra stylesheet. `sysmda_md_button_styles` skips the
  stylesheet entirely. Assets load only on pages that actually render a button.
* New filters: `sysmda_md_button_position`, `sysmda_md_button_items`,
  `sysmda_md_button_label`, `sysmda_md_button_enqueue_style` and
  `sysmda_md_button_html`.

## 0.30.2
* Fixed: the WordPress.org readme parser truncated the changelog, because a
  `Changelog` section may not exceed 5000 characters and this one had grown to
  roughly 34000. `readme.txt` now lists the three most recent releases and links
  to the full history on GitHub. No code, output or behaviour change.

## 0.30.1

* The plugin's **Author URI** now points to `https://diecieventi.com/`, the site
  of the author named in the header, instead of a different site run by the same
  author. Metadata only — no code, output or behaviour changes.

## 0.30.0

* **The LiteSpeed `.htaccess` rules no longer let an odd `Accept` header bypass
  your page cache.** The optional block contained a second rule that sent any
  request whose `Accept` allowed neither HTML nor a wildcard straight to PHP, so
  the plugin could answer `406`. Because the rule matched every URL on the site,
  a client sending an arbitrary media type — `Accept: application/json`, or a
  different random one on each request — skipped the page cache site-wide and
  paid a full WordPress boot every time. What it bought was a `406` for clients
  that do not exist in practice: browsers, crawlers and agents always send
  `text/html` or a wildcard. The rule is gone; Markdown negotiation still
  bypasses the cache exactly as before, and the `406` itself is unchanged on
  every request that reaches PHP. If you have the option enabled, the block is
  rewritten automatically the next time you open the settings page.
* **New filter `sysmda_front_matter_enabled`** to serve the Markdown without its
  YAML front matter, for setups whose consumers expect the document to start at
  the `# Title` heading. On by default: the block carries the canonical URL,
  dates and author, which nothing in the body can replace.
* **New filter `sysmda_markdown_prewarm`** to rebuild a post's Markdown in the
  background after each save, so the first reader after an edit is served from
  the cache instead of waiting for the conversion. Off by default, deliberately:
  the rebuild runs under WP-Cron, where a dynamic block or shortcode that
  inspects the current request can render differently than it would on a real
  page view.
* Documentation: new FAQ entries on running behind a CDN (Cloudflare, Fastly,
  Varnish) and on testing that no cache is mixing the HTML and Markdown
  representations of a URL.

## 0.29.0

* **Markdown URLs can be cached again — but never reused without checking
  first.** The plugin used to send no caching policy of its own on `.md` URLs,
  on the assumption that saying nothing meant "always ask me". It does not, and
  in practice the responses were not silent at all: WordPress had already
  marked them `no-store` (its standard headers for this kind of request), which
  forbids browsers and caches from keeping any copy. The result was the worst of
  both worlds — nobody could reuse a `.md`, nobody ever revalidated one, and
  every single request rebuilt the whole document from scratch, so the `ETag`
  work of the last releases could never pay off. `.md` URLs and `/llms.txt` now
  send `Cache-Control: public, max-age=0, must-revalidate`: any cache may store
  the file, none may serve it without asking the site whether it changed. A
  Markdown file can no longer outlive the article behind it, which matters
  because page-cache plugins clear the article's URL on save and have no idea
  the `.md` version exists.
* **`/llms.txt` now answers "not modified".** The index is the largest file the
  plugin produces and the one crawlers re-fetch most often; it now carries an
  `ETag` computed from its exact contents and replies `304` when the client
  already has that version. A rebuilt index that comes out identical still
  counts as unchanged.
* **New filter `sysmda_cache_control`** to override that policy — including an
  `s-maxage` for infrastructure with its own purge mechanism, or an empty string
  to send no header at all.

## 0.28.0

* **The `ETag` of a Markdown response is now a weak validator (`W/"…"`).** It is
  built from the post's modification date, the plugin version, your settings and
  the dependency fingerprints — never from the bytes of the response, which
  would mean building the page before deciding whether to send it and would
  defeat the point of a `304`. A strong `ETag` claims the response is identical
  byte for byte, and with dynamic blocks, shortcodes or site filters in the mix
  that is a promise the plugin cannot make. Nothing changes for clients:
  `If-None-Match` has always been compared this way, and a validator issued by
  an earlier version still revalidates.
* **Revalidation now survives Apache's compression.** `mod_deflate` and
  `mod_brotli` rewrite the `ETag` of a compressed response by appending `-gzip`
  or `-br` inside the quotes — their default — and the browser sends that back.
  It no longer matched, so a compressed `.md` on a stock Apache re-sent the
  whole body on every visit instead of answering `304`. The suffix is now
  ignored, as is the weak flag on either side.
* **Renaming an author, changing the permalink structure or moving the site
  address now refresh the Markdown.** The `author:` line comes from the user's
  display name, and `url:` / `markdown_url:` (plus every absolute link in the
  body) from the permalink settings — none of which touch a post, so nothing
  invalidated the cached body or the `ETag` and a client that already had the
  page was told "not modified" indefinitely. All three now rebuild the cache
  once. Deleting a user and reassigning their posts does the same.
  A profile save that does not change the display name changes nothing, so
  ordinary user activity (customer accounts and the like) costs nothing.

Upgrade note: every `.md` gets a new `ETag` once, in the `W/"…"` form, so the
first conditional request after the update returns the full body instead of a
`304`.

## 0.27.0

* **The cache validator (and the `ETag`) now covers what the Markdown reads
  outside the post itself.** Editing a synced pattern, swapping the featured
  image or its alt text, rewriting the Rank Math description or changing an ACF
  field all change the published Markdown while the post's modification date
  stays put — so a client that already had the page kept being told "not
  modified" and went on showing the old content, whether or not the body cache
  was enabled. Those inputs are now part of the validator.
  A synced pattern that embeds another one is followed all the way down, and
  the same rule now applies to the `If-Modified-Since` check, not only to the
  `ETag`: a client that sends just a date is no longer told "not modified" when
  one of those inputs has changed.
* **New filter `sysmda_markdown_cache_dependencies`** for output the plugin
  cannot fingerprint on its own (dynamic blocks, shortcodes, site filters that
  read options or remote data): return any value that changes the Markdown and
  it becomes part of the validator.
* **A malformed `Accept` weight no longer counts as a preference.** A media
  range with a non-numeric quality (`text/markdown;q=banana`, or an empty `q=`)
  was read as the strongest possible preference and could serve Markdown to a
  client that never asked for it; the range is now ignored. Numeric weights are
  unchanged, out-of-range values included. A request whose `Accept` becomes
  entirely unparseable is treated like one with no `Accept` header — it gets the
  HTML page, never a `406`.
* **Password-protected posts never get a Markdown version, not even for a
  reader who has entered the password.** The check asked WordPress "does this
  visitor still have to supply the password", which stops being true once the
  reader has typed it in — so the `.md`, the discovery link, the shortcode and
  the dynamic tag all unlocked with the page. Protected content is now excluded
  outright, which is also what `/llms.txt` already did.
* **`/llms.txt` picks up a change to the site name or tagline.** They are the
  heading and the subtitle of the file, but they are edited in Settings →
  General, which does not touch any post, so the index kept showing the old ones
  for up to a day.
* Test suite: the runner buffers its own output, so a failing assertion no
  longer suppresses the `304` status recorded by a later conditional-request
  test and produces a second, phantom failure.

Upgrade note: posts with a featured image, a description or a synced pattern get
a new `ETag` once, so the first conditional request after the update returns the
full body instead of a `304`. Posts with none of them keep the validator they
already had, so nothing else is invalidated.

## 0.26.3

* **The `.htaccess` rollback added in 0.26.2 now also covers a file that was
  empty.** A write that fell short left half a rule behind even when the file
  had just been created, and the rollback skipped that case on the assumption
  that "empty" was already the previous state. It is not, once something has
  been written: the file is now truncated back to zero bytes.

## 0.26.2

* **A failed `.htaccess` write no longer leaves the file empty or half-written.**
  The in-place rewrite added in 0.26.1 empties the file before writing the new
  rules, so if the write failed — a full disk, an I/O error — the site was left
  with an empty or truncated `.htaccess`: broken permalinks, or a 500 from a rule
  cut in two. The previous contents are now put back before the lock is released.
* **Short writes are detected.** `fwrite()` reports a partial write as a byte
  count rather than as a failure, so a half-written file was previously reported
  as a success. The byte count is now compared with what was meant to be written.

## 0.26.1

* **`.htaccess` is now read and rewritten under a single exclusive lock.** The
  atomic replacement added in 0.26.0 prevented a half-written file from ever
  being observed, but it did not serialize the read-modify-write around it — and
  `.htaccess` is a shared file: WordPress core rewrites it when you save the
  permalink settings, and cache and security plugins write to it too. A writer
  landing between the read and the write had its block silently reverted. The
  lock now spans the whole sequence and the file is rewritten in place, matching
  what WordPress core itself does, so concurrent writers block each other
  properly instead of overwriting each other's rules.

## 0.26.0

Correctness and robustness pass over the whole conversion pipeline, from a full
code review of the shipped code. Two fixes change the output on real content.

* **Fixed: an unbalanced `</div>` in the content silently truncated the Markdown
  body.** The fragment was parsed inside a `<div>` wrapper and only that
  wrapper's children were serialized, so a stray closing tag — custom HTML
  blocks, migrated content, legacy column shortcodes — ended the wrapper early
  and everything after it was dropped. The HTML page rendered fine, which made
  the loss invisible. The wrapper is now an element the content cannot close.
* **Fixed: tables came out as unreadable glued text** ("NamePriceCoffee2"). The
  library's table converter is now registered, so tables become GFM pipe tables
  with `|` escaped inside cells. Definition lists (`<dl>`) are flattened to a
  bold term plus paragraphs instead of being concatenated.
* **Fixed: whitespace normalization rewrote the inside of fenced code blocks.**
  Trailing spaces and runs of blank lines are now preserved verbatim inside
  fences (they are meaningful in code samples, transcripts and diffs) and still
  normalized everywhere else.
* **Fixed: code from syntax highlighters that wrap each line in its own element**
  (Shiki, and therefore Code Block Pro) collapsed onto a single line. Line
  breaks are reconstructed when the markup carries none of its own.
* **Fixed: absolute URLs with a scheme other than `http(s)`** — `ftp:`, `sms:`,
  `whatsapp:`, `callto:`, `webcal:` — were mangled into bogus site-relative
  URLs. Any RFC 3986 scheme is now recognised as absolute.
* **Fixed: a query-only link** (`?page=2`) resolved against the base directory
  instead of the base path on permalink structures without a trailing slash.
* **Markdown is no longer served for feeds, oEmbed views, trackbacks, paged
  comments or post sub-pages.** `Accept: text/markdown` on `/my-post/feed/`
  returned the article body instead of the feed: those URLs are variants of the
  post, not the post, and are never advertised as Markdown. The negotiation
  guard now matches the one used for the `rel="alternate"` link.
* **New: posts with a non-standard post format** (aside, status, quote, link,
  gallery, image, video, audio, chat) no longer expose a Markdown version. They
  are short snippets, usually untitled, with no editorial body worth serving as
  a document; they also disappear from `/llms.txt`, the alternate link, the
  shortcode and the dynamic tag. Filterable through
  `sysmda_markdown_excluded_post_formats` (return an empty array to serve them
  again).
* **Fixed: the `.md` route rendered blocks and shortcodes with no post context.**
  On that route the main query 404s, leaving the global `$post` empty, so dynamic
  blocks and shortcodes falling back to `get_the_ID()` rendered against nothing —
  and the same post could convert differently through `.md` than through
  `?format=markdown`. The loop is now set up for the conversion on both routes.
* **`/llms.txt` stays out of the way until a content type is enabled.** The
  endpoint is on by default but had nothing to index on a fresh install, so it
  answered a site name and a tagline while taking the URL over from anything else
  that might serve it. It also no longer lists posts the `.md` endpoint would
  404.
* **`.htaccess` is now replaced atomically** (a private temporary file renamed
  over the target), with a one-time `.htaccess.sysmda-bak` snapshot, so a
  half-written file can never be observed. See 0.26.1: the locking around it
  needed more than that.
* **Uninstall now cleans every site of a multisite network**, in batches, instead
  of only the current one.
* Fixed: a CSS class supplied through `sysmda_markdown_excluded_classes` that is
  not a plain token (a quote in it) took the whole response down with a fatal
  XPath error; such entries are skipped now.
* Fixed: `[sysmda_md_url]` returned the main post's URL inside a secondary loop
  (related posts, a query block) instead of the current item's.
* Fixed: an enabled content type whose plugin was temporarily deactivated was
  silently dropped from the selection by the next save of the settings page.
* Fixed: the `/llms.txt` conflict check looked for a physical `llms.txt` in
  ABSPATH instead of the site root (wrong on subdirectory installs).
* Fixed: an ACF field whose value is the string `0` was skipped.
* `X-Robots-Tag` and `Link: rel="canonical"` values coming from filters are
  sanitized before reaching `header()`.
* `.md` URLs are matched case-insensitively, and an inactive plugin (no content
  type enabled) no longer redirects `.md` URLs it would then 404.
* Settings page: keyboard navigation for the tabs (arrow keys, Home/End) with
  the matching ARIA roles, and a plain-rendering fallback if the section list
  ever becomes unreadable.
* Tests: the DOM pipeline and the Markdown conversion — previously uncovered,
  which is where every output bug above lived — now have golden coverage
  (260 assertions, up from 211). PHPCS is clean with zero warnings.

## 0.25.0
* Changed: the *Custom taxonomies* setting is now a **list of checkboxes, one per
  taxonomy**, instead of a single on/off switch. Only the taxonomies you tick are
  added to the front matter; nothing is selected by default, and a taxonomy
  registered by a plugin you install later appears in the list unticked instead
  of publishing itself.
* Fixed: a taxonomy registered as public but **not publicly queryable** — the
  usual shape of an editorial-internal classification with no term archive — was
  added to the front matter automatically. Whether such a taxonomy is published
  is now your explicit choice; the list labels those rows as internal, and they
  stay selectable for the cases where you do want them.
* Changed: `sysmda_front_matter_taxonomy_slugs` now receives the saved selection
  as its default value (it can still narrow it and extend it), and
  `sysmda_front_matter_taxonomies` becomes a kill switch whose default is "at
  least one taxonomy is selected".
* Upgrade note: if you had the 0.24.x checkbox enabled, the selection is seeded
  automatically with the taxonomies that are public **and** publicly queryable,
  so only the internal ones drop out of the front matter. Cached Markdown and
  `ETag`s are refreshed once during the upgrade. Sites that had the checkbox off
  see no change at all.

## 0.24.0
* Added: optional **custom taxonomies in the front matter**. A new *Custom
  taxonomies* checkbox under "Markdown output" (off by default) appends a
  nested `taxonomies:` block listing the post type's public custom taxonomies
  and their terms. Categories and tags keep their own keys and are not
  repeated; `post_format` is excluded as presentational. Taxonomy slugs and
  term names are sorted alphabetically. Curate the list with the
  `sysmda_front_matter_taxonomy_slugs` filter.
* Added: the emitted taxonomy data is now part of the cache validity hash and
  therefore of the `ETag`. Assigning or renaming a term does not change a post's
  modification date, so without this a conditional request could keep answering
  `304 Not Modified` with outdated terms.
* Note: with the toggle off, both the Markdown output and the `ETag` are
  identical to 0.23.3, so upgrading does not invalidate any cached response.

## 0.23.3
* Fixed: links using an uppercase or mixed-case scheme (`MAILTO:`, `TEL:`,
  `DATA:`) are now preserved instead of being mistaken for relative paths and
  rewritten into a broken absolute URL. Scheme names are case-insensitive per
  RFC 3986, which the absolute `http`/`https` check already assumed.
* Fixed: `attachment` is now excluded from the servable post types inside the
  shared eligibility logic, so the "media is never served" rule also holds when
  a post type list is injected through the `sysmda_markdown_supported_post_types`
  filter, not only when it comes from the settings page. The filtered list is
  also normalized (entries trimmed, empty and duplicate values dropped).

## 0.23.2
* Fixed: normalize excluded CSS-class entries with WordPress's class-specific
  sanitizer (`sanitize_html_class`), addressing the WordPress.org Plugin Check
  `register_setting()` sanitization notice. Whitespace-separated tokens are
  normalized individually, empty entries removed and duplicates dropped. The
  other multiline settings (shortcodes, block names, key content) are
  unchanged. No change to the Markdown output.

## 0.23.1
* Packaging: exclude the bundled `league/html-to-markdown` command-line
  binaries (`vendor/bin` and `vendor/league/html-to-markdown/bin`) from the
  distributed plugin. They are never used at runtime (the plugin calls the
  library classes directly) and are flagged as not-permitted files by the
  WordPress.org Plugin Check. No functional change.

## 0.23.0
* New "Settings" action link on the plugin row in the Plugins list, pointing
  to the settings page (Settings → Markdown Alternate).

## 0.22.1
* Clearer guidance for the LiteSpeed cache compatibility option: when LiteSpeed
  is detected and the option is off, the settings page now shows an explicit
  recommendation (whether a host honours `Vary: Accept` cannot be detected
  automatically, so enabling the rules is the safe choice when unsure). The
  FAQ now also documents a quick manual test to check whether a host ignores
  `Vary: Accept`. No change to behaviour or output.

## 0.22.0
* New optional `.md` hit counter (Advanced → "Count `.md` requests", off by
  default): counts how many times the Markdown endpoint is served — `200` and
  `304` alike, for both the `.md` suffix and the negotiated permalink — split
  bot vs human (empty user agents and known crawler/HTTP-client/AI-agent
  signatures count as bot; customizable via the `sysmda_md_hits_bot_patterns`
  filter). Only aggregate daily totals are stored (pruned after 90 days,
  `sysmda_md_hits_retention_days` filter): no IP addresses, no user-agent
  strings, no per-visitor data, no cookies, no external calls. The settings
  page shows read-only totals for today / last 7 / last 30 days. Note:
  requests served by a page cache or CDN without reaching PHP are not
  counted — an indicator, not analytics.
* Documented the developer filter API in the user-facing docs: new FAQ entry
  with examples and a pointer to the full filter list in the GitHub repository.

## 0.21.4
* Cache hardening: the negotiated Markdown and `406` responses now always send
  the standard `Cache-Control: no-cache, no-store, must-revalidate, private`
  header. These responses share their URL with the HTML page and some caches
  (default LiteSpeed, some CDNs) key by URL only and ignore `Vary: Accept`;
  previously the standard header only appeared when the LiteSpeed Cache plugin
  added it, so the protection now no longer depends on any specific cache
  plugin. The explicit `.md` URLs are unchanged: they remain fully cacheable
  with `ETag`/`Last-Modified` revalidation and no `Cache-Control`.
* The LiteSpeed page cache is now purged on plugin activation and deactivation
  (no-op when the LiteSpeed Cache plugin is absent): entries cached before
  activation carry no `Vary` header and could produce mixed HTML/Markdown
  responses that are hard to diagnose.

## 0.21.3
* Fix: removing the LiteSpeed `.htaccess` block (disabling the option or
  uninstalling) no longer leaves blank lines at the top of the file when the
  block was the first thing in `.htaccess`.

## 0.21.2
* Refined the LiteSpeed `.htaccess` rules: two separate bypass rules instead of
  one combined condition. Requests with an empty Accept header or a wildcard
  Accept (`text/*`, `*/*`) now stay on the cached HTML (PHP would serve HTML
  for them anyway), so fewer requests skip the page cache. Same behaviour for
  all real traffic (browsers, Markdown agents, 406 probes).
* The rules-present check compares directives only (comments and indentation
  ignored), so a hand-maintained block with equivalent directives is left
  untouched by the settings-page sync instead of being rewritten.

## 0.21.1
* Fix: the LiteSpeed compatibility block is now written at the TOP of
  `.htaccess`. Appended at the bottom (the `insert_with_markers` default) it
  landed after the `# BEGIN WordPress` block, whose `[L]` rules end every
  rewrite pass, so the bypass rules were never evaluated (verified live). An
  existing bottom copy is automatically moved to the top on the next settings
  page load.
* Fix: the rules-present check now ignores comment lines (WordPress adds its
  own instruction comment inside marker blocks) and verifies the block
  position; previously the settings page always reported the rules as missing
  and re-wrote the block (with a LiteSpeed purge) on every load.

## 0.21.0
* LiteSpeed cache compatibility. Some LiteSpeed servers cache the permalink by
  URL only and ignore `Vary: Accept`, so a cached Markdown variant could be
  served to HTML clients (and cached HTML to Markdown clients). The negotiated
  Markdown and `406` responses now send `X-LiteSpeed-Cache-Control: no-cache`
  and define `DONOTCACHEPAGE`, so URL-keyed page caches never store them; the
  explicit `.md` URLs remain fully cacheable. A new opt-in setting (Advanced →
  LiteSpeed cache compatibility) writes an `.htaccess` block, inert outside
  LiteSpeed, that makes Markdown-negotiating requests bypass the LiteSpeed page
  cache so PHP always performs the negotiation; the block is kept in sync from
  the settings page, purges the LiteSpeed cache on change, and is removed on
  uninstall.

## 0.20.2
* Packaging fix: keep `composer.json` alongside the bundled `vendor/` directory
  so WordPress.org Plugin Check can review the production dependencies. Tests
  and `composer.lock` remain excluded from the distributable package.

## 0.20.1
* Removed duplicate Settings API success notices from the plugin settings page;
  WordPress now remains the single source that renders these notices.
* Description fallbacks now remove complete `script`, `style` and `iframe`
  nodes before extracting text. This prevents embedded code from leaking into
  `.md` front matter and enriched `/llms.txt` entries.
* Completed the WordPress.org internationalization readiness audit: runtime
  strings remain static English with the plugin text domain, while code
  comments, tests, build tooling and workflow messages are now consistently
  English. Official translations remain delivered exclusively through
  translate.wordpress.org language packs.

## 0.20.0
* All internal names now use the distinctive `sysmda_` / `SYSMDA_` prefix and
  the `Diecieventi\SystemMarkdownAlternate` namespace, per the wordpress.org
  plugin review guidelines (options, settings, filters, shortcode, Dynamic Tag,
  constants, cache keys, asset handles). **Breaking**: filters and the shortcode
  are renamed (`sma_*` → `sysmda_*`, `[sma_md_url]` → `[sysmda_md_url]`,
  `{{sma_md_url}}` → `{{sysmda_md_url}}`); settings must be re-saved after
  updating, since option names changed.
* Removed bundled translation files and manual translation loading:
  translations are delivered as language packs by translate.wordpress.org.

## 0.19.0
* `/llms.txt`: new optional **last modified dates** toggle (off by default —
  when off the output is unchanged). When enabled, every entry gets an
  `(updated: YYYY-MM-DD)` note (ISO date, taken from the post's last
  modification), in both the basic and the enriched output, so LLM crawlers can
  spot changed content without re-fetching each `.md` URL. New
  `sysmda_llms_txt_lastmod` filter.

## 0.18.0
* Conditional requests on the `.md` endpoint: the Markdown response now sends
  `ETag` and `Last-Modified`, and honours `If-None-Match` / `If-Modified-Since`,
  replying `304 Not Modified` (no body) when the client already has the current
  version. The validator reuses the existing cache-version hash
  (`post_modified_gmt` + plugin version + settings salt), so a `304` always means
  the cached body would be identical. Works even with the body cache disabled.
* `/llms.txt`: escape the link text and normalise each entry onto a single line,
  so titles or descriptions containing `[`, `]`, `(`, `)`, backslashes, newlines
  or control characters can no longer break a link or the file structure.

## 0.17.1
* Plugin Check compliance (wordpress.org): escape the post-type checkbox state via
  the core `checked()` helper, and annotate the deliberate direct transient cleanup
  query in `uninstall.php`. No change to behaviour or Markdown output.
* Minimum WordPress bumped to 6.1 (the object-cache group flush on uninstall uses
  `wp_cache_flush_group()`, available since 6.1).

## 0.17.0
* Admin settings page restyle (presentation only — no change to options, saving,
  sanitization, security or Markdown output): a page header with a single Save
  button, native WordPress tabs (General, Markdown output, llms.txt, Integrations,
  Advanced), section cards, a two-column layout with an at-a-glance `/llms.txt`
  status/conflict panel, and the built-in exclusion defaults collapsed into a
  `details` disclosure. Fully responsive, admin-scoped CSS, and a tiny dependency-
  free vanilla-JS enhancement for the tabs (the page stays usable without JS).

## 0.16.0
* Optional enriched `/llms.txt` output (new toggle, off by default — when off the
  output is unchanged): site summary paragraph, curated "Key content" section
  (post IDs or URLs from the settings page), a description for each entry (Rank
  Math meta → excerpt → trimmed text, same chain as the front matter), overflow
  beyond the most recent posts moved to an `Optional` section, and a
  `sysmda_llms_txt_footer` filter as a hook for future LLM signals.

## 0.15.0
* Synced patterns (reusable blocks) are now expanded and cleaned like regular
  content: excluded blocks and shortcodes inside a pattern no longer leak into
  the Markdown output.
* Plain permalinks (`?p=123`) no longer produce broken `.md` URLs: Markdown URLs
  fall back to `?format=markdown` (served via content negotiation) and the
  settings page shows a notice.
* New `sysmda_llms_txt_cache_ttl` filter for the `/llms.txt` cache TTL
  (previously shared with `sysmda_markdown_cache_ttl`, which received a `null`
  post and could break third-party callbacks).
* Internal: post eligibility rules centralized in a single helper; local test
  suite and CI added.

## 0.14.0
* Content negotiation is now RFC 9110 compliant. The `Accept` header is parsed with
  q-values: Markdown is served only when explicitly preferred, so clients that prefer
  HTML (or send a wildcard such as `*/*`) keep getting HTML.
* Negotiable URLs now send `Vary: Accept`, so caches/CDNs store the HTML and Markdown
  representations separately instead of poisoning each other.
* Optional `406 Not Acceptable` when the client accepts neither HTML nor Markdown
  (new `sysmda_markdown_strict_406` filter, on by default; real browsers and crawlers are
  never affected).

## 0.13.1
* Repository moved to the Web Dietro le Quinte GitHub organization: updated the
  Plugin URI and Composer package name accordingly, and added an Author URI.
  No functional changes.

## 0.13.0
* Internationalization (i18n): all admin panel strings (and the plugin header
  description) are now translatable through the `system-markdown-alternate` text
  domain, with English as the source language. A bundled `it_IT` translation
  keeps the UI in Italian. The translation template (`.pot`) and the Italian
  translation (`.po`, `.mo` and a `.l10n.php` preferred by WordPress 6.5+) ship
  in `/languages`, and the text domain is loaded on `init`.

## 0.12.1
* Removed the on-demand HTTP "Check /llms.txt now" button and the loopback
  request: it was unreliable behind a WAF/CDN and added no real value. The
  /llms.txt conflict detection now relies only on stable local signals (active
  SEO plugins + physical file).

## 0.12.0
* Settings page UX overhaul (single page, native Settings API): sections grouped
  into Generale, Output Markdown, llms.txt, Integrazioni, Avanzate; supported
  post types moved to the top; compact exclusion textareas with defaults shown
  one per line; llms.txt status (enabled + URL); page-scoped admin CSS.
* Exclusion lists are normalized on save (trim, drop empty lines, de-duplicate).
* Supported post types are validated against the registered public types.
* ACF settings are registered only when ACF is active, so saving while ACF is
  inactive no longer wipes the saved field names.

## 0.11.0
* Simpler, low-maintenance `/llms.txt` conflict detection: it now only checks
  whether known SEO plugins (Rank Math, Yoast, AIOSEO, SEOPress) are active and
  whether a physical llms.txt file exists, then warns. It no longer reads those
  plugins' internal options to guess if their feature is on (brittle and
  maintenance-heavy). The on-demand HTTP check is kept.

## 0.10.1
* The on-demand `/llms.txt` HTTP check now uses a browser User-Agent (avoids
  false negatives from WAFs that block bot user agents) and uses the response
  content type to tell a real text llms.txt from an HTML block/soft-404 page.

## 0.10.0
* Automatic conflict detection for `/llms.txt`: warns in the settings if another
  SEO plugin (Rank Math, Yoast, AIOSEO, SEOPress) has an llms.txt feature active,
  if a physical `llms.txt` file exists at the site root, or (on demand) if the
  URL already responds. Detection checks the feature state, not just whether the
  plugin is installed.

## 0.9.1
* No `rel="alternate"` link is printed when no post type is enabled (previously
  it could appear on any singular content).
* Relative links and images are now resolved against the source permalink, not
  the site root (e.g. `file.pdf` inside `/blog/post/` resolves correctly).
* The Rank Math description is only discarded when it contains an unresolved
  `%variable%` placeholder, not any `%` (so "20% off" is kept).
* The ACF TL;DR now goes through the same DOM pipeline as the body (exclusions,
  code normalization, absolute URLs).

## 0.9.0
* Performance: the `/llms.txt` index is now cached and skips priming meta/term
  caches; password-protected posts are excluded from it.
* Caching now uses the persistent object cache (Redis/Memcached) when available,
  falling back to transients otherwise.
* Cache invalidation skips revisions and autosaves.
* Added `uninstall.php` to remove all plugin options and cached data on deletion.

## 0.8.0
* The GenerateBlocks `{{sysmda_md_url}}` Dynamic Tag now registers automatically
  whenever GenerateBlocks 2.x is active (the on/off toggle has been removed). It
  resolves to an empty value for non-servable posts, so leftover tags never
  render as literal text while the plugin and GenerateBlocks are active.

## 0.7.0
* Admin panel reorganized into sections; ACF and GenerateBlocks integrations are
  shown only when the related plugin is active.
* Dedicated Shortcode section.

## 0.6.0
* Single `[sysmda_md_url]` shortcode.
* GenerateBlocks 2.x `{{sysmda_md_url}}` Dynamic Tag, with an on/off toggle.

## 0.5.0
* Shortcodes to output the Markdown URL.

## 0.4.1
* Cache invalidation on plugin update and settings change.

## 0.4.0
* ACF subtitle and TL;DR rendered as a preamble between the H1 and the body.

## 0.3.0
* `Link: rel="canonical"` header on `.md` responses.

## 0.2.1
* Settings-driven filters now apply on front-end requests too.

## 0.2.0
* `/llms.txt` endpoint, custom post type support, content negotiation,
  proactive cache invalidation, ACF integration, admin settings panel and the
  `sysmda_markdown_excluded_classes` filter.

## 0.1.0
* Initial release: `.md` endpoint, alternate link, front matter, block/shortcode
  cleaning and transient cache.

