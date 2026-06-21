<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

use League\HTMLToMarkdown\HtmlConverter;

defined( 'ABSPATH' ) || exit;

/**
 * Converte l'HTML pulito in Markdown usando league/html-to-markdown.
 */
class MarkdownConverter {

	/**
	 * @param string $html HTML pronto per la conversione.
	 * @return string Markdown del corpo (senza front matter).
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
			// Fallback robusto: estrazione testo semplice invece di rompere la risposta.
			$markdown = wp_strip_all_tags( $html );
		}

		return $this->normalize_whitespace( $markdown );
	}

	/**
	 * Pulisce spazi finali e collassa righe vuote multiple.
	 */
	private function normalize_whitespace( string $markdown ): string {
		$markdown = str_replace( "\r\n", "\n", $markdown );
		$markdown = preg_replace( '/[ \t]+\n/', "\n", $markdown );
		$markdown = preg_replace( '/\n{3,}/', "\n\n", $markdown );

		return trim( $markdown ) . "\n";
	}
}
