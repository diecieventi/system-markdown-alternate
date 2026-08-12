---
title: "Excluding content from the Markdown"
description: "Three lists — shortcodes, blocks and CSS classes — decide what never reaches the Markdown. They add to the built-in defaults rather than replacing them."
sidebar:
  order: 2
---

**Settings → Markdown Alternate → Markdown output**

Most page furniture never reaches the Markdown in the first place, because the plugin converts your blocks rather than scraping the rendered page — headers, menus, sidebars, cookie banners and anything injected into `the_content` are simply not part of the pipeline.

What is left is the furniture that lives *inside* the post: a subscription form in the middle of an article, a table of contents, a promo box. Three lists handle those.

## The three lists

| Field | Takes | Use for |
|---|---|---|
| Excluded shortcodes | Shortcode tags, without brackets | Forms, tables of contents, anything shortcode-driven |
| Excluded blocks | Block names, e.g. `acme/promo` | Blocks whose output is interface, not content |
| Excluded CSS classes | Class names, without the dot | Marking a section in the editor as not-for-Markdown |

One entry per line in each field.

## Your entries add to the defaults

This is the part worth reading twice. The built-in defaults **always apply**, and whatever you type is added to them. Typing one tag into *Excluded shortcodes* does not switch the built-in form exclusions off.

The panel shows the current defaults under *View built-in defaults*. They cover the common form plugins — Contact Form 7, Gravity Forms, WPForms, Fluent Forms, Ninja Forms, Formidable — the common newsletter forms — MailerLite, Mailchimp for WordPress, MailPoet, The Newsletter Plugin, Brevo — and the common table-of-contents plugins.

Removing a default is possible, but deliberately requires code rather than a text field:

```
add_filter( 'sysmda_markdown_excluded_shortcodes', function ( array $tags ) {
	return array_diff( $tags, array( 'toc' ) );
} );
```

The asymmetry is intentional. Getting an exclusion wrong in the permissive direction publishes a form into every Markdown file on the site; getting it wrong in the restrictive direction drops a paragraph. The cheap path is the safe one.

## Excluding a section while writing

Three class names work out of the box, on any block: `no-md`, `md-exclude` and `exclude-from-markdown`. Add one to a block's *Additional CSS class(es)* field in the editor sidebar and that block — with everything nested inside it — is gone from the Markdown.

The exclusion also applies to the front matter `description`, so an excluded section can never be summarised into the metadata of the very document that refuses to publish it.

## Code samples are safe

An article that *documents* a shortcode is not the same as an article that *uses* one. Text inside a code block or an inline `code` span is never expanded and never stripped, so writing about `[contact-form-7]` in a tutorial leaves the example intact even though the tag is on the exclusion list.

Outside code, a shortcode you have *not* excluded is expanded normally, exactly as it is on the HTML page.

One case that looks like a bug and is not: a shortcode whose tag is **not registered by any active plugin** is left in the text as literal `[foo]`, because that is what WordPress itself does. If you find a bare tag in your Markdown, check whether the plugin that provides it is still active before adding it to an exclusion list — the HTML page shows the same literal text.

## Finding what to exclude

The practical method is to read the output. Open a few representative articles' `.md` URLs — a long one, one with a form, one with a table of contents — and look for text that is interface rather than prose. A stray "Subscribe to our newsletter / Email / I agree to the privacy policy" sequence in the middle of an article is the classic signature.

After changing any of the three lists, saving invalidates the cache site-wide, so the next request shows the new result.
