---
title: "The /llms.txt index"
description: "A single file at the site root listing your Markdown content, so an agent can discover the whole site without crawling it."
sidebar:
  order: 2
---

**Settings → Markdown Alternate → llms.txt**

`/llms.txt` is a plain-text index at the root of your site listing the content that has a Markdown version. It is to an agent what a sitemap is to a search engine — one file, one request, the whole map.

```
# Example Site

> Tagline of the site

## Posts

- [How to lazy load images](https://example.com/lazy-load-images.md)
- [Choosing an image format](https://example.com/image-formats.md)

## Pages

- [About](https://example.com/about.md)
```

Entries point at the `.md` URLs, not the HTML pages, so a client that follows one lands directly on the machine-readable representation.

## Enable /llms.txt

**On by default.** It stays silent until you enable at least one content type, though: with nothing to index there is nothing to say, and answering with a bare site name would take the URL over from anything else that might serve it.

Turn it off if another plugin on your site already generates the file. The panel detects the likely candidates and tells you — including the case that beats every plugin: a physical `llms.txt` file in the site root, which the web server delivers before WordPress ever runs.

The notice is informational. The plugin never disables itself on the strength of a guess about another plugin's configuration; which handler wins is your call.

## Enriched output

**Off by default.** Switching it on adds, in order:

- a **site summary** paragraph, from the field below the toggle;
- a **Key content** section listing posts you nominate by ID or URL, before the automatic sections;
- a **one-line description** per entry, taken from the SEO description, then the excerpt, then the trimmed opening text;
- an **`Optional`** section holding everything beyond the most recent posts — a keyword the llms.txt convention reserves for content a client may skip when working to a budget.

Leave it off and the output is the plain list above, unchanged.

## Last modified dates

**Off by default.** Appends `(updated: 2026-08-11)` to every entry, so a crawler can spot what changed without re-fetching each URL. It works with both the plain and the enriched output, and the date sits in the free-text part of the line, which keeps the file valid against the convention.

## What is never listed

The index and the `.md` endpoint apply the same eligibility rules, so the file can never advertise a URL that answers 404. Password-protected posts, drafts, non-standard post formats and disabled content types are all absent. See [Enabled content types](/settings/enabled-content-types/).

## Caching

The file is built once and cached; publishing or editing a post clears it. It answers conditional requests with a strong `ETag` and a `304`, because unlike the `.md` endpoint the body already exists before the response is written, so hashing the actual bytes is free.

There is no `Last-Modified`: an index of many posts has no single modification date to report honestly.
