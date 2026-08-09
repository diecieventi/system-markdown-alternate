<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

defined( 'ABSPATH' ) || exit;

/**
 * Converts clean HTML to Markdown using league/html-to-markdown.
 */
class MarkdownConverter {

	/**
	 * @param string $html HTML ready for conversion.
	 * @return string Body Markdown (without front matter).
	 */
	public function convert( string $html ): string {
		$html = trim( $html );

		if ( '' === $html ) {
			return '';
		}

		try {
			$markdown = $this->converter()->convert( $html );
		} catch ( \Throwable $e ) {
			// Robust fallback: extract plain text instead of breaking the response.
			$markdown = wp_strip_all_tags( $html );
		}

		return $this->normalize_whitespace( $markdown );
	}

	/**
	 * Builds the configured converter.
	 *
	 * `TableConverter` ships with the library but is NOT part of its default
	 * environment: without it `strip_tags` removed every table tag and glued the
	 * cells together ("NamePriceCoffee2"), which is worse than useless for a
	 * machine-readable representation. Registered explicitly, it produces GFM
	 * pipe tables and escapes `|` inside cells.
	 */
	private function converter(): HtmlConverter {
		$converter = new HtmlConverter(
			array(
				'header_style'    => 'atx',   // # Heading
				'strip_tags'      => true,
				'remove_nodes'    => 'script style iframe',
				'hard_break'      => false,
				'list_item_style' => '-',
			)
		);

		$converter->getEnvironment()->addConverter( new TableConverter() );

		return $converter;
	}

	/**
	 * Removes trailing whitespace and collapses multiple blank lines, leaving
	 * fenced code blocks byte-for-byte alone.
	 *
	 * Both rules would otherwise rewrite the code itself: trailing spaces are
	 * significant in a Markdown sample (two of them are a hard break) and runs of
	 * blank lines carry meaning in REPL transcripts, diffs and patch bodies.
	 * The converter always fences preformatted content (PreformattedConverter and
	 * CodeConverter both emit backticks), so tracking fences covers every code
	 * block it can produce.
	 */
	private function normalize_whitespace( string $markdown ): string {
		$markdown = str_replace( "\r\n", "\n", $markdown );

		$out   = '';
		$plain = '';
		$fence = '';
		$depth = '';

		foreach ( explode( "\n", $markdown ) as $line ) {
			$marker = self::fence_marker( $line );

			if ( '' === $fence ) {
				if ( '' === $marker ) {
					$plain .= rtrim( $line, " \t" ) . "\n";
					continue;
				}

				// A fence opens: flush the normalized text collected before it.
				$out  .= self::collapse_blank_lines( $plain );
				$plain = '';
				$fence = $marker;
				$depth = self::fence_depth( $line );
				$out  .= $line . "\n";
				continue;
			}

			$out .= $line . "\n"; // Verbatim while inside the fence.

			if ( self::closes_fence( $line, $marker, $fence, $depth ) ) {
				$fence = '';
				$depth = '';
			}
		}

		$out .= self::collapse_blank_lines( $plain );

		return trim( $out ) . "\n";
	}

	/**
	 * A fence delimiter, with whatever block containers hold it.
	 *
	 * The delimiter is not always at the left margin. `<blockquote><pre>` comes
	 * out of the converter as ``> ```php ``, `<li><pre>` as ``- ```php `` with
	 * its body indented four spaces, and the two nest. Matching only
	 * `^ {0,3}` missed every one of those, so the code inside them was
	 * normalized as prose: trailing spaces (a Markdown hard break, and
	 * meaningful in a transcript or a diff) were trimmed, and runs of blank
	 * lines collapsed. That is precisely the rewriting the fence tracking
	 * exists to prevent.
	 *
	 * Being permissive here is the safe direction: a false positive preserves a
	 * region of prose verbatim, while a false negative rewrites code.
	 */
	const FENCE_PATTERN = '/^(?P<prefix>(?:[ \t]*(?:>|[-*+][ \t]|\d{1,9}[.)][ \t]))*[ \t]*)(?P<fence>`{3,}|~{3,})/';

	/**
	 * The backtick/tilde run opening or closing a fence on this line, or '' when
	 * the line is not a fence delimiter.
	 */
	private static function fence_marker( string $line ): string {
		return 1 === preg_match( self::FENCE_PATTERN, $line, $matches ) ? $matches['fence'] : '';
	}

	/**
	 * The blockquote nesting of a fence delimiter, as its run of `>`.
	 *
	 * Indentation and list markers are dropped on purpose: a fence opened as
	 * ``- ```php `` is closed by an indented ``` ``` ``` four spaces in, so
	 * comparing the literal prefixes would never match. The `>` run is the part
	 * that does stay identical, and keeping it distinguishes a fence inside a
	 * quote from one that merely follows it.
	 */
	private static function fence_depth( string $line ): string {
		if ( 1 !== preg_match( self::FENCE_PATTERN, $line, $matches ) ) {
			return '';
		}

		return (string) preg_replace( '/[^>]/', '', $matches['prefix'] );
	}

	/**
	 * Whether the line closes the fence currently open: same delimiter character,
	 * at least as long as the opening run, the same blockquote nesting, and
	 * nothing but whitespace after it (an info string marks an opening fence,
	 * never a closing one).
	 */
	private static function closes_fence( string $line, string $marker, string $fence, string $depth ): bool {
		if ( '' === $marker || $marker[0] !== $fence[0] || strlen( $marker ) < strlen( $fence ) ) {
			return false;
		}

		if ( self::fence_depth( $line ) !== $depth ) {
			return false;
		}

		$after = substr( $line, (int) strpos( $line, $marker ) + strlen( $marker ) );

		return '' === trim( $after );
	}

	/**
	 * Collapses runs of blank lines down to a single blank line.
	 */
	private static function collapse_blank_lines( string $text ): string {
		return (string) preg_replace( '/\n{3,}/', "\n\n", $text );
	}
}
