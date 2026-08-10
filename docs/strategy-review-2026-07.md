# Future considerations — Markdown serving as a niche technical product

> Historical strategy note from July 2026, reduced to the reasoning that still
> informs future decisions. The work committed by the original review has
> shipped. The open implementation plans are
> [`llms-txt-multilingual-plan.md`](llms-txt-multilingual-plan.md) and
> [`exclusion-scanner-plan.md`](exclusion-scanner-plan.md); everything below is
> deliberately parked, not planned.
>
> Note on the *Server-side diagnostics* entry below: the exclusion scanner is
> **not** that idea coming back. It renders nothing, previews no post, and
> estimates no sizes — it inventories shortcode tags and block names in the
> source so the exclusion lists can be filled in. The brittle signals listed
> there (`strip_tags()`, `url_to_postid()`, in-process response comparison) are
> among the reasons its design is static and frequency-based, and they stay out.

## Gate for reconsideration

Do not turn these ideas into plans until there is a decisive signal: real,
recurring `.md` traffic from important clients. They are retained so the
reasoning is not lost, not as a backlog.

## Future thoughts

- **Server-side diagnostics** (in-process, no loopback) — a read-only admin view
  of per-post servability, `.md` preview, size/token estimates, stripped or
  unconverted markup, and unresolved internal links. This was removed from the
  active plan because competitors already ship previews and dashboards, while
  the useful differentiator is origin-aware output. Several proposed signals
  were also brittle: `strip_tags()` means no raw tags survive into Markdown;
  `url_to_postid() === 0` does not prove a link is broken; and an in-process
  comparison cannot measure the real public HTML response. If reconsidered,
  build only a small MVP (servability reason, `.md` URL/mode, exact preview via a
  shared side-effect-free builder, and clearly labelled size estimates) on a
  separate admin page. The settings panel is one `options.php` form and cannot
  host a nested picker form.
- **ACF structured extraction** — render Repeater, Flexible Content,
  Relationship, and Gallery fields structurally instead of relying on the
  current text-only handling. Do not build this generically without real ACF
  exports as fixtures:
  - the panel configures only subtitle and TL;DR field names; the general
    `sysmda_acf_field_keys` list remains developer-only;
  - Repeater and Flexible Content have no universal semantic representation and
    need an explicit per-site template or callback contract;
  - configurable ACF return formats can produce IDs, `WP_Post` objects, URLs,
    arrays, or nested combinations, so every supported shape needs defined
    normalization and escaping;
  - unknown values do not fall back to text today: non-string arrays and objects
    are skipped;
  - helpers must return escaped semantic HTML fragments, or a structured
    intermediate consumed by one renderer, never Markdown mixed into HTML input;
  - a sensible implementation order is scalars and links, Relationship/Post
    Object, Gallery/Image, Repeater with templates, then Flexible Content with
    per-layout callbacks.
- **WooCommerce** — structured product Markdown has potential, but the feature is
  heavy and its audience is unconfirmed.
- **Technical hardening** — further real-WordPress integration coverage,
  multisite/subdirectory verification, and explicit Varnish or generic
  reverse-proxy compatibility should be addressed when evidence justifies the
  work rather than treated as a standalone project.
- **Broader multilingual support** — per-language `.md` correctness and
  cross-language alternates go beyond the currently scoped `/llms.txt` plan and
  should wait for a demonstrated use case.
- **Per-post-type Markdown templates and controlled component substitution** —
  keep these filter-led and add them only for real content requirements.
- **HTML vs Cloudflare vs origin-native benchmark** — potentially useful as a
  marketing article or asset, not as a plugin feature.

## Explicitly out

- **Loopback-based live cache self-tests** — unreliable behind WAFs and proxies;
  the documented manual curl diagnostic remains the answer.
- **Rich per-client request logging** — conflicts with the count-only hit-counter
  decision: no IP addresses, raw user agents, per-visitor records, or sub-daily
  timestamps.
- **Rate limiting, a `.md` XML sitemap, or a synthesized homepage index** — these
  have already been rejected as plugin features.
- **MCP, WebMCP, GEO scores, or AI content generation** — these would turn a
  focused technical plugin into an AI-optimization package without a verifiable
  promise.
