---
title: "ACF: subtitle and TL;DR"
description: "Two Advanced Custom Fields values can be promoted into the Markdown document, between the title and the body."
sidebar:
  order: 1
---

**Settings → Markdown Alternate → Markdown output** (visible only while ACF is active)

If your articles carry a subtitle or a summary in an ACF field, that text is part of the article — but it lives outside `post_content`, so a converter working from the content alone would drop it. This integration promotes two such fields into the document.

## The two fields

| Setting | ACF field type | Rendered as |
|---|---|---|
| ACF subtitle field | Text | Italic line directly under the H1 |
| ACF TL;DR field | WYSIWYG editor | A `**TL;DR**` section between horizontal rules |

Enter the **field name** — the machine name from the ACF field group, not the label. Leave a box empty to skip that field.

## What it produces

```
# How to lazy load images

*A practical guide for slow image-heavy pages*

---

**TL;DR**

Turn on native lazy loading, keep your hero image out of it, and measure.

---

The body of the article starts here.
```

Both sit between the title and the body, in that order. The TL;DR goes through the same conversion pipeline as the body, so links, lists and emphasis written in the WYSIWYG editor survive as Markdown.

## If ACF is deactivated

The section disappears from the settings page and the fields stop being read — but your saved field names are kept, so reactivating ACF restores the previous behaviour without reconfiguration.

## Other fields

Two settings cover the two positions in the document that need one. For an ACF field that is simply *part of the article* — a standfirst, a specification table, a disclaimer — use [Extra custom fields](/settings/extra-custom-fields/) instead: it takes a list of meta keys from the settings page, appends their values to the end of the body, and works for JetEngine and native Custom Fields too.

The difference is position. Subtitle and TL;DR have one, and it is why they are separate settings; a generic field has none, so it goes at the end.

There is also a filter, predating the panel field and still supported, which appends ACF field values to the source content:

```
add_filter( 'sysmda_acf_field_keys', function ( array $keys ) {
	$keys[] = 'key_takeaways';
	return $keys;
} );
```

Those values join the block source before rendering, so a synced pattern referenced from an ACF field is followed like any other. See [Extending the plugin with filters](/developers/extending-with-filters/).
