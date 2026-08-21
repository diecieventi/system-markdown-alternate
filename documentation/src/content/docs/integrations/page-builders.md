---
title: "Page builders"
description: "Bricks pages get a real Markdown version, rendered through Bricks' own API. Posts built with Elementor, Divi, WPBakery, Oxygen, Beaver Builder or Breakdance have no Markdown version — and why a 404 is the honest answer for those."
sidebar:
  order: 3
---

Most page builders store their content somewhere other than the WordPress post content — in post meta, or in the content field as their own layout shortcodes. The plugin builds Markdown from the rendered content, so how a builder is handled depends on whether the plugin has an adapter for it.

**Bricks is supported.** A Bricks-built page gets a real `.md`, rendered through Bricks' own API.

**Every other page builder has no Markdown version at all.** Its `.md` URL returns 404, its HTML page advertises no Markdown `alternate` link, it is absent from `/llms.txt`, and the shortcodes and the dynamic tag render nothing for it.

## Which builders

| Builder | Status |
|---|---|
| Bricks | **Supported** — a real `.md`, rendered through Bricks' own API |
| Elementor | No Markdown version — support is possible but not planned |
| Divi | No Markdown version, permanently |
| WPBakery Page Builder | No Markdown version, permanently |
| Oxygen | No Markdown version, permanently |
| Beaver Builder | No Markdown version, permanently |
| Breakdance | No Markdown version, permanently |

## Bricks

A Bricks page's `.md` is built by calling Bricks' own `\Bricks\Frontend::render_data()` on the page's stored element tree — the plugin never re-implements what a Bricks element renders as. That has a few practical consequences.

**Images work correctly.** Bricks' own lazy-loading normally replaces an image's `src` with a placeholder until JavaScript swaps in the real one; the plugin disables that for the duration of the render, so every image in the `.md` carries its real, fetchable URL.

**Excluding content works the way it already does elsewhere.** Set the `md-exclude` CSS class on a Bricks element (its *CSS Classes* field, in the Style panel) and its content is stripped from the `.md`, exactly like a `md-exclude` class anywhere else on the site.

**A dedicated exclusion list keeps builder chrome out by default.** Bricks' own form, navigation menu, share, table-of-contents and breadcrumb elements are excluded automatically — they are interface, not article content, the same reasoning that already excludes contact-form and table-of-contents shortcodes everywhere else. See [Excluding content from the Markdown](/settings/excluding-content/) for how to add to or work around that list.

**A page switched to *Render with WordPress* is unaffected.** The plugin follows the page's *current* render mode, not whether Bricks data happens to be stored on it, so switching back to the WordPress editor serves an ordinary `.md` built from the post content — even though the Bricks tree is still sitting in the database.

**The front-matter `description` and its `/llms.txt` entry never fall back to the post's raw content.** For posts in the ordinary editor, a missing SEO description and excerpt fall back to a trimmed extract of the post content. A Bricks page's post content is not reliable for that — it can hold text left over from before the page was rebuilt in Bricks — so the fallback there instead reads a small amount of text straight out of the Bricks element tree. It is deliberately crude (it may pick up a button label) but it is never stale.

## Why the others get a 404

Because the alternatives are worse, and both were what happened before this rule existed.

For the builders that keep their content in post meta — Elementor, Beaver Builder, Oxygen, Breakdance — the post content is empty, so the `.md` came out as front matter and a bare heading. A document with no document in it.

For Divi and WPBakery, which fill the post content with `[et_pb_section]` and `[vc_row]` wrappers, the `.md` came out full of layout scaffolding converted as prose. That is the worse of the two: an empty file is obviously useless, whereas a file full of builder wrappers looks like content to whatever fetched it, and looks like nothing at all from the WordPress admin.

An assistant or crawler that asks for the Markdown version of such a page is better served by a clear "there isn't one" than by either.

## This is decided post by post

The rule never applies to a content type as a whole, and never to your site as a whole. It is evaluated for each post, from the render mode that builder recorded on that post.

Three consequences, all of them the ones people worry about:

- **Activating a page builder does not affect the posts you did not build with it.** If your pages are Bricks or Elementor and your 150 articles are in the block editor, all 150 articles are unaffected either way. This is the normal shape of a builder site, and it is the case the rule is designed around.
- **Switching a post back to the WordPress editor brings its Markdown version back** (for an unsupported builder) **or keeps it built from the post content** (for Bricks). Builders such as Bricks and Elementor keep their stored data when you switch a post to render with WordPress. The plugin follows what actually renders, not what is stored.
- **Writing about a page builder is safe.** Nothing is read from the post content to detect the builder, so an article quoting `[et_pb_section]` in a code sample is not mistaken for a Divi page. It keeps its Markdown version like any other article.

The rule also does not care whether the builder plugin is currently active — for the unsupported builders. Deactivating Divi does not restore the Markdown version of a Divi page: the layout shortcodes are still sitting in the content, and with the plugin gone they would be published as raw text — the worst result of the three. Bricks is the opposite: with Bricks itself deactivated, a Bricks-mode page is served from its post content like any ordinary post, because that is what a visitor would actually see too.

## Seeing which of your content is affected

**Settings → Markdown Alternate → General** shows, beside each content type, what its published posts are actually built with:

```
☑ Post   (post)   — 148 Gutenberg, 2 classic
☑ Page   (page)   — 12 Bricks, 3 Gutenberg
☐ Case study (case_study) — 8 Divi, 1 Gutenberg
                    ⚠ Divi content has no Markdown version and is never served.
```

It is informational only — it never changes what is served, and ticking or unticking a type is still entirely your decision. It is there so that "are my articles affected?" takes one look rather than an audit. Only the *unsupported* builders carry the warning; Bricks content is counted the same way but never flagged, since it is served.

The count is refreshed every few minutes, so a page you have just rebuilt may take a moment to move between columns.

## If you want an unsupported builder's empty document anyway

A site that would rather publish the front matter than nothing for an unsupported builder can remove it from the veto list in code:

```
add_filter( 'sysmda_markdown_unsupported_builders', function ( array $builders ) {
	return array_diff( $builders, array( 'elementor' ) );
} );
```

Those posts are then served by the ordinary pipeline, with whatever it can make of them. Returning an empty array switches the rule off for every remaining unsupported builder. See [Extending the plugin with filters](/developers/extending-with-filters/).

## What about a single-post template built with a page builder?

Nothing changes. A template that wraps your articles — a Bricks template, an Elementor theme-builder template — is theme-level layout, not post content: the articles themselves are still block or classic content (or, for a genuinely Bricks-built article, rendered on their own terms), and the template's author box, related posts and calls to action are ignored — the same thing the plugin does with every theme, and the reason the Markdown stays clean in the first place.
