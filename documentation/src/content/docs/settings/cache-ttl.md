---
title: "Cache TTL"
description: "How long a generated Markdown document is kept before it is rebuilt, and why the value matters less than you would expect."
sidebar:
  order: 3
---

**Settings → Markdown Alternate → General**

How long a generated Markdown document is kept in the cache before being rebuilt from scratch.

**Default:** `86400` seconds — 24 hours. `0` disables the cache entirely.

## What is cached

The converted document, per post. Building one means rendering the post's blocks, running the DOM passes and converting the result to Markdown, so caching it saves real work on a popular article.

Where it is stored depends on your site: if a persistent object cache is available (Redis, Memcached) the plugin uses it, otherwise it falls back to transients. You do not configure this — it is detected.

## Why the value matters less than it looks

Two things blunt it in practice.

**Edits do not wait for the TTL.** Saving a post clears that post's entry immediately, and saving the settings page clears every entry. The TTL is a ceiling on how long a stale document can survive an event the plugin did *not* see — not a delay between your edit and the published result.

**The conversion is not the slow part.** On a measured 18 KB article the whole conversion stage runs in under 10 ms, against roughly a second of WordPress boot for the same request. Turning the cache off does not make responses feel slow; the boot dominates either way.

So the honest advice is to leave it alone. The default is a reasonable ceiling, and moving it up or down changes very little.

## When to change it

**Set it to `0`** while you are tuning the exclusion lists. Every request then rebuilds, so you see the effect of a change immediately without saving the settings page to force it. Put it back afterwards.

**Raise it** if you have a very large site on a slow host and articles are edited rarely. The gain is modest, for the reason above.

## Conditional requests are not affected

Turning the cache off does not turn off `304` responses. The validator sent with each document is computed from the post's modification date and the plugin's settings, not from the cached body, so a client holding a current copy still revalidates cheaply even with the cache disabled.

That is the part worth keeping: `ETag` handling and the body cache are independent, and the first one is where most of the bandwidth saving lives.

## From code

Two filters, both taking seconds:

```php
// The per-post Markdown documents.
add_filter( 'sysmda_markdown_cache_ttl', function ( int $ttl, WP_Post $post ) {
	return 'product' === $post->post_type ? HOUR_IN_SECONDS : $ttl;
}, 10, 2 );

// The /llms.txt index.
add_filter( 'sysmda_llms_txt_cache_ttl', function ( int $ttl ) {
	return 6 * HOUR_IN_SECONDS;
} );
```

Returning `0` or less from the first one disables the body cache for that post.
