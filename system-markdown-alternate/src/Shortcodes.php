<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode per inserire dinamicamente l'URL/link del Markdown nei contenuti,
 * nei bottoni e nei blocchi (Gutenberg/GenerateBlocks).
 *
 * L'URL è calcolato al volo dal permalink (nessun dato salvato a DB).
 *
 *   [sma_md_url]                         → solo l'URL del .md del post corrente
 *   [sma_md_url id="123"]                → URL del .md di un post specifico
 *   [sma_md_link]Scarica Markdown[/sma_md_link]
 *   [sma_md_link class="btn" download="1"]<svg…/> MD[/sma_md_link]
 */
class Shortcodes {

	public function register(): void {
		add_shortcode( 'sma_md_url', array( $this, 'render_url' ) );
		add_shortcode( 'sma_md_link', array( $this, 'render_link' ) );
	}

	/**
	 * [sma_md_url] → stringa URL del .md, oppure '' se il post non è servibile.
	 *
	 * @param array<string,mixed>|string $atts Attributi shortcode.
	 */
	public function render_url( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sma_md_url' );

		$post = $this->resolve_post( $atts );

		if ( ! $post instanceof \WP_Post || ! $this->is_servable( $post ) ) {
			return '';
		}

		return esc_url( MetadataBuilder::markdown_url( $post ) );
	}

	/**
	 * [sma_md_link] → tag <a> verso il .md. Se ha contenuto interno lo usa come
	 * etichetta (così puoi inserire la tua icona MD e le tue classi), altrimenti
	 * usa l'attributo `text`.
	 *
	 * Attributi:
	 *   id       ID post (default: post corrente)
	 *   class    classi CSS dell'<a> (default: sma-md-link)
	 *   text     etichetta se non c'è contenuto interno (default: "Markdown")
	 *   download "1" aggiunge l'attributo download (forza il download del file)
	 *   rel      attributo rel (default: nofollow)
	 *
	 * @param array<string,mixed>|string $atts    Attributi shortcode.
	 * @param string|null                $content Contenuto racchiuso (etichetta/icona).
	 */
	public function render_link( $atts, $content = null ): string {
		$atts = shortcode_atts(
			array(
				'id'       => 0,
				'class'    => 'sma-md-link',
				'text'     => 'Markdown',
				'download' => '0',
				'rel'      => 'nofollow',
			),
			$atts,
			'sma_md_link'
		);

		$post = $this->resolve_post( $atts );

		if ( ! $post instanceof \WP_Post || ! $this->is_servable( $post ) ) {
			return '';
		}

		$url = MetadataBuilder::markdown_url( $post );

		// Contenuto racchiuso = etichetta custom (può contenere l'icona dell'utente).
		$label = ( null !== $content && '' !== trim( $content ) )
			? do_shortcode( $content )
			: esc_html( (string) $atts['text'] );

		$attr  = ' class="' . esc_attr( (string) $atts['class'] ) . '"';
		$attr .= ' href="' . esc_url( $url ) . '"';

		if ( '' !== (string) $atts['rel'] ) {
			$attr .= ' rel="' . esc_attr( (string) $atts['rel'] ) . '"';
		}

		if ( '1' === (string) $atts['download'] ) {
			$attr .= ' download';
		}

		return '<a' . $attr . '>' . $label . '</a>';
	}

	/**
	 * Risolve il post: attributo `id`, oggetto interrogato o post nel loop.
	 *
	 * @param array<string,mixed> $atts
	 */
	private function resolve_post( array $atts ): ?\WP_Post {
		if ( ! empty( $atts['id'] ) ) {
			$post = get_post( (int) $atts['id'] );
			return $post instanceof \WP_Post ? $post : null;
		}

		$queried = get_queried_object();
		if ( $queried instanceof \WP_Post ) {
			return $queried;
		}

		$post = get_post();
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Verifica che il post esponga davvero un .md (tipo supportato, pubblicato,
	 * non protetto da password), per non generare bottoni che puntano a un 404.
	 */
	private function is_servable( \WP_Post $post ): bool {
		$types = (array) apply_filters( 'sma_markdown_supported_post_types', array() );

		return in_array( $post->post_type, $types, true )
			&& 'publish' === $post->post_status
			&& ! post_password_required( $post );
	}
}
