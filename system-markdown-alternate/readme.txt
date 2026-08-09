=== System Markdown Alternate ===
Contributors: system4pc
Tags: markdown, llms.txt, ai, llm, content negotiation
Requires at least: 6.1
Tested up to: 7.0
Requires PHP: 7.4
Stable tag: 0.38.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Exposes a clean Markdown version of your posts (readable by LLMs, agents and technical tools) by appending .md to the permalink.

== Description ==

System Markdown Alternate publishes a clean, machine-readable Markdown representation of your content. Append `.md` to any supported permalink and you get YAML front matter plus the post body converted to Markdown — with marketing clutter, forms and navigation widgets stripped out.

    https://example.com/my-post/    → HTML
    https://example.com/my-post.md  → Markdown (front matter + content)

It is built for the era of AI assistants, agents and technical scrapers that prefer plain Markdown over rendered HTML. It is **not** a generic SEO plugin.

= Key features =

* **`.md` endpoint** for every supported, published, public post.
* **Content negotiation**: the same Markdown is returned for `Accept: text/markdown` or `?format=markdown` requests. The `Accept` header is parsed with q-values, so a client that prefers HTML (higher q) still gets HTML.
* **`Vary: Accept`** on negotiable URLs, so caches and CDNs that honour it keep the HTML and Markdown representations of the same address apart. Because some page caches key by URL only and ignore `Vary`, the negotiated Markdown (and `406`) responses are also sent non-cacheable, so safety never depends on `Vary` alone.
* **Markdown discovery in HTML and HTTP**: supported canonical pages advertise the representation with both `<link rel="alternate" type="text/markdown">` in the document head and a typed `Link: rel="alternate"` response header. The HTTP form is also available to `HEAD` requests.
* **Correct HTTP headers**: `Content-Type: text/markdown`, `X-Robots-Tag` (default `noindex, follow`) and a `Link: rel="canonical"` back to the HTML.
* **Clean conversion**: Gutenberg blocks are rendered individually (no injected related/CTA blocks), excluded blocks/shortcodes/CSS classes are removed, code blocks become fenced blocks, URLs are made absolute.
* **`/llms.txt` endpoint** (optional): an index of your content for LLMs and AI agents. An optional **enriched mode** (off by default) adds a site summary, a curated "Key content" section, a description for each entry and an `Optional` section for older posts. Another optional toggle appends the **last modified date** (`updated: YYYY-MM-DD`) to every entry, so crawlers can spot changed content without re-fetching each URL.
* **Custom taxonomies in the front matter** (optional, nothing selected by default): tick the taxonomies you want and their terms are added as a `taxonomies:` block, alphabetically ordered. Nothing is ever published automatically: a taxonomy registered by another plugin appears in the panel unticked, and taxonomies with no public term archive are labelled as internal.
* **Object cache** with proactive invalidation on post edit, plugin update and settings change: a persistent object cache is used when one is available, falling back to transients otherwise.
* **Optional `.md` hit counter** (off by default): counts how many times the Markdown endpoint is served, split bot vs human. Privacy by design: only aggregate daily totals are stored — no IP addresses, no user-agent strings, no per-visitor data, no cookies, no external calls.
* **Admin panel** to choose which post types are exposed and to tune cache, exclusions and headers — no post type is exposed until you pick one.
* **Shortcodes** `[sysmda_md_url]` (the Markdown URL) and `[sysmda_md_download]` (a link that saves the file instead of opening it).
* **Optional integrations**, shown only when the related plugin is active:
  * **Advanced Custom Fields**: add a subtitle and a TL;DR (from ACF fields) as a preamble between the H1 and the body.
  * **GenerateBlocks 2.x**: a `{{sysmda_md_url}}` Dynamic Tag, available automatically, usable in element fields (e.g. a Button URL).
* **Developer-extensible**: every behaviour above — which content is served, the headers, the caching, the conversion pipeline, the front matter and `/llms.txt` — is exposed as a WordPress filter. See the FAQ below for examples and a link to the full documented list.

== Installation ==

1. Upload the plugin to `/wp-content/plugins/` or install it through the Plugins screen in WordPress.
2. Activate the plugin.
3. Go to **Settings → Markdown Alternate** and select at least one post type under **Supported post types**. Until you do, the plugin stays inactive.
4. Visit any supported post and append `.md` to its URL.

No rewrite rules are added, so no permalink flush is required.

== Frequently Asked Questions ==

= Why is nothing served at the .md URL? =

By default no post type is enabled. Open **Settings → Markdown Alternate** and tick at least one post type under **Supported post types**.

= Which content does NOT get a .md version? =

Anything the endpoint would not be able to serve honestly:

* content types not enabled in the settings page;
* drafts, pending and private content, and password-protected posts;
* media attachments (always excluded);
* posts with a **non-standard post format** — aside, status, quote, link, gallery, image, video, audio, chat. These are short snippets, usually untitled, with no editorial body worth serving as a document. Use the `sysmda_markdown_excluded_post_formats` filter to change that.

Markdown is also never served for URL *variants* of a post — its feed, its oEmbed view, its trackback endpoint, paged comments and the sub-pages of a post split with `<!--nextpage-->` — even with `Accept: text/markdown`. Only the canonical permalink and its `.md` URL return Markdown.

= What does the Markdown output look like? =

Each `.md` response is a UTF-8 document with a YAML front-matter block (title, URL, Markdown URL, published/modified dates, and — when available — author, featured image, categories, tags and a description), followed by the `# Title` heading and the post body converted to clean Markdown. The exact keys, their order and the escaping rules are documented as a stable contract, with conformance tests, in `docs/output-format.md` in the source repository.

= Can I include my custom taxonomies? =

Yes. Open **Settings → Markdown Alternate → Markdown output** and tick the ones you want under *Custom taxonomies*: the front matter then carries a `taxonomies:` block with their terms, sorted alphabetically. Categories and tags already have their own keys and are not repeated.

Nothing is selected by default and nothing is ever added implicitly — a taxonomy registered by a plugin you install later shows up in the list unticked, so it cannot start publishing itself. Taxonomies used for editorial classification only, with no public term archive ("publicly queryable" off), are labelled as internal in the list: they are still selectable, but only on purpose. Developers can curate the list further with the `sysmda_front_matter_taxonomy_slugs` filter.

= How do I exclude part of a post from the Markdown? =

Add one of the CSS classes `no-md`, `md-exclude` or `exclude-from-markdown` to a block; the element (and its children) is removed from the Markdown output. You can customize the list with the `sysmda_markdown_excluded_classes` filter.

= Does it affect my SEO? =

The `.md` responses are sent with `X-Robots-Tag: noindex, follow` and a `Link: rel="canonical"` header pointing back to the HTML version, so search engines are told to prefer the original page.

= How do I get the Markdown URL in a button or template? =

Use the `[sysmda_md_url]` shortcode. If you run GenerateBlocks 2.x, the `{{sysmda_md_url}}` Dynamic Tag is available automatically — use it in element fields such as a Button URL. When the post has no `.md`, the tag resolves to an empty value so GenerateBlocks can hide the element instead of leaving a broken link.

= How do I let readers download the .md instead of opening it? =

Use the `[sysmda_md_download]` shortcode. It prints a link that saves the file:

`[sysmda_md_download]`
`[sysmda_md_download text="Save the Markdown"]`
`[sysmda_md_download id="123"]`

The link carries the HTML `download` attribute, which is what tells the browser to save the file instead of displaying it. The file name comes from the post slug. Nothing changes on the server side: the `.md` URL itself behaves exactly as it always has, so opening it directly still shows whatever your browser normally does with a Markdown file.

The shortcode outputs a plain link with a single `sysmda-md-download` class, and the plugin loads **no CSS and no JavaScript** on your site for it. Any styling is your theme's job.

Like `[sysmda_md_url]`, it outputs nothing when the post has no Markdown version, so it can never produce a link to a 404.

= Is the .md content cached? =

Yes (default 24h). It uses a persistent object cache when one is available and falls back to transients otherwise. The cache is regenerated automatically when the post is edited, when the plugin is updated, or when you save the settings — and also when something outside the post changes what the Markdown says: a synced pattern, the featured image, the description, an ACF field, the author's display name, the permalink structure or the site address.

That is the cache inside WordPress. Caches *outside* it — your browser, a page cache, Varnish, a CDN — are told `Cache-Control: public, max-age=0, must-revalidate`: they may keep a copy, but they must ask the site whether it is still current before serving it, and the answer is a small `304 Not Modified` when nothing changed. So a `.md` cannot keep circulating after you edit the article, without depending on anyone purging it — which matters, because page caches purge the article's URL and do not know its `.md` version exists. If your infrastructure has its own purge mechanism and you would rather trade that guarantee for raw speed, the `sysmda_cache_control` filter lets you set a real lifetime.

= Can I customize the plugin from my own code? =

Yes: the plugin is developer-extensible through WordPress filters — which content is served, the HTTP headers, the caching, every stage of the conversion pipeline, the front matter and `/llms.txt` can all be changed from a theme or a site plugin. A few examples:

`add_filter( 'sysmda_markdown_output', fn( $md, $post ) => $md . "\n---\nCustom footer.\n", 10, 2 );`

`add_filter( 'sysmda_markdown_excluded_classes', fn( $classes ) => array_merge( $classes, array( 'my-private-block' ) ) );`

`add_filter( 'sysmda_llms_txt_enriched', '__return_true' );`

Every filter, with its default value, what changing it does and how much compatibility it promises, is documented here: [Developer extension API](https://github.com/diecieventi/system-markdown-alternate/blob/main/docs/filters.md). Hooks are labelled Stable or Advanced: the Advanced ones are supported and documented, but may still evolve while the plugin is pre-1.0. The Markdown output format itself is a separate, stronger contract.

= Content negotiation misbehaves behind LiteSpeed cache. What can I do? =

Some LiteSpeed cache configurations key the page cache by URL only and ignore `Vary: Accept`, so a cached representation can be served regardless of the `Accept` header. The plugin already tells the cache not to store the negotiated Markdown; if requests for Markdown on the permalink still receive cached HTML, enable **LiteSpeed cache compatibility** in **Settings → Markdown Alternate → Advanced**: it adds `.htaccess` rules that make Markdown-negotiating requests bypass the LiteSpeed page cache (normal browser traffic stays cached; on other servers the rules are inert). Then purge the LiteSpeed cache. The explicit `.md` URLs are not affected and remain fully cacheable.

Not sure whether your host is affected? Whether a LiteSpeed server honours `Vary: Accept` depends on the host and cannot be detected automatically, so if in doubt simply enable the option: it is the safe choice, and on hosts that already behave correctly the rules are just redundant. To test it yourself: open a post in a normal browser first (so its HTML gets cached), then request the same permalink with a Markdown Accept header, for example:

`curl -A "Mozilla/5.0" -H "Accept: text/markdown" https://example.com/my-post/`

If the response is HTML (often with an `x-litespeed-cache: hit` header) instead of Markdown, your server ignores `Vary: Accept` and you need the option. The browser-like `-A` value matters: a WAF/CDN may block non-browser user agents.

= Does it work behind a CDN (Cloudflare, Fastly, Varnish)? =

The `.md` URLs need nothing from you. The negotiated permalink depends on your CDN, and the difference matters:

* the dedicated `.md` URLs are their own cache key — one URL, one representation, nothing to mix up. Any CDN may store them, and `Cache-Control: public, max-age=0, must-revalidate` means it must revalidate before reuse, which is a cheap `304 Not Modified` when nothing changed. This route works everywhere, with no configuration;
* the **negotiated** permalink (`Accept: text/markdown` on the HTML page's own URL) is sent `no-store`, so a Markdown response is never stored and can never be handed to a browser that asked for HTML. That closes the harmful direction, but it cannot fix the opposite one: if your CDN caches the HTML page by URL and ignores `Vary: Accept`, a later Markdown request is answered at the edge, PHP never runs, and the client simply gets HTML. `Vary: Accept` is sent on every negotiable response, which is all a cache that honours it needs.

So for the negotiated route one of these has to be true: your CDN honours `Vary: Accept`, or you configure it to bypass the cache — or to vary its cache key — for requests whose `Accept` mentions `text/markdown`. On LiteSpeed the plugin ships that bypass for you: see the previous entry. If you are not sure which case you are in, the three-request test below tells you in a few seconds. And the `.md` URL keeps working regardless — it is what the `rel="alternate"` link and `/llms.txt` advertise, so agents following either one are unaffected.

Two more things worth knowing. Some CDNs rewrite validators in transit — Cloudflare turns a strong `ETag` into a weak one — which the plugin handles: incoming validators are compared with the weak-comparison rules, so revalidation keeps working either way. And if you would rather have the CDN really cache the `.md` instead of revalidating it, set a lifetime with the `sysmda_cache_control` filter, keeping in mind that nothing purges a `.md` when you edit the post.

= How do I check my cache is not mixing HTML and Markdown? =

Send three requests to the same permalink, in this order, and compare the `content-type` of each:

`curl -sI -A "Mozilla/5.0" -H "Accept: text/markdown" https://example.com/my-post/`
`curl -sI -A "Mozilla/5.0" -H "Accept: text/html" https://example.com/my-post/`
`curl -sI -A "Mozilla/5.0" -H "Accept: text/markdown" https://example.com/my-post/`

The first and third must answer `text/markdown`, the second `text/html`. If the second returns Markdown, or the third returns HTML, something in front of PHP is serving one stored representation to everyone: look at the `age`, `x-cache`, `cf-cache-status` or `x-litespeed-cache` headers to see which layer, and purge it (on LiteSpeed, see the entry above).

To check revalidation on a `.md` URL, read its `etag` and send it back:

`curl -sI -A "Mozilla/5.0" https://example.com/my-post.md`
`curl -sI -A "Mozilla/5.0" -H 'If-None-Match: W/"paste-the-etag-here"' https://example.com/my-post.md`

The second request should answer `304` with no body. A `200` instead is usually not the plugin: some stacks strip conditional headers from the request before PHP ever sees them (observed with nginx configured to cache the location). It is a missed optimisation, not a correctness problem — the response is still current.

As above, the browser-like `-A` value matters: a WAF/CDN may block non-browser user agents outright, and a block page is easy to mistake for a plugin bug.

== Screenshots ==

1. Settings — General: pick the content types that expose a `.md` (nothing is served until at least one is ticked) and set the cache TTL. The sidebar reports the `/llms.txt` status at a glance.
2. Settings — Markdown output: what stays out of the `.md`. Excluded shortcodes, blocks and CSS classes (leave empty for the built-in defaults), plus the custom taxonomies added to the front matter and the ACF fields.
3. Settings — llms.txt: enable the endpoint, the enriched output and the last modified date on each entry, then add the site summary and the curated key content.
4. Settings — Integrations: the `[sysmda_md_url]` and `[sysmda_md_download]` shortcodes, with the GenerateBlocks and ACF detection status.
5. Settings — Advanced: the `X-Robots-Tag` header, the opt-in LiteSpeed cache bypass rules and the `.md` hit counter, split bot vs human.

== Changelog ==

= 0.38.0 =

* Fixed: a code sample containing a ``` fence used to break out of its own code block and swallow the rest of the document. Fenced blocks and inline code spans now size their delimiters to the content they wrap.
* Fixed: a paragraph whose text is a bare ``` fence is escaped instead of opening a code block that runs to the end of the document.
* Fixed: image, table and embed captions are separated from what they caption instead of being glued to it on one line.
* Fixed: `core/details` no longer renders its summary and body concatenated; the summary becomes a bold lead-in paragraph.

= 0.37.0 =

* Supported canonical HTML pages now advertise their Markdown representation in the HTTP `Link` header as well as in the document `<head>`. The header is also present on `HEAD` responses, appends without replacing other link relations and is not emitted on `.md`, negotiated Markdown, `406` or redirect responses.
* Simplified release packaging around one shared `.distignore`: the local build and the wordpress.org deploy now stage the same files, and the obsolete `BUILD-INFO.txt` artifact is gone.
* Cleaned up the test bootstrap for PHP 8.5 and removed an empty duplicate `php_codesniffer` test suite from CI.

= 0.36.0 =

* **Renaming a category or tag now refreshes the Markdown.** `categories:` and `tags:` are always part of the front matter, but nothing told the caching layer they had changed, so a client that had already fetched a post kept being told "not modified" — indefinitely, with the cache on or off. Changing the site timezone had the same effect on the dates, and replacing the file behind a featured image on its URL.
* **Fenced code inside a quote or a list item is preserved again.** Only code at the left margin was recognised as code, so anything indented inside a blockquote or a list had its trailing spaces trimmed and its blank lines collapsed — silently rewriting samples, transcripts and diffs.
* **`Vary: Accept` is no longer skipped by mistake.** A site already sending `Vary: Accept-Encoding` (most of them, once compression is on) looked to the plugin as if the header were covered, and it was never added — leaving caches free to hand the HTML page to a client asking for Markdown.
* **The `.md` is now explicitly the anonymous version of a post.** A logged-in visitor's request is never stored in the shared cache and is never publicly cacheable, so a block or shortcode that renders differently for that visitor cannot end up being served to everyone else.
* **New `sysmda_post_is_servable` filter** so a membership or paywall plugin can deny the Markdown of a single post. The built-in checks only understand WordPress's own post status and password field.
* A post type that is no longer registered as public stops being served, instead of remaining servable because its name was still saved in the settings.
* `?format=banana` no longer disables the `406` response that `?format=markdown` is allowed to skip.
* A read error while updating `.htaccess` now aborts the update instead of rewriting the file from the part that had been read.
* `/llms.txt` counts eligible posts against its per-type limit, so a batch of excluded ones no longer shortens the index — or empties a section that still has content behind it.
* The panel now distinguishes "`/llms.txt` enabled" from "enabled but waiting for a content type", which is when the endpoint deliberately stays silent.
* Control characters arriving from an import or a REST write can no longer break the YAML front matter.
* Hardened the wordpress.org release workflow: every GitHub Action is pinned to an exact revision, and a deploy is refused unless the tag exists and the version agrees across the plugin header, the readme and the changelog.

[View the full changelog](https://github.com/diecieventi/system-markdown-alternate/blob/main/CHANGELOG.md)

== Upgrade Notice ==

= 0.8.0 =
The GenerateBlocks Dynamic Tag is now always available when GenerateBlocks is active; the enable/disable toggle was removed. No action required.

= 0.7.0 =
Integrations now appear only when ACF or GenerateBlocks are active. No action required.
