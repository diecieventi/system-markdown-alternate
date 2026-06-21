<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Intercetta le richieste *.md, valida il post, serve il Markdown e stampa il link alternate.
 */
class MarkdownController {

	/** @var ContentRenderer */
	private $renderer;

	/** @var MarkdownConverter */
	private $converter;

	/** @var MetadataBuilder */
	private $metadata;

	public function __construct( ContentRenderer $renderer, MarkdownConverter $converter, MetadataBuilder $metadata ) {
		$this->renderer  = $renderer;
		$this->converter = $converter;
		$this->metadata  = $metadata;
	}

	/**
	 * Hook: template_redirect (priorità 0).
	 *
	 * Rileva il suffisso `.md`, risolve e valida il post, quindi serve il Markdown.
	 * Per le richieste non `.md` esce subito lasciando proseguire WordPress.
	 */
	public function maybe_render_markdown(): void {
		$post = $this->resolve_requested_post();

		if ( ! $post instanceof \WP_Post ) {
			return; // Non è una richiesta .md gestibile: WordPress prosegue normalmente.
		}

		if ( ! $this->is_servable( $post ) ) {
			$this->force_404();
			return; // Lascia che WordPress renderizzi il template 404.
		}

		$markdown = $this->get_markdown( $post );

		$this->send_headers( $post );

		echo $markdown; // phpcs:ignore WordPress.Security.EscapeOutput -- output Markdown grezzo voluto.
		exit;
	}

	/**
	 * Hook: wp_head. Stampa il link alternate solo sui singoli articoli pubblici.
	 */
	public function print_alternate_link(): void {
		if ( ! is_singular( 'post' ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || 'publish' !== $post->post_status || post_password_required( $post ) ) {
			return;
		}

		printf(
			'<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
			esc_url( MetadataBuilder::markdown_url( $post ) )
		);
	}

	/**
	 * Ricostruisce il post a partire dalla REQUEST_URI con suffisso `.md`.
	 *
	 * Gestisce query string e trailing slash (`/slug.md/` → 301 verso `/slug.md`).
	 * Usa l'host fidato di home_url() e url_to_postid() (richiede permalink "puri").
	 */
	private function resolve_requested_post(): ?\WP_Post {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return null;
		}

		$request_uri = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path        = wp_parse_url( $request_uri, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			return null;
		}

		$path = rawurldecode( $path );

		// Trailing slash: /slug.md/ → 301 verso /slug.md (preservando la query string).
		if ( (bool) preg_match( '#\.md/+$#', $path ) ) {
			$target = preg_replace( '#\.md/+$#', '.md', $path );
			$query  = wp_parse_url( $request_uri, PHP_URL_QUERY );
			if ( $query ) {
				$target .= '?' . $query;
			}
			wp_safe_redirect( $target, 301 );
			exit;
		}

		// Deve terminare con `.md`.
		if ( '.md' !== substr( $path, -3 ) ) {
			return null;
		}

		$clean_path = substr( $path, 0, -3 );

		$post_id = url_to_postid( $this->build_site_url( $clean_path ) );

		if ( ! $post_id ) {
			$post_id = url_to_postid( $this->build_site_url( trailingslashit( $clean_path ) ) );
		}

		if ( ! $post_id ) {
			return null;
		}

		$post = get_post( $post_id );

		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Compone uno URL assoluto usando scheme/host fidati di home_url() e il path della richiesta.
	 * Robusto anche per install in sottocartella ed evita lo spoofing di HTTP_HOST.
	 */
	private function build_site_url( string $path ): string {
		$home  = wp_parse_url( home_url() );
		$parts = is_array( $home ) ? $home : array();

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : ( is_ssl() ? 'https' : 'http' );
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . $host . $port . '/' . ltrim( $path, '/' );
	}

	/**
	 * Verifica che il post sia servibile come Markdown.
	 */
	private function is_servable( \WP_Post $post ): bool {
		if ( 'post' !== $post->post_type ) {
			return false;
		}

		if ( 'publish' !== $post->post_status ) {
			return false;
		}

		if ( post_password_required( $post ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Imposta lo stato 404 lasciando a WordPress il rendering del template.
	 */
	private function force_404(): void {
		global $wp_query;

		if ( $wp_query instanceof \WP_Query ) {
			$wp_query->set_404();
		}

		status_header( 404 );
		nocache_headers();
	}

	/**
	 * Recupera il Markdown dalla cache transient o lo rigenera.
	 */
	private function get_markdown( \WP_Post $post ): string {
		/** Filtro: TTL cache in secondi. 0 disabilita la cache. */
		$ttl       = (int) apply_filters( 'sma_markdown_cache_ttl', DAY_IN_SECONDS, $post );
		$cache_key = 'sma_md_' . $post->ID . '_' . md5( (string) $post->post_modified_gmt );

		if ( $ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( is_string( $cached ) && '' !== $cached ) {
				return $cached;
			}
		}

		$markdown = $this->build_markdown( $post );

		if ( $ttl > 0 ) {
			set_transient( $cache_key, $markdown, $ttl );
		}

		return $markdown;
	}

	/**
	 * Assembla front matter + titolo H1 + corpo convertito.
	 */
	private function build_markdown( \WP_Post $post ): string {
		$front_matter = $this->metadata->build_front_matter( $post );
		$html         = $this->renderer->render( $post );
		$body         = $this->converter->convert( $html );

		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
		$title = trim( preg_replace( '/\s+/', ' ', $title ) );

		$markdown = $front_matter . "\n# " . $title . "\n\n" . $body;

		/** Filtro: Markdown finale (front matter + contenuto). */
		$markdown = apply_filters( 'sma_markdown_output', $markdown, $post );

		return rtrim( $markdown ) . "\n";
	}

	/**
	 * Invia gli header HTTP per la risposta Markdown.
	 */
	private function send_headers( \WP_Post $post ): void {
		if ( headers_sent() ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );

		/** Filtro: header X-Robots-Tag. Stringa vuota = header non inviato. */
		$robots = apply_filters( 'sma_markdown_robots_header', 'noindex, follow', $post );

		if ( is_string( $robots ) && '' !== $robots ) {
			header( 'X-Robots-Tag: ' . $robots );
		}
	}
}
