---
title: "Enabled content types"
description: "The single setting the plugin cannot work without: which post types expose a Markdown version. Empty means the plugin stays inactive."
sidebar:
  order: 1
---

**Settings → Markdown Alternate → General**

Selects which content types expose a Markdown version. This is the master switch: with nothing ticked the plugin is inactive, and every other setting on the page is inert.

**Default:** nothing selected.

## What ticking a type does

Every published post of that type, subject to the eligibility rules below, gains:

- a `.md` URL, and content negotiation on its normal permalink;
- an `alternate` link in the page head and in the HTTP response headers;
- an entry in `/llms.txt`;
- working output from the [shortcodes](/shortcodes/md-url/) and the [GenerateBlocks dynamic tag](/integrations/generateblocks-dynamic-tag/).

## Which types appear in the list

All **public** content types registered on the site, custom post types included. Two exceptions:

- **Media** (`attachment`) is never offered. Attachment pages have no editorial body worth a document representation.
- **Non-public types are not offered**, and a saved type that later stops being public quietly stops being served — without being removed from your selection. If the plugin that registers it is deactivated for an afternoon, your choice is still there when it comes back.

A type registered by a plugin that is currently inactive is shown as *not registered right now* rather than dropped.

## Which posts actually get a .md

Enabling a type is necessary but not sufficient. A post is served only when all of the following hold:

| Rule | Why |
|---|---|
| Status is `publish` | Drafts, pending and private posts have no public representation. |
| No password set | Protected content never has a Markdown version — not even for a visitor who has already entered the password. The rule is about the content, not the visitor. |
| Standard post format | Aside, status, quote, link, image, video, audio, gallery and chat are skipped: short, often untitled snippets. The *absence* of a format — which is almost all content — is unaffected. |
| Not built with a page builder | A post rendered by Bricks, Elementor, Divi, WPBakery, Oxygen, Beaver Builder or Breakdance keeps its content outside the WordPress post content, so there is nothing to convert. Decided post by post — see [Page builders](/integrations/page-builders/). |

A post failing any of these returns **404** on its `.md` URL, is absent from `/llms.txt`, gets no `alternate` link, and makes the shortcodes render nothing at all — so nothing on your site ever links to a Markdown URL that does not exist.

## Changing the selection later

Safe at any time. Unticking a type stops serving it immediately; its URLs return 404 and its entries leave `/llms.txt`. Saving the settings page invalidates the Markdown cache site-wide, so the change is visible at once.

## From code

The saved selection is passed through a filter, so a site can add types the panel will not offer — including non-public ones, which is treated as a deliberate request:

```
add_filter( 'sysmda_markdown_supported_post_types', function ( array $types ) {
	$types[] = 'documentation';
	return $types;
} );
```

To exclude individual posts rather than whole types, use `sysmda_post_is_servable` — see [Extending the plugin with filters](/developers/extending-with-filters/).
