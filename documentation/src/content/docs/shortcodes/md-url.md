---
title: "[sysmda_md_url] — the Markdown URL"
description: "Outputs the bare .md URL of a post, for use in links, templates and attributes."
sidebar:
  order: 1
---

Outputs the `.md` URL of a post, and nothing else — no markup, no label, no wrapper.

```
[sysmda_md_url]
→ https://example.com/my-post.md
```

## Attributes

| Attribute | Default | Meaning |
|---|---|---|
| `id` | current post | The post to link to |

```
[sysmda_md_url id="123"]
```

## Why it returns a bare URL

Because that is what makes it safe to drop anywhere a URL is expected:

```html
<a href="[sysmda_md_url]">Read as Markdown</a>
```

If this shortcode could also return markup — given a label, say — that usage would break the first time someone passed one. It returns a URL, always, so you can build whatever you want around it. For a ready-made link, use [`[sysmda_md_download]`](./md-download.md) or [`[sysmda_md_actions]`](./md-actions.md) instead.

## It never links to a 404

If the post has no Markdown version — the content type is not enabled, it is a draft, it is password-protected, or it uses a non-standard post format — the shortcode outputs **nothing at all**. Not a broken URL, not a `#`: an empty string.

That means a template like the one above degrades to an empty `href` rather than a link to a missing page, and a conditional around it works as you would expect:

```php
$url = do_shortcode( '[sysmda_md_url]' );
if ( '' !== $url ) {
	printf( '<a href="%s">Markdown</a>', esc_url( $url ) );
}
```

## On plain permalinks

If your site uses plain permalinks (`?p=123`) there is no path to append `.md` to, so the shortcode returns the `?format=markdown` form of the URL instead. It still works; it is just less tidy. See [Serving your first Markdown file](../getting-started/serving-your-first-markdown-file.md).

## In the Markdown itself

This shortcode — like the other two — is stripped from the Markdown output. Interface elements pointing at the document do not belong inside the document.

Writing about it in an article is fine, though: a shortcode inside a code block or an inline `code` span is left exactly as typed, so a tutorial showing `[sysmda_md_url]` keeps its example intact.
