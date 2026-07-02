<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Serve l'endpoint /llms.txt: indice dei contenuti Markdown del sito.
 *
 * Due modalità:
 * - base (default): nome sito, tagline, elenco per post type (excerpt manuale).
 * - arricchita (toggle `sma_llms_txt_enriched`): aggiunge una sintesi del sito,
 *   una sezione di contenuti in evidenza, la description per ogni voce (stessa
 *   catena del front matter) e sposta l'overflow in una sezione `Optional`
 *   (parola chiave della spec llms.txt, non tradotta). A toggle spento l'output
 *   resta identico alla modalità base.
 */
class LlmsTxtController {

	/** Chiave di cache dell'output /llms.txt. */
	const CACHE_KEY = 'sma_llms_txt';

	/** @var MetadataBuilder */
	private $metadata;

	public function __construct( MetadataBuilder $metadata ) {
		$this->metadata = $metadata;
	}

	/**
	 * Hook: template_redirect (priorità 0).
	 *
	 * Intercetta /llms.txt e serve il file di testo; per qualsiasi altro path esce subito.
	 */
	public function maybe_render_llms_txt(): void {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$expected  = $home_path . '/llms.txt';

		// Check URI per primo (string op economica) prima di leggere le opzioni.
		if ( $path !== $expected && $path !== $expected . '/' ) {
			return;
		}

		if ( '1' !== get_option( 'sma_llms_txt_enabled', '1' ) ) {
			return; // Disabilitato dal pannello admin.
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
	 * Stampa l'output /llms.txt, servendolo dalla cache se disponibile.
	 */
	private function render(): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, follow' );
		}

		/** Filtro: TTL cache di /llms.txt in secondi. 0 disabilita la cache. */
		$ttl     = (int) apply_filters( 'sma_llms_txt_cache_ttl', DAY_IN_SECONDS );
		$version = md5( SMA_VERSION . '|' . (string) get_option( 'sma_cache_salt', '0' ) );

		if ( $ttl > 0 ) {
			$cached = Cache::get( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['v'], $cached['txt'] ) && $cached['v'] === $version ) {
				echo $cached['txt']; // phpcs:ignore WordPress.Security.EscapeOutput
				return;
			}
		}

		$body = $this->build();

		if ( $ttl > 0 ) {
			Cache::set(
				self::CACHE_KEY,
				array(
					'v'   => $version,
					'txt' => $body,
				),
				$ttl
			);
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Genera il contenuto /llms.txt.
	 */
	private function build(): string {
		$post_types = PostSupport::supported_post_types();

		/** Filtro: abilita l'output arricchito (sintesi, contenuti in evidenza, description, Optional). */
		$enriched = (bool) apply_filters( 'sma_llms_txt_enriched', false );

		$lines   = array();
		$lines[] = '# ' . get_bloginfo( 'name' );

		$tagline = get_bloginfo( 'description' );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}

		if ( $enriched ) {
			/** Filtro: paragrafo di sintesi del sito, dopo la tagline ('' = nessuno). */
			$summary = trim( wp_strip_all_tags( (string) apply_filters( 'sma_llms_txt_summary', '' ) ) );
			if ( '' !== $summary ) {
				$lines[] = '';
				$lines[] = preg_replace( '/\s+/', ' ', $summary );
			}

			$key_items = $this->key_content_items();
			if ( ! empty( $key_items ) ) {
				$lines[] = '';
				$lines[] = '## ' . __( 'Key content', 'system-markdown-alternate' );
				$lines[] = '';
				foreach ( $key_items as $item ) {
					$lines[] = $item;
				}
			}
		}

		$optional = array(); // label → righe overflow (solo modalità arricchita).

		foreach ( $post_types as $post_type ) {
			$obj   = get_post_type_object( $post_type );
			$label = $obj ? $obj->labels->name : $post_type;

			/** Filtro: numero massimo di post per tipo nell'indice llms.txt. */
			$limit = (int) apply_filters( 'sma_llms_txt_max_posts', 500, $post_type );

			/**
			 * Filtro: in modalità arricchita, numero di post per tipo nella sezione
			 * principale; l'eccedenza (fino al max) finisce sotto `## Optional`.
			 */
			$main_limit = $enriched ? (int) apply_filters( 'sma_llms_txt_main_posts', 25, $post_type ) : $limit;

			$posts = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'has_password'           => false, // Esclude i contenuti protetti (come l'endpoint .md).
					'posts_per_page'         => $limit,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => $enriched, // La description arricchita legge i meta.
					'update_post_term_cache' => false,     // I termini mai.
				)
			);

			if ( empty( $posts ) ) {
				continue;
			}

			$lines[] = '';
			$lines[] = '## ' . $label;
			$lines[] = '';

			foreach ( array_values( $posts ) as $i => $post ) {
				if ( $enriched && $i >= $main_limit ) {
					$optional[ $label ][] = $this->item_line( $post, false );
					continue;
				}

				$lines[] = $this->item_line( $post, $enriched );
			}
		}

		if ( $enriched && ! empty( $optional ) ) {
			// "Optional" è una parola chiave della spec llms.txt: non si traduce.
			$lines[] = '';
			$lines[] = '## Optional';

			foreach ( $optional as $label => $items ) {
				$lines[] = '';
				$lines[] = '### ' . $label;
				$lines[] = '';
				foreach ( $items as $item ) {
					$lines[] = $item;
				}
			}
		}

		if ( $enriched ) {
			/** Filtro: blocco libero in coda a /llms.txt ('' = nessuno; gancio per policy/LLM signals). */
			$footer = trim( (string) apply_filters( 'sma_llms_txt_footer', '' ) );
			if ( '' !== $footer ) {
				$lines[] = '';
				$lines[] = $footer;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * Riga elenco per un post: `- [titolo](url.md)` + description opzionale.
	 *
	 * @param bool $with_description In modalità arricchita usa la catena description
	 *                               del front matter (Rank Math → excerpt → troncato);
	 *                               altrimenti il solo excerpt manuale (comportamento base).
	 */
	private function item_line( \WP_Post $post, bool $with_description ): string {
		$md_url = MetadataBuilder::markdown_url( $post );
		$title  = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		$description = '';

		if ( $with_description ) {
			$raw = $this->metadata->description( $post );
			if ( '' !== $raw ) {
				$description = ': ' . wp_trim_words( wp_strip_all_tags( $raw ), 30, '…' );
			}
		} elseif ( has_excerpt( $post ) ) {
			$raw         = wp_strip_all_tags( get_the_excerpt( $post ) );
			$description = ': ' . wp_trim_words( $raw, 20, '…' );
		}

		return '- [' . $title . '](' . $md_url . ')' . $description;
	}

	/**
	 * Righe della sezione "Key content": risolve le voci configurate (ID numerico
	 * o URL, una per riga), tiene solo i post servibili, deduplica per ID.
	 *
	 * @return string[]
	 */
	private function key_content_items(): array {
		/** Filtro: contenuti in evidenza per /llms.txt (ID numerici o URL). */
		$entries = (array) apply_filters( 'sma_llms_txt_key_content', array() );

		$items = array();
		$seen  = array();

		foreach ( $entries as $entry ) {
			$entry = trim( (string) $entry );
			if ( '' === $entry ) {
				continue;
			}

			$post_id = ctype_digit( $entry ) ? (int) $entry : url_to_postid( $entry );
			if ( $post_id <= 0 || isset( $seen[ $post_id ] ) ) {
				continue;
			}

			$post = get_post( $post_id );
			if ( ! $post instanceof \WP_Post || ! PostSupport::is_servable( $post ) ) {
				continue;
			}

			$seen[ $post_id ] = true;
			$items[]          = $this->item_line( $post, true );
		}

		return $items;
	}
}
