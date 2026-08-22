---
title: "Extra custom fields"
description: "Pull content out of custom fields and into the Markdown. Works with ACF, JetEngine, Meta Box and the native Custom Fields box, because underneath they are all post meta."
sidebar:
  order: 5
---

**Settings → Markdown Alternate → Markdown output**

The Markdown document is built from the post content. If part of your page comes
from somewhere else — an ACF field, a JetEngine dynamic field, a value typed into
WordPress's own Custom Fields box — none of it reaches the `.md` unless you name
it here.

**Default:** empty. With nothing listed the output is byte-identical to a site
that has never seen this setting.

## When you need it

You need it when a visitor sees text on the page that is not in the post content.

The usual shape is a template — a GeneratePress Element, a theme part, a page
builder layout — that renders the post content *plus* a few fields around it: a
standfirst above the article, a specification table below it, a disclaimer. On
the page it reads as one article. In the `.md` it was only ever the middle part,
with nothing marking what was missing.

If everything your readers see is inside the editor, you do not need this
setting at all.

## What to put in the box

One **meta key** per line, in the order you want the values to appear.

```
product_specification
editorial_disclaimer
```

A meta key is the internal name of the field, not its label. Where to find it:

| Where the field comes from | The key is |
|---|---|
| ACF | the **Field Name** in the field group editor (not the label) |
| JetEngine | the **Meta Field** name in the field settings |
| Meta Box | the field `id` |
| The native Custom Fields box | the name shown in the left column |

This works across all of them for one reason: whatever wrote the value, it is
ordinary post meta by the time the plugin reads it. There is no per-plugin
integration to enable.

## Where the values land

At the **end of the body**, after the post content, in the order you listed the
keys.

They are not inserted where the template puts them, and they cannot be: the
plugin renders the post, not your theme's layout, so it has no way to know that
a field appears between the second and third paragraph. A predictable position
is worth more than a guessed one.

Once appended, the values travel the whole pipeline like the post content — your
exclusions apply, shortcodes expand, links become absolute.

## What is skipped

**Anything that is not text.** An image field, a repeater, a relationship, a
gallery — anything stored as an array — is left out rather than rendered.

That is deliberate. A repeater is a structure, and there is no single right way
to turn it into prose; the plugin would have to invent one, and would then be
confidently wrong in every document on the site. If you need structured fields in
the Markdown, format them yourself and add the result with the
[`sysmda_markdown_appended_html`](https://github.com/diecieventi/system-markdown-alternate/blob/main/docs/filters.md#the-conversion-pipeline)
filter.

An empty field is skipped too. A field containing just `0` is **not** — that is a
real value.

## With ACF active

If ACF is installed, values are read through ACF, so a field it knows arrives
formatted the way ACF would render it.

For a key ACF has no field definition for, it returns the stored value unchanged
— so a JetEngine or native Custom Fields key behaves the same whether or not ACF
happens to be installed.

## This is not the ACF subtitle setting

They coexist, and they answer different questions.

| | Use |
|---|---|
| A field that is **part of the article** | **Extra custom fields** — appended to the body |
| A **subtitle** or a **TL;DR** | [ACF subtitle and TL;DR](/integrations/acf-subtitle-and-tldr/) — placed between the title and the body, with their own formatting |

The subtitle and TL;DR settings exist because those two have a *position* in the
document. A generic field list has no opinion about position, and should not
pretend to.

## Turning it on changes every article that has the field

Worth saying plainly: listing a key changes the Markdown of every post carrying
that field, and changes the cache validator with it. Clients holding a copy will
re-fetch. That is correct, and it is why the box starts empty.

Posts that do **not** have the field are unaffected — their document and their
validator stay exactly as they were. This matters more than it sounds: it is what
keeps a site of 900 ordinary articles from being invalidated because two landing
pages use a custom field.

Editing a custom field does not touch a post's modification date, so the plugin
folds a fingerprint of the value into the validator. Otherwise a client would be
told "not modified" while holding a document with the old text.

## Only the keys you name

Post meta is mostly internal plumbing — cache markers, editor state, plugin
bookkeeping — so the plugin never guesses which keys are content. There are no
built-in defaults and nothing is detected automatically. A field starts appearing
in your Markdown when you type its key into this box, and not before.

Keys beginning with an underscore are accepted: some plugins store real content
in them.

## From code

```php
add_filter( 'sysmda_markdown_extra_meta_keys', function ( array $keys, WP_Post $post ) {
    if ( 'product' === $post->post_type ) {
        $keys[] = 'product_specification';
    }

    return $keys;
}, 10, 2 );
```

The panel field feeds this filter at priority 5, so site code at the default
priority 10 sees the saved list and can narrow or extend it per post.

Note that the list **replaces** rather than adds to a default — unlike the
exclusion settings, which accumulate. There are no defaults here to preserve.

If you add keys from code **without** listing them in the panel, see the caveat
about cache invalidation in
[the filter reference](https://github.com/diecieventi/system-markdown-alternate/blob/main/docs/filters.md#extra-custom-fields).
