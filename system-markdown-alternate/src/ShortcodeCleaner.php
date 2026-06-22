<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Rimuove gli shortcode non desiderati dal contenuto prima della conversione.
 */
class ShortcodeCleaner {

	/**
	 * @param string $content Contenuto sorgente.
	 * @return string Contenuto senza gli shortcode esclusi.
	 */
	public function strip( string $content ): string {
		$tags = $this->excluded_shortcodes();

		if ( empty( $tags ) || false === strpos( $content, '[' ) ) {
			return $content;
		}

		$pattern = get_shortcode_regex( $tags );

		$result = preg_replace_callback(
			'/' . $pattern . '/s',
			static function ( $m ) {
				// Shortcode "escapato" con [[...]]: va preservato.
				if ( '[' === $m[1] && ']' === $m[6] ) {
					return $m[0];
				}
				return '';
			},
			$content
		);

		return null === $result ? $content : $result;
	}

	/**
	 * Lista (filtrabile) degli shortcode da rimuovere.
	 *
	 * @return string[]
	 */
	private function excluded_shortcodes(): array {
		$defaults = array(
			'contact-form-7',
			'gravityform',
			'wpforms',
			'mailerlite_form',
			'lwptoc', // LuckyWP Table of Contents: navigazione, non contenuto.
		);

		/** Filtro: shortcode da escludere dal Markdown. */
		return (array) apply_filters( 'sma_markdown_excluded_shortcodes', $defaults );
	}
}
