<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Runs a string transformation with the inside of `<pre>` and `<code>` hidden
 * from it.
 *
 * Exists because "a shortcode shown inside a code sample is documentation, not
 * an instruction" is one rule, and it was implemented as half of one. `0.38.1`
 * protected code regions from shortcode *expansion*, so a sample showing
 * `[gallery]` stopped being rewritten into whatever the shortcode renders. The
 * *removal* pass — `ShortcodeCleaner::strip()`, which runs earlier and on the
 * raw source — had no such protection, so an article documenting an excluded
 * tag had it deleted from its own code block: `echo do_shortcode('');`. Same
 * rule, same content, opposite halves of the pipeline, one of them missing.
 *
 * Both callers now go through here, which is the point: a shared helper cannot
 * be applied to one side and forgotten on the other. Only the *inside* of a
 * code region is hidden, so a shortcode wrapping one still runs, and
 * WordPress's own `[[tag]]` escape stays the way to keep literal brackets
 * outside code.
 *
 * No WordPress dependency: pure string work, directly testable.
 */
final class CodeRegions {

	/**
	 * Applies $transform to $html, leaving the content of `<pre>` and `<code>`
	 * elements untouched.
	 *
	 * **The transform runs at most once.** An enclosing shortcode is entitled to
	 * rewrite, escape or discard the body it is handed, so a placeholder can
	 * legitimately fail to come back — and re-running the transform on the
	 * unmasked string to "recover" would be worse than the problem twice over:
	 * it would expand `[gallery]` inside the very code sample this class exists
	 * to protect, and it would run every wrapper's side effects a second time.
	 * Regions whose placeholder survives are restored; one that a wrapper
	 * consumed stays consumed, which is that wrapper's decision, not this
	 * helper's to undo.
	 *
	 * The one exception is a masking failure (a PCRE limit on pathological
	 * input): nothing was masked and the transform has not run at all, so
	 * running it unprotected is a first attempt rather than a repeat. That is
	 * what each caller did before this class existed — skipping would publish a
	 * raw shortcode tag on the expansion side and excluded chrome on the removal
	 * side, both worse than a damaged sample on input nobody writes by hand.
	 *
	 * @param string   $html      Markup to transform.
	 * @param callable $transform Receives the masked string, returns the result.
	 */
	public static function protect( string $html, callable $transform ): string {
		$stash = array();
		$token = self::token( $html );

		$masked = preg_replace_callback(
			'#<(pre|code)\b[^>]*>.*?</\1\s*>#is',
			static function ( $matches ) use ( &$stash, $token ) {
				$key = $token . count( $stash );

				$stash[ $key ] = $matches[0];

				return $key;
			},
			$html
		);

		if ( null === $masked ) {
			return (string) $transform( $html );
		}

		// strtr() leaves an absent key alone, so this is already the partial
		// restore described above: no separate "did they all survive?" pass, and
		// nothing to decide when one did not.
		return strtr( (string) $transform( $masked ), $stash );
	}

	/**
	 * Prefix for the placeholders standing in for code regions, guaranteed not
	 * to occur in the string it is used on.
	 *
	 * Deliberately `[A-Za-z0-9_]` only — no angle brackets, no ampersand, and no
	 * `--`. The placeholder is handed to arbitrary shortcode callbacks, and the
	 * transformations they routinely apply to their own body are exactly the
	 * ones that would mangle a livelier token: `esc_html()` would rewrite an
	 * HTML-comment-shaped one, and `wptexturize()` (reached through any callback
	 * that runs `the_content`) turns `--` into an en dash. A token made of word
	 * characters passes through both untouched and is restored normally, which
	 * removes the most plausible way for a region to go missing rather than
	 * handling it after the fact.
	 */
	private static function token( string $html ): string {
		do {
			$token = 'sysmda_code_' . md5( uniqid( '', true ) ) . '_';
		} while ( false !== strpos( $html, $token ) );

		return $token;
	}
}
