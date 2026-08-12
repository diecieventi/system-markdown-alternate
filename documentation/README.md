# User documentation

The plugin's user-facing documentation: nineteen articles across seven sections,
plus the landing page. Built with [Astro Starlight](https://starlight.astro.build/)
and published to GitHub Pages.

**https://diecieventi.github.io/system-markdown-alternate/**

## Not part of the plugin

This folder sits outside `system-markdown-alternate/`, and `bin/build.sh`
packages only that directory — so nothing here reaches the distributed zip or
wordpress.org. No `.distignore` entry is needed to keep it that way.

## Working on it

```bash
cd documentation
npm install
npm run dev      # local preview with live reload
npm run build    # production build into dist/
```

Publishing is automatic: a push to `main` that touches `documentation/` runs
`.github/workflows/docs-site.yml`, which builds and deploys. It can also be
started by hand from the Actions tab.

## Structure

```
documentation/
├── astro.config.mjs         site config, sidebar, base path
├── remark-base-paths.mjs    applies `base` to root-relative Markdown links
├── public/favicon.png       the plugin icon, shared with the wordpress.org listing
└── src/
    ├── content.config.ts
    └── content/docs/
        ├── index.md             landing page (splash template)
        ├── getting-started/     what it does, first Markdown file
        ├── settings/            one article per panel field
        ├── endpoints/           .md endpoint, /llms.txt
        ├── shortcodes/          [sysmda_md_url], download link, reader actions
        ├── integrations/        ACF, GenerateBlocks
        ├── developers/          the filter API, in overview
        └── troubleshooting/     when it does not work
```

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

A new section needs a group added to `sidebar` in `astro.config.mjs`; articles
inside an existing section are picked up automatically and ordered by
`sidebar.order`.

### Links

Link other articles as **root-relative paths without the base**:

```markdown
See [Enabled content types](/settings/enabled-content-types/).
```

Not `./article.md`, and not `/system-markdown-alternate/settings/…`. The reason
is worth knowing, because every plausible alternative is wrong in a way the
build does not report:

**Astro rewrites nothing.** Verified against 7.2.1 with four link forms —
relative `.md`, relative directory, root-relative, and root-relative with the
base already applied. All four reach the HTML byte-for-byte as written. A
`./article.md` link therefore resolves while browsing this repository on GitHub
and 404s on the site, and the build succeeds either way.

So the base has to be applied somewhere, and the choice is the content or the
build. `remark-base-paths.mjs` does it at build time, which keeps the articles
deployment-neutral: the same files can feed a WordPress import or another host
without carrying one deployment's URL prefix. Moving to a custom domain becomes
`base: '/'` in the config — the plugin turns into a no-op — instead of a
find-and-replace across the set.

**Front matter is not Markdown**, so it never reaches that pass. The landing
page's hero actions are written as plain relative links (`getting-started/…`)
which resolve correctly against the site root whatever the base is.

Links to the developer contracts stay **full GitHub URLs**. A relative path
would reach the file while browsing the repository and break on the site, where
`docs/` is not part of the content collection.

## The line between this and `docs/`

Two audiences, two places, and they must not restate each other.

| | Audience | Lives in |
|---|---|---|
| User documentation | Site owners installing and configuring the plugin | here |
| [`docs/filters.md`](../docs/filters.md), [`docs/output-format.md`](../docs/output-format.md) | Developers writing code against the plugin | `docs/`, versioned with the code |

Articles here **link** to those contracts rather than reproducing them. Two
copies of a contract drift; one does not.

## Keeping it current

Documentation lives in this repository so that a change to the plugin and the
change to its documentation can travel in the **same pull request** — reviewed
together, merged together. A pull request that alters a filter, a setting or a
shortcode and touches nothing here is visible as such in review.

That is the whole mechanism, and it is why no synchronisation tooling exists:
there are no two places to keep in step.
