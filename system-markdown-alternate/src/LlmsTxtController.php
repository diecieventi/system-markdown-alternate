<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Serve l'endpoint /llms.txt: indice dei contenuti Markdown del sito.
 */
class LlmsTxtController {

	/**
	 * Hook: template_redirect (priorità 0).
	 *
	 * Intercetta /llms.txt e serve il file di testo; per qualsiasi altro path esce subito.
	 */
	public function maybe_render_llms_txt(): void {
		if ( get_option( 'sma_llms_txt_enabled', '1' ) !== '1' ) {
			return; // Disabilitato dal pannello admin.
		}

		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$expected  = $home_path . '/llms.txt';

		if ( $path !== $expected && $path !== $expected . '/' ) {
			return;
		}

		// Trailing slash: /llms.txt/ → 301 verso /llms.txt.
		if ( $path === $expected . '/' ) {
			wp_safe_redirect( home_url( '/llms.txt' ), 301 );
			exit;
		}

		$this->render();
		exit;
	}

	/**
	 * Genera e stampa il contenuto /llms.txt.
	 */
	private function render(): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, follow' );
		}

		$post_types = (array) apply_filters( 'sma_markdown_supported_post_types', array() );

		$lines   = array();
		$lines[] = '# ' . get_bloginfo( 'name' );

		$tagline = get_bloginfo( 'description' );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}

		foreach ( $post_types as $post_type ) {
			$obj   = get_post_type_object( $post_type );
			$label = $obj ? $obj->labels->name : $post_type;

			/** Filtro: numero massimo di post per tipo nell'indice llms.txt. */
			$limit = (int) apply_filters( 'sma_llms_txt_max_posts', 500, $post_type );

			$posts = get_posts(
				array(
					'post_type'      => $post_type,
					'post_status'    => 'publish',
					'posts_per_page' => $limit,
					'orderby'        => 'date',
					'order'          => 'DESC',
					'no_found_rows'  => true,
				)
			);

			if ( empty( $posts ) ) {
				continue;
			}

			$lines[] = '';
			$lines[] = '## ' . $label;
			$lines[] = '';

			foreach ( $posts as $post ) {
				$md_url  = MetadataBuilder::markdown_url( $post );
				$title   = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
				$excerpt = '';

				if ( has_excerpt( $post ) ) {
					$raw     = wp_strip_all_tags( get_the_excerpt( $post ) );
					$excerpt = ': ' . wp_trim_words( $raw, 20, '…' );
				}

				$lines[] = '- [' . $title . '](' . $md_url . ')' . $excerpt;
			}
		}

		echo implode( "\n", $lines ) . "\n";
	}
}
