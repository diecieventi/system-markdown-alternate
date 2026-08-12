---
title: "GenerateBlocks dynamic tag"
description: "Use {{sysmda_md_url}} anywhere GenerateBlocks accepts a dynamic tag — a button URL, a link, an attribute."
sidebar:
  order: 2
---

**Settings → Markdown Alternate → Integrations** (informational; nothing to configure)

When GenerateBlocks 2.x is active, the plugin registers a dynamic tag:

```
{{sysmda_md_url}}
```

It resolves to the current post's `.md` URL, and can go anywhere GenerateBlocks accepts a dynamic tag — most usefully a button or link URL field.

There is no toggle. The tag registers itself when GenerateBlocks is present and is simply absent when it is not.

## Typical use

Add a Button block, open its link settings, and use the dynamic tag as the URL. The result is a themed button pointing at the Markdown version of whatever post the template is rendering — which is the thing the shortcodes cannot do inside a GenerateBlocks template.

## Posts without a Markdown version

The tag resolves to an **empty string** when the post has no `.md` — an unsupported content type, a draft, a password-protected post, a non-standard post format.

Pair it with GenerateBlocks' **"required to render"** option on the containing element. The element then disappears entirely for those posts instead of rendering a button that goes nowhere.

This is worth setting up even if every post on your site currently qualifies: the first password-protected article you publish is otherwise the first broken button.

## Why a dynamic tag and not just the shortcode

Shortcodes are expanded in content. GenerateBlocks templates work with dynamic data at render time, and its own fields do not run shortcodes — so `[sysmda_md_url]` typed into a URL field would be published literally. The dynamic tag is the same value in the form that context can consume.

Both exist because both contexts exist. In post content, use [`[sysmda_md_url]`](/shortcodes/md-url/); in a GenerateBlocks field, use the tag.

## GeneratePress without GenerateBlocks

The tag comes from GenerateBlocks, not from the theme. On GeneratePress alone it is not available — use the shortcodes, or call the plugin's URL helper from a template hook.
