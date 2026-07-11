<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the Markdown YAML front matter.
 */
class MetadataBuilder {

	/** @var ShortcodeCleaner */
	private $shortcodes;

	public function __construct( ShortcodeCleaner $shortcodes ) {
		$this->shortcodes = $shortcodes;
	}

	/**
	 * @param \WP_Post $post Reference post.
	 * @return string Front matter block (--- ... ---) with a trailing newline.
	 */
	public function build_front_matter( \WP_Post $post ): string {
		$lines = array( '---' );

		$lines[] = 'title: ' . $this->scalar( get_the_title( $post ) );
		$lines[] = 'url: ' . $this->scalar( get_permalink( $post ) );
		$lines[] = 'markdown_url: ' . $this->scalar( self::markdown_url( $post ) );
		$lines[] = 'date_published: ' . $this->scalar( get_post_time( 'c', false, $post ) );
		$lines[] = 'date_modified: ' . $this->scalar( get_post_modified_time( 'c', false, $post ) );

		$author = get_the_author_meta( 'display_name', (int) $post->post_author );
		if ( $author ) {
			$lines[] = 'author: ' . $this->scalar( $author );
		}

		$this->append_featured_image( $lines, $post );
		$this->append_terms( $lines, 'categories', $this->term_names( $post, 'category' ) );
		$this->append_terms( $lines, 'tags', $this->term_names( $post, 'post_tag' ) );

		$description = $this->description( $post );
		if ( '' !== $description ) {
			$lines[] = 'description: ' . $this->scalar( $description );
		}

		$lines[] = '---';

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Builds the Markdown version URL from the permalink (handling the trailing slash).
	 *
	 * With "Plain" permalinks (?p=123), the .md suffix cannot be used, so this
	 * falls back to `?format=markdown`, served through content negotiation on the
	 * same permalink. The same applies when the permalink has no usable path.
	 */
	public static function markdown_url( \WP_Post $post ): string {
		$permalink = (string) get_permalink( $post );

		if ( '' === (string) get_option( 'permalink_structure' ) ) {
			return add_query_arg( 'format', 'markdown', $permalink );
		}

		$parts = wp_parse_url( $permalink );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return untrailingslashit( $permalink ) . '.md';
		}

		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

		// A permalink without a path or with a query string cannot use the .md
		// suffix; negotiation with ?format=markdown always works.
		if ( '' === $path || isset( $parts['query'] ) ) {
			return add_query_arg( 'format', 'markdown', $permalink );
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'];
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . $host . $port . $path . '.md';
	}

	/**
	 * Adds featured_image (and alt text) when available.
	 *
	 * @param string[] $lines Reference to the array of front matter lines.
	 */
	private function append_featured_image( array &$lines, \WP_Post $post ): void {
		$thumb_id = get_post_thumbnail_id( $post );

		if ( ! $thumb_id ) {
			return;
		}

		$src = wp_get_attachment_image_url( $thumb_id, 'full' );

		if ( ! $src ) {
			return;
		}

		$lines[] = 'featured_image: ' . $this->scalar( $src );

		$alt = get_post_meta( $thumb_id, '_wp_attachment_image_alt', true );
		if ( is_string( $alt ) && '' !== $alt ) {
			$lines[] = 'featured_image_alt: ' . $this->scalar( $alt );
		}
	}

	/**
	 * Adds a YAML list of terms when it is not empty.
	 *
	 * @param string[] $lines Reference to the array of lines.
	 * @param string   $key   Chiave YAML (es. "categories").
	 * @param string[] $terms Term names.
	 */
	private function append_terms( array &$lines, string $key, array $terms ): void {
		if ( empty( $terms ) ) {
			return;
		}

		$lines[] = $key . ':';
		foreach ( $terms as $term ) {
			$lines[] = '  - ' . $this->scalar( $term );
		}
	}

	/**
	 * Names of a post's terms in a taxonomy.
	 *
	 * @return string[]
	 */
	private function term_names( \WP_Post $post, string $taxonomy ): array {
		$terms = get_the_terms( $post, $taxonomy );

		if ( ! is_array( $terms ) ) {
			return array();
		}

		return wp_list_pluck( $terms, 'name' );
	}

	/**
	 * Description fallback order: Rank Math => excerpt => trimmed content text.
	 *
	 * Public because LlmsTxtController reuses it for enriched index entries.
	 */
	public function description( \WP_Post $post ): string {
		$rank_math = get_post_meta( $post->ID, 'rank_math_description', true );

		// Discard only when it contains an unresolved Rank Math placeholder (%var%
		// or %var(args)%). Do not discard descriptions with a normal % (e.g. "20% off").
		if ( is_string( $rank_math ) && '' !== $rank_math
			&& ! preg_match( '/%[a-z0-9_]+(?:\([^)]*\))?%/i', $rank_math ) ) {
			return $rank_math;
		}

		if ( has_excerpt( $post ) ) {
			$excerpt = get_the_excerpt( $post );
			if ( '' !== trim( (string) $excerpt ) ) {
				return $excerpt;
			}
		}

		$raw = $post->post_content;
		$raw = $this->shortcodes->strip( $raw );   // Removes excluded shortcodes (even when they are not registered).
		$raw = strip_shortcodes( $raw );           // Removes other registered shortcodes.
		$raw = preg_replace( '/<!--.*?-->/s', ' ', $raw ); // Replaces block delimiters with spaces.
		$raw = preg_replace( '/<(script|style|iframe)\b[^>]*>.*?<\/\1\s*>/is', ' ', $raw ); // Replaces non-text nodes with spaces.
		$raw = preg_replace( '/<[^>]+>/', ' ', $raw );     // Replaces tags with spaces to prevent joined words.

		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
		$text = preg_replace( '/\s+([.,;:!?…])/u', '$1', $text ); // Removes whitespace before punctuation.

		if ( '' === $text ) {
			return '';
		}

		return $this->truncate( $text, 200 );
	}

	/**
	 * Truncates at a word boundary within $limit characters and appends an ellipsis.
	 */
	private function truncate( string $text, int $limit ): string {
		if ( mb_strlen( $text ) <= $limit ) {
			return $text;
		}

		$cut   = mb_substr( $text, 0, $limit );
		$space = mb_strrpos( $cut, ' ' );

		if ( false !== $space && $space > 0 ) {
			$cut = mb_substr( $cut, 0, $space );
		}

		return rtrim( $cut ) . '…';
	}

	/**
	 * Serializes a string as a quoted YAML scalar (escaping entities, \ and ").
	 */
	private function scalar( $value ): string {
		$value = (string) $value;
		$value = html_entity_decode( $value, ENT_QUOTES, 'UTF-8' );
		$value = wp_strip_all_tags( $value );
		$value = preg_replace( '/\s+/', ' ', $value );
		$value = trim( $value );
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( '"', '\\"', $value );

		return '"' . $value . '"';
	}
}
