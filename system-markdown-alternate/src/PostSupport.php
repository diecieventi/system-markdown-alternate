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
	 * Post types that are never servable, whatever the settings or the filter say.
	 *
	 * Media is always excluded (durable product decision): an attachment page is
	 * not editorial content and has no Markdown representation to offer.
	 */
	const NEVER_SERVABLE = array( 'attachment' );

	/**
	 * Supported post types (filterable). An empty list means the plugin is inactive.
	 *
	 * @return string[]
	 */
	public static function supported_post_types(): array {
		static $types = null;

		if ( null === $types ) {
			/** Filters post types that expose the .md endpoint and alternate link. */
			$types = self::sanitize_types( (array) apply_filters( 'sysmda_markdown_supported_post_types', array() ) );
		}

		return $types;
	}

	/**
	 * Normalizes a supported-types list and drops the never-servable ones.
	 *
	 * The settings page already keeps `attachment` out of the saved option, but
	 * the filter is a public extension point: enforcing the rule here keeps it
	 * true for every consumer (.md endpoint, negotiation, alternate link,
	 * /llms.txt), not just for values coming from the panel.
	 *
	 * @param array $types Raw list, as returned by the filter.
	 * @return string[] Normalized list, without duplicates or excluded types.
	 */
	public static function sanitize_types( array $types ): array {
		$clean = array();

		foreach ( $types as $type ) {
			if ( ! is_string( $type ) ) {
				continue;
			}

			$type = trim( $type );

			if ( '' === $type
				|| in_array( $type, self::NEVER_SERVABLE, true )
				|| in_array( $type, $clean, true ) ) {
				continue;
			}

			$clean[] = $type;
		}

		return $clean;
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
