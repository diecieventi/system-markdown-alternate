# User documentation

The plugin's user-facing documentation, as Markdown sources. Nineteen articles
across seven sections: what the plugin does, every field in the settings panel,
both endpoints, the shortcodes, the integrations, the filter API and
troubleshooting.

There is no published site yet, and that is deliberate. These are the articles;
where they get published is a later decision that does not change them.

## Not part of the plugin

This folder sits outside `system-markdown-alternate/`, and `bin/build.sh`
packages only that directory — so nothing here ever reaches the distributed zip
or wordpress.org. No configuration is needed to keep it that way.

## Structure

```
documentation/src/content/docs/
├── getting-started/     what it does, first Markdown file
├── settings/            one article per panel field
├── endpoints/           .md endpoint, /llms.txt
├── shortcodes/          [sysmda_md_url], download link, reader actions
├── integrations/        ACF, GenerateBlocks
├── developers/          the filter API, in overview
└── troubleshooting/     when it does not work
```

The path is where [Astro Starlight](https://starlight.astro.build/) expects
content, so publishing later means adding a config and a `package.json` **in
this folder** — not moving or rewriting anything. Until then GitHub renders the
files directly and the links between them resolve here as they will on a site.

Markdown is also the neutral format: the same files can feed a static site, a
WordPress import, or anything else. Choosing where to publish stays reversible.

## Writing an article

Front matter is Starlight's, and only three keys are used:

```yaml
---
title: "Enabled content types"
description: "One sentence, used as the page summary and in search results."
sidebar:
  order: 1
---
```

No H1 in the body — Starlight prints the title from the front matter, so an H1
would repeat it. Start at `##`.

Cross-links between articles are relative to the file: `./other-article.md`
inside the same category, `../category/article.md` across categories.

## The line between this and `docs/`

Two audiences, two places, and they must not restate each other.

| | Audience | Lives in |
|---|---|---|
| User documentation | Site owners installing and configuring the plugin | here |
| [`docs/filters.md`](../docs/filters.md), [`docs/output-format.md`](../docs/output-format.md) | Developers writing code against the plugin | `docs/`, versioned with the code |

Articles here **link** to those contracts rather than reproducing them. Two
copies of a contract drift; one does not.

Those links are written as full GitHub URLs on purpose. A relative path would
reach the file while browsing the repository but break on a published site,
where the contracts are not part of the content collection.

## Keeping it current

Documentation lives in this repository so that a change to the plugin and the
change to its documentation can travel in the **same pull request** — reviewed
together, merged together. A pull request that alters a filter, a setting or a
shortcode and touches nothing here is visible as such in review.

That is the whole mechanism, and it is the reason no synchronisation tooling
exists: there are no two places to keep in step.
