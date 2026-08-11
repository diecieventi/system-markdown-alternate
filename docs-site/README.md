# Documentation site

Scaffolding for the plugin's public documentation site — a WordPress knowledge
base in the shape used by [perfmatters.io/docs](https://perfmatters.io/docs/):
a `doc` content type, a category per section, one article per topic.

**Nothing here ships with the plugin.** Root folders are outside
`system-markdown-alternate/`, so the build never sees this directory. It is site
code kept in the repository so it survives a staging reset and can be reviewed
in a pull request like anything else.

## Why WordPress and not a docs platform

The plugin's whole claim is that WordPress content can be served in a form
machines read well. Running the documentation on WordPress with the plugin
active makes the documentation its own demonstration: every article has a `.md`
twin, `/llms.txt` indexes the set, and both are verifiable with one `curl` by
anyone evaluating the plugin.

```
https://<site>/docs/what-it-does/     → HTML
https://<site>/docs/what-it-does.md   → Markdown
https://<site>/llms.txt               → the index, including a Docs section
```

A hosted docs platform would supply the same two features as a product — which
would leave the plugin's own documentation buying elsewhere what the plugin
exists to provide.

## Contents

| Path | What it is |
|---|---|
| `mu-plugins/smadocs-site.php` | Registers the `doc` post type and the `doc_category` taxonomy. Drop into `wp-content/mu-plugins/`. |

## Installing on a site

Copy the mu-plugin into `wp-content/mu-plugins/`. It registers the type, flushes
the rewrite rules once, and orders the category archives by the `smadocs_order`
term meta rather than alphabetically.

Then, in **Settings → Markdown Alternate → General**, tick **Docs** so the
articles are served as `.md` and listed in `/llms.txt`.

## Two things that bite

Both were hit while building this, and both are silent failures.

**Register the taxonomy before the post type.** A post type rewritten under
`docs` also generates the attachment rule `docs/[^/]+/([^/]+)/?$`, which matches
`docs/category/troubleshooting/` and resolves it to an attachment that does not
exist. Rewrite rules are emitted in permastruct registration order, so
registering the post type first buries the taxonomy rule and every category
archive answers 404 — with the correct rule sitting unused in the table.

**Slash content before writing it.** `wp_insert_post()` and `wp_update_post()`
unslash their input, so a literal backslash in a code sample is deleted on the
way to the database: `"\n"` is stored as `"n"`. Documentation is mostly code
samples, so this corrupts silently and at scale. Pass content through
`wp_slash()` when creating articles programmatically. Authoring in the block
editor is unaffected.

## Source of truth

The articles live in WordPress; this folder holds only the code that makes the
site work. The developer contracts stay in `docs/` at the repository root —
[`filters.md`](../docs/filters.md) and
[`output-format.md`](../docs/output-format.md) are versioned with the code they
describe, and the site links to them rather than restating them.
