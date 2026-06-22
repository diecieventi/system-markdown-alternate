<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Costruisce il front matter YAML del Markdown.
 */
class MetadataBuilder {

	/** @var ShortcodeCleaner */
	private $shortcodes;

	public function __construct( ShortcodeCleaner $shortcodes ) {
		$this->shortcodes = $shortcodes;
	}

	/**
	 * @param \WP_Post $post Post di riferimento.
	 * @return string Blocco front matter (--- ... ---) con newline finale.
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
	 * URL della versione Markdown a partire dal permalink (gestendo il trailing slash).
	 */
	public static function markdown_url( \WP_Post $post ): string {
		$permalink = get_permalink( $post );
		$parts     = wp_parse_url( $permalink );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return untrailingslashit( (string) $permalink ) . '.md';
		}

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : 'https';
		$host   = $parts['host'];
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$path   = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';

		if ( '' === $path ) {
			$path = '/index';
		}

		return $scheme . '://' . $host . $port . $path . '.md';
	}

	/**
	 * Aggiunge featured_image (+ alt) se presente.
	 *
	 * @param string[] $lines Riferimento all'array di righe del front matter.
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
	 * Aggiunge una lista YAML di termini, se non vuota.
	 *
	 * @param string[] $lines Riferimento all'array di righe.
	 * @param string   $key   Chiave YAML (es. "categories").
	 * @param string[] $terms Nomi dei termini.
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
	 * Nomi dei termini di una tassonomia per il post.
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
	 * Description in ordine: Rank Math → excerpt → testo del contenuto troncato.
	 */
	private function description( \WP_Post $post ): string {
		$rank_math = get_post_meta( $post->ID, 'rank_math_description', true );

		// Scarta solo se contiene un placeholder Rank Math non risolto (%var% o
		// %var(args)%). Non scartare descrizioni con % "normale" (es. "Sconto 20%").
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
		$raw = $this->shortcodes->strip( $raw );   // Rimuove gli shortcode esclusi (anche se non registrati).
		$raw = strip_shortcodes( $raw );           // Rimuove gli altri shortcode registrati.
		$raw = preg_replace( '/<!--.*?-->/s', ' ', $raw ); // Delimitatori di blocco → spazio.
		$raw = preg_replace( '/<[^>]+>/', ' ', $raw );     // Tag → spazio (evita parole concatenate).

		$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
		$text = preg_replace( '/\s+([.,;:!?…])/u', '$1', $text ); // Niente spazio prima della punteggiatura.

		if ( '' === $text ) {
			return '';
		}

		return $this->truncate( $text, 200 );
	}

	/**
	 * Tronca al confine di parola entro $limit caratteri, aggiungendo un ellissi.
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
	 * Serializza una stringa come scalare YAML quotato (escape di entità, \ e ").
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
