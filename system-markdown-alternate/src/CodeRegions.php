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
	 * On a masking failure (a PCRE limit on pathological input) the transform is
	 * applied to the unprotected string rather than skipped. That is deliberate
	 * and matches what each caller did before this class existed: skipping would
	 * mean publishing a raw shortcode tag on the expansion side, and publishing
	 * excluded chrome on the removal side. A damaged code sample is the milder
	 * of the two failures, and the input that triggers it is pathological.
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
				$key = $token . count( $stash ) . '-->';

				$stash[ $key ] = $matches[0];

				return $key;
			},
			$html
		);

		if ( null === $masked ) {
			return (string) $transform( $html );
		}

		$transformed = (string) $transform( $masked );

		// A transform that rewrites the placeholders leaves nothing for strtr()
		// to match, and the masked regions would then be *lost* rather than
		// restored — the code sample replaced by a stray comment-shaped token.
		// Neither caller does this (both rewrite `[shortcode]` syntax, which
		// cannot match a comment), but the failure would be silent and would
		// destroy content, so it is checked rather than assumed.
		foreach ( $stash as $key => $unused ) {
			if ( false === strpos( $transformed, $key ) ) {
				return (string) $transform( $html );
			}
		}

		return strtr( $transformed, $stash );
	}

	/**
	 * Prefix for the placeholders standing in for code regions, guaranteed not
	 * to occur in the string it is used on.
	 *
	 * Shaped like an HTML comment so a placeholder that somehow survived
	 * restoration would be invisible rather than printed as stray text.
	 */
	private static function token( string $html ): string {
		do {
			$token = '<!--sysmda-code-' . md5( uniqid( '', true ) ) . '-';
		} while ( false !== strpos( $html, $token ) );

		return $token;
	}
}
