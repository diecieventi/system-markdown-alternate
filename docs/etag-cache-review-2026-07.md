# ETag, cache and response-validation review — July 2026

> **Status: triaged against the shipped code; F1–F3 fixed in `0.28.0`, F4 and F8
> in `0.29.0` after a production measurement.** The
> brief was an external checklist ("verify that the architecture does not give
> ETags an excessive or unreliable role"), not a bug report, so every point was
> re-derived from the code before being accepted or rejected. Several of its
> assumptions do not hold for this plugin and are answered below rather than
> silently dropped.
>
> | # | Finding | Outcome |
> |---|---|---|
> | F1 | The `ETag` was strong, and could not be | **Fixed in 0.28.0** — now `W/"…"` |
> | F2 | Apache's compression suffix silently kills `304` | **Fixed in 0.28.0** |
> | F3 | Three site-wide output inputs never moved the validator | **Fixed in 0.28.0** |
> | F4 | No `Cache-Control` on `.md` leaves heuristic freshness open | **Fixed in 0.29.0** — and it was worse than reported, see the measurement below |
> | F5 | A later `header()` can overwrite `Vary: Accept` on the HTML branch | **Accepted, not fixed** — safety does not depend on `Vary` |
> | F6 | Preconditions on non-GET/HEAD should be `412`, not `304` | **Not fixed** — unreachable in practice |
> | F7 | `HEAD` renders a body it will not send | **Already triaged** (0.26.3 review, L2) |
> | F8 | `/llms.txt` sends no validators at all | **Implemented in 0.29.0** |

## 1. Current architecture

**Request flow.** `template_redirect` priority 0, two entry points in
`MarkdownController::maybe_render_markdown()`:

1. **`.md` suffix** — `resolve_requested_post()` reads `REQUEST_URI`, redirects
   `/slug.md/` → `/slug.md` (301), resolves the permalink with
   `url_to_postid()`. The `Accept` header is ignored: the URL *is* the request
   for Markdown.
2. **Negotiation on the canonical permalink** — only when
   `is_negotiable_request()` agrees (singular, enabled type, servable, and not a
   feed / embed / trackback / comment page / `<!--nextpage-->` sub-page).
   `AcceptNegotiator` decides with q-values; `?format=markdown` overrides.

Both converge on `serve_markdown()`, so validators, headers and body are
produced in exactly one place.

**Markdown generation.** `build_markdown()` sets up the loop, then front matter
(`MetadataBuilder`) + `# Title` + preamble + body (`ContentRenderer` →
`MarkdownConverter`). Cached under `sysmda_md_{ID}` through the `Cache` helper
(persistent object cache when present, transients otherwise), default TTL one
day, `sysmda_markdown_cache_ttl` = `0` disables it.

**Validator generation.** `cache_version()` = `md5( post_modified_gmt |
SYSMDA_VERSION | settings salt [| taxonomies fingerprint] [| dependencies
fingerprint] )`. The same string is the body-cache validity key **and** the
`ETag`. Both fingerprints return `''` when they have nothing to describe, which
is what keeps the hash byte-identical for plain posts across upgrades.

**Invalidation.** Version hash first (a stale entry is never served, only
ignored), plus proactive deletion on `save_post` / `deleted_post`, plus a global
salt bumped by `AdminSettings` on any plugin option write and — since `0.28.0` —
on the site-wide events in F3. The `ETag` is *not* an invalidation mechanism and
never was.

**Conditional requests.** `handle_conditional()` runs before any body work:
`If-None-Match` first and alone when present (RFC 9110), `If-Modified-Since`
only when `date_is_strong_validator()` confirms `post_modified_gmt` knows about
every input. `send_not_modified()` emits status + `ETag` + `Last-Modified` and
the caller `exit`s.

**`Vary` and cache safety.** `send_vary_header()` appends `Vary: Accept`
(never replaces, and skips if an existing `Vary` already covers `Accept`) on
every negotiable URL, whichever representation wins. Because honouring `Vary` is
a per-host property, safety does not rest on it: negotiated Markdown and `406`
always carry `Cache-Control: no-cache, no-store, must-revalidate, private` plus
the LiteSpeed-specific signals. The `.md` URLs are their own cache key and carry
no `Cache-Control`.

## 2. Findings

### F1 — The `ETag` was strong, and the plugin cannot back a strong tag

**Severity:** Medium. **Where:** `MarkdownController::send_headers()`,
`handle_conditional()`. **Probability:** certain (every response).

A strong entity tag asserts the representation is identical **byte for byte**
(RFC 9110 §8.8.1). This validator is computed from metadata — modification date,
plugin version, settings salt, the two fingerprints — and never from the bytes,
which is deliberate: hashing the body would mean generating it before deciding
whether to send it, giving up the entire benefit of the `304`. The gap is
acknowledged in the code itself: `sysmda_markdown_cache_dependencies` exists
because dynamic blocks, shortcodes and site filters can change the body with
nothing else moving. A validator with a documented escape hatch is by definition
not byte-exact.

The 0.26.3 review raised the same point in passing and correctly noted that
weakening the tag does not *fix* a false `304` — that is what the fingerprints
introduced in `0.27.0` are for. What weakening fixes is the claim.

**Fixed in `0.28.0`:** the tag is `W/"…"`. Nothing is given up — strong
comparison is only required for `If-Match` and `If-Range`, and this endpoint
implements neither (whole-document `GET`/`HEAD`); `If-None-Match` is defined to
use weak comparison in all cases (RFC 9110 §13.1.2). `etag_matches()` now
ignores the `W/` flag on **both** sides, so a client still holding a strong tag
issued by ≤ `0.27.0`, or a tag weakened in transit by an intermediary
(Cloudflare does this on some plans), keeps revalidating instead of
re-downloading.

### F2 — Apache's compression suffix silently disables every `304`

**Severity:** Medium (performance, not correctness). **Where:**
`MarkdownController::etag_matches()`. **Probability:** high on Apache/LiteSpeed
configurations that compress `text/markdown`.

`mod_deflate` rewrites the `ETag` of a compressed response by appending `-gzip`
*inside* the quotes (`DeflateAlterETag AddSuffix` — the default); `mod_brotli`
appends `-br`. The client stores and echoes back what it received, so
`If-None-Match: "abc-gzip"` was compared against `"abc"`, never matched, and
the endpoint re-sent the whole body on every visit. Nothing looks broken from
the outside, which is why it is worth fixing rather than documenting.

**Fixed in `0.28.0`:** a trailing `-gzip` / `-br` is stripped before comparison,
alongside the `W/` flag. Only those two codings, only at the end of the value —
the plugin's own validators are md5 hex and can never end that way.

### F3 — Site-wide inputs of the output that no post save touches

**Severity:** Medium. **Where:** `MetadataBuilder::build_front_matter()` (the
`author:`, `url:` and `markdown_url:` keys), `ContentRenderer` (absolute URLs
resolved against the permalink). **Probability:** low per site, but the
consequence is unbounded.

`0.27.0` closed this class of defect for everything the plugin reads *per post*
(synced patterns, featured image, description, ACF). Three inputs are
site-wide and were missed:

| Input | What it changes | What used to move | 
|---|---|---|
| Author display name (`profile_update`) | the `author:` line of every post by that user | nothing |
| `permalink_structure` | `url:`, `markdown_url:`, every absolute link in the body | nothing |
| `home` (site address) | same | nothing |
| `wp_delete_user()` with reassignment | `author:` of the reassigned posts (core rewrites `post_author` with a direct DB write, so `post_modified_gmt` stays put) | nothing |

The consequence is the one this project treats as unacceptable: a client holding
the old validator is answered `304` **forever**, since no TTL bounds a
conditional response.

**Fixed in `0.28.0`** by bumping the existing global salt from
`update_option_permalink_structure`, `update_option_home`, `deleted_user`, and
`profile_update` **guarded on an actual display-name change** — that hook fires
on every user save, and on a store with customer accounts an unguarded bump
would flush the whole cache routinely.

Folding these into `cache_version()` instead was rejected deliberately: read per
request they would make the dependency fingerprint non-empty for *every* post,
which (a) invalidates the entire site on upgrade and (b) permanently disables
the `If-Modified-Since` path, which `date_is_strong_validator()` switches off
for any post with out-of-post dependencies. A rare event does not deserve a
per-request cost.

### F4 — No `Cache-Control` on `.md` leaves heuristic freshness open

**Severity:** Low–Medium. **Where:** the durable decision "NO freshness
`Cache-Control` on the dedicated `.md` URLs". **Probability:** depends entirely
on the infrastructure. **Not implemented — this needs a ruling.**

The decision rests on "revalidation via `ETag`/`304` never serves stale
Markdown". That holds for a cache that revalidates. It does not hold for a cache
that considers the response *fresh*: with no explicit freshness information, RFC
9111 §4.2.2 explicitly permits a heuristic lifetime, and the common heuristic is
a fraction of the age since `Last-Modified`. For a post last edited a year ago,
10% is over a month. Varnish's stock `default_ttl` (120 s) is the same behaviour
with a small constant.

In practice the exposure is modest — Cloudflare does not cache unknown
extensions by default, LiteSpeed and nginx cache only what they are configured
to cache — but "no header" is not the same as "must revalidate", and the
decision reads as though it were.

The minimal change would be `Cache-Control: public, max-age=0, must-revalidate`
on `.md` responses only: it removes the heuristic window while *keeping* the
response storable and revalidatable, which is strictly closer to the decision's
own stated goal ("never serve an outdated version") than sending nothing. It is
not a freshness lifetime and does not conflict with page-cache plugins in the
way `max-age > 0` would.

It is nevertheless a header the project decided not to send, so it is recorded
here as an explicit decision point rather than applied.

**Outcome: measured in production, and it was worse than written above. Fixed in
`0.29.0` after the maintainer withdrew the decision.**

The finding assumed the `.md` responses were going out with no `Cache-Control`.
They were not. Anonymous `curl` against a live post
(`webdietrolequinte.it/…-acf.md`, 26 July 2026):

```
cache-control: no-cache, must-revalidate, max-age=0, no-store, private
expires: Wed, 11 Jan 1984 05:00:00 GMT
etag: W/"e350aa502dfd8851fb627f130be2a31a"
x-runcache-status: MISS
```

That `Cache-Control` is not the plugin's — the plugin's own string, visible on
the negotiated route in the same session, is `no-cache, no-store,
must-revalidate, private`, with no `max-age=0` and no `Expires`. It is
`wp_get_nocache_headers()` verbatim: this route resolves as an error inside
WordPress, so `WP::send_headers()` had already sent it, and the plugin — by
never touching the header — inherited it. **A policy of omission cannot be
implemented by omission.**

The consequence is the opposite of the heuristic-freshness worry, and worse:
`no-store` forbids any cache, browsers included, from keeping a copy. So no
client ever revalidates, `x-runcache-status` is permanently `MISS`, every single
hit pays for a full render, and the entire `ETag`/`304` mechanism built in
`0.18.0` and refined in `0.27.0`/`0.28.0` was inert. Confirmed by measurement:
`If-None-Match` in weak form, in strong form, with a `-gzip` suffix, and
`If-Modified-Since` — four requests, four `200`s, on `.md`, on `.md?query` with
the page cache in `BYPASS`, on the negotiated route and on `?format=markdown`.

A second, secondary defect surfaced while testing this: those manual
conditional headers never reached PHP at all (the plugin answered with a freshly
generated `ETag`, which only happens when `handle_conditional()` saw no match).
Cloudflare answers `304` for static assets but from its own cache, so it does
not prove forwarding to origin; the likelier culprit is nginx, which strips
client conditional headers from the upstream request when caching is configured
for the location — `BYPASS` included. That is infrastructure, not plugin, and it
resolves itself either way once the response is storable: whoever stores it
answers the revalidation.

The fix is the candidate above, applied to the `.md` route and to `/llms.txt`,
with `sysmda_cache_control` as the override. See the replacement decision in
`AGENTS.md`.

**Post-deploy measurement, same host.** The headers came out exactly as
designed (`public, max-age=0, must-revalidate`, no `Expires`, negotiated route
still `no-store`) and **still no `304`**. The prediction in the paragraph above
— that the `304` would come back once the response was storable, delivered by
nginx if not by PHP — was wrong, and for an instructive reason: `max-age=0`
gives nginx nothing worth storing, so `x-runcache-status` stays `MISS`.

The remaining cause was then isolated. `If-None-Match: *` also answers `200`,
and that wildcard makes `etag_matches()` return true without comparing
anything, so PHP demonstrably never sees the header. Tested against the origin
directly (`--resolve`, `server: nginx-rc`, Cloudflare out of the path): still
`200`. So it is **nginx**, not the CDN — it strips conditional headers from the
upstream request wherever caching is configured for the location, `BYPASS`
included.

Closed without action, deliberately. The fix would be host-specific nginx
configuration, and the prize is the ~12 KB body, not the ~1 s of WordPress boot
that dominates the response (TTFB ~1.0–1.2 s on `.md` against ~0.4 s on a
page-cache hit of the same article as HTML). **The conditional-request path is
worth bandwidth, not time** — a good thing to have where the infrastructure
allows it, never the answer to a slow origin. The plugin sends a standard
header that is correct everywhere and needs tuning nowhere; a stack that
forwards conditional headers gets its `304`s for free.

One incidental confirmation: `/llms.txt` emits a strong `ETag` and it arrives
at the client as `W/"…"`. Cloudflare weakens strong tags in transit, exactly as
the `0.28.0` decision assumed — and the symmetric comparison in
`etag_matches()` is what keeps that round trip viable.

### F5 — `Vary: Accept` can be overwritten downstream on the HTML branch

**Severity:** Low. **Where:** `MarkdownController::send_vary_header()`.
**Accepted, not fixed.**

`Vary: Accept` is sent at `template_redirect`. On the Markdown branch the
request `exit`s immediately, so nothing can touch it. On the HTML branch
WordPress goes on rendering, and a theme or plugin calling
`header( 'Vary: User-Agent' )` (replace defaults to true) would drop ours. The
failure mode is a cache serving HTML to a Markdown-preferring client — an
annoyance, never a leak, and the reverse direction is prevented by the no-cache
invariant rather than by `Vary`. That is exactly the property the review asks
for ("do not build correctness around `Vary`"), so no defensive re-assertion was
added.

### F6 — Preconditions on methods other than GET/HEAD

**Severity:** Low (informational). **Not fixed.**

RFC 9110 §13.2.2 says a matching `If-None-Match` on a method other than
`GET`/`HEAD` must produce `412 Precondition Failed`, not `304`. A `POST` to a
`.md` URL carrying `If-None-Match` would currently get a `304`. It requires a
client doing something no client does, against an endpoint that is a read-only
document; adding a method branch to serve it would be anticipation, which this
project deliberately avoids.

### F7 — `HEAD` generates a body it will not send

Already found and ruled on in the 0.26.3 review (L2): real, bounded (usually a
cache hit), revisit if the hit counter ever shows meaningful `HEAD` volume. No
new information here.

### F8 — `/llms.txt` sends no validators

**Severity:** Low. **Where:** `LlmsTxtController::render()`. **Implemented in
`0.29.0`**, once F4 turned the caching contract into a decision being taken
anyway — leaving the other public endpoint out of it would have been arbitrary.

The index is served from a versioned server-side cache but carries no `ETag`,
no `Last-Modified` and no `Cache-Control`, so every poll transfers the whole
file. It is the largest single response the plugin produces and the one most
likely to be fetched on a schedule.

If it is ever added, the validator **must be `md5()` of the body about to be
sent**, not the internal `cache_version()`: the latter deliberately does not
cover the listed posts (a new post is picked up by deleting the cache entry, not
by moving the version), so using it as an `ETag` would recreate the exact defect
`0.27.0` fixed — a `304` with a stale index. Hashing the body is free-ish here,
precisely because the body already exists before the response is written; that
is also the one place a *strong* tag would be justified.

That is exactly how it was built: `LlmsTxtController::body_etag()` hashes the
bytes, `handle_conditional()` answers `If-None-Match` with `304`, and the
response carries the same `Cache-Control` as a `.md`. No `Last-Modified`: the
index has no single modification date, and inventing one would be a validator
that lies, so `If-Modified-Since` is not honoured either.

### Verified as already correct

Checked against the code and found sound; listed so they are not re-reviewed:

- A `304` really does skip generation — `handle_conditional()` runs before
  `get_markdown()`, and the plugin exits on the spot. What it does *not* skip is
  the WordPress bootstrap and `cache_version()` itself (a `parse_blocks()` pass
  plus a few meta reads). That is inherent to being a plugin.
- `If-None-Match` parsing: wildcard, comma-separated lists, surrounding
  whitespace, weak flags. A value that fails to match yields a normal `200`.
- `If-None-Match` takes precedence over `If-Modified-Since`, and when it is
  present but does not match, the date is not consulted at all.
- `Last-Modified` is `post_modified_gmt`, formatted with `gmdate()`, second
  precision, never in the future for published content, and suppressed for a
  zero date.
- `Vary: Accept` is present on negotiated `304`s: it is emitted before
  `serve_markdown()`, together with the no-cache header, so both conditional and
  full responses carry it.
- Multisite needs no special handling: the object-cache group is not global and
  transients live in the per-site options table, so `sysmda_md_61` on two blogs
  are two different entries. `SYSMDA_VERSION` and the per-site salt are in the
  hash on top of that.
- Password-protected, draft, private, non-enabled and non-standard-format
  content is excluded by `PostSupport::is_servable()` before any cache or
  validator work, on every route at once.
- WPML/Polylang translations are separate posts with separate IDs, so they get
  separate cache entries and separate validators by construction.

### Assumptions in the brief that do not apply here

- *"The ETag may be acting as the invalidation mechanism."* It is not.
  Invalidation is the version hash plus `save_post`/`deleted_post` plus the salt;
  the tag is a validator only.
- *"The ETag may represent the compressed or uncompressed body."* Neither: PHP
  does not compress. Compression and `Vary: Accept-Encoding` belong to the web
  server, which is also why F2 exists at all.
- *"Cache keys should include the representation."* They do, by URL design: the
  `.md` route has its own URL, and the negotiated variant is never stored
  (`no-store`), so HTML and Markdown cannot share an entry.
- *"Prefer `/article.md` as the primary mode."* Already the case. Every
  advertisement of the Markdown representation — `rel="alternate"`,
  `/llms.txt`, the shortcode, the dynamic tag — points at the `.md` URL;
  negotiation exists for clients that ask, and is marked non-cacheable
  precisely because it shares a URL with the HTML.
- *"Responses for authenticated users must not reach a public cache."* The body
  is built from cleaned blocks through `render_block()`, not `the_content`, so
  the membership/personalisation filters that make HTML user-specific do not run.
  A dynamic block could still vary by user; a site in that position should
  declare it through `sysmda_markdown_cache_dependencies` or set
  `sysmda_markdown_cache_ttl` to `0`. Unchanged, and worth stating explicitly.

## 3. Output dependencies

After `0.28.0`. "Moves `Last-Modified`" means the date remains usable as a
validator; where it is *no*, `date_is_strong_validator()` disables the
`If-Modified-Since` path for that post instead of answering with a stale date.

| Dependency | Changes the Markdown | Invalidates the cache | Moves the `ETag` | Moves `Last-Modified` |
|---|---|---|---|---|
| Post content, title, excerpt, dates | yes | yes | yes | yes |
| Categories / tags | yes | yes | yes (fingerprint) | no |
| Selected custom taxonomies | yes (when selected) | yes | yes (fingerprint) | no |
| Synced pattern, at any depth | yes | yes | yes (fingerprint) | no |
| Featured image / alt text | yes | yes | yes (fingerprint) | no |
| Rank Math description | yes | yes | yes (fingerprint) | no |
| ACF fields read by the integration | yes | yes | yes (fingerprint) | no |
| Author display name | yes | yes (salt, `0.28.0`) | yes | no |
| Permalink structure, site address | yes | yes (salt, `0.28.0`) | yes | no |
| User deleted with reassignment | yes | yes (salt, `0.28.0`) | yes | no |
| Plugin settings | yes | yes (salt) | yes | no |
| Plugin update | possibly | yes (`SYSMDA_VERSION`) | yes | no |
| Dynamic blocks, shortcodes, site filters | yes | only via `sysmda_markdown_cache_dependencies` | same | no |
| Post format assignment | changes servability | `save_post` from the editor only | n/a | n/a |
| Site name / tagline | `/llms.txt` only | yes (its own version) | n/a | n/a |

The last two rows are unchanged deliberate decisions, recorded in `AGENTS.md`.

## 4. Infrastructure

LiteSpeed is the only row verified live on this project (two production hosts,
July 2026). The rest is documented behaviour, not measurement, and is listed to
be checked rather than trusted.

| Layer | Keeps the `ETag` | May transform it | Honours `Vary: Accept` | Risk here | Notes |
|---|---|---|---|---|---|
| Apache | yes | **yes** — `-gzip`/`-br` suffix when compressing | yes | none since F2 | `DeflateAlterETag NoChange` also avoids it, server-side |
| Nginx (proxy/FastCGI cache) | yes | no | yes, when caching | low | `proxy_cache_revalidate on` makes `304` useful upstream |
| LiteSpeed / OpenLiteSpeed | yes | no | **often not** — keys by URL | handled | no-cache signals always on for negotiated responses; opt-in `.htaccess` bypass |
| Varnish | yes | no | yes (stock VCL) | none since F4 | stock `default_ttl` 120 s applied while no `Cache-Control` was sent; `max-age=0` now means pass (or revalidate, with `beresp.keep` in VCL) |
| Cloudflare / CDN | usually | **yes** — weakens strong tags on some plans | yes | none since F1/F2 | `.md` is not a default-cached extension |
| WordPress page cache | n/a (bypasses PHP) | n/a | varies | handled | negotiated responses are `no-store`; `.md` has its own URL |

## 4b. What each layer does with `public, max-age=0, must-revalidate`

Added when F4 was applied. The property that matters is the same everywhere —
**no layer may hand out a `.md` without checking first** — but what each one does
with the permission to store differs. Two rows have been measured since: the
browser, and nginx — the latter on the reference host only, which is not the same
as nginx in general (see the correction below). Every other row is read from the
specification, and each layer's own configuration can move it.

| Layer | Stores the body? | Serves it without asking? | Net effect |
|---|---|---|---|
| Browser | yes | no — revalidates with `If-None-Match` | `304`, no body on the wire. The gain the whole design was built for, and it was impossible under `no-store` |
| nginx `fastcgi_cache` / `proxy_cache` | configuration-dependent — **no** on the reference host (measured, see below) | no while it honours the upstream `Cache-Control`; **yes** once configured to ignore it, which is the stale-body case below | in principle it may store and answer `304` itself; `proxy_cache_revalidate on` makes the refresh of an *expired* entry conditional instead of a full refetch. On the one host measured it stores nothing at all, so every request reaches PHP |
| Varnish | no (TTL 0) — unless VCL sets `beresp.keep` | n/a | behaves as a pass, like today, minus the heuristic 120 s window that `no-store` was accidentally protecting against |
| LiteSpeed LSCache | no (it caches only what it is told to) | n/a | unchanged; the negotiated route keeps its own LiteSpeed signals |
| Cloudflare / CDN | only if a Cache Rule opts `.md` in | no, with "respect origin headers" | safe by default (`.md` is not a default-cached extension) |
| WP page-cache plugins | generally no | n/a | unchanged |

The one configuration that reintroduces staleness is a host that strips incoming
cache headers (`fastcgi_ignore_headers Cache-Control`) and applies its own TTL.
There is no header that defends against that; the answer would be a purge
integration, which is deliberately not built — see below.

### Correction to the nginx row, measured after `0.29.0` shipped

The table above was written from the specification. The nginx row predicted that
the cache would store the body and simply refuse to reuse it without asking. On
the reference host (RunCloud/nginx behind Cloudflare) it does neither: it stores
nothing at all, `x-runcache-status` is `MISS` on every `.md` request, and PHP runs
every time. `max-age=0` marks the response stale on arrival, and this cache
declines to keep something it would have to revalidate before every use. The same
disposition is why no `304` is ever produced: it strips conditional headers from
the upstream request wherever caching is configured for the location.

**This is one configuration, not nginx as such** — and the directive that decides
it is more specific than "the cache settings". nginx honours an upstream
`Cache-Control`, and it takes precedence over `proxy_cache_valid`, so adding a TTL
does not by itself override `max-age=0`. What overrides it is
`proxy_ignore_headers Cache-Control` (`fastcgi_ignore_headers` for the FastCGI
variant): after that a lifetime from `proxy_cache_valid` or `X-Accel-Expires`
applies, the entry counts as fresh, and it is served without contacting PHP —
the stale body this policy exists to prevent, and the case named under the table.
`proxy_cache_revalidate on` is orthogonal to both: it only makes the refresh of an
already-expired entry conditional. So another nginx deployment may well store the
body exactly as the table first predicted; read the row as the reference-host
result, and measure before assuming it describes a stack you are diagnosing.

That reading was confirmed by a control experiment on the same host, which is
what makes it a diagnosis rather than a guess. Pointing `sysmda_cache_control` at
a lifetime, from an mu-plugin and with nothing else changed:

```php
add_filter( 'sysmda_cache_control', fn() => 'public, max-age=0, s-maxage=600, must-revalidate' );
```

flipped the very same URL to `x-runcache-status: HIT`, with PHP out of the path.
So the capability was always there and `max-age=0` was the whole reason it went
unused. Two observations from the run, both worth keeping:

- nginx adds **no `Age` header** on a hit, so its absence proves nothing;
  `x-runcache-status` is the signal to read on this stack.
- Cloudflare stayed `cf-cache-status: DYNAMIC` before and after, confirming the
  CDN row exactly: `.md` is not a default-cached extension, and an explicit Cache
  Rule is required to move the hit to the edge.

**None of this argues for changing the default.** What a lifetime buys is
narrower than it first looks: a one-pass crawl of the whole site is unaffected,
because each URL is visited once and every visit is a first-time miss that boots
WordPress regardless. It pays off on re-crawls, on concurrent requests for the
same URL — the realistic way to exhaust PHP-FPM workers — and on ordinary repeat
traffic. Against a single sweep the answer is rate limiting upstream. And the
price is the one the policy exists to avoid: nothing purges a `.md`, so an edited
article keeps serving its old Markdown for up to the lifetime. Correct by
default, faster by explicit per-site choice.

**Why no purge integration.** Making a shared cache hold `.md` copies *and* stay
correct needs the cache to be purged on every edit. Page-cache plugins purge the
permalink and have no idea `permalink.md` exists, so this would mean per-plugin
integrations (LSCWP, WP Rocket, nginx-helper, …), each partial, none covering
Varnish or a CDN without credentials. `max-age=0, must-revalidate` gets the same
correctness with none of that surface, at the cost of one revalidation round
trip — which is exactly what an `ETag` is for.

## 5. What changed, and what did not

**Applied in `0.28.0` (correctness):** F1, F2, F3.

**Applied in `0.29.0`:** F4 — after the production measurement showed the
premise was wrong in the plugin's disfavour, and the maintainer withdrew the
durable decision that blocked it — and F8 alongside it.

**Accepted and deliberately not changed:** F5 (safety does not depend on
`Vary`), F6 (unreachable), F7 (already ruled on).

**Rejected outright:** nothing in the brief was rejected as wrong except the
assumptions listed in §2, which are answered there.

## 6. Tests

Fifteen assertions added to `tests/run-tests.php`, all failing against `0.27.0`:

- the emitted tag is weak (`etag()` through reflection);
- weak comparison in both directions, including a strong client tag against the
  weak resource tag — the upgrade path;
- `-gzip` and `-br` suffixes, alone and inside a list; and the negative cases
  (a suffix in the middle of the value, an unknown coding) so the normalizer
  cannot start eating real validators;
- a full conditional round trip with the weak form of the current validator;
- the salt bumps: no bump for an unchanged display name, for a profile update
  with no usable data, or for an unrelated/excluded option; a bump for a real
  rename; and one bump per request however many triggers fire.

`0.29.0` adds fifteen more: the index's body `ETag` (stability, and that two
different bodies differ), its conditional path (no header → body, match → `304`
actually sent, stale → body, weakened tag → still `304`), and the
`Cache-Control` value (default, a site-imposed `s-maxage`, `''`, a header
injection attempt, a non-string return).

Suite: **330 assertions, 0 failed** on PHP 8.4; PHPCS clean.

Not covered by the pure-logic suite, and worth doing once on staging:

1. `curl -I` a `.md` URL, confirm `ETag: W/"…"`, replay it in `If-None-Match`,
   expect `304`; replay the same value without the `W/` prefix, expect `304` too.
2. Same against an Apache host with compression on for `text/markdown`
   (`curl --compressed`): the browser-supplied `"…-gzip"` must now yield `304`.
3. Rename an author's display name, then re-request an existing `.md` with the
   old validator: expect `200` and the new name.
4. Change the permalink structure on a staging copy: same expectation.
5. Save any user profile without touching the display name and confirm the salt
   did **not** move (`sysmda_cache_salt` in the options table).
6. Confirm the `.md` now answers `Cache-Control: public, max-age=0,
   must-revalidate` with **no** `Expires`, and that `/llms.txt` answers `304`
   for its own `ETag`.
7. Edit the post, re-request immediately with the previous validator: `200` with
   the new body. This is the one that matters — it is the property the whole
   policy exists to guarantee.
