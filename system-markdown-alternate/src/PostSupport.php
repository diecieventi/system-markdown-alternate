<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Shared eligibility rules for posts that expose a Markdown version.
 *
 * Single source of truth for the .md endpoint, content negotiation, alternate link,
 * shortcode [sysmda_md_url] e dynamic tag {{sysmda_md_url}}.
 */
class PostSupport {

	/**
	 * Supported post types (filterable). An empty list means the plugin is inactive.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		static $types = null;

		if ( null === $types ) {
			/** Filters post types that expose the .md endpoint and alternate link. */
			$types = (array) apply_filters( 'sysmda_markdown_supported_post_types', array() );
		}

		return $types;
	}

	/**
	 * Whether the post exposes a .md representation: supported type, published,
	 * and not password-protected.
	 */
	public static function is_servable( \WP_Post $post ): bool {
		return in_array( $post->post_type, self::supported_post_types(), true )
			&& 'publish' === $post->post_status
			&& ! post_password_required( $post );
	}
}
