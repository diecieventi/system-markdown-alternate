<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Integrazione ACF: aggiunge il contenuto di campi specifici al sorgente Markdown.
 *
 * Opt-in tramite il filtro `sma_acf_field_keys`:
 *
 *   add_filter( 'sma_acf_field_keys', function( $keys, $post ) {
 *       return array( 'my_text_field', 'my_wysiwyg_field' );
 *   }, 10, 2 );
 *
 * I valori vengono accodati al post_content prima della conversione, quindi
 * attraversano l'intera pipeline (pulizia blocchi, DOM, URL assoluti).
 * Supporta campi text e wysiwyg; campi complessi (repeater, gallery) vanno
 * gestiti tramite filtro personalizzato su `sma_markdown_source_content`.
 */
class AcfIntegration {

	/**
	 * Aggiunge il contenuto dei campi ACF configurati in coda al sorgente.
	 *
	 * Hook: sma_markdown_source_content (priorità 20).
	 *
	 * @param string   $content Contenuto sorgente corrente.
	 * @param \WP_Post $post    Post di riferimento.
	 * @return string Contenuto con i campi ACF accodati.
	 */
	public function append_fields( string $content, \WP_Post $post ): string {
		if ( ! function_exists( 'get_field' ) ) {
			return $content;
		}

		/**
		 * Filtro: chiavi dei campi ACF da includere nel Markdown.
		 *
		 * @param string[]  $keys Chiavi dei campi (default: nessuno).
		 * @param \WP_Post  $post Post di riferimento.
		 */
		$keys = (array) apply_filters( 'sma_acf_field_keys', array(), $post );

		if ( empty( $keys ) ) {
			return $content;
		}

		$extra = '';
		foreach ( $keys as $key ) {
			$value = get_field( (string) $key, $post->ID );

			if ( ! $value || ! is_string( $value ) ) {
				continue;
			}

			$value = trim( $value );
			if ( '' === $value ) {
				continue;
			}

			$extra .= '<div>' . $value . '</div>';
		}

		return $content . $extra;
	}
}
