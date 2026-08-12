---
title: "System Markdown Alternate"
description: "A clean Markdown version of every published post, served at the same address with .md appended — for LLMs, agents and technical tools."
template: splash
hero:
  tagline: "Every published post gets a second, machine-readable representation. Append <code>.md</code> to any permalink."
  actions:
    # Relative on purpose: hero actions come from front matter, which never
    # reaches the remark pass that applies `base` to body links. Resolved
    # against the site root, this is correct whether base is `/` or a subpath.
    - text: What it does
      link: getting-started/what-it-does/
      icon: right-arrow
    - text: View on GitHub
      link: https://github.com/diecieventi/system-markdown-alternate
      icon: external
      variant: minimal
---

## The idea

```
https://example.com/my-post/      → HTML, for people
https://example.com/my-post.md    → Markdown, for machines
```

No second copy of your content, no build step, no change to the HTML page. The Markdown is generated on request from your blocks — so the navigation, the cookie banner, the related-posts widget and everything else injected into the page never enter it.

## Start here

| | |
|---|---|
| [What System Markdown Alternate does](/getting-started/what-it-does/) | The concept, the output format, and what it deliberately is not |
| [Serving your first Markdown file](/getting-started/serving-your-first-markdown-file/) | Install, enable one content type, confirm it works |
| [Enabled content types](/settings/enabled-content-types/) | The one setting the plugin cannot work without |
| [Excluding content](/settings/excluding-content/) | Forms, tables of contents and other page furniture |

## Also worth knowing

- **[The `.md` endpoint](/endpoints/the-md-endpoint/)** — two ways to ask for Markdown, and the HTTP contract behind them
- **[`/llms.txt`](/endpoints/the-llms-txt-index/)** — one file listing your Markdown content, so an agent can find it all
- **[Shortcodes](/shortcodes/md-url/)** — link to, download, or let readers copy the Markdown
- **[Extending with filters](/developers/extending-with-filters/)** — thirty-two documented hooks
- **[Troubleshooting](/troubleshooting/nothing-served-at-the-md-url/)** — when nothing is served, or a cache answers first

## It creates no SEO risk

Markdown responses carry `X-Robots-Tag: noindex, follow` and a canonical `Link` header pointing back at the HTML page. Search engines are told plainly which URL counts. There is no sitemap of `.md` URLs, for the same reason.
