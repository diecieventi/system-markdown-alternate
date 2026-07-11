<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * [sysmda_md_url] shortcode: returns the URL of the post's Markdown version.
 *
 * The URL is calculated from the permalink at runtime (no data stored in the database).
 *
 *   [sysmda_md_url]           → .md URL of the current post
 *   [sysmda_md_url id="123"]  → .md URL of a specific post
 *
 * Returns an empty string when the post is not servable (unsupported type,
 * unpublished, or password-protected), avoiding links to a 404 response.
 */
class Shortcodes {

	public function register(): void {
		add_shortcode( 'sysmda_md_url', array( $this, 'render_url' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts Shortcode attributes.
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
	 * Resolves the post from an explicit ID, the queried object, or the loop.
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
