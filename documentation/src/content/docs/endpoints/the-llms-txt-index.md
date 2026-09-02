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

**Off by default.** Turning it on takes over `/llms.txt` on your site immediately, so it is never assumed — you enable it from **Settings → Markdown Alternate → llms.txt**.

Check the status panel first for another plugin that already generates the file. It detects the likely candidates and tells you — including the case that beats every plugin: a physical `llms.txt` file in the site root, which the web server delivers before WordPress ever runs.

The notice is informational only, and it is the reason the endpoint stays off by default rather than on: the plugin cannot reliably tell whether another handler is already serving the URL, so it never takes it over on your behalf. Which handler wins is always your call, made by turning this toggle on yourself.

Once enabled, it also stays silent until you select at least one content type: with nothing to index there is nothing to say, and answering with a bare site name would still take the URL over from anything else that might serve it.

## How agents find the index

While `/llms.txt` is enabled, every page that already advertises its Markdown version also points at the index, in the document head and in the response headers:

```html
<link rel="describedby" href="https://example.com/llms.txt" />
```

```http
Link: <https://example.com/llms.txt>; rel="describedby"
```

`describedby` is the relation added by version 2 of the llms.txt specification. Without it an agent has to already know to try `/llms.txt`; with it, landing on any article is enough to find the index.

There is nothing to configure. The link follows the `/llms.txt` setting: turn the endpoint off and the link goes with it, while the Markdown alternate is unaffected. It appears only where the Markdown alternate already appears, so feeds, embeds and content with no Markdown version never carry it.

One case it deliberately does **not** cover: if you turned `/llms.txt` off because another plugin generates it, pages will not advertise that file. Whether a third party actually serves the URL is not something the plugin can check — it would have to read another plugin's internal settings or fetch the URL over the network, neither of which is reliable — and pointing every page at an index that may not exist is worse than pointing at none.

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
