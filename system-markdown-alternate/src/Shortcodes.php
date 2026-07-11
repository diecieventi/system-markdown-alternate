<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [sysmda_md_url]: restituisce l'URL della versione Markdown del post.
 *
 * L'URL è calcolato al volo dal permalink (nessun dato salvato a DB).
 *
 *   [sysmda_md_url]           → URL del .md del post corrente
 *   [sysmda_md_url id="123"]  → URL del .md di un post specifico
 *
 * Restituisce stringa vuota se il post non è servibile (tipo non supportato,
 * non pubblicato o protetto da password), per non generare link verso un 404.
 */
class Shortcodes {

	public function register(): void {
		add_shortcode( 'sysmda_md_url', array( $this, 'render_url' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts Attributi shortcode.
	 */
	public function render_url( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sysmda_md_url' );

		$post = $this->resolve_post( (int) $atts['id'] );

		if ( ! $post instanceof \WP_Post || ! PostSupport::is_servable( $post ) ) {
			return '';
		}

		return esc_url( MetadataBuilder::markdown_url( $post ) );
	}

	/**
	 * Risolve il post: ID esplicito, oggetto interrogato o post nel loop.
	 */
	private function resolve_post( int $id ): ?\WP_Post {
		if ( $id > 0 ) {
			$post = get_post( $id );
			return $post instanceof \WP_Post ? $post : null;
		}

		$queried = get_queried_object();
		if ( $queried instanceof \WP_Post ) {
			return $queried;
		}

		$post = get_post();
		return $post instanceof \WP_Post ? $post : null;
	}
}
