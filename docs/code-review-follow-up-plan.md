# Implementation plan — code-review follow-ups

> English-only repository convention. This document plans the two follow-ups
> identified during the 2026-07-24 code review. It intentionally contains no
> runtime change. Implement each item in its own runtime PR; do not bundle it
> with this planning-document PR.

## Scope and priorities

| Item | Priority | Risk addressed |
| --- | --- | --- |
| Case-insensitive special URL schemes | Low | Markdown conversion can turn valid uppercase/mixed-case `mailto:`, `tel:`, or `data:` URLs into incorrect site-relative URLs. |
| Attachment hard exclusion | Low | A third-party filter can re-add `attachment`, contrary to the plugin's stated invariant that attachments are never servable. |

Both changes are defensive correctness fixes. Neither broadens the feature
scope, changes the default output for valid existing content, introduces
tracking, performs HTTP loopbacks, or changes cache policy.

## PR A — Preserve special URI schemes case-insensitively

### Current behavior

`ContentRenderer::absolutize()` preserves `data:`, `mailto:`, `tel:`, and `#`
before resolving relative URLs. The prefix comparison is case-sensitive.
Therefore `MAILTO:hello@example.test` or `Tel:+390000000` is treated as a
document-relative URL and incorrectly becomes a URL under the source post's
permalink.

### Implementation

1. In `src/ContentRenderer.php`, normalize only the comparison value (for
   example with `strtolower()`), while returning the original `$url` unchanged
   when it is a special scheme. This preserves the source spelling and avoids
   altering opaque data after the colon.
2. Keep the existing behavior for absolute HTTP(S), protocol-relative,
   root-relative, document-relative, and fragment URLs.
3. Do not turn this helper into a general URL sanitizer. Its responsibility is
   URL resolution, and WordPress/the Markdown converter retain their existing
   output behavior.
4. Add pure-logic coverage. If the private helper remains private, expose a
   small, side-effect-free public helper only if that is the smallest API; do
   not use reflection in the production test suite. Cover at least:
   - `mailto:`, `MAILTO:`, and mixed-case variants;
   - `tel:` and `TEL:`;
   - `data:` and `DATA:`;
   - `#fragment` unchanged;
   - normal relative `guide.html`, root-relative `/guide.html`, and `../guide.html`
     still resolving exactly as before.
5. Run a WordPress-level smoke test with a post containing all variants and
   inspect the resulting Markdown links.

### Acceptance criteria

- Uppercase and mixed-case special schemes remain verbatim in the Markdown URL.
- Existing normal relative-URL behavior is unchanged.
- No new remote URL fetching, no new filter, and no output-format contract
  change are introduced.

### Files expected

- `system-markdown-alternate/src/ContentRenderer.php`
- `system-markdown-alternate/tests/run-tests.php`
- `system-markdown-alternate/readme.txt` changelog only if released
- `system-markdown-alternate/system-markdown-alternate.php` only if released

## PR B — Enforce the attachment exclusion centrally

### Current behavior

The settings sanitizer removes `attachment` from selectable public post types.
However, `PostSupport::supported_post_types()` accepts the value returned by
`sysmda_markdown_supported_post_types` as-is. Code attached to that public
filter can therefore re-add `attachment`; if such an attachment has status
`publish` and no password, `PostSupport::is_servable()` accepts it.

### Implementation

1. Make `PostSupport` the final authority for the invariant: remove
   `attachment` from the filtered supported-type list before returning it, or
   reject it explicitly in `is_servable()`. Prefer normalizing once in
   `supported_post_types()` so every caller receives the same safe list.
2. Preserve all other public, filter-supplied post types. Do not restrict the
   filter to the admin UI's current list: plugins may register legitimate public
   CPTs after this plugin is installed.
3. Preserve the empty-array behavior: no enabled types means the plugin is
   inactive.
4. Add isolated tests with a WordPress filter stub or an equivalent existing
   test fixture proving that a filter returning `array( 'post', 'attachment' )`
   yields a list without `attachment`, and that a publishable attachment is not
   servable. Also retain coverage that a normal enabled post remains servable.
5. At WordPress level, verify that the settings page still excludes Media and
   that an injected filter cannot cause an attachment alternate link, shortcode
   URL, dynamic-tag URL, `.md` response, or `/llms.txt` entry.

### Acceptance criteria

- `attachment` is never served, regardless of settings or the public filter.
- Public posts, pages, and configured public CPTs continue to work unchanged.
- All consumers of `PostSupport::is_servable()` inherit the same result.

### Files expected

- `system-markdown-alternate/src/PostSupport.php`
- `system-markdown-alternate/tests/run-tests.php`
- `system-markdown-alternate/readme.txt` changelog only if released
- `system-markdown-alternate/system-markdown-alternate.php` only if released

## Release and verification checklist

For each runtime PR:

1. Keep the change atomic and use its own branch/PR.
2. Lint every touched PHP file.
3. Run `php system-markdown-alternate/tests/run-tests.php`.
4. Run the WordPress-level smoke checks noted above, including pretty and plain
   permalinks where applicable.
5. Treat either runtime fix as a patch release: bump both plugin version
   declarations, update `readme.txt` Stable tag and changelog, and rebuild the
   distributable with `bash bin/build.sh`.
6. Before merge, verify that the built ZIP still excludes Composer CLI binaries.

## Explicit non-goals

- No change to the count-only `.md` hit-counter privacy model.
- No per-visitor identifiers, raw User-Agent storage, IP storage, cookies, or
  finer-than-daily timestamps.
- No rate limiting, sitemap generation, homepage index, cache self-test, or
  loopback HTTP requests.
