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
	 * The configured ACF field values, as HTML appended to the document.
	 *
	 * Hook: sysmda_markdown_appended_html (priority 20).
	 *
	 * Moved off `sysmda_markdown_source_content` in 0.47.0, and it was a real
	 * defect rather than tidying: a post claimed by a page-builder adapter is
	 * rendered from the builder's own tree, so the filtered source — and every
	 * ACF value appended to it — was discarded. Silent since 0.46.0.
	 *
	 * Nothing else changes: ContentRenderer::render_appended() runs the same
	 * block branch the source path did, so a synced pattern referenced from an
	 * ACF field is still expanded, exactly as collect_acf_dependencies() assumes
	 * when it walks those references for the cache validator.
	 *
	 * @param string   $appended Current appended HTML.
	 * @param \WP_Post $post     Reference post.
	 * @return string Appended HTML with the ACF field values.
	 */
	public function appended_html( string $appended, \WP_Post $post ): string {
		if ( ! function_exists( 'get_field' ) ) {
			return $appended;
		}

		/**
		 * Filters ACF field keys included in Markdown.
		 *
		 * @param string[] $keys Field keys (default: none).
		 * @param \WP_Post $post Reference post.
		 */
		$keys = (array) apply_filters( 'sysmda_acf_field_keys', array(), $post );

		if ( empty( $keys ) ) {
			return $appended;
		}

		$values = array();
		foreach ( $keys as $key ) {
			$values[] = get_field( (string) $key, $post->ID );
		}

		// Shared with MetaFields so the skip rules and the separator live in one
		// place: the emptiness test is deliberately `'' === trim()` rather than
		// a falsy one, and a second copy of that reasoning is exactly what
		// drifts.
		return MetaFields::append( $appended, $values );
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
				// A text field is text: emitted raw between the delimiters, a value
				// containing Markdown punctuation was parsed instead of read, and
				// `A *literal* marker` became `*A *literal* marker*` — the emphasis
				// this line is supposed to be, split in three by the user's own
				// asterisks. escape_inline() applies the same escaping the body gets.
				$parts[] = '*' . $this->converter->escape_inline( $subtitle ) . '*';
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
