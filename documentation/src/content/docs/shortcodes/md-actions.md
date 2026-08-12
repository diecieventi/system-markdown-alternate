---
title: "[sysmda_md_actions] — copy, view, download"
description: "A split button that copies the Markdown document to the clipboard, with view and download in a dropdown."
sidebar:
  order: 3
---

A GitHub-style split button for readers. The main action copies the complete Markdown document to the clipboard; the dropdown repeats copy and adds *view in a new tab* and *download*.

```
[sysmda_md_actions]
[sysmda_md_actions id="123"]
```

## Attributes

| Attribute | Default | Meaning |
|---|---|---|
| `id` | current post | The post the actions apply to |

## What "copy" copies

The whole document as it is served — front matter, title and body — fetched from the `.md` URL at the moment of the click, so it is never a stale copy of what was on the page.

If the response does not come back as `text/markdown`, the copy is **refused** rather than pasting whatever arrived. That matters on hosts where a cache might answer with the HTML page: better an action that fails visibly than a reader who thinks they copied Markdown and pasted a page of markup.

## Where the assets load

The stylesheet and script load **only on pages where this shortcode actually renders**. A site that uses it on ten articles pays for it on those ten, and nowhere else — no theme-wide asset, no request on pages that do not use it.

This works whether the shortcode sits in the post content, in a template, in a widget or in a secondary loop.

## The dropdown escapes your layout

The menu is moved to the end of the document when it opens and positioned against the viewport, flipping left/right and above/below as needed and staying clear of the screen edges.

This is not decoration. A dropdown rendered inside a narrow article column gets clipped by the theme's `overflow` rules, and on a phone it is the difference between a working menu and one you cannot see. Positioning it against the viewport removes the theme from the equation.

## Styling

Minimal white-and-bordered by default, with namespaced classes so a theme can restyle it. There is no settings UI for the appearance and no filters for the items or labels — the actions are fixed at three, and the scope is fixed on purpose.

An earlier version of this plugin shipped a configurable, automatically-inserted Markdown button. It broke layouts on mobile, loaded assets for every visitor including the majority who never used it, and each round of feedback bought another round of CSS fighting an unknown theme. It was removed. This shortcode is the narrow version that survived: explicit placement, three actions, assets only where used.

If you want something else entirely, [`[sysmda_md_url]`](./md-url.md) gives you the URL and your theme does the rest.

## Requirements

Copying to the clipboard requires JavaScript and a secure context (HTTPS). On a site served over plain HTTP the copy action will not work — the view and download actions still will.

## When it renders nothing

If the post has no Markdown version, the shortcode outputs nothing at all, and no assets are loaded for it.
