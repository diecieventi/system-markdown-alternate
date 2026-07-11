<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * ACF integration: adds specific field content to the Markdown source.
 *
 * Opt in through the `sysmda_acf_field_keys` filter:
 *
 *   add_filter( 'sysmda_acf_field_keys', function( $keys, $post ) {
 *       return array( 'my_text_field', 'my_wysiwyg_field' );
 *   }, 10, 2 );
 *
 * Values are appended to post_content before conversion, so they pass through
 * the full pipeline (block cleaning, DOM processing, absolute URLs). Text and
 * WYSIWYG fields are supported; complex fields (repeater, gallery) should be
 * handled with a custom `sysmda_markdown_source_content` filter.
 *
 * Configure `sysmda_acf_subtitle_key` and `sysmda_acf_tldr_key` (through the
 * admin panel or a filter) for subtitle and TL;DR content inserted between the
 * H1 title and article body.
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
	 * Appends configured ACF field content to the source.
	 *
	 * Hook: sysmda_markdown_source_content (priority 20).
	 *
	 * @param string   $content Current source content.
	 * @param \WP_Post $post    Reference post.
	 * @return string Content with appended ACF fields.
	 */
	public function append_fields( string $content, \WP_Post $post ): string {
		if ( ! function_exists( 'get_field' ) ) {
			return $content;
		}

		/**
		 * Filters ACF field keys included in Markdown.
		 *
		 * @param string[] $keys Field keys (default: none).
		 * @param \WP_Post $post Reference post.
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
	 * Inserts the subtitle and TL;DR in the Markdown preamble (between title and body).
	 *
	 * Hook: sysmda_markdown_preamble (priority 20).
	 *
	 * @param string   $preamble Current preamble.
	 * @param \WP_Post $post     Reference post.
	 * @return string Preamble with subtitle and/or TL;DR.
	 */
	public function build_preamble( string $preamble, \WP_Post $post ): string {
		if ( ! function_exists( 'get_field' ) ) {
			return $preamble;
		}

		/**
		 * Filters the ACF field name/key for the subtitle (text).
		 * An empty string disables it.
		 */
		$subtitle_key = (string) apply_filters( 'sysmda_acf_subtitle_key', '', $post );

		/**
		 * Filters the ACF field name/key for the TL;DR (WYSIWYG).
		 * An empty string disables it.
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
				// Use the same pipeline as the body (exclusions, code, absolute URLs).
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
