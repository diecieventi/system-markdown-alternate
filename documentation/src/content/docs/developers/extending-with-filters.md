---
title: "Extending the plugin with filters"
description: "Thirty-three documented filters, split into two stability levels, covering what gets served, what the document contains and how it is cached."
sidebar:
  order: 1
---

Everything the settings page does, it does through a filter — and a few extension points exist that the panel deliberately does not expose. Put the snippets below in your theme's `functions.php`, a site-specific plugin, or an mu-plugin.

## Two stability levels

Each filter is documented as one of two levels, and the difference is what the hook is anchored to — not how useful it is.

- **Stable** — tied to a setting in the panel, or to a concept the plugin is about: what may be served, what the finished document is, what the response says about caching. Breaking one goes through deprecation and a changelog entry.
- **Advanced** — tied to a stage of the current implementation: where the conversion pipeline cuts, how ACF is read, how the index is laid out. Supported and documented, but free to evolve before 1.0.

## Deciding what gets served

`sysmda_post_is_servable` is the per-post veto, and the one to reach for when a membership plugin, a paywall or your own rule decides a published post should have no Markdown representation. It is consulted only after the built-in checks have already said yes, so it can hide content but never expose a draft:

```
add_filter( 'sysmda_post_is_servable', function ( bool $servable, WP_Post $post ) {
	if ( get_post_meta( $post->ID, '_members_only', true ) ) {
		return false;
	}
	return $servable;
}, 10, 2 );
```

Every consumer honours it at once: the endpoint, negotiation, the discovery links, `/llms.txt`, the shortcodes and the dynamic tag.

`sysmda_markdown_unsupported_builders` is the built-in list of page builders whose posts have no Markdown version — see [Page builders](/integrations/page-builders/) for what that means and why. Drop a key to serve that builder's posts anyway, or return an empty array to switch the rule off entirely:

```
add_filter( 'sysmda_markdown_unsupported_builders', function ( array $builders ) {
	return array_diff( $builders, array( 'bricks' ) );
} );
```

Those posts then go through the ordinary pipeline, which for a builder means an empty document or a body of layout wrappers. Adding a key the plugin does not recognise does nothing; to deny posts built with something else, use `sysmda_post_is_servable` above.

## Changing the document

`sysmda_markdown_output` receives the finished document and returns one. It is the right place for anything that is genuinely about the final text, and being Stable, it survives changes to how the conversion works underneath:

```
add_filter( 'sysmda_markdown_output', function ( string $markdown, WP_Post $post ) {
	return $markdown . "\n---\n\nCC BY-SA 4.0 — " . get_bloginfo( 'name' ) . "\n";
}, 10, 2 );
```

To drop the YAML front matter entirely and start the document at the H1, return `false` from `sysmda_front_matter_enabled`.

## When your output changes and the .md does not

This is the one filter worth knowing about before you need it. The cache validator is built from the post's modification date plus everything the plugin itself knows can move the body. It cannot know about *your* dynamic block that reads an option, or a shortcode pulling in remote data — so from its point of view nothing changed, and clients keep being told `304`.

```
add_filter( 'sysmda_markdown_cache_dependencies', function ( array $deps, WP_Post $post ) {
	$deps['pricing'] = get_option( 'acme_pricing_version' );
	return $deps;
}, 10, 2 );
```

Whatever you add joins the validator, so a change to it invalidates the cached document and the conditional responses together. Keep the values cheap to read: this code runs on every request, including the ones that send no body at all.

## Caching headers

`sysmda_cache_control` sets the header on the URLs the plugin owns. The default is `public, max-age=0, must-revalidate` — storable anywhere, never reusable without checking first. Giving a shared cache a real lifetime is a deliberate trade, because nothing purges a `.md` when a post is edited:

```
add_filter( 'sysmda_cache_control', function ( string $value ) {
	return 'public, max-age=0, s-maxage=600, must-revalidate';
} );
```

That buys repeat traffic and concurrent crawlers a cache hit, at the price of an edit staying invisible for up to the lifetime you set. Requests from logged-in visitors are never made publicly cacheable, whatever this filter returns.

## The full list

All thirty-two filters — with defaults, stability levels and runnable examples, grouped by area — are documented in the repository:

- [Developer extension API](https://github.com/diecieventi/system-markdown-alternate/blob/main/docs/filters.md) — every filter
- [Markdown output format](https://github.com/diecieventi/system-markdown-alternate/blob/main/docs/output-format.md) — the document contract, stated as append-only
