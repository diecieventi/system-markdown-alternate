# Implementation Plan: WordPress.org i18n and English Code Comments

> Temporary implementation plan. Delete this file after the work is completed
> and documented in the project changelog.

## Goals

1. Keep the official plugin fully compliant with the WordPress.org translation
   workflow.
2. Do not bundle local translation files or create an alternative Italian
   package.
3. Do not add or use `load_plugin_textdomain()`, `load_textdomain()`, custom MO
   path filters, or any other manual translation loader.
4. Keep English as the only source language in the plugin.
5. Convert all code comments, DocBlocks, tooling comments, and technical tooling
   messages from Italian to English.

## Decisions

- There will be only one distributable plugin:
  `DIST/system-markdown-alternate.zip`.
- Italian and other translations will be created and delivered exclusively by
  translate.wordpress.org after the plugin is published.
- No `.pot`, `.po`, `.mo`, `.l10n.php`, `languages/` directory, local language
  plugin, companion plugin, or translated ZIP will be added to the repository
  or distributable.
- The plugin will rely on WordPress core's automatic language-pack loading.
- The intentional Italian documents `AGENTS.it.md` and `README.it.md` remain in
  the repository because they are translations of project documentation, not
  plugin source code or bundled runtime translations.
- Existing temporary implementation plans may remain in Italian and are outside
  this cleanup.

## Part 1: WordPress.org internationalization audit

### Plugin metadata

Verify the main plugin header keeps:

```text
Text Domain: system-markdown-alternate
```

The text domain must continue to match the plugin slug exactly. Keep the plugin
description and all other source metadata in English. Do not add a `Domain Path`
header because no bundled translation directory exists.

Keep `Requires at least: 6.1`, which is newer than the WordPress version that
introduced automatic WordPress.org language-pack loading.

### Runtime strings

Audit every user-visible string in the official plugin and ensure that:

- the source text is English;
- translatable strings use the appropriate WordPress gettext function;
- every gettext call uses the `system-markdown-alternate` text domain;
- translated strings are escaped according to their output context;
- strings containing allowed inline HTML continue to pass through
  `wp_kses_post()`;
- dynamically formatted strings use placeholders instead of concatenating
  translatable fragments;
- every formatted translation containing placeholders has a nearby
  `translators:` comment explaining the placeholder;
- product names, protocol tokens, shortcode names, option names, filter names,
  URLs, `Optional`, and `updated:` remain unchanged where translation would
  alter a public or machine-readable contract.

Audit at minimum the settings page, dependency error notice, `/llms.txt`
headings, integration notices, field descriptions, buttons, tab labels, status
labels, accessibility labels, and conflict warnings.

### Remove manual-loading references from the official plugin

Under `system-markdown-alternate/`:

- confirm there are no calls to manual text-domain loading functions;
- remove the runtime comment in `src/Plugin.php` that discusses the previously
  removed loader;
- reword current documentation or changelog text that names a manual loading
  function, while preserving the historical meaning: translations are now
  delivered through WordPress.org language packs and bundled catalogs were
  removed;
- confirm there are no custom translation-path filters or locale overrides.

Repository development documentation may describe the policy in general terms:
the official plugin contains no bundled catalogs and relies exclusively on
WordPress.org language packs.

### Translation readiness

Do not generate or commit translation catalogs. Instead, verify that the source
is extractable by the WordPress.org translation system:

- static English source strings;
- exact plugin text domain;
- supported WordPress gettext functions;
- no variables used as gettext source strings;
- no missing text domains;
- no mixed-language source strings.

Once the plugin is accepted, create the Italian translation only on
translate.wordpress.org and request PTE status if required. No repository change
is needed when the official language pack becomes available.

## Part 2: Convert comments and tooling to English

### Included scope

Translate to English without changing behavior:

- PHP comments and DocBlocks in the plugin bootstrap, `src/`, tests, and
  `uninstall.php`;
- JavaScript and CSS comments under the plugin assets directory;
- comments and terminal messages in `bin/` scripts;
- comments, step labels, and technical messages in GitHub workflow files;
- the root package description in `composer.json`;
- test descriptions, assertion labels, and other developer-facing diagnostic
  text.

Preserve PHPDoc types, hook names, identifiers, regexes, examples, protocol
keywords, public filter documentation, and code snippets exactly where their
technical value depends on the original syntax.

### Excluded scope

Do not translate or remove:

- `AGENTS.it.md`;
- `README.it.md`;
- Italian `msgstr` values hosted in the future on translate.wordpress.org;
- third-party files under `vendor/`;
- temporary user-facing implementation plans;
- historical proper names or immutable external identifiers.

### Documentation alignment

Update `AGENTS.md` and its Italian counterpart in the same commit to record:

- English is the source language for runtime strings, code comments, DocBlocks,
  tests, build tooling, and workflow messages;
- `AGENTS.it.md` and `README.it.md` are the only intentional Italian repository
  documentation;
- runtime translations are managed exclusively by translate.wordpress.org;
- no translation files or manual translation loader belong in the plugin or
  repository.

Update `README.md` and `README.it.md` only if their current translation guidance
needs to be clarified. Keep the English document as the source of truth.

## Implementation order

1. Convert code comments, DocBlocks, tests, scripts, workflow labels, Composer
   metadata, and tooling messages to English.
2. Audit all user-visible strings and gettext calls after line numbers have
   stabilized.
3. Add missing `translators:` comments for formatted strings.
4. Remove manual translation-loader references from files included in the
   official plugin and align the repository documentation.
5. Run automated searches, PHP linting, and the test suite.
6. Build the single official ZIP and inspect its contents.
7. Update `system-markdown-alternate/readme.txt` changelog if the cleanup is
   included in a release.
8. Delete this plan after implementation.
9. Commit the cleanup atomically directly to `main`; do not create a branch or
   pull request.

## Verification

### Internationalization checks

Search the official plugin directory and require all of the following:

- zero manual translation-loader calls;
- zero bundled `.pot`, `.po`, `.mo`, or `.l10n.php` files;
- zero `languages/` directories;
- zero gettext calls without the `system-markdown-alternate` text domain;
- zero variable or dynamically constructed gettext source strings;
- zero unintended Italian user-facing source strings;
- `Text Domain: system-markdown-alternate` remains in the main plugin header.

Run Plugin Check against the final official ZIP when available and confirm that
it reports no internationalization or translation-loading issues.

### English comment checks

- Search source and tooling files for Italian accented characters and a curated
  list of common Italian technical terms.
- Review every remaining match manually.
- Accept matches only in the explicitly excluded Italian documentation or in
  immutable external content.
- Review the diff to ensure the cleanup changed comments and developer-facing
  text only, except for any necessary gettext metadata fixes.

### Code quality

Run:

```bash
find system-markdown-alternate -path '*/vendor/*' -prune -o -name '*.php' -print \
  -exec php -l {} \;
php system-markdown-alternate/tests/run-tests.php
bash bin/build.sh
```

Inspect the archive:

```bash
unzip -l DIST/system-markdown-alternate.zip
```

Confirm that it contains the production plugin and Composer dependencies, but
no tests, translation catalogs, alternative language package, or translation
loader.

## Acceptance criteria

- Only `DIST/system-markdown-alternate.zip` is produced.
- The official plugin contains no bundled translations and no manual translation
  loading code.
- All translatable user-interface strings are English, extractable, correctly
  domain-scoped, and safely escaped.
- The plugin is ready to receive official language packs from
  translate.wordpress.org without a future code change.
- All comments and technical tooling text in scope are English.
- Italian remains only in the explicitly retained translated documentation and
  temporary planning documents.
- Runtime behavior, options, filters, endpoints, cache behavior, and public APIs
  are unchanged.
- PHP lint, tests, build, archive inspection, and Plugin Check succeed.

