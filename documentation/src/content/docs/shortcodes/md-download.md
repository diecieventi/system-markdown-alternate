---
title: "[sysmda_md_download] — download the file"
description: "A link that saves the .md as a file instead of opening it in the browser."
sidebar:
  order: 2
---

Renders a link that **saves** the Markdown as a file rather than opening it:

```
[sysmda_md_download]
```

```html
<a class="sysmda-md-download"
   href="https://example.com/my-post.md"
   download="my-post.md">Download as Markdown</a>
```

## Attributes

| Attribute | Default | Meaning |
|---|---|---|
| `id` | current post | The post to link to |
| `text` | built-in label | Your own link text |

```
[sysmda_md_download text="Save this article"]
[sysmda_md_download id="123" text="Download"]
```

## How the download works

Entirely in the browser. The link is same-origin and carries the HTML `download` attribute, which is all a browser needs to save the target instead of navigating to it.

The response itself sends **no `Content-Disposition` header**, and the plugin reads no request argument to trigger one. This is deliberate: the `.md` URL has exactly one behaviour, and it does not change based on how a client intends to store the file. Every argument read from a URL is a public input to be validated forever, and this one would have bought only the case of pasting the URL into the address bar by hand — where the browser decides, as it always has.

## The file name

Derived from the post slug, then reduced to a strictly safe character set (`A–Z`, `a–z`, `0–9`, `.`, `_`, `-`). Accented characters are transliterated, percent-encoding is decoded first, and anything left over is dropped. If nothing usable survives, the name falls back to `post-123.md`.

So a post at `/perché-il-markdown/` downloads as `perche-il-markdown.md`.

## Styling

The link carries one class, `sysmda-md-download`, and nothing else — no inline styles, no `data-` attributes, no stylesheet. It is a plain anchor for your theme to style:

```css
.sysmda-md-download {
	display: inline-block;
	padding: .5em 1em;
	border: 1px solid currentColor;
	border-radius: 4px;
	text-decoration: none;
}
```

The plugin ships no CSS for it and will not: presentation belongs to your theme, which is the only thing that knows what the rest of your page looks like.

## When it renders nothing

Same rule as every other Markdown control: if the post has no `.md`, the shortcode outputs nothing. No empty link, no dead button.
