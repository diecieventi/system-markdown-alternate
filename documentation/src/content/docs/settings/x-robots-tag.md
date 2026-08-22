---
title: "X-Robots-Tag"
description: "The header that keeps Markdown responses out of search results, and why changing it is almost always the wrong move."
sidebar:
  order: 6
---

**Settings → Markdown Alternate → Advanced**

The `X-Robots-Tag` header sent with every Markdown response.

**Default:** `noindex, follow`. Leaving the field empty sends no header at all.

## What it does

`noindex` tells search engines not to list the Markdown URL in their results. `follow` tells them the links inside it are still worth following.

It works together with the other header every Markdown response carries:

```
x-robots-tag: noindex, follow
link: <https://example.com/my-post/>; rel="canonical"
```

Between them, a crawler is told two unambiguous things: do not index this URL, and the real address of this content is the HTML page. That is what lets the plugin add a second representation of every article without creating a duplicate-content problem.

## Why you should almost certainly leave it

The obvious temptation is to remove `noindex` so the Markdown gets indexed too. It does not work the way it sounds:

- You would be asking search engines to index two URLs with identical content. The best case is that they pick one and ignore the other; the worse case is that they pick the one you did not want.
- Search Console reports the mismatch as an error when a sitemap and a `noindex` disagree, which is why the plugin ships no sitemap for `.md` URLs either.
- It buys nothing. Search engines rank the HTML page; the Markdown exists for clients that fetch a specific URL on purpose.

`noindex` is not a limitation of the feature. It is the thing that makes the feature safe to switch on.

## When changing it is legitimate

**An internal or staging site** where you want `noindex, nofollow` on everything.

**A site behind authentication** that no crawler reaches anyway, where you may prefer to drop the header entirely to keep responses minimal.

Both are deliberate, narrow cases. If you are changing this to get more traffic, the answer is no.

## From code

```php
add_filter( 'sysmda_markdown_robots_header', function ( string $value ) {
	return 'noindex, nofollow';
} );
```

Returning an empty string suppresses the header, exactly like clearing the field in the panel.
