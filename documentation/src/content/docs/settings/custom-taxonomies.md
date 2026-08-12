---
title: "Custom taxonomies in the front matter"
description: "Add your own taxonomies to the Markdown metadata. Opt-in per taxonomy, and never inferred automatically."
sidebar:
  order: 4
---

**Settings → Markdown Alternate → Markdown output**

Categories and tags are always in the front matter under their own keys. Any *other* taxonomy — a genre, a topic, a product line — is added only if you tick it here.

**Default:** nothing selected. With nothing ticked the output is byte-identical to a site that has never seen this setting.

## What it produces

A nested `taxonomies:` block, appended after `description`:

```yaml
taxonomies:
  genre:
    - "Ambient"
    - "Techno"
  topic:
    - "Privacy"
```

A selected taxonomy with no terms on a post is omitted; if none of the selected taxonomies has terms, the `taxonomies:` key is not emitted at all.

## Why it is opt-in

Because the plugin genuinely cannot tell which of your taxonomies belong in a public, machine-readable document.

WordPress's registry describes how a taxonomy is *routed*, not what it means. A taxonomy registered `public => true, publicly_queryable => false` is the usual shape of an editorial-internal classification with no term archive — a workflow stage, an internal priority, a client code. Publishing that in every article's metadata because a flag said "public" would be a mistake the site owner never asked for, and an earlier version of the plugin made exactly that mistake.

So the panel lists the candidates and labels the ones that are not publicly queryable, and you decide. A taxonomy registered later by a newly installed plugin appears **unticked**: nothing starts publishing itself.

Selecting an internal taxonomy on purpose is entirely supported — the point is that it is your call, not a guess.

## What can never be selected

`category` and `post_tag` already have their own front-matter keys and are never repeated here. `post_format` is presentational and is excluded too.

## Ordering

Taxonomy slugs and term names are both sorted in **byte order**, not locale-aware alphabetical order. This is deliberate: it keeps the output identical regardless of the server's locale, which matters for a format that is compared and cached.

The visible consequence is that accented names sort after unaccented ones — `Ähnlich` comes after `Zeta`. The order is stable, not human-alphabetical.

## Turning it on changes every article

Worth saying plainly: enabling a taxonomy changes the front matter of every post that has terms in it, and changes the cache validator with it. Clients holding a copy will re-fetch. That is correct, and it is why the default is off.

Term changes are covered too. Assigning a term does not touch a post's modification date, so the plugin folds a fingerprint of the emitted terms into the validator — otherwise a client would be told "not modified" while holding the old terms.

## From code

The saved selection passes through a filter that can both narrow and extend it:

```php
add_filter( 'sysmda_front_matter_taxonomy_slugs', function ( array $slugs, WP_Post $post ) {
	$slugs[] = 'product-line';
	return $slugs;
}, 10, 2 );
```

Returning an empty array opts a post out. To suppress the whole block regardless of the selection, return `false` from `sysmda_front_matter_taxonomies`.
