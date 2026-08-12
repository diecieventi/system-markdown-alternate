---
title: "Markdown negotiation returns HTML"
description: "When Accept: text/markdown on the permalink gets you the HTML page, a cache is answering before PHP does. How to confirm it and what to do."
sidebar:
  order: 2
---

The `.md` URL works, but asking for Markdown on the ordinary permalink returns the HTML page:

```
curl -sI -H 'Accept: text/markdown' https://example.com/my-post/
→ content-type: text/html; charset=UTF-8
```

Almost always, this means a page cache answered the request and PHP never ran. Two representations share that one URL, and telling them apart requires honouring `Vary: Accept` — which the plugin sends, and which not every cache respects. Some key on the URL alone.

## Confirm it in three requests

Run these in order against the same post and compare:

```
curl -sI https://example.com/my-post/
curl -sI -H 'Accept: text/markdown' https://example.com/my-post/
curl -sI https://example.com/my-post.md
```

- The first should be `text/html` and carry `vary: accept`.
- The second should be `text/markdown`. If it is HTML, a cache is serving the stored HTML variant.
- The third must always be `text/markdown`. If it is not, this is not a negotiation problem — see [Nothing is served at the .md URL](./nothing-served-at-the-md-url.md).

Look for a cache-status header in the response (`x-litespeed-cache`, `x-cache`, `cf-cache-status`, `x-runcache-status`). A hit on the second request is the confirmation.

## What is and is not at risk

Worth being precise, because the two directions are not equally serious.

**Markdown being served to a browser cannot happen.** Negotiated Markdown responses are sent with `no-store`, so no cache keeps a copy to hand out later. That protection needs no configuration and works on every server.

**The reverse is what you are seeing.** The cache already holds the HTML for that URL and answers from it, so no header the plugin sends can matter — PHP is never reached. The result is a client that asked for Markdown getting HTML: unhelpful, but not harmful.

## Fixes

**Use the .md URL.** It is its own cache key, so it can never be confused with the HTML page, and it works on every host without configuration. If you are publishing a Markdown address for agents to use, this is the one to publish.

**On LiteSpeed**, tick [*LiteSpeed cache compatibility*](../settings/litespeed-compatibility.md) under **Advanced**. It adds a small `.htaccess` block making requests that negotiate Markdown bypass the page cache, so PHP always decides. Normal browser traffic stays fully cached, and on any other server the rules are inert. Purge the LiteSpeed cache afterwards.

Whether a given LiteSpeed host honours `Vary` cannot be detected automatically, so if you are unsure, enabling is the safe choice — on a host that already honours it the rules are simply redundant.

**Behind a CDN**, either add `Accept` to the cache key for your post URLs, or add a rule that bypasses the cache when `Accept` contains `text/markdown`. Note that `.md` is usually not among the extensions a CDN caches by default, so the dedicated URL may reach your origin every time — which is correct, if slower.

## One thing that is not a fault

A client sending `Accept: */*` — which is what `curl` and most HTTP libraries send unless told otherwise — gets HTML, deliberately. A wildcard is not a preference for Markdown, and treating it as one would flip the representation for a large amount of ordinary traffic. Ask for `text/markdown` explicitly, or use the `.md` URL.
