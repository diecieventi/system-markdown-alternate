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
		$open  = null;

		foreach ( explode( "\n", $markdown ) as $line ) {
			if ( null === $open ) {
				$opens = self::fence_opens( $line );

				if ( null === $opens ) {
					$plain .= rtrim( $line, " \t" ) . "\n";
					continue;
				}

				// A fence opens: flush the normalized text collected before it.
				$out  .= self::collapse_blank_lines( $plain );
				$plain = '';
				$open  = $opens;
				$out  .= $line . "\n";
				continue;
			}

			$out .= $line . "\n"; // Verbatim while inside the fence.

			if ( self::closes_fence( $line, $open ) ) {
				$open = null;
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
	 * Everything the fence logic needs about a delimiter line, or null when the
	 * line is not one.
	 *
	 * Three fields, each earning its place:
	 * - `marker`: the backtick/tilde run itself;
	 * - `depth`: the run of `>`, i.e. the blockquote nesting. Indentation and
	 *   list markers are dropped from it on purpose — a fence opened as
	 *   ``- ```php `` is closed by an indented ``` ``` ``` four spaces in, so
	 *   comparing literal prefixes would never match, while the quote depth is
	 *   the part that does stay identical;
	 * - `indent`: the leading whitespace CommonMark counts, i.e. what is left
	 *   after the block markers. A blockquote marker takes one optional space
	 *   with it (`> ` is the marker, not one space of indentation), so that
	 *   space is discounted;
	 * - `list`: whether a list marker opened this line.
	 *
	 * @return array{marker:string,depth:string,indent:int,list:bool}|null
	 */
	private static function fence_parts( string $line ): ?array {
		if ( 1 !== preg_match( self::FENCE_PATTERN, $line, $matches ) ) {
			return null;
		}

		$prefix = $matches['prefix'];

		// Whitespace after the last block marker, with the one space a `>`
		// carries discounted.
		$tail = $prefix;
		$last = strrpos( $prefix, '>' );
		if ( false !== $last ) {
			$tail = (string) substr( $prefix, $last + 1 );
			$tail = (string) preg_replace( '/^ /', '', $tail );
		}

		return array(
			'marker' => $matches['fence'],
			'depth'  => (string) preg_replace( '/[^>]/', '', $prefix ),
			'indent' => strlen( (string) preg_replace( '/[^ \t]/', '', $tail ) ),
			'list'   => 1 === preg_match( '/[-*+]|\d/', $prefix ),
		);
	}

	/**
	 * How much indentation a fence delimiter may carry before it stops being one.
	 *
	 * CommonMark §4.5 allows up to three spaces; beyond that the line is
	 * indented content, not a delimiter. Honouring that limit is what keeps a
	 * ``` ``` ``` sitting four spaces deep INSIDE a top-level fence — an
	 * ordinary thing to find in a sample that itself shows fenced code — from
	 * closing that fence early and handing the rest of the document to the
	 * prose rules.
	 */
	const MAX_FENCE_INDENT = 3;

	/**
	 * The fence opened by this line, or null when it opens none.
	 */
	private static function fence_opens( string $line ): ?array {
		$parts = self::fence_parts( $line );

		if ( null === $parts || $parts['indent'] > self::MAX_FENCE_INDENT ) {
			return null;
		}

		return $parts;
	}

	/**
	 * Whether the line closes the fence currently open: same delimiter character,
	 * at least as long as the opening run, the same blockquote nesting, an
	 * acceptable indentation, and nothing but whitespace after it (an info string
	 * marks an opening fence, never a closing one).
	 *
	 * The indentation rule is where the two nesting shapes part company. A fence
	 * opened by a LIST marker (``- ```php ``) has its body — closing delimiter
	 * included — indented to the item's content column, four spaces in the
	 * converter's output, and that indentation is structural rather than
	 * content. Anywhere else the CommonMark limit applies, so an indented
	 * delimiter is code and leaves the fence open.
	 *
	 * @param array $open The parts of the line that opened the fence.
	 */
	private static function closes_fence( string $line, array $open ): bool {
		$parts = self::fence_parts( $line );

		if ( null === $parts ) {
			return false;
		}

		if ( $parts['marker'][0] !== $open['marker'][0]
			|| strlen( $parts['marker'] ) < strlen( $open['marker'] )
			|| $parts['depth'] !== $open['depth'] ) {
			return false;
		}

		if ( ! $open['list'] && $parts['indent'] > self::MAX_FENCE_INDENT ) {
			return false;
		}

		$after = substr( $line, (int) strpos( $line, $parts['marker'] ) + strlen( $parts['marker'] ) );

		return '' === trim( $after );
	}

	/**
	 * Collapses runs of blank lines down to a single blank line.
	 */
	private static function collapse_blank_lines( string $text ): string {
		return (string) preg_replace( '/\n{3,}/', "\n\n", $text );
	}
}
