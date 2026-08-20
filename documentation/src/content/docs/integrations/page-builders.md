---
title: "Page builders"
description: "Posts built with Bricks, Elementor, Divi, WPBakery, Oxygen, Beaver Builder or Breakdance have no Markdown version — and why a 404 is the honest answer."
sidebar:
  order: 3
---

Page builders store their content somewhere other than the WordPress post content — in post meta, or in the content field as their own layout shortcodes. The plugin builds Markdown from the post content, so a post built with a builder has nothing to convert.

**A post rendered by a page builder therefore has no Markdown version at all.** Its `.md` URL returns 404, its HTML page advertises no Markdown `alternate` link, it is absent from `/llms.txt`, and the shortcodes and the dynamic tag render nothing for it.

## Which builders

| Builder | Status |
|---|---|
| Bricks | No Markdown version — support is planned |
| Elementor | No Markdown version — support is possible but not planned |
| Divi | No Markdown version, permanently |
| WPBakery Page Builder | No Markdown version, permanently |
| Oxygen | No Markdown version, permanently |
| Beaver Builder | No Markdown version, permanently |
| Breakdance | No Markdown version, permanently |

## Why 404 rather than something

Because the alternatives are worse, and both were what happened before this rule existed.

For the builders that keep their content in post meta — Bricks, Elementor, Beaver Builder, Oxygen, Breakdance — the post content is empty, so the `.md` came out as front matter and a bare heading. A document with no document in it.

For Divi and WPBakery, which fill the post content with `[et_pb_section]` and `[vc_row]` wrappers, the `.md` came out full of layout scaffolding converted as prose. That is the worse of the two: an empty file is obviously useless, whereas a file full of builder wrappers looks like content to whatever fetched it, and looks like nothing at all from the WordPress admin.

An assistant or crawler that asks for the Markdown version of such a page is better served by a clear "there isn't one" than by either.

## This is decided post by post

The rule never applies to a content type as a whole, and never to your site as a whole. It is evaluated for each post, from the render mode that builder recorded on that post.

Three consequences, all of them the ones people worry about:

- **Activating a page builder does not affect the posts you did not build with it.** If your pages are Bricks and your 150 articles are in the block editor, all 150 articles keep their Markdown version. This is the normal shape of a builder site, and it is the case the rule is designed around.
- **Switching a post back to the WordPress editor brings its Markdown version back.** Builders such as Bricks and Elementor keep their stored data when you switch a post to render with WordPress. The plugin follows what actually renders, not what is stored, so that post is served again — immediately, with no setting to change.
- **Writing about a page builder is safe.** Nothing is read from the post content, so an article quoting `[et_pb_section]` in a code sample is not mistaken for a Divi page. It keeps its Markdown version like any other article.

The rule also does not care whether the builder plugin is currently active. Deactivating Divi does not restore the Markdown version of a Divi page: the layout shortcodes are still sitting in the content, and with the plugin gone they would be published as raw text — the worst result of the three.

## Seeing which of your content is affected

**Settings → Markdown Alternate → General** shows, beside each content type, what its published posts are actually built with:

```
☑ Post   (post)   — 148 Gutenberg, 2 classic
☐ Page   (page)   — 12 Bricks, 3 Gutenberg
                    ⚠ Bricks content has no Markdown version and is never served.
```

It is informational only — it never changes what is served, and ticking or unticking a type is still entirely your decision. It is there so that "are my articles affected?" takes one look rather than an audit.

The count is refreshed every few minutes, so a page you have just rebuilt may take a moment to move between columns.

## If you want the empty document anyway

A site that would rather publish the front matter than nothing can remove a builder from the list in code:

```
add_filter( 'sysmda_markdown_unsupported_builders', function ( array $builders ) {
	return array_diff( $builders, array( 'bricks' ) );
} );
```

Those posts are then served by the ordinary pipeline, with whatever it can make of them. Returning an empty array switches the rule off for every builder. See [Extending the plugin with filters](/developers/extending-with-filters/).

## What about a Bricks single-post template?

Nothing changes. A Bricks template that wraps your articles is theme-level layout, not post content: the articles themselves are still block or classic content, and they keep their Markdown version. The template's author box, related posts and calls to action are ignored — which is the same thing the plugin does with every theme, and the reason the Markdown is clean in the first place.
