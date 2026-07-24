# Fix plan — Sanitization for `register_setting()` (CSS classes)

Reported by the wordpress.org plugin validator ("Sanitization for
`register_setting()`").

## Problem

`src/AdminSettings.php:157` registers the `sysmda_excluded_classes` option using
the generic `sanitize_lines()` callback:

```php
register_setting( self::OPTION_GROUP, 'sysmda_excluded_classes',
    array( 'type' => 'string', 'sanitize_callback' => array( $this, 'sanitize_lines' ) ) );
```

`sanitize_lines()` (`src/AdminSettings.php:259-271`) applies
`sanitize_text_field()` to each line. For **CSS class names** this is too
permissive: `sanitize_text_field()` lets through spaces, dots, `<`, and other
characters that are not valid in an HTML class. The validator asks for the
class-specific sanitizer `sanitize_html_class` (allows alphanumerics, `-`, `_`).

The other options that share `sanitize_lines()` must **not** change:
`sysmda_excluded_block_names` (`gravityforms/form`), `sysmda_excluded_shortcodes`
and `sysmda_llms_txt_key_content` (URLs/IDs) legitimately contain `/`, `:`, etc.,
which `sanitize_html_class` would strip. The fix is surgical: only
`sysmda_excluded_classes`.

### No regression downstream

The excluded classes are consumed in `src/BlockCleaner.php:170` and
`src/ContentRenderer.php:119` as a comparison against `className`. The defaults
(`no-md`, `md-exclude`, `exclude-from-markdown`) and any real class use only
characters that `sanitize_html_class` preserves, so matching is unchanged.

## Proposed solution

### 1. New dedicated sanitizer in `AdminSettings.php`

Add next to `sanitize_lines()` (around line 271):

```php
/**
 * Normalizes a "one CSS class per line" textarea: sanitizes each entry with
 * sanitize_html_class (class-specific), drops empty/invalid entries and
 * deduplicates. Space-separated classes on a single line are split into
 * separate entries.
 *
 * @param mixed $value
 */
public function sanitize_class_lines( $value ): string {
    $lines = preg_split( '/[\r\n\s]+/', (string) $value );
    $out   = array();

    foreach ( (array) $lines as $line ) {
        $line = sanitize_html_class( $line );
        if ( '' !== $line && ! in_array( $line, $out, true ) ) {
            $out[] = $line;
        }
    }

    return implode( "\n", $out );
}
```

Note: splitting on whitespace too means a line with several classes (`foo bar`)
becomes two valid entries instead of being merged into `foobar` by
`sanitize_html_class`. Alternative (strict "one class per line", consistent with
`sanitize_lines`): use `preg_split( '/\r\n|\r|\n/', ... )` instead — **decision
to confirm**.

### 2. Change only the callback at line 157

```php
'sanitize_callback' => array( $this, 'sanitize_class_lines' )
```

## Verification

- `php -l system-markdown-alternate/src/AdminSettings.php`
- `php system-markdown-alternate/tests/run-tests.php`
- Recommended: add a case in `tests/run-tests.php` for `sanitize_class_lines`
  (input `"no-md\n.md exclude\n<script>"` → expected `"no-md\nmd\nexclude\nscript"`
  or similar), since the sanitizers currently have no coverage.

## Docs / versioning

- Patch bump in `system-markdown-alternate.php` (`Version:` + `SYSMDA_VERSION`).
- `readme.txt`: bump `Stable tag` + changelog entry
  ("Fixed: excluded CSS classes now sanitized with `sanitize_html_class`").
- No `AGENTS.md` change needed (behaviour unchanged).

## Open decision

Split on whitespace (more robust for multi-class lines) **or** strict
one-class-per-line (consistent with `sanitize_lines`)?
