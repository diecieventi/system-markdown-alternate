---
title: "The .md endpoint and content negotiation"
description: "Two ways to ask for Markdown — the .md suffix and the Accept header — and the HTTP contract each of them answers with."
sidebar:
  order: 1
---

There are two ways to ask for the Markdown representation of a post. They return the same document, byte for byte, and differ only in how caches and clients treat them.

## 1. The .md suffix

```
https://example.com/my-post.md
```

A URL of its own, so any cache can store it without ambiguity. This is the address to hand out — in documentation, in a link, to a crawler. It ignores the `Accept` header entirely: the URL *is* the request for Markdown.

A trailing slash is redirected (`/my-post.md/` → `/my-post.md`). On sites with plain permalinks the suffix has nowhere to attach, and the plugin uses `?format=markdown` instead.

## 2. Content negotiation on the permalink

The normal permalink returns Markdown when the client explicitly prefers it:

```
curl -H 'Accept: text/markdown' https://example.com/my-post/
```

"Explicitly" is doing real work there. The `Accept` header is parsed with its quality values, and Markdown wins only when it is preferred at least as strongly as HTML. A wildcard (`*/*`) or a missing header means HTML — so ordinary browsers, and tools like `curl` that send `*/*` by default, keep getting the page they expect.

`?format=markdown` on the permalink does the same thing without a header.

Negotiation applies to the canonical single-post URL only. Feeds, embeds, trackbacks, paged comments and multi-page posts are excluded — asking for Markdown on `/my-post/feed/` returns the feed.

## How clients discover it

Every eligible HTML page advertises its Markdown twin twice — in the document head, and as an HTTP header that also answers a `HEAD` request:

```
<link rel="alternate" type="text/markdown" href="https://example.com/my-post.md">

link: <https://example.com/my-post.md>; rel="alternate"; type="text/markdown"
```

## The response headers

| Header | Value |
|---|---|
| `Content-Type` | `text/markdown; charset=utf-8` |
| `X-Robots-Tag` | `noindex, follow` |
| `Link` | the HTML permalink, `rel="canonical"` |
| `ETag` / `Last-Modified` | validators for conditional requests |
| `Cache-Control` | `public, max-age=0, must-revalidate` on `.md` URLs |

The two `noindex`/`canonical` headers together tell search engines exactly one thing: index the HTML page, not this. That is why the plugin creates no SEO risk, and why it deliberately ships no sitemap of `.md` URLs.

## Conditional requests

A client holding a current copy can revalidate instead of re-downloading:

```
curl -sI -H 'If-None-Match: W/"…"' https://example.com/my-post.md
→ HTTP/2 304
```

The validator covers more than the post's modification date: it also folds in the plugin version, your settings and the things that can change a document without touching the post row, such as terms or a featured image. A `304` therefore means the body really would have been identical.

The `ETag` is intentionally **weak** (`W/"…"`). It is computed from metadata rather than from the bytes — which is the entire point of answering without generating the body — and a weak tag is exactly the honest claim to make about it. Nothing is lost: `If-None-Match` always uses weak comparison.

Not every host forwards conditional headers to PHP. Some reverse proxies strip them, in which case you will see `200` where a `304` was possible. That is a server configuration property, not a plugin fault.

## Negotiated responses are never cached

When Markdown is served on the shared permalink, the response is sent with `no-store`. Honouring `Vary: Accept` is a per-host property — some page caches key on the URL alone — and a cache that ignores it would happily serve the Markdown variant to the next browser that asks for the page. Refusing storage on that route removes the possibility.

The `.md` URL has no such problem: it is its own cache key, so it stays fully cacheable. One more reason to prefer it when handing out a link. If negotiation on the permalink returns HTML on your host, see [Markdown negotiation returns HTML](/troubleshooting/negotiation-returns-html/).
