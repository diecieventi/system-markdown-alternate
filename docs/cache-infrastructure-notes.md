# Cache infrastructure notes

Operational notes for the `.md` response policy and the cache layers that may
sit between WordPress and a client. This is not an implementation plan. The
current HTTP contract lives in [`output-format.md`](output-format.md); the filter
surface lives in [`filters.md`](filters.md).

## Current policy

A dedicated `.md` URL sends:

```http
Cache-Control: public, max-age=0, must-revalidate
ETag: W/"…"
Last-Modified: …
```

The representation may be stored, but it must not be reused without
revalidation. Negotiated Markdown and `406` responses remain private and
`no-store` because some page caches key only by URL and ignore `Vary: Accept`.
The weak `ETag` comparison also tolerates the `-gzip` and `-br` suffixes Apache
may add inside an entity tag.

## Layer matrix

Only LiteSpeed, a browser, and the reference nginx/RunCloud host were measured
for this project in July 2026. Other rows describe standard or documented
behaviour; local configuration can change it.

| Layer | Expected handling | Project-specific note |
|---|---|---|
| Browser | Stores the body, revalidates, and can receive `304` | This is the main benefit of allowing storage with `max-age=0` |
| Apache | Preserves ETags but may add `-gzip`/`-br` when compressing | The plugin normalizes those suffixes during weak comparison |
| nginx FastCGI/proxy cache | Configuration-dependent; may store stale entries or decline to store a zero-TTL response | The reference host declines to store it and strips conditional headers before PHP |
| LiteSpeed/LSCache | Some installations key by URL and ignore `Vary: Accept` | Negotiated responses send no-cache signals; the optional `.htaccess` rule bypasses the cache for Markdown negotiation |
| Varnish | A zero TTL normally behaves as pass unless VCL grants keep time | `max-age=0` removes the heuristic freshness window that existed without an explicit policy |
| Cloudflare/CDN | `.md` commonly needs an explicit cache rule | Cloudflare may weaken a strong ETag; the plugin already emits a weak tag |
| WordPress page-cache plugin | Varies, and generally bypasses PHP on a hit | Negotiated responses are `no-store`; `.md` has its own URL |

The invariant is that no layer may serve a stored `.md` without checking whether
it is current. A host configured to ignore origin `Cache-Control` and impose its
own TTL defeats that invariant; no additional response header can repair such a
configuration.

## Reference-host measurement

The production measurement used RunCloud/nginx behind Cloudflare. With the
default policy:

- every `.md` request reported `x-runcache-status: MISS`;
- PHP ran for every request;
- `If-None-Match`, including `*`, never reached WordPress, so the plugin could
  not return `304`;
- Cloudflare reported `cf-cache-status: DYNAMIC` because `.md` was not included
  in an explicit edge cache rule.

This is a property of that host configuration, not nginx in general. nginx can
honour upstream `Cache-Control`, ignore it, store an expired response, or decline
to store it depending on the FastCGI/proxy cache directives. In particular,
`proxy_cache_revalidate on` only changes how an already-expired cached response
is refreshed; it does not override an origin `max-age=0` policy.

### Control experiment

On the same host, a temporary site filter added a shared-cache lifetime:

```php
add_filter(
    'sysmda_cache_control',
    fn() => 'public, max-age=0, s-maxage=600, must-revalidate'
);
```

With nothing else changed, the same URL began reporting
`x-runcache-status: HIT`, and PHP left the request path. nginx emitted no `Age`
header, so `x-runcache-status` was the reliable signal on this stack.
Cloudflare remained `DYNAMIC`, confirming that the origin cache and CDN cache
were separate decisions.

The experiment established that the cache was capable of storing `.md`; the
zero shared-cache lifetime was why it did not reuse the response.

## Why the default remains zero

A positive shared-cache lifetime does not accelerate a one-pass crawl: every URL
is still a first-time miss and boots WordPress. It helps re-crawls, concurrent
requests for the same URL, and ordinary repeat traffic. Its cost is staleness:
page-cache plugins generally purge the HTML permalink and do not know that a
second `permalink.md` cache key exists.

Keeping `max-age=0, must-revalidate` therefore chooses correctness by default.
A site may opt into a lifetime through `sysmda_cache_control` after accepting its
staleness window.

## Why there is no generic purge integration

Correctly purging stored `.md` variants would require separate integrations for
LSCache, WP Rocket, nginx-helper, Varnish, CDNs, and other cache products. Each
would be partial and some would require credentials. Revalidation provides a
portable correctness rule without that integration surface.

Do not ship host-specific nginx, Varnish, VCL, or CDN configuration as a plugin
fix. Diagnose the actual stack first; if it removes conditional request headers
or ignores origin cache policy, that is a host configuration decision.
