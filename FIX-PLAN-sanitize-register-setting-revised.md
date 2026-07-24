# Revised fix plan — CSS-class sanitization for `register_setting()`

This document supersedes `FIX-PLAN-sanitize-register-setting.md`. It keeps the
original fix narrow, resolves its open decision, corrects the sanitizer
semantics, and adds complete acceptance criteria.

## Outcome

Change only the `sysmda_excluded_classes` setting so every submitted CSS-class
token is normalized with WordPress core's `sanitize_html_class()`.

Do not change the behavior of the other multiline settings. They legitimately
accept values such as block names, shortcode names, IDs, and URLs, so they must
continue to use `sanitize_lines()`.

This is worth implementing because it:

- satisfies the WordPress plugin-validator request with the class-specific core
  sanitizer;
- prevents malformed administrator input from being stored as an excluded
  class;
- prevents quotes and other punctuation from reaching the XPath expression
  built by `ContentRenderer`.

## Scope

Files expected to change in the implementation PR:

- `system-markdown-alternate/src/AdminSettings.php`
- `system-markdown-alternate/tests/run-tests.php`
- `system-markdown-alternate/system-markdown-alternate.php` when releasing
- `system-markdown-alternate/readme.txt` when releasing
- `DIST/system-markdown-alternate.zip` when releasing

No change is required in `AGENTS.md`: sanitizer internals are not part of the
public product behavior documented there.

The original plan document may be removed by the implementation PR after this
revised plan has been accepted, to avoid two competing instructions.

## Important semantics

`sanitize_html_class()` normalizes a value to WordPress's restricted class-name
subset: ASCII letters, digits, hyphens, and underscores. It transforms many
invalid inputs rather than rejecting the whole entry:

- `.notice` becomes `notice`;
- `<script>` becomes `script`;
- punctuation-only entries become empty and can then be discarded.

The implementation and tests must describe this as normalization, not
validation or rejection.

## Implementation

### 1. Add a dedicated sanitizer

Add a public `sanitize_class_lines()` method next to `sanitize_lines()` in
`AdminSettings.php`:

```php
/**
 * Normalizes CSS-class tokens, removes empty entries, and deduplicates them.
 *
 * @param mixed $value
 */
public function sanitize_class_lines( $value ): string {
    $tokens = preg_split(
        '/\s+/',
        trim( (string) $value ),
        -1,
        PREG_SPLIT_NO_EMPTY
    );
    $out = array();

    foreach ( (array) $tokens as $token ) {
        $class = sanitize_html_class( $token );
        if ( '' !== $class && ! in_array( $class, $out, true ) ) {
            $out[] = $class;
        }
    }

    return implode( "\n", $out );
}
```

Whitespace splitting is the decided behavior. Although the settings UI asks
for one class per line, accepting spaces and tabs is safer for pasted class
lists. Sanitizing an entire line such as `foo bar` would instead produce the
unintended class `foobar`.

Do not use `[\r\n\s]+`: `\s+` already covers spaces, tabs, and line breaks.

### 2. Change only one registered setting

Update the `sysmda_excluded_classes` registration:

```php
register_setting(
    self::OPTION_GROUP,
    'sysmda_excluded_classes',
    array(
        'type'              => 'string',
        'sanitize_callback' => array( $this, 'sanitize_class_lines' ),
    )
);
```

Leave these callbacks unchanged:

- `sysmda_excluded_shortcodes` → `sanitize_lines()`
- `sysmda_excluded_block_names` → `sanitize_lines()`
- `sysmda_llms_txt_key_content` → `sanitize_lines()`

### 3. Add regression coverage

Extend `tests/run-tests.php` so `AdminSettings::sanitize_class_lines()` is
covered without loading WordPress:

1. Add a minimal `sanitize_html_class()` stub matching the relevant WordPress
   core behavior.
2. Require `src/AdminSettings.php`.
3. Instantiate `AdminSettings` without calling `boot()`.
4. Assert the exact cases below.

Required cases:

| Input | Expected stored value |
| --- | --- |
| `"no-md\nmd-exclude\nexclude-from-markdown"` | unchanged |
| `"foo bar\tbaz"` | `"foo\nbar\nbaz"` |
| `"foo\r\nfoo\nbar"` | `"foo\nbar"` |
| `".notice\n<custom>"` | `"notice\ncustom"` |
| `"...\n---\n___"` | `"---\n___"` |
| `""` or whitespace only | `""` |

Add one regression assertion for `sanitize_lines()` using a value containing
`/`, `:`, or a URL. This proves that the generic multiline sanitizer was not
replaced globally.

Do not make the test claim that transformed input was rejected. For example,
`<custom>` is deliberately normalized to `custom`.

## Downstream behavior

No downstream change is required for valid saved classes:

- `BlockCleaner` splits Gutenberg's `className` on whitespace and compares
  exact tokens;
- `ContentRenderer` removes DOM elements matching the saved class token;
- the built-in defaults (`no-md`, `md-exclude`,
  `exclude-from-markdown`) survive unchanged.

The public `sysmda_markdown_excluded_classes` filter can still return malformed
values after the saved option has been sanitized. Filters are developer-owned
input, so this does not block the validator fix. If desired, XPath escaping or
consumer-boundary normalization should be handled as a separate hardening
change with dedicated tests; it must not expand this surgical fix silently.

## Verification

Run:

```bash
php -l system-markdown-alternate/src/AdminSettings.php
php -l system-markdown-alternate/tests/run-tests.php
php system-markdown-alternate/tests/run-tests.php
bash bin/build.sh
```

Then run WordPress Plugin Check against the rebuilt distributable and confirm
that the `register_setting()` sanitization finding for
`sysmda_excluded_classes` is gone.

Also perform one settings-page smoke test:

1. Save valid class names separated by newlines, spaces, and tabs.
2. Reload the page and confirm they are shown one per line.
3. Confirm duplicate and punctuation-only entries are absent.
4. Confirm exclusions still work for both Gutenberg block classes and nested
   rendered HTML classes.

## Versioning and release

The plan-only PR that adds this document does not bump the plugin version.

When the implementation is released, use a patch version and complete the
repository release checklist:

1. Bump both the `Version:` header and `SYSMDA_VERSION`.
2. Update `Stable tag` and add an English changelog entry in `readme.txt`.
3. Rebuild `DIST/system-markdown-alternate.zip` with `bash bin/build.sh`.
4. Commit and push the implementation branch, then open a PR to `main`.
5. Merge only after CI and Plugin Check are green.
6. After the squash merge, run `bash bin/release-tag.sh` from the Mac.

Suggested changelog entry:

> Fixed: normalize excluded CSS-class entries with WordPress's
> class-specific sanitizer.

## Acceptance criteria

- Only `sysmda_excluded_classes` uses the new callback.
- Every whitespace-separated token is sanitized individually.
- Empty normalized values are removed.
- Duplicates are removed while preserving first-seen order.
- Existing valid classes and defaults are unchanged.
- Other multiline settings retain their current behavior.
- The pure-logic test suite covers the sanitizer.
- PHP lint, the full local tests, CI, and WordPress Plugin Check pass.
- The release ZIP is rebuilt if the implementation is released.
