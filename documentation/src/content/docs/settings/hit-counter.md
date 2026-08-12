---
title: "Hit counter"
description: "Counts how often the Markdown endpoint is used, split bot versus human. Aggregate daily totals only — no visitor data of any kind."
sidebar:
  order: 7
---

**Settings → Markdown Alternate → Advanced**

Counts how many times the Markdown endpoint is served, split between bots and humans.

**Default:** off.

## Why you might want it

There is no way to know whether anyone is fetching your Markdown by looking at ordinary analytics: those run on JavaScript in a browser, and the clients this feature exists for do not execute JavaScript. Without a counter, the endpoint is invisible.

It answers exactly one question — *is this being used, and by whom* — which is usually enough to decide whether to invest more in it.

## What is stored

Aggregate daily buckets, and nothing else:

```
2026-08-11 → bot: 143, human: 6
2026-08-10 → bot: 97,  human: 2
```

That is the complete data model. There are **no IP addresses, no user-agent strings, no timestamps finer than the day, and no per-visitor identifier of any kind**. The user agent is read once to decide whether the request looks like a bot, then discarded — it is never written anywhere.

No external service is contacted and no cookie is set.

Count-only is a design constraint rather than a default: there is no setting that turns this into something richer, because the richer version was never built.

What that does **not** mean is that this page can tell you your obligations are met. Classifying a request means reading its user agent, and reading it is processing even though nothing is written; meanwhile your server's access log almost certainly already records the same request with its IP address and full user agent, and that log is outside this plugin's control. Whether your site needs a lawful basis, an entry in its privacy notice, or consent depends on your jurisdiction and on everything else your site does — not on this checkbox. Assess it for your own deployment.

The narrower, plugin-level statement is the one worth having: enabling this adds **no new category of stored data** to your site, and creates no per-visitor record to disclose, export or erase.

Buckets older than 90 days are pruned automatically.

## Reading the numbers

The panel shows totals for today, the last 7 days and the last 30 days, split bot versus human. Days are counted in **UTC**, not your site's timezone, so "today" may not line up with your local day near midnight.

Both `200` and `304` responses are counted — a client that revalidates and is told "not modified" still accessed the document. Both routes count too: the `.md` URL and the negotiated permalink.

## The undercount caveat

**Requests answered by a page cache or CDN never reach PHP, and so are never counted.** On a site with edge caching in front of it, the real numbers are higher than what you see, sometimes by a lot.

Treat the figures as an indicator of direction — *is this growing, is anyone here at all* — not as analytics. Comparing this month to last month is meaningful; the absolute total is a floor, not a measurement.

## How bots are told apart

By matching the user agent against a list of tokens covering crawlers, HTTP clients and command-line tools, headless browsers, and AI agents. An empty user agent counts as a bot.

It is a heuristic and it will not be perfect, but the split it produces is stable enough to be useful — and the interesting signal is usually the ratio moving over time, not any single classification.

```php
add_filter( 'sysmda_md_hits_bot_patterns', function ( array $patterns ) {
	$patterns[] = 'acme-internal-monitor';
	return $patterns;
} );
```

Retention can be changed the same way, with `sysmda_md_hits_retention_days`.

## Turning it off

Unticking the box stops counting; the buckets already collected are kept, so you can turn it back on without losing history. Uninstalling the plugin removes them.
