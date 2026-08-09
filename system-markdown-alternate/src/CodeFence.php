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
	 * CommonMark strips one leading and one trailing space from a code span that
	 * has both, which is what makes the padding safe; without it a span whose
	 * content starts or ends with a backtick would merge with its own delimiter
	 * and change length.
	 */
	public static function needs_padding( string $code ): bool {
		if ( '' === $code ) {
			return false;
		}

		return '`' === $code[0] || '`' === substr( $code, -1 );
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
