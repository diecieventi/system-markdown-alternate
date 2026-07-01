<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Regole di eleggibilità condivise: quali post espongono la versione Markdown.
 *
 * Punto unico di verità per endpoint .md, content negotiation, link alternate,
 * shortcode [sma_md_url] e dynamic tag {{sma_md_url}}.
 */
class PostSupport {

	/**
	 * Post type supportati (filtrabile). Lista vuota = plugin inattivo.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		static $types = null;

		if ( null === $types ) {
			/** Filtro: post type che espongono l'endpoint .md e il link alternate. */
			$types = (array) apply_filters( 'sma_markdown_supported_post_types', array() );
		}

		return $types;
	}

	/**
	 * True se il post espone davvero un .md: tipo supportato, pubblicato,
	 * non protetto da password.
	 */
	public static function is_servable( \WP_Post $post ): bool {
		return in_array( $post->post_type, self::supported_post_types(), true )
			&& 'publish' === $post->post_status
			&& ! post_password_required( $post );
	}
}
