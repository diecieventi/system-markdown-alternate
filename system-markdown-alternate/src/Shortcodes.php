<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Shortcode [sma_md_url]: restituisce l'URL della versione Markdown del post.
 *
 * L'URL è calcolato al volo dal permalink (nessun dato salvato a DB).
 *
 *   [sma_md_url]           → URL del .md del post corrente
 *   [sma_md_url id="123"]  → URL del .md di un post specifico
 *
 * Restituisce stringa vuota se il post non è servibile (tipo non supportato,
 * non pubblicato o protetto da password), per non generare link verso un 404.
 */
class Shortcodes {

	public function register(): void {
		add_shortcode( 'sma_md_url', array( $this, 'render_url' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts Attributi shortcode.
	 */
	public function render_url( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sma_md_url' );

		$post = $this->resolve_post( (int) $atts['id'] );

		if ( ! $post instanceof \WP_Post || ! $this->is_servable( $post ) ) {
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

	/**
	 * Verifica che il post esponga davvero un .md (tipo supportato, pubblicato,
	 * non protetto da password).
	 */
	private function is_servable( \WP_Post $post ): bool {
		$types = (array) apply_filters( 'sma_markdown_supported_post_types', array() );

		return in_array( $post->post_type, $types, true )
			&& 'publish' === $post->post_status
			&& ! post_password_required( $post );
	}
}
