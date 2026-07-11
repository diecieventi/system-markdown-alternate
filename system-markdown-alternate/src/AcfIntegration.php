<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Integrazione ACF: aggiunge il contenuto di campi specifici al sorgente Markdown.
 *
 * Opt-in tramite il filtro `sysmda_acf_field_keys`:
 *
 *   add_filter( 'sysmda_acf_field_keys', function( $keys, $post ) {
 *       return array( 'my_text_field', 'my_wysiwyg_field' );
 *   }, 10, 2 );
 *
 * I valori vengono accodati al post_content prima della conversione, quindi
 * attraversano l'intera pipeline (pulizia blocchi, DOM, URL assoluti).
 * Supporta campi text e wysiwyg; campi complessi (repeater, gallery) vanno
 * gestiti tramite filtro personalizzato su `sysmda_markdown_source_content`.
 *
 * Per sottotitolo e TL;DR, configurare `sysmda_acf_subtitle_key` e
 * `sysmda_acf_tldr_key` (tramite admin panel o filtro): vengono inseriti
 * tra il titolo H1 e il corpo dell'articolo.
 */
class AcfIntegration {

	/** @var MarkdownConverter */
	private $converter;

	/** @var ContentRenderer */
	private $renderer;

	public function __construct( MarkdownConverter $converter, ContentRenderer $renderer ) {
		$this->converter = $converter;
		$this->renderer  = $renderer;
	}

	/**
	 * Aggiunge il contenuto dei campi ACF configurati in coda al sorgente.
	 *
	 * Hook: sysmda_markdown_source_content (priorità 20).
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
		$keys = (array) apply_filters( 'sysmda_acf_field_keys', array(), $post );

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

	/**
	 * Inserisce sottotitolo e TL;DR nel preambolo Markdown (tra # Titolo e corpo).
	 *
	 * Hook: sysmda_markdown_preamble (priorità 20).
	 *
	 * @param string   $preamble Preambolo corrente.
	 * @param \WP_Post $post     Post di riferimento.
	 * @return string Preambolo con sottotitolo e/o TL;DR.
	 */
	public function build_preamble( string $preamble, \WP_Post $post ): string {
		if ( ! function_exists( 'get_field' ) ) {
			return $preamble;
		}

		/**
		 * Filtro: nome/chiave del campo ACF per il sottotitolo (testo).
		 * Stringa vuota = disabilitato.
		 */
		$subtitle_key = (string) apply_filters( 'sysmda_acf_subtitle_key', '', $post );

		/**
		 * Filtro: nome/chiave del campo ACF per il TL;DR (WYSIWYG).
		 * Stringa vuota = disabilitato.
		 */
		$tldr_key = (string) apply_filters( 'sysmda_acf_tldr_key', '', $post );

		$parts = array();

		if ( '' !== $subtitle_key ) {
			$subtitle = trim( wp_strip_all_tags( (string) get_field( $subtitle_key, $post->ID ) ) );
			if ( '' !== $subtitle ) {
				$parts[] = '*' . $subtitle . '*';
			}
		}

		if ( '' !== $tldr_key ) {
			$tldr_html = trim( (string) get_field( $tldr_key, $post->ID ) );
			if ( '' !== $tldr_html ) {
				// Passa dalla stessa pipeline del corpo (esclusioni, code, URL assoluti).
				$tldr_html = $this->renderer->render_fragment( $tldr_html, $post );
				$tldr_md   = trim( $this->converter->convert( $tldr_html ) );
				if ( '' !== $tldr_md ) {
					$parts[] = "---\n\n**TL;DR**\n\n" . $tldr_md . "\n\n---";
				}
			}
		}

		if ( empty( $parts ) ) {
			return $preamble;
		}

		return $preamble . implode( "\n\n", $parts ) . "\n\n";
	}
}
