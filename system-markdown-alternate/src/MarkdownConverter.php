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
				$out  .= $line . "\n";
				continue;
			}

			$out .= $line . "\n"; // Verbatim while inside the fence.

			if ( self::closes_fence( $line, $marker, $fence ) ) {
				$fence = '';
			}
		}

		$out .= self::collapse_blank_lines( $plain );

		return trim( $out ) . "\n";
	}

	/**
	 * The backtick/tilde run opening or closing a fence on this line, or '' when
	 * the line is not a fence delimiter. Up to three leading spaces are allowed
	 * (CommonMark §4.5).
	 */
	private static function fence_marker( string $line ): string {
		if ( 1 !== preg_match( '/^ {0,3}(`{3,}|~{3,})/', $line, $matches ) ) {
			return '';
		}

		return $matches[1];
	}

	/**
	 * Whether the line closes the fence currently open: same delimiter character,
	 * at least as long as the opening run, and nothing but whitespace after it
	 * (an info string marks an opening fence, never a closing one).
	 */
	private static function closes_fence( string $line, string $marker, string $fence ): bool {
		if ( '' === $marker || $marker[0] !== $fence[0] || strlen( $marker ) < strlen( $fence ) ) {
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
