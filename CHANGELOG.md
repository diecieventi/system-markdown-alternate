# Changelog

Full release history of **System Markdown Alternate**, newest first.

The plugin's `readme.txt` carries only the three most recent releases: the
wordpress.org readme parser truncates a `Changelog` section longer than 5000
characters, so the complete history lives here and `readme.txt` links to it.

Versions from `0.17.1` onward also have an annotated `vX.Y.Z` git tag, whose
notes are generated from the entries in this file by `bin/release-tag.sh`.

## 0.50.0

* **Changed: `/llms.txt` is now off by default**, reversing the July 2026 "on by
  default" decision. `LlmsTxtController` intercepts `/llms.txt` at
  `template_redirect` priority 0 and calls `exit` the moment it renders, which
  is a hard takeover of the URL: if another plugin or SEO tool generates the
  file dynamically, this plugin's priority-0 exit wins the race and the other
  handler is never reached. `ConflictDetector` can only ever surface an admin
  notice — by design it never disables anything on the strength of a guess — so
  an on-by-default endpoint meant a fresh install could start shadowing another
  plugin's `/llms.txt` the moment the owner ticked a single post type for the
  unrelated `.md` feature. Serving the file is now always an explicit choice
  from Settings → Markdown Alternate → llms.txt.
  **Impact on existing sites: none in practice.** The toggle is registered in
  the plugin's single settings group with a checkbox sanitizer, and the
  Settings API writes every registered option of the group on each save — so
  any install that ever saved the settings page (which is the only way to
  select a content type) already holds an explicit `'1'` or `'0'` row and keeps
  its current behaviour. Only an install that never saved the panel picks up
  the new default, and that install serves nothing anyway: with no content type
  enabled the endpoint was already silent.
  Four call sites read the option and all four now default to `'0'`
  (`LlmsTxtController::maybe_render_llms_txt()`,
  `MarkdownController::should_advertise_llms_txt()` and two in
  `AdminSettings`), so the endpoint, the `rel="describedby"` discovery, the
  status aside and the checkbox itself cannot disagree about what a fresh
  install does.

## 0.49.4

* Fixed image `alt`/`title` and link `title`/destination interpolation in the
  Markdown body: `league/html-to-markdown` places these values into
  `![alt](src "title")` / `[text](href "title")` with no escaping at all, so
  `alt="Note] end"` closed the image label early, `title='He said "hi"'`
  broke the quoted title out of its own delimiters, and a destination
  containing a space or a parenthesis was never wrapped in `<…>`. Third
  occurrence of the defect family `0.46.1`/`0.47.1` each closed once already
  for a hand-placed value (an ACF subtitle, a custom-field value) — *a value
  placed into the document is text, and text is escaped by the same
  converter the body uses*. Two new converters, `SafeImageConverter` and
  `SafeLinkConverter`, registered the same way `CodeElementConverter`/
  `SafeParagraphConverter` already are; `alt` goes through the existing
  `MarkdownConverter::escape_inline()`, and `title`/destinations through two
  new static helpers, `escape_link_title()` and `wrap_destination()`. Phase 1
  of a larger fidelity review (table grid/header defects are Phase 2,
  deferred separately). No documentation surface changes: broken output
  becomes correct output, and nothing a user does or configures changes.
* `Tested up to: 7.1` (WordPress "Mary Lou", 19 August 2026). No plugin code
  needed to change for this: both connected staging environments were
  already run against real WordPress 7.1 for the `0.45.0`/`0.48.0`
  acceptance passes (`docs/staging-acceptance.md`), and nothing in the 7.1
  Field Guide touches an API this plugin uses (`template_redirect`,
  `url_to_postid()`, `parse_blocks()`/`render_block()`, the Settings API,
  post meta, WP-Cron, `wp_get_attachment_image_attributes`). The `readme.txt`
  header was simply never bumped past the prior release's `7.0`.

## 0.49.3

* Updated the wordpress.org listing screenshots (`.wordpress-org/screenshot-1.png`
  through `-5.png`) to the current settings panel — the previous set was several
  versions out of date (v0.35.2) and missing panel sections shipped since
  (Extra custom fields, Excluded builder elements, the hit counter's named-bot
  breakdown). Added a sixth screenshot showing the `[sysmda_md_actions]`
  front-end split button (copy/view/download), with a matching caption in
  `readme.txt`. Metadata and repository tooling only — no plugin code, output
  or behaviour changes.

## 0.49.2

* The Live Preview blueprint (`.wordpress-org/blueprints/blueprint.json`) now
  lands on the site's front page (`/`) instead of `/hello-world.md`. Landing
  directly on a `text/markdown` response gave a first-time visitor a download
  or a wall of unstyled plain text with no context — not a demo. The seed
  content every fresh WordPress install carries (the "Hello world!" post, the
  "Sample Page" page) already exercises both enabled post types and
  `/llms.txt`, and a real front page a visitor can click around makes a
  better first impression. `sysmda_llms_txt_enabled` is now also set
  explicitly in the blueprint (already on by default, but demonstrated
  regardless of that default). Metadata and repository tooling only — no
  plugin code, output or behaviour changes.

## 0.49.1

* The plugin now ships a `.wordpress-org/blueprints/blueprint.json`, enabling
  wordpress.org's **Live Preview** button on the plugin's listing page:
  visitors can try the plugin live, active, in WordPress Playground (a
  WASM WordPress running in the browser) with no install. The blueprint
  installs the plugin from its own published wordpress.org release, enables
  the `post` and `page` content types, sets pretty permalinks and lands on
  the seed post's `.md` — the most direct demonstration of what the plugin
  does. Metadata and repository tooling only — no plugin code, output or
  behaviour changes; not bundled into the plugin zip.

## 0.49.0

* Added: pages now point at the site's `/llms.txt` with the `rel="describedby"` link relation introduced by version 2 of the llms.txt specification, in both the HTML head and the HTTP `Link:` header — alongside the Markdown alternate they already advertised. An agent landing on any article can find the site's index without having to guess that `/llms.txt` exists. Emitted only where the Markdown alternate is already emitted and only while this plugin's own `/llms.txt` is enabled, so the link never points at an endpoint that is not being served; there is no new setting.
* Changed: `MarkdownController::link_header_has_alternate()` is now `link_header_has_relation()` and takes the relation to look for. It is an internal helper, public only so its parsing can be exercised from the test suite; no documented filter or output contract refers to it.

## 0.48.0

* Added: a per-known-bot-name breakdown in the `.md` hit counter. Below the
  existing bot/human totals, a second table names any of a short curated list —
  `ClaudeBot`, `GPTBot`, `PerplexityBot`, `CCBot`, matched together with their
  user-initiated variants (`Claude-User`, `ChatGPT-User`, `OAI-SearchBot`,
  `Perplexity-User`) — with at least one hit in the last 30 days. A crawler
  never seen gets no row at all, rather than a permanent zero. Still
  aggregate-only and count-only: it names a few crawlers already counted
  inside the `bot` total, it does not add any new stored data, IP address,
  raw user-agent string or per-visitor identifier. New filter
  `sysmda_md_hits_named_bot_patterns` (Advanced), independent of the existing
  `sysmda_md_hits_bot_patterns` — one decides bot vs human, the other only
  names a match already counted as a bot.

## 0.47.1

* Fixed: a text custom field containing Markdown punctuation was published with
  that punctuation active — a field reading `A *literal* marker` arrived with one
  word in italics instead of the asterisks the author typed. Underscores,
  brackets and backslashes were the same case. Each value was wrapped in a
  `<div>`, and that wrapper silently switched off the escaping every other piece
  of text in the document gets. Values are now separated by a blank line instead,
  which restores the escaping and keeps them apart; markup from a WYSIWYG field
  is unaffected and still converts as before.
* Fixed: listing a custom field whose value contains Gutenberg block markup
  alongside plain-text fields ran those text fields together on one line. One
  block-valued field sent every sibling down the block path, where plain text is
  emitted without paragraphs.

## 0.47.0

* Added: **Extra custom fields** — a new setting listing the post meta keys whose
  values belong in the Markdown. Their content is appended to the end of the
  body, in the order listed. One setting covers ACF, JetEngine, Meta Box and
  WordPress's own Custom Fields box, because underneath they all store post meta,
  so a page whose text comes partly from a template's fields is no longer
  published half missing. Empty by default and never detected automatically: a
  field starts appearing when you type its key into the box, and not before.
  Values that are not text — an image, a repeater, anything stored as an array —
  are skipped rather than guessed at.
* Added: `sysmda_markdown_extra_meta_keys` (Stable) as the filter behind the new
  setting, so the list can be varied per post from code.
* Changed: a post that does **not** carry any of the configured keys keeps its
  document *and* its cache validator byte-identical, so configuring a field for a
  couple of landing pages does not make every article on the site revalidate.
* Fixed: ACF field values added through `sysmda_acf_field_keys` never reached a
  Bricks page's Markdown. Such a page is rendered through Bricks' own API, which
  does not read the post content the values were appended to, so they were
  dropped silently — since 0.46.0. Both they and the new custom fields now go
  through a dedicated `sysmda_markdown_appended_html` filter (Advanced) that is
  honoured on every render path.
* Fixed: the *Excluded builder elements* setting added in 0.46.0 was not removed
  on uninstall.

## 0.46.1

* Fixed: an ACF subtitle containing Markdown punctuation was parsed instead of
  read. A subtitle of `A *literal* marker` was emitted raw between the emphasis
  delimiters, so the reader's own asterisks took part in the formatting and the
  single italic line came out as three runs of emphasis. Subtitle text is now
  escaped the same way the article body is, so it reads back exactly as typed —
  asterisks, underscores, brackets, backslashes and a leading `#` included.
* Fixed: a link whose body is a non-breaking space (or another invisible
  character) is now named from its `aria-label` or `title` like any other link
  that renders nothing. Card and link-preview plugins fill an overlay link with
  `&nbsp;` rather than leaving it truly empty, and those links were still
  arriving as `[](url "Name")` — with the name in the tooltip and nothing tying
  it to the address.
* Changed: the documentation site is now built on every pull request instead of
  only after merging, so a broken page cannot reach the published site.

## 0.46.0

* **Bricks pages now get a real `.md`** (Phase 2 of the page-builder plan;
  `bricks` leaves `BuilderDetector::AWAITING_ADAPTER`, which is the whole
  phasing mechanism — no other rule changed). The new `BricksAdapter` renders
  a Bricks-mode post through Bricks' own `\Bricks\Frontend::render_data()`
  rather than reimplementing its element types, the same "read the builder's
  data to decide, read the vendor to render" shape `BuilderDetector` already
  used. A new `BuilderAdapter` interface and a third branch in
  `ContentRenderer::render()`, tried before the block/classic branches, is the
  seam: `sysmda_markdown_builder_adapters` (Advanced) is the extension point.
  A post switched back to *Render with WordPress* is unaffected — the adapter
  requires the render mode AND the Bricks plugin active, so with either
  missing the ordinary `post_content` pipeline is the correct answer, exactly
  as it was before this release.
* **Fixed the one defect the Phase 0 reconnaissance found**: Bricks' own image
  lazy-loading swaps `src` for an inline SVG placeholder and moves the real
  URL to `data-src` unless `\Bricks\Database::$page_settings['disableLazyLoad']`
  is set for the render — the adapter now brackets every render with a
  save/restore of that flag, so every Bricks image converts to a normal
  `![](url)` instead of a meaningless data URI.
* **New exclusion list**: `sysmda_markdown_excluded_builder_elements`
  (Stable), a panel field and filter for page-builder chrome — Bricks'
  form, nav menu, share bar, table of contents and breadcrumbs elements are
  excluded by default, the same way `sysmda_markdown_excluded_classes`
  excludes a CSS class. Additive to the built-in defaults, never a
  replacement (the 0.40.0 rule). The existing `md-exclude` class already
  worked on Bricks elements with no code change needed — Bricks emits it
  verbatim on the element's wrapper.
* **The cache validator now covers Bricks content**: the render mode, a hash
  of the stored element tree, and the modification date of any referenced
  `template` element's own post are folded into the existing dependency
  fingerprint, so a mode flip or a template edit moves the ETag even though
  `post_modified_gmt` does not.
* **The front-matter `description` fallback and `/llms.txt` entries** now have
  a Bricks-aware last resort, after Rank Math and the excerpt: a cheap,
  unrendered read of the stored tree's text-bearing settings, run through the
  same exclusion pass as the body. A Bricks post's `post_content` is never
  used for this, even when the adapter's fallback finds nothing — it can hold
  stale prose left over from before the page was rebuilt in Bricks, and
  publishing that would reproduce, in the description field, the exact
  "confidently wrong" failure the page-builder veto exists to prevent in the
  body.
* **Advanced-level toggle**: `sysmda_markdown_builder_suppress_content_filters`
  (default on) removes foreign `the_content` callbacks — a related-posts
  block, a CTA — while Bricks' own `post-content` element renders, since that
  element calls the full `the_content` chain internally and would otherwise
  reintroduce exactly the injected content this pipeline avoids everywhere
  else. Flagged as a maintainer-reversible design choice; return `false` from
  the filter to accept whatever a real visitor sees there instead.
* `sysmda_markdown_prewarm` stays off for Bricks posts as for everything else:
  element-level visibility conditions are unverified under WP-Cron's missing
  request context (documentation only, no code change).
* Elementor remains the only builder in `AWAITING_ADAPTER`; Divi, WPBakery,
  Oxygen, Beaver Builder and Breakdance stay permanently unsupported.

## 0.45.1

* Fixed: the `[sysmda_md_actions]` dropdown opened off to the right of the
  button instead of below it. The menu was anchored to the caret rather than to
  the split button as a whole, so its left edge started halfway across the
  control and the panel hung out over the text beside it. It is now aligned to
  the button's own edge and drops straight below, which is what the control
  looked like it should do all along.
* Fixed: the fallback placements now run only when they are needed, and they
  handle the awkward cases better. The menu switches to the opposite alignment
  when the button sits too close to the screen edge, flips above the button when
  there is no room below, and — where there is room on neither side — caps its
  height and scrolls instead of growing across the button it belongs to. On a
  right-to-left site both alignments mirror.
* Changed: the menu is sized to its own content instead of a fixed 250 px, with
  the same width as its floor, so a longer translated label is no longer squeezed
  into two lines while a narrow screen still keeps it inside the viewport.

## 0.45.0

* **A post rendered by a page builder no longer has a Markdown representation.**
  The pipeline assumes the content lives in `post_content`, and page builders
  break that assumption in two different ways. Bricks, Elementor, Beaver
  Builder, Oxygen and Breakdance keep their tree in post meta, so the `.md` came
  out as front matter plus a bare `# Title` — a document with no document in it.
  Divi and WPBakery fill `post_content` with their own `[et_pb_*]` / `[vc_*]`
  layout shortcodes, so the `.md` came out full of scaffolding converted as
  prose. The second is the worse of the two: an empty file is obviously useless,
  whereas a file of builder wrappers looks like content to whatever fetched it
  and looks like nothing at all from the admin side.

  Such a post is now denied at `PostSupport::is_servable()`, so everything
  follows from one rule: the `.md` URL returns 404, no `rel="alternate"` link or
  `Link:` header is advertised, the post is absent from `/llms.txt`, and
  `[sysmda_md_url]`, `[sysmda_md_download]`, `[sysmda_md_actions]` and the
  GenerateBlocks dynamic tag all render nothing — so nothing on the site points
  at a Markdown URL that does not exist.

  Three properties of the detection are worth stating, because each is the
  opposite of what a naive implementation would do:

  * **It is decided per post, never per content type and never per site.** Sites
    routinely build their pages with a builder while the articles stay in the
    ordinary editor, and mixed types are the normal case. Activating a builder
    on a site of 150 block-editor articles changes nothing for any of them.
  * **It reads the render mode, not the presence of builder data.** Bricks and
    Elementor both offer a per-post switch back to the WordPress editor that
    leaves the builder tree stored while the front end serves `post_content`, so
    a post switched back keeps its Markdown version. The same exactness is what
    stops `_wpb_vc_js_status` claiming every post that ever had the WPBakery
    editor opened on it: it stores the string `false`, which is truthy.
  * **It never inspects `post_content`.** An article documenting Divi and quoting
    `[et_pb_section]` in a code sample is not a Divi page, and keeps its Markdown
    version like any other article.

  The rule also holds whether the builder plugin is currently active or not:
  with Divi deactivated its shortcodes stay in `post_content` unregistered and
  would be published as literal text, which is the worst of the three outcomes.

  New Stable filter `sysmda_markdown_unsupported_builders` serves a builder's
  posts anyway — with whatever the ordinary pipeline makes of them — or switches
  the rule off entirely. Bricks and Elementor sit on the list *until an adapter
  exists*; the others are permanent.

* **The settings page shows what each content type is actually built with.**
  *Enabled content types* now carries a per-type breakdown — for example
  *12 Bricks, 3 Gutenberg* — with a warning naming any builder whose posts have
  no Markdown version. A breakdown rather than one label, because mixed types
  are the normal case. It is advisory only: it never filters, enables or
  disables anything, it is computed on the settings screen and never on the
  front end, and it is cached.

* **Fixed: rebuilding `/llms.txt` issued one extra query per candidate post.**
  The batch query primed the post meta cache only in enriched mode, from when
  the enriched descriptions were the only reader of post meta. The new
  page-builder check reads meta too, so on the basic path every candidate cost
  its own `postmeta` query — with the default 500-post limit and up to five
  pages of candidates, as many as 2500 per content type whenever the index cache
  was cold. Both caches are now primed for the whole batch, which is also what
  was already being done for the term lookup the post-format check needs.

## 0.44.0

* **A card whose whole surface is clickable now converts to a link that has a
  name.** Link-preview, related-posts and card plugins build that surface as an
  empty anchor laid over the card — the "stretched link" idiom, which CSS
  frameworks document as a utility — with the title, image and summary as
  siblings rather than children of the link. Nothing was lost in the
  conversion, but the anchor came out as `[](url "Title")`: a link with no text
  at all, while the name the markup does carry ended up in a paragraph of its
  own further down, so nothing tied the name to the address. For a document
  read by an agent that severs the one association that matters.
* An anchor that renders nothing now takes the accessible name its markup
  already declares — `aria-label` first, since that is the mechanism meant for
  it, otherwise `title` — as its link text. The consumed `title` is dropped, or
  the library would emit it a second time as a Markdown link title. The rule is
  read off the anchor itself rather than guessed from the surrounding card, so
  it covers every plugin producing this shape and none of them specifically.
* Deliberately narrow in three ways. An anchor with **no** declared name is
  left exactly as it was: the markup says nothing about it, and synthesising a
  name from the address would turn decorative anchors — `#top`, JS hooks, skip
  links — into visible URLs in documents that read cleanly today. Emptiness
  means what the anchor *renders*, so an anchor wrapping an image (named by its
  alt) or holding an icon element is untouched. And nothing is rewritten inside
  `<pre>` or `<code>`, where the markup is a sample an author is quoting.
* The card's own heading keeps its place instead of being folded into the link.
  Reattaching it would mean guessing which of a card's elements is the title —
  the structural guesswork this pass exists to avoid — so the name appears
  twice: once as the link text, once as the card's heading. Honest, and cheaper
  than being wrong.

## 0.43.0

* **An embedded video, tweet or track now leaves a usable address behind
  instead of nothing.** `iframe` is among the nodes the converter removes, so
  once anything had resolved an embed — a cached oEmbed result, a plugin
  filtering `render_block`, an embed block shipped by another plugin — the
  player was stripped and the document kept no trace of it at all, not even the
  address. The unresolved shape reached the reader as loose text: a
  `core/embed` block stores the bare URL and WordPress resolves it inside
  `the_content`, which this pipeline skips by design.
* An element carrying the `wp-block-embed` class is now handled by what it
  says. Nothing but the URL — the stored address, a player frame on its own, a
  fallback link with no text — becomes a paragraph holding that link, which
  converts to an autolink. Real text plus a link that already names the
  resource (a quoted tweet, a provider's fallback markup) is left alone. Real
  text with the address only in the frame has **the frame alone** replaced by
  the link, in place: the text and the address both survive, which they cannot
  if the pass insists on replacing the whole element.
* The address is taken from the URL stored in the block, then from a link in
  the provider's fallback markup, then from the frame's `src`. Frame references
  are resolved against the permalink — root-relative and protocol-relative
  `src` attributes included — because the later URL pass only covers `a` and
  `img` and the frame is removed before anything else could resolve it.
* A bare `<iframe>` outside an embed block is not this construct and is still
  removed.
* Documented in `docs/output-format.md` (a new section and a row in the
  conversion table) and in the user documentation.

## 0.42.0

* Added to the default exclusions, each verified against the plugin's own
  source rather than assumed: `ninja_form` (shortcode) and `ninja-forms/form`
  (block) for Ninja Forms — the singular, unquoted-id shortcode syntax
  confirmed by the plugin's publishing docs, the block name by its `block.json`
  registration — and `formidable` (shortcode) and `formidable/simple-form`
  (block) for Formidable Forms, whose block name was read directly from
  `FrmSimpleBlocksController::register_simple_form_block()` in the plugin's
  GitHub source. Formidable's other shortcodes (`frm-show-entry`,
  `formresults`, `frm-stats`, `frm-graph`, …) display submitted entry data —
  real content, not interface chrome — and are deliberately left out.

## 0.41.1

* **An actions shortcode first rendered after the footer-script pass now
  initializes normally.** A template or plugin callback running after
  WordPress's priority-20 footer printer could enqueue `md-actions.js` after the
  queue had already been consumed. The component starts hidden until that
  script initializes it, so the valid shortcode remained invisible. The late
  path now prints only its registered handle through the WordPress scripts API,
  preserving localization and dependency handling; earlier renders still use
  the normal footer queue and repeated calls remain de-duplicated.

## 0.41.0

* **One independently designed converter now owns both `<code>` and `<pre>`.**
  The implementation was written test-first from the frozen behavior plan,
  CommonMark's code-span/fence rules, the plugin's `CodeFence` contract and the
  public `league/html-to-markdown` interfaces. It reads decoded element values
  through `ElementInterface::getValue()` instead of serialized child markup;
  the two adapted converter files are gone. League remains the general HTML to
  Markdown engine and its bundled MIT license remains in the distribution.
* **Inline code now preserves the text the HTML element actually represents.**
  Each CR/LF line ending becomes one space instead of silently concatenating
  words; symmetric boundary spaces receive compensating padding so CommonMark
  parsing does not remove them; an empty element emits no invalid backtick pair;
  and nested syntax-highlighter spans contribute decoded text rather than their
  wrapper tags. Content remains structurally inline regardless of its bytes.
* **Fenced block boundaries no longer manufacture an extra blank line.** A
  source with no final newline receives exactly the separator it needs, while
  one or several existing trailing newlines are preserved. Empty blocks remain
  valid and content-sized delimiters still make breakout impossible. A bare
  `<pre>` whose literal text resembles a valid fence is wrapped by a wider one;
  pass-through requires a single structural `<code>` child as well as safe
  fence syntax, so literal backticks cannot be reinterpreted as Markdown.
* **Fallback language detection is token-based and ordered.** It accepts only an
  anchored `language-*` class token, then `data-language` / `data-lang`, checking
  `<code>` before its parent `<pre>` and sanitizing every candidate. A class such
  as `notlanguage-php` can no longer become the false info string `notphp`.

## 0.40.1

* **Authenticated `/llms.txt` rendering no longer touches the shared anonymous
  body cache.** The index applies the same servability policy as every other
  surface, and site code may make that policy membership-dependent. A logged-in
  request could therefore build a different title/description list and store it
  under the one public cache key, or read the anonymous list instead of its own.
  Authenticated requests now rebuild without reading or writing that cache and
  remain `private, no-store`; anonymous cache behaviour is unchanged. The index
  keeps its body-derived strong ETag because it describes the bytes actually
  rebuilt for that request.
* **Synced patterns referenced from generic ACF source fields now participate
  transitively in the dependency fingerprint.** Those fields join the post
  source before block rendering, but the validator previously traversed only
  `post_content`, so editing a referenced `wp_block` could change the `.md`
  without changing its ETag. The ACF traversal now shares the same cycle guard
  and dependency list as the post body.
* **Removing the last out-of-row dependency can no longer make an old post date
  trustworthy again.** A selected custom taxonomy now fingerprints its empty
  state as well as its terms, so removing the last term still disables
  `If-Modified-Since` and moves the ETag. Deleting or emptying the featured-image
  relation or Rank Math description records the disappearance through the
  deferred global salt. Plain posts with no configured external surface retain
  the `If-Modified-Since` fast path.

## 0.40.0

* **The three exclusion settings now add to the built-in defaults instead of
  replacing them.** Typing a single tag into "Excluded shortcodes" used to drop
  all five built-in ones with it: a site adding one newsletter tag silently
  stopped excluding `contact-form-7`, `gravityform`, `wpforms`,
  `mailerlite_form` and `lwptoc`, and the only hint was a help text describing
  the empty case. Exclusions are a safety list — the failure mode of getting one
  wrong is publishing a form into every `.md` — so they accumulate rather than
  trade off. Removing a built-in default is still possible, through the matching
  `sysmda_markdown_excluded_*` filter, which runs at priority 10 before the
  closure that appends the saved lines at 20; only the textarea is additive.
  `ShortcodeCleaner::ALWAYS_EXCLUDED` remains removable by nothing.
* **An excluded shortcode was deleted from inside code samples.** An article
  documenting `[contact-form-7 id="42"]` in a code block had the tag stripped
  out of its own example, publishing `echo do_shortcode('');`. `0.38.1`
  protected code regions from shortcode *expansion*, but the removal pass
  (`ShortcodeCleaner::strip()`) runs earlier, on the raw source, and had no
  notion of code — so one rule was applied to one half of the pipeline. The
  masking now lives in `CodeRegions` and is shared by both, which is what stops
  it being applied to one side and forgotten on the other. Two properties of it
  are load-bearing: the transform runs **at most once** — an enclosing shortcode
  may rewrite, escape or discard the body it is handed, and re-running on the
  unmasked string to recover a lost placeholder would expand shortcodes inside
  the very code sample this protects and repeat every wrapper side effect — and
  the placeholder is made of word characters only, so `esc_html()` and
  `wptexturize()` (which turns `--` into an en dash) leave it intact where a
  comment-shaped token would not.
* Consequence worth stating: the code protection applies to
  `ShortcodeCleaner::ALWAYS_EXCLUDED` as well, so `[sysmda_md_button]` and
  `[sysmda_md_actions]` written inside a code sample are now shown instead of
  removed. The 0.34.0 rule they exist for — a bare tag left in old content must
  not surface as literal text — is unchanged, and neither can ever *render* into
  the Markdown, because a masked region is never expanded.
* The panel's "View built-in defaults" lists are read from the classes that
  apply them instead of being copied into `AdminSettings`, so they cannot drift
  from what the plugin actually excludes.
* Added to the default excluded shortcodes, all verified against the plugins
  that register them: `fluentform`; the newsletter subscription forms
  `mc4wp_form`, `mailpoet_form`, `newsletter_form` and `sibwp_form` — the
  category that produced the original symptom; and the tables of contents
  `ez-toc`, `ez-toc-widget-sticky` and `toc`. Deliberately **not** added: the
  bare `newsletter` tag, which is The Newsletter Plugin's public-page shortcode
  and far too generic a word to claim by default.

## 0.39.0

* **Added `[sysmda_md_actions]`, an explicit reader-facing Markdown control.**
  It follows the GitHub Docs split-button behaviour requested for this feature:
  the primary **Copy as Markdown** action fetches and copies the complete `.md`
  document, while the dropdown repeats that action and adds **View as
  Markdown** in a new tab plus **Download Markdown**. `id="123"` targets another
  post through the same `Shortcodes::resolve_post()` and `PostSupport` rules as
  the existing URL/download shortcodes, so unsupported, draft, protected and
  non-standard-format content produces no control rather than a broken link.
* **The menu is designed for unknown theme layouts rather than assuming an
  article column.** JavaScript moves it to `document.body`, positions it against
  the viewport, flips horizontally and vertically when an edge has no room,
  clamps it to an 8 px inset, and recalculates on scroll/resize. That prevents
  an ancestor's width or `overflow` from clipping the dropdown — the mobile
  failure that made the old, broader front-end button untenable in `0.34.0`.
  Native buttons/links retain their semantics; `aria-expanded`, Escape,
  outside-focus closing, arrow/Home/End navigation, a polite live region and a
  hidden “opens in new tab” note cover keyboard and assistive-technology use.
* **Assets remain opt-in with the shortcode.** The small namespaced stylesheet
  and dependency-free script are enqueued early when `has_shortcode()` can see
  the control in the queried post, and from the render callback as a late
  fallback for templates, widgets and secondary loops. If that fallback runs
  after `wp_head`, the stylesheet is explicitly printed before footer scripts;
  merely enqueuing it there would leave the documented late placement unstyled.
  WordPress de-duplicates both paths, and pages that render no control load
  neither asset. The download stays the existing client-only contract: a
  same-origin `download` attribute and `MetadataBuilder::download_filename()`,
  with no new request parameter or `Content-Disposition` response.
* The root stays hidden until JavaScript has initialized it, so a failed or
  unavailable clipboard API never leaves a dead copy action or an unpositioned
  menu. Copying uses the promise-backed `ClipboardItem` route Safari requires,
  then falls back to `writeText`/the legacy textarea path, and refuses to copy a
  response whose content type is not `text/markdown`.

## 0.38.2

* **The front-matter `description` still leaked the text of an excluded
  *block*.** `0.38.1` made the description fallback apply the exclusion rules to
  the post content, but only the element-level one — the DOM pass that matches a
  CSS class. Two block-level cases stayed open, and both are reachable from the
  settings page: a block excluded **by name** ("Excluded blocks"), which is
  harmless for the names the plugin ships — they are dynamic blocks with no text
  of their own — but not for a static block like a pullquote, whose text sits in
  the saved markup; and a block excluded through `attrs.className` whose saved
  inner HTML does not repeat the class attribute, leaving the DOM pass nothing
  to match on. In both, the body dropped the block and the front matter (and
  enriched `/llms.txt`) published its text.

  Block content now goes through `BlockCleaner` and is re-serialized before the
  class pass runs, so the fallback applies the same rules as the body rather
  than a subset of them. Deliberately with no cheap substring guard in front of
  it: a guard would be evaluated against the post's own markup, and a synced
  pattern keeps its content in another post, so it would go blind exactly where
  `BlockCleaner` follows the reference. Descriptions of content with nothing
  excluded are unchanged.
* **`DIST/` is no longer committed.** The zip in the repository was a copy of the
  last release package that no release path reads: the `Publish release`
  workflow rebuilds from the tag before attaching the asset, and the
  wordpress.org deploy stages from the repository. Keeping it only produced
  drift whenever a commit did not change the version, a pull request spent on
  refreshing it, and a `git checkout --force` in the workflow that existed
  purely because a tracked file was rewritten on every build. The zip of a
  release is the asset on its GitHub Release, built from the tag; `bin/build.sh`
  still produces one on demand.
* `bin/build.sh` checks for `composer`, `rsync` and `zip` before installing or
  writing anything — `rsync` became a hard requirement when packaging moved to
  `.distignore`, and a missing one used to surface as `command not found`
  halfway through a build that had already replaced the installed dependencies
  with `--no-dev`. The zip is now assembled in the staging directory and moved
  into place only once complete, so a failed build no longer deletes the
  previous one and puts nothing back.

## 0.38.1

* **Shortcodes inside blocks are expanded.** `render_block()` does not expand
  them — on the front end that is `the_content`'s job, and this pipeline skips
  `the_content` by design so that injected related/CTA content never enters the
  Markdown. Nothing took over the expansion, so a shortcode typed into a
  paragraph, written in a Custom HTML block or placed in the core Shortcode
  block reached the converter as literal text and was published as an escaped
  `\[tag\]`. Classic (non-block) content was unaffected: it had always called
  `do_shortcode()`.
* **Shortcodes shown as examples inside code are no longer expanded.** The
  expansion `do_shortcode()` performs is a plain regex over the whole string
  with no notion of markup, so a code sample containing `[gallery]` was
  expanded like the real thing and the sample was silently rewritten into
  whatever the shortcode renders. Code regions (`<pre>` and `<code>`, in either
  content branch) are now masked for the duration of the expansion. This was a
  pre-existing defect of the classic-content branch; fixing it in one place
  covers both, so the block branch never inherited it.
* **`md-exclude` sections no longer leak into the front-matter `description`.**
  When a post has neither an SEO description nor an excerpt, the description
  falls back to the post text — read from the post content rather than from the
  rendered body, deliberately, because the same code builds every entry of
  `/llms.txt` and rendering each listed post there would be prohibitive. The
  exclusion rules are applied by the render pipeline, so that shortcut summarised
  a section the body promises never to publish. The fallback now runs the same
  exclusion pass first; content carrying no excluded class is untouched, and its
  description is byte-identical to before.

## 0.38.0

* **Code samples can no longer break out of their own code block.** The
  conversion library picks a Markdown delimiter without looking at what it
  wraps, so a code block whose body contained ` ``` ` closed early: the rest of
  the sample was re-read as prose and the trailing delimiter opened a new fence
  that swallowed everything after it, headings and paragraphs included. Fenced
  blocks now open with a delimiter longer than the longest backtick run inside
  them, and inline code spans do the same (with padding when the content starts
  or ends with a backtick).
* **A fence written as ordinary prose is escaped.** A paragraph whose text was
  three backticks — writing about Markdown, or pasted terminal output — used to
  open a code fence that ran to the end of the document. An inline code span
  that happens to use a long delimiter is deliberately left alone.
* **Captions are separated from what they caption.** `<figcaption>` is not a tag
  the converter knows, so with tag stripping on a captioned image came out as
  `![Alt](url)My caption` on a single line. Captions are now promoted to their
  own paragraph, which fixes images, tables and embeds at once.
* **`core/details` is readable.** The summary and the body used to be
  concatenated with nothing between them ("MoreHidden body"); the summary is now
  a bold lead-in paragraph followed by the disclosure body.
* Documented all of the above in the output-format contract, and pinned each
  case with golden tests — including end-to-end ones that assert the text
  *after* a code block is still text.

## 0.37.0

* **Supported canonical HTML pages now advertise their Markdown representation
  in the HTTP `Link` header as well as in the document `<head>`.** The response
  field is `Link: <markdown URL>; rel="alternate"; type="text/markdown"` and is
  present on both `GET` and `HEAD`, which lets clients discover the alternate
  without downloading or parsing the HTML body. It is appended without
  replacing Link relations emitted by WordPress, a theme or another plugin, and
  the exact relation/target is not duplicated when it already exists. The same
  canonical-request predicate still owns discovery and negotiation: feeds,
  embeds, trackbacks, paged comments, post sub-pages and unsupported content do
  not advertise it, while `.md`, negotiated Markdown, `406` responses and
  canonical/access redirects leave before the late HTML-only header is sent.
* **Simplified release packaging around one shared `.distignore`.** The local
  ZIP build and the wordpress.org deploy now stage the same files through the
  same exclusion list, instead of maintaining a second partial copy inside the
  workflow. The obsolete `BUILD-INFO.txt` artifact and its release plumbing are
  gone; integrity and version checks remain in place.
* Cleaned up the test bootstrap for PHP 8.5 and removed the empty duplicate
  `php_codesniffer` test suite from CI. The actual PHPCS job remains unchanged.

## 0.36.0

* **Renaming a category or tag now refreshes the Markdown.** `categories:` and
  `tags:` are always part of the front matter, but nothing told the caching
  layer they had changed, so a client that had already fetched a post kept being
  told "not modified" — indefinitely, with the cache on or off. Changing the
  site timezone had the same effect on the dates, and replacing the file behind a
  featured image on its URL.
* **Fenced code inside a quote or a list item is preserved again.** Only code at
  the left margin was recognised as code, so anything indented inside a
  blockquote or a list had its trailing spaces trimmed and its blank lines
  collapsed — silently rewriting samples, transcripts and diffs.
* **`Vary: Accept` is no longer skipped by mistake.** A site already sending
  `Vary: Accept-Encoding` (most of them, once compression is on) looked to the
  plugin as if the header were covered, and it was never added — leaving caches
  free to hand the HTML page to a client asking for Markdown.
* **The `.md` is now explicitly the anonymous version of a post.** A logged-in
  visitor's request is never stored in the shared cache and is never publicly
  cacheable, so a block or shortcode that renders differently for that visitor
  cannot end up being served to everyone else.
* **New `sysmda_post_is_servable` filter** so a membership or paywall plugin can
  deny the Markdown of a single post. The built-in checks only understand
  WordPress's own post status and password field.
* A post type that is no longer registered as public stops being served, instead
  of remaining servable because its name was still saved in the settings.
* `?format=banana` no longer disables the `406` response that `?format=markdown`
  is allowed to skip.
* A read error while updating `.htaccess` now aborts the update instead of
  rewriting the file from the part that had been read.
* `/llms.txt` counts eligible posts against its per-type limit, so a batch of
  excluded ones no longer shortens the index — or empties a section that still
  has content behind it.
* The panel now distinguishes "`/llms.txt` enabled" from "enabled but waiting for
  a content type", which is when the endpoint deliberately stays silent.
* Control characters arriving from an import or a REST write can no longer break
  the YAML front matter.
* Hardened the wordpress.org release workflow: every GitHub Action is pinned to
  an exact revision, and a deploy is refused unless the tag exists and the
  version agrees across the plugin header, the readme and the changelog.

## 0.35.4

* **Moved the filter reference out of `AGENTS.md` into a dedicated
  `docs/filters.md`.** The full list previously lived inside the agent guide,
  which opens with the git workflow and release process — a developer looking
  up a filter had to read through all of it. The new page is grouped by area
  (content selection, HTTP headers, caching, the conversion pipeline, front
  matter, ACF, `/llms.txt`, hit counter), carries the default-exclusion tables
  and runnable examples, and states which filters run on every request
  (including `304` responses) so a slow callback is never attached to a
  bodiless response by mistake.
* The `readme.txt` `== Description ==` section no longer duplicates the full
  filter list (it enumerated ~30 filters in a block the FAQ entry right below
  already summarized with a link); one feature bullet now states the plugin is
  filter-extensible, and both the FAQ and `README.md` link to the new page.
* Corrected the documented execution order of the conversion-pipeline filters,
  including the preamble's second cleaning pass and the exact set of filters
  reached on cache hits and conditional `304` responses. Documentation only —
  no plugin behaviour changed.

## 0.35.3

* **Refreshed the wordpress.org listing assets.** The four screenshots still
  showed the pre-0.17.0 admin UI, from before the tabs/cards restyle, so every
  one of them misrepresented the panel a user actually sees. They are replaced
  by five, one per settings tab in panel order (General, Markdown output,
  `/llms.txt`, Integrations, Advanced), and the `== Screenshots ==` captions in
  `readme.txt` are rewritten to match — including the parts the old set never
  showed at all: `[sysmda_md_download]`, the last modified dates in
  `/llms.txt`, the LiteSpeed bypass rules and the `.md` hit counter.
* **Why this is a release and not just an asset update.** The captions live in
  `readme.txt`, which ships in the package, and the deploy workflow builds from
  the version tag rather than from `main`. Without a new version, a deploy of
  `0.35.2` would have published the old captions and the old images, silently
  undoing the refresh.
* Updated the `wp-coding-standards/wpcs` development dependency to 3.4.1, which
  fixes an arbitrary code execution advisory in the
  `WordPress.WP.EnqueuedResourceParameters` sniff (it evaluated the `$ver`
  argument of `wp_enqueue_script()` through `eval()`, so scanning untrusted PHP
  could run commands on the scanning host — the repository's own CI being the
  realistic case). No effect on the distributed plugin: the coding-standards
  tooling is a development dependency and the build installs with `--no-dev`,
  so it has never been part of the package.

## 0.35.2

* Removed the CSS snippet the `[sysmda_md_download]` FAQ offered as an example of
  styling the link as a button. The plugin ships no stylesheet for that link and
  never will, so it has no business suggesting one either: the readme states that
  the link carries a single `sysmda-md-download` class and that styling is the
  theme's job, and stops there.

## 0.35.1

* **Fixed: the settings panel never mentioned `[sysmda_md_download]`.** The
  Integrations tab listed only `[sysmda_md_url]`, so the shortcode added in
  0.35.0 was documented everywhere except the one place you look while actually
  using the plugin. The card now covers both, with the `text=""` and `id=""`
  variants.
* Fixed: the same card claimed a shortcode returns empty for a post that is
  "type not enabled, draft, or password-protected", omitting **non-standard post
  formats**, which have been a reason for exclusion since 0.26.x. The list is now
  complete, and says explicitly that this is what keeps the shortcodes from
  linking to a 404.
* Internal: the `PostSupport` docblock, which lists everywhere the eligibility
  rules apply, was still half in Italian and predated both `/llms.txt` and the
  download shortcode.

## 0.35.0

* **New `[sysmda_md_download]` shortcode**: a link that saves the Markdown as a
  file instead of opening it in the browser. Optional `text=""` for the label
  (default "Download MD", translatable) and `id=""` for another post, exactly
  like `[sysmda_md_url]`. Like that one, it returns an empty string when the post
  has no Markdown version, so it can never print a link to a 404.
* **Nothing changes on the server side, deliberately.** The download is the
  standard HTML `download` attribute, which applies because the link is
  same-origin. No `Content-Disposition` is sent and no request argument is read:
  the `.md` keeps exactly one representation and one behaviour, and the response
  carries no header that varies by how a client intends to store it.
  A `?download=1` argument was built and removed before release. It only covered
  opening the URL by hand — where the browser decides anyway — while costing a
  permanent public input to validate on every request: `?download[]=1` makes
  `$_GET` an array, and the resulting warning, raised after `status_header()`,
  would flush the headers already sent and cost the response its `ETag`,
  `Last-Modified` and `X-Robots-Tag` on any site with `display_errors` on.
* The download file name is percent-decoded (WordPress stores non-Latin slugs
  encoded), transliterated and reduced to `[A-Za-z0-9._-]`, falling back to
  `post-<ID>.md` when nothing survives. The charset is asserted in the tests as a
  property rather than a fixed string, so a slug carrying a quote, a backslash or
  a CRLF cannot break out of the attribute it is interpolated into.
* **A separate shortcode rather than attributes on `[sysmda_md_url]`.** That one
  always returns a bare URL, which is what makes `<a href="[sysmda_md_url]">`
  safe; making its return type depend on an attribute would break that usage the
  day someone passed a label.
* **No CSS and no JavaScript are added to the front end.** The output is a bare
  anchor with a single `sysmda-md-download` class, there only so a theme can
  style it. The tests assert the shape — one class, no inline styles, no `data-`
  hooks — because the button removed in 0.34.0 started out exactly this small.

## 0.34.0

* **Removed the Markdown button**, three versions after it shipped. It was the
  wrong answer to a real problem. Reported from a live site: the dropdown broke
  the layout on mobile, and it put a stylesheet and a script on the front end for
  a control most readers never use. Each round of feedback had already bought
  another round of CSS — auto-insert removed in 0.32.0, the cascade fixed so
  overrides worked at all, then twelve custom properties and a specificity fight
  with the theme in 0.33.0. A plugin whose value is a clean machine-readable
  representation should not be shipping a presentational widget it cannot test
  against an unknown theme.
* `MarkdownButton.php`, `assets/md-button.css`, `assets/md-button.js`, the
  **Markdown button** panel tab, the five `sysmda_md_button_*` filters and both
  saved options are gone. The options remain in `uninstall.php` as legacy keys so
  they are still cleaned up.
* **No output change**: the `.md` is byte-for-byte what it was, and discovery is
  unaffected — `rel="alternate"`, `/llms.txt`, content negotiation and
  `[sysmda_md_url]` all behave exactly as before.
* `ShortcodeCleaner::ALWAYS_EXCLUDED` deliberately keeps stripping
  `[sysmda_md_button]`. The shortcode is no longer registered, so one left in old
  post content would otherwise survive into the Markdown as the literal text
  `[sysmda_md_button]`.

## 0.33.0

* **The dropdown can now be coloured separately from the button**, and hover and
  focus are stylable at last. Five new custom properties:
  `--sysmda-btn-hover-fg` and `--sysmda-btn-hover-bg` for the toggle,
  `--sysmda-btn-menu-fg`, `--sysmda-btn-menu-hover-fg` and
  `--sysmda-btn-menu-hover-bg` for the entries. Each falls back through to the
  button's own value, so a menu only needs its own colours when you want it to
  differ, and focus deliberately reuses the hover pair rather than introducing a
  third state to style. Twelve properties in total, all listed in the panel.
* **Fixed: a dropdown entry could render invisible.** Two of the four entries are
  links, and a theme rule as ordinary as `.entry-content a` (specificity 0,2,0)
  outranked the plugin's `.sysmda-md-button__item` (0,1,0) and repainted them —
  on a dark menu that made "View as Markdown" disappear entirely. The plugin's
  property rules are now scoped a level deeper so they hold against that. This
  does not make overriding harder: customisation goes through the custom
  properties, which the plugin never declares, and a custom property set anywhere
  on or above the button wins regardless of the selector that reads it.

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
