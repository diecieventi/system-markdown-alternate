---
title: "Serving your first Markdown file"
description: "Install the plugin, enable one content type and confirm the .md URL is answering. Three steps, about two minutes."
sidebar:
  order: 2
---

The plugin ships **inactive**: until you enable at least one content type, no `.md` URL answers anything. That is deliberate — nothing about your site changes until you say so. Here is the whole setup.

## 1. Install and activate

Install from the WordPress plugin directory, or upload the zip under **Plugins → Add New → Upload Plugin**. The package bundles its one dependency, so nothing else is required on the server.

Requirements: WordPress 6.1 or newer, PHP 7.4 or newer.

## 2. Enable a content type

Go to **Settings → Markdown Alternate**. On the **General** tab, tick the content types that should expose a Markdown version — *Posts* is the usual starting point — and save.

Every public content type on your site is listed, custom post types included. Nothing else on the page needs touching: the defaults are chosen to be safe on a normal site.

## 3. Check it

Open any published post, then add `.md` to its permalink:

```
https://example.com/my-post/   →   https://example.com/my-post.md
```

You should see the front matter block, the title as an H1 and the article body as Markdown. From the command line, the headers tell the same story:

```
curl -sI https://example.com/my-post.md

content-type: text/markdown; charset=utf-8
x-robots-tag: noindex, follow
link: <https://example.com/my-post/>; rel="canonical"
cache-control: public, max-age=0, must-revalidate
etag: W/"…"
```

If a firewall or CDN sits in front of the site, some block command-line user agents outright. A redirect to a block page instead of Markdown is not a plugin problem — see [Nothing is served at the .md URL](/troubleshooting/nothing-served-at-the-md-url/).

## What you get automatically

With that one setting saved, three things are already in place:

- **Discovery.** Each HTML page now advertises its Markdown twin, both in the document head and as an HTTP `Link` header, so a client can find it without guessing the URL.
- **Content negotiation.** A client that explicitly asks for `text/markdown` on the normal permalink gets Markdown, without needing the `.md` suffix at all.
- **An index.** `/llms.txt` lists the content you just enabled, so an agent can discover the whole site from one file. See [The /llms.txt index](/endpoints/the-llms-txt-index/).

## A note on permalinks

The `.md` suffix needs pretty permalinks. If your site still uses plain permalinks (`?p=123`), there is nowhere to put the suffix, so Markdown URLs fall back to `?format=markdown` and everything else keeps working. The settings page tells you when this applies. Switching to a pretty permalink structure under **Settings → Permalinks** is the better fix.

## Next

- [Excluding content from the Markdown](/settings/excluding-content/) — forms, tables of contents and other page furniture
- [The .md endpoint and content negotiation](/endpoints/the-md-endpoint/)
