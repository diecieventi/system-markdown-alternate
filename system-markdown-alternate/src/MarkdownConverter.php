<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

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
			$converter = new HtmlConverter(
				array(
					'header_style'    => 'atx',   // # Heading
					'strip_tags'      => true,
					'remove_nodes'    => 'script style iframe',
					'hard_break'      => false,
					'list_item_style' => '-',
				)
			);

			$markdown = $converter->convert( $html );
		} catch ( \Throwable $e ) {
			// Robust fallback: extract plain text instead of breaking the response.
			$markdown = wp_strip_all_tags( $html );
		}

		return $this->normalize_whitespace( $markdown );
	}

	/**
	 * Removes trailing whitespace and collapses multiple blank lines.
	 */
	private function normalize_whitespace( string $markdown ): string {
		$markdown = str_replace( "\r\n", "\n", $markdown );
		$markdown = preg_replace( '/[ \t]+\n/', "\n", $markdown );
		$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );

		return trim( $markdown ) . "\n";
	}
}
