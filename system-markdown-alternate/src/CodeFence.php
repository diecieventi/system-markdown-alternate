<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Chooses Markdown code delimiters that the code itself cannot break out of.
 *
 * `league/html-to-markdown` hardcodes three backticks for a block and one for a
 * span, without ever looking at what it is wrapping. Content containing a
 * delimiter run therefore terminates its own code block: a sample that shows a
 * fenced snippet (or a shell heredoc, or a Markdown tutorial) closes the fence
 * early, the remainder of the sample is re-read as prose, and the trailing
 * delimiter opens a *new* fence that swallows the rest of the document — heading
 * and all. The same applies inline to `` `foo ` bar` ``.
 *
 * CommonMark §6.1 / §4.5 already define the way out: a fence is closed only by a
 * run of the same character at least as long as the opening one, so an opening
 * run longer than anything inside the content can never be closed from within.
 */
class CodeFence {

	/** CommonMark's minimum fence length. */
	const MIN_LENGTH = 3;

	/**
	 * Opening/closing delimiter for a fenced block: longer than the longest
	 * backtick run in the code, and never shorter than three.
	 */
	public static function block_delimiter( string $code ): string {
		return str_repeat( '`', max( self::MIN_LENGTH, self::longest_run( $code ) + 1 ) );
	}

	/**
	 * Delimiter for an inline code span: longer than the longest backtick run,
	 * and never shorter than one.
	 */
	public static function inline_delimiter( string $code ): string {
		return str_repeat( '`', self::longest_run( $code ) + 1 );
	}

	/**
	 * Whether an inline span needs a space of padding inside its delimiters.
	 *
	 * Padding separates a boundary backtick from the delimiter. It also preserves
	 * a value that already begins and ends with an ASCII space: CommonMark §6.1
	 * removes one such symmetric pair unless the value consists only of spaces.
	 */
	public static function needs_padding( string $code ): bool {
		if ( '' === $code ) {
			return false;
		}

		if ( '`' === $code[0] || '`' === substr( $code, -1 ) ) {
			return true;
		}

		return ' ' === $code[0]
			&& ' ' === substr( $code, -1 )
			&& '' !== trim( $code, ' ' );
	}

	/**
	 * An info string that cannot itself terminate or corrupt the fence: a
	 * backtick is never allowed in one (CommonMark §4.5), and neither is a line
	 * break.
	 */
	public static function info_string( string $language ): string {
		$language = (string) preg_replace( '/[^A-Za-z0-9#+._-]/', '', $language );

		return $language;
	}

	/**
	 * Whether the text is already exactly one fenced block that nothing inside
	 * it can break out of.
	 *
	 * This is the test for "has a converter already fenced this?", and it has to
	 * be structural. Asking whether the element still has a `<code>` child does
	 * not work — by the time a `<pre>` is converted its children have been
	 * replaced by their own Markdown (`setFinalMarkdown()`) — and asking whether
	 * the string merely starts and ends with a backtick, which is what the
	 * library does and what this replaced, accepts two very different things it
	 * should not: preformatted text that merely happens to begin and end with a
	 * backtick, and a `<pre>` holding several `<code>` children whose fences sit
	 * side by side. Both were then emitted with no fence of their own, so an
	 * interior ``` line opened an unterminated fence and swallowed the rest of
	 * the document — the exact defect this class exists to prevent.
	 *
	 * Safe means all three: the first line opens a fence, the last line closes
	 * it with at least as long a run, and no line in between could close it.
	 */
	public static function is_safely_fenced( string $text ): bool {
		$lines = explode( "\n", trim( $text ) );

		if ( count( $lines ) < 2 ) {
			return false;
		}

		$first = array_shift( $lines );
		$last  = array_pop( $lines );

		// An info string may not contain a backtick (CommonMark §4.5), so a
		// first line that has one later is not a fence opener at all.
		if ( 1 !== preg_match( '/^(`{' . self::MIN_LENGTH . ',})[^`]*$/', $first, $open ) ) {
			return false;
		}

		$length = strlen( $open[1] );

		// A closing fence carries no info string.
		if ( 1 !== preg_match( '/^(`{' . self::MIN_LENGTH . ',})[ \t]*$/', $last, $close )
			|| strlen( $close[1] ) < $length ) {
			return false;
		}

		foreach ( $lines as $line ) {
			if ( 1 === preg_match( '/^[ \t]{0,3}`{' . $length . ',}[ \t]*$/', $line ) ) {
				return false; // An interior line would end the block early.
			}
		}

		return true;
	}

	/**
	 * Length of the longest unbroken run of backticks in the text.
	 */
	public static function longest_run( string $text ): int {
		if ( ! preg_match_all( '/`+/', $text, $matches ) ) {
			return 0;
		}

		$longest = 0;
		foreach ( $matches[0] as $run ) {
			$longest = max( $longest, strlen( $run ) );
		}

		return $longest;
	}
}
