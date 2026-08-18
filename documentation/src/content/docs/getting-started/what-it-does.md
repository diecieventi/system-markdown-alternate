---
title: "What System Markdown Alternate does"
description: "Every published post gets a second, machine-readable representation: a clean Markdown document served at the same address with .md appended to the permalink."
sidebar:
  order: 1
---

Every published post on your site has an HTML page. **System Markdown Alternate** gives that same post a second representation: a clean Markdown document, served at the same address with `.md` appended to the permalink.

```
https://example.com/my-post/      → HTML, for people
https://example.com/my-post.md    → Markdown, for machines
```

Nothing about the HTML page changes. The Markdown is an additional representation of the same content, generated on request and [cached](/settings/cache-ttl/), so there is no second copy of your articles to keep in sync.

## What the Markdown looks like

A YAML front matter block carrying the metadata, then the title as an H1, then the converted body:

```
---
title: "How to lazy load images"
url: "https://example.com/lazy-load-images/"
markdown_url: "https://example.com/lazy-load-images.md"
date_published: "2026-03-14T09:00:00+01:00"
date_modified: "2026-08-02T17:22:00+02:00"
author: "Jane Doe"
categories:
  - "Guides"
description: "Why lazy loading matters and how to switch it on."
---

# How to lazy load images

The body follows here, converted to clean Markdown.
```

The keys are emitted in a fixed order and the format is a stable contract — see [The .md endpoint](/endpoints/the-md-endpoint/) for the full picture.

## Who reads it

- **AI assistants fetching a page on a reader's behalf.** When someone asks an assistant about one of your articles, the assistant fetches the URL. Given the choice, it gets the text instead of a page of navigation, cookie banners and related-post widgets.
- **Agents and scripts** that need your content as text without writing a scraper for your theme.
- **Retrieval pipelines** building an index over your site, where clean structure matters more than styling.
- **Readers**, when you add a [copy or download control](/shortcodes/md-actions/) to your template.

## How the Markdown is produced

The plugin does not scrape the rendered page. It renders your *blocks* and converts those, which is why the result is clean by construction rather than by cleanup:

- Content injected into `the_content` by other plugins — related posts, calls to action, share buttons — never enters the pipeline, because that filter is skipped.
- Navigation blocks, forms and anything you list under the exclusion settings are removed. See [Excluding content from the Markdown](/settings/excluding-content/).
- Relative links and images are resolved to absolute URLs, so the document still works when it is read somewhere else entirely.
- Code blocks keep their language and their formatting, including code that contains Markdown syntax of its own.
- Embedded videos, tweets and tracks leave a link to what they embed. A player is markup a text document cannot carry, so what survives is the address — the part a reader or an assistant can actually follow. Captions keep their own line underneath, and an embed that shows real text of its own (a quoted post, for instance) keeps that text as well as the link.
- Link cards arrive with their name attached. Many card and link-preview plugins make the whole card clickable by laying an invisible link over it, with the title sitting in a separate element underneath — which used to convert to a link with no text at all, and a title floating a paragraph away from it. The link now takes the name the card already declares, so what it points at is readable again.

## What it is not

**It is not an SEO plugin.** Markdown responses are sent with `X-Robots-Tag: noindex, follow` and a canonical `Link` header pointing back at the HTML page, so search engines are told plainly which URL is the one that counts. The plugin adds no sitemap for the `.md` URLs, for the same reason: a sitemap listing `noindex` URLs sends search engines a contradiction.

**It is not a static export.** There is no build step and no second copy of your content in the database. Edit a post and its `.md` follows on the next request.

## Next

- [Serving your first Markdown file](/getting-started/serving-your-first-markdown-file/)
- [Enabled content types](/settings/enabled-content-types/) — the one setting the plugin cannot work without
