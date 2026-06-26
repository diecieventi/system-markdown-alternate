<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Intercetta le richieste *.md e tramite content negotiation, valida il post,
 * serve il Markdown e stampa il link alternate.
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
	 * 1. Suffisso .md → risolve il post, valida, serve Markdown.
	 * 2. Content negotiation sul permalink canonico: lo stesso URL può rispondere
	 *    HTML o Markdown a seconda dell'header `Accept` (con q-values) o di
	 *    `?format=markdown`. Per ogni contenuto servibile l'URL dichiara
	 *    `Vary: Accept` così cache e proxy non mischiano le due rappresentazioni.
	 */
	public function maybe_render_markdown(): void {
		// --- Via suffisso .md ---
		$post = $this->resolve_requested_post();

		if ( $post instanceof \WP_Post ) {
			if ( ! $this->is_servable( $post ) ) {
				$this->force_404();
				return;
			}
			$this->send_headers( $post );
			echo $this->get_markdown( $post ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		// --- Content negotiation sul permalink canonico ---
		$queried = get_queried_object();
		if ( ! $queried instanceof \WP_Post || ! $this->is_servable( $queried ) ) {
			return; // Non negoziabile: WP prosegue con il rendering normale.
		}

		// Questo URL varia in base ad Accept: dichiararlo a cache/CDN/proxy,
		// sia che si risponda Markdown sia che si lasci l'HTML a WordPress.
		$this->send_vary_header();

		if ( $this->prefers_markdown() ) {
			$this->send_headers( $queried );
			echo $this->get_markdown( $queried ); // phpcs:ignore WordPress.Security.EscapeOutput
			exit;
		}

		if ( $this->should_reject_unacceptable() ) {
			$this->send_not_acceptable();
			exit;
		}

		// Default: WordPress serve l'HTML (Vary: Accept già inviato).
	}

	/**
	 * Hook: wp_head. Stampa il link alternate solo sui post/CPT supportati pubblici.
	 */
	public function print_alternate_link(): void {
		$types = $this->supported_post_types();

		// Guard esplicito: is_singular([]) in WP è true per QUALSIASI singular.
		// Senza tipi selezionati il plugin è inattivo e non deve stampare il link.
		if ( empty( $types ) || ! is_singular( $types ) ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || ! $this->is_servable( $post ) ) {
			return;
		}

		printf(
			'<link rel="alternate" type="text/markdown" href="%s" />' . "\n",
			esc_url( MetadataBuilder::markdown_url( $post ) )
		);
	}

	/**
	 * Hook: save_post / deleted_post. Elimina la cache Markdown del post e
	 * l'indice /llms.txt (così nuovi post, modifiche e cancellazioni si
	 * riflettono subito).
	 *
	 * Salta revisioni e autosave: save_post scatta di continuo durante
	 * l'editing e quegli ID non hanno cache propria.
	 */
	public function invalidate_cache( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		Cache::delete( 'sma_md_' . $post_id );
		Cache::delete( LlmsTxtController::CACHE_KEY );
	}

	// ─── Risoluzione ──────────────────────────────────────────────────────────

	/**
	 * Ricostruisce il post a partire dalla REQUEST_URI con suffisso `.md`.
	 *
	 * Gestisce query string e trailing slash (`/slug.md/` → 301 verso `/slug.md`).
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

		// Trailing slash: /slug.md/ → 301 verso /slug.md.
		if ( (bool) preg_match( '#\.md/+$#', $path ) ) {
			$target = preg_replace( '#\.md/+$#', '.md', $path );
			$query  = wp_parse_url( $request_uri, PHP_URL_QUERY );
			if ( $query ) {
				$target .= '?' . $query;
			}
			wp_safe_redirect( $target, 301 );
			exit;
		}

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
	 * True se il client preferisce esplicitamente il Markdown all'HTML.
	 *
	 * Il Markdown viene servito solo quando è richiesto in modo esplicito:
	 * - `?format=markdown` (override applicativo), oppure
	 * - `text/markdown` elencato nell'Accept con q ≥ a quello effettivo di
	 *   `text/html`.
	 *
	 * Un Accept solo-wildcard (`text/*` o il wildcard totale) o assente NON
	 * attiva il Markdown: il default del sito resta l'HTML. Così i client che
	 * mandano un Accept wildcard (curl, molte librerie HTTP) ricevono l'HTML.
	 */
	private function prefers_markdown(): bool {
		if ( isset( $_GET['format'] ) && 'markdown' === $_GET['format'] ) { // phpcs:ignore WordPress.Security.NonceVerification
			return true;
		}

		$accept = $this->accept_header();
		if ( '' === $accept ) {
			return false;
		}

		$md = AcceptNegotiator::explicit_quality( $accept, 'text/markdown' );
		if ( null === $md || $md <= 0.0 ) {
			return false;
		}

		return $md >= AcceptNegotiator::quality( $accept, 'text/html' );
	}

	/**
	 * True se l'Accept del client non accetta NESSUNA rappresentazione offerta
	 * (né HTML né Markdown, nessun wildcard) → candidato a `406 Not Acceptable`.
	 *
	 * I client reali (browser, crawler, agenti) mandano sempre `text/html` o un
	 * wildcard e non vengono mai colpiti. Disattivabile via filtro
	 * `sma_markdown_strict_406` (RFC 9110: il 406 è opzionale, è lecito servire
	 * comunque la rappresentazione di default).
	 */
	private function should_reject_unacceptable(): bool {
		/** Filtro: invia 406 quando l'Accept non accetta né HTML né Markdown. */
		if ( ! apply_filters( 'sma_markdown_strict_406', true ) ) {
			return false;
		}

		if ( isset( $_GET['format'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		$accept = $this->accept_header();
		if ( '' === $accept ) {
			return false; // Nessun Accept = accetta qualsiasi cosa.
		}

		return AcceptNegotiator::quality( $accept, 'text/html' ) <= 0.0
			&& AcceptNegotiator::quality( $accept, 'text/markdown' ) <= 0.0;
	}

	/**
	 * Header `Accept` della richiesta, normalizzato (stringa vuota se assente).
	 */
	private function accept_header(): string {
		return isset( $_SERVER['HTTP_ACCEPT'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Aggiunge `Vary: Accept` senza duplicare un Vary già presente che lo includa.
	 */
	private function send_vary_header(): void {
		if ( headers_sent() ) {
			return;
		}

		foreach ( headers_list() as $sent ) {
			if ( 0 === stripos( $sent, 'vary:' ) && false !== stripos( $sent, 'accept' ) ) {
				return; // già coperto.
			}
		}

		header( 'Vary: Accept', false );
	}

	/**
	 * Risposta `406 Not Acceptable` minimale (l'URL offre solo HTML/Markdown).
	 */
	private function send_not_acceptable(): void {
		if ( headers_sent() ) {
			return;
		}

		status_header( 406 );
		nocache_headers();
		header( 'Content-Type: text/plain; charset=utf-8' );
		echo "406 Not Acceptable\n";
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

	// ─── Validazione ──────────────────────────────────────────────────────────

	/**
	 * Verifica che il post sia servibile come Markdown.
	 */
	private function is_servable( \WP_Post $post ): bool {
		if ( ! in_array( $post->post_type, $this->supported_post_types(), true ) ) {
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
	 * Post type supportati (filtrabile).
	 *
	 * @return string[]
	 */
	private function supported_post_types(): array {
		static $types = null;

		if ( null === $types ) {
			/** Filtro: post type che espongono l'endpoint .md e il link alternate. */
			$types = (array) apply_filters( 'sma_markdown_supported_post_types', array() );
		}

		return $types;
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

	// ─── Cache & output ───────────────────────────────────────────────────────

	/**
	 * Recupera il Markdown dalla cache transient o lo rigenera.
	 *
	 * Chiave: `sma_md_{post_id}`. Il valore include un hash di versione
	 * (post_modified_gmt + versione plugin + salt impostazioni) per rilevare
	 * modifiche senza chiavi orfane. Viene invalidato proattivamente:
	 * - all'edit del post (post_modified_gmt cambia)
	 * - all'aggiornamento del plugin (SMA_VERSION cambia)
	 * - al salvataggio delle impostazioni (salt cambia, vedi AdminSettings)
	 * - dall'hook save_post tramite invalidate_cache().
	 */
	private function get_markdown( \WP_Post $post ): string {
		/** Filtro: TTL cache in secondi. 0 disabilita la cache. */
		$ttl       = (int) apply_filters( 'sma_markdown_cache_ttl', DAY_IN_SECONDS, $post );
		$cache_key = 'sma_md_' . $post->ID;
		$version   = $this->cache_version( $post );

		if ( $ttl > 0 ) {
			$cached = Cache::get( $cache_key );
			if ( is_array( $cached ) && isset( $cached['v'], $cached['md'] ) &&
				$cached['v'] === $version ) {
				return $cached['md'];
			}
		}

		$markdown = $this->build_markdown( $post );

		if ( $ttl > 0 ) {
			Cache::set(
				$cache_key,
				array(
					'v'  => $version,
					'md' => $markdown,
				),
				$ttl
			);
		}

		return $markdown;
	}

	/**
	 * Hash di validità della cache: cambia all'edit del post, all'aggiornamento
	 * del plugin o al salvataggio delle impostazioni (salt globale).
	 */
	private function cache_version( \WP_Post $post ): string {
		$salt = (string) get_option( 'sma_cache_salt', '0' );

		return md5( (string) $post->post_modified_gmt . '|' . SMA_VERSION . '|' . $salt );
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

		/** Filtro: blocco Markdown tra # Titolo e corpo (sottotitolo, TL;DR, ecc.). */
		$preamble = (string) apply_filters( 'sma_markdown_preamble', '', $post );

		$markdown = $front_matter . "\n# " . $title . "\n\n" . $preamble . $body;

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

		/**
		 * Filtro: URL canonico verso l'originale HTML (header Link rel="canonical").
		 * Stringa vuota = header non inviato.
		 */
		$canonical = apply_filters( 'sma_markdown_canonical_url', get_permalink( $post ), $post );

		if ( is_string( $canonical ) && '' !== $canonical ) {
			header( 'Link: <' . $canonical . '>; rel="canonical"', false );
		}
	}
}
