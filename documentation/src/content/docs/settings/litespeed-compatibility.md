---
title: "LiteSpeed cache compatibility"
description: "An opt-in .htaccess rule that stops a LiteSpeed page cache from answering Markdown requests with the HTML page."
sidebar:
  order: 6
---

**Settings → Markdown Alternate → Advanced**

Adds a small block to your site's `.htaccess` so requests that negotiate Markdown bypass the LiteSpeed page cache.

**Default:** off. **Recommended on LiteSpeed hosts**, and harmless everywhere else.

## The problem it solves

A post's permalink can return two different things — HTML or Markdown — depending on the `Accept` header the client sends. Telling caches about that is what the `Vary: Accept` header is for, and the plugin always sends it.

Whether a cache *honours* it is another matter. Some LiteSpeed configurations key their page cache on the URL alone and ignore `Vary` entirely. When that happens the cache already holds the HTML for that URL and answers from it, PHP never runs, and a client that asked for Markdown gets the HTML page.

Whether your particular host behaves this way cannot be detected from inside WordPress — it depends on the server configuration, not on anything the plugin can read. That is why this is a checkbox rather than something automatic, and why "enable it if unsure" is the safe advice: on a host that already honours `Vary`, the rule is simply redundant.

## What the rule does

Requests whose `Accept` header mentions `text/markdown` skip the LiteSpeed cache, so PHP always decides which representation to send. Everything else is untouched: ordinary browser traffic stays fully cached, which is the whole reason you have the cache.

The block is wrapped in `<IfModule LiteSpeed>`, so on Apache, nginx or anything else it is inert. Enabling it on a non-LiteSpeed host costs nothing and does nothing.

It is written at the **top** of `.htaccess`, before WordPress's own block — WordPress's rules end the rewrite pass, so a block appended at the bottom would never be evaluated at all.

## After enabling

Purge the LiteSpeed cache. Entries stored before the rule existed do not know about it, and stale entries are exactly the symptom you are trying to fix.

If `.htaccess` is not writable, the panel shows the block so you can paste it in yourself. Turning the setting off removes it again, and so does uninstalling the plugin.

## What it does not do, and does not need to

It does not protect against Markdown being served *to* a browser. That cannot happen regardless of this setting: negotiated Markdown responses are sent with `no-store`, so no cache keeps a copy to hand out later. That protection is unconditional and works on every server.

This rule is about the other direction only — making negotiation *work* on a host whose cache would otherwise answer first. Safety is already handled; this is about function.

## If you are not on LiteSpeed

Nothing here applies, but the underlying situation can. Behind a CDN, either add `Accept` to the cache key for your post URLs, or add a rule bypassing the cache when `Accept` contains `text/markdown`.

And the answer that works everywhere without configuration: **use the `.md` URL.** It is its own cache key, so no cache can confuse it with the HTML page.

See [Markdown negotiation returns HTML](/troubleshooting/negotiation-returns-html/) for how to confirm what your host is actually doing.
