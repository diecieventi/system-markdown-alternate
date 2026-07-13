<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Intercepts *.md requests and content negotiation, validates the post,
 * serves Markdown, and prints the alternate link.
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
	 * Hook: template_redirect (priority 0).
	 *
	 * 1. .md suffix: resolves the post, validates it, and serves Markdown.
	 * 2. Content negotiation on the canonical permalink: the same URL can return
	 *    HTML or Markdown depending on the `Accept` header (with q-values) or
	 *    `?format=markdown`. Every servable URL declares `Vary: Accept` so caches
	 *    and proxies do not mix the two representations.
	 */
	public function maybe_render_markdown(): void {
		// --- .md suffix route ---
		$post = $this->resolve_requested_post();

		if ( $post instanceof \WP_Post ) {
			if ( ! $this->is_servable( $post ) ) {
				$this->force_404();
				return;
			}
			$this->serve_markdown( $post );
		}

		// --- Content negotiation on the canonical permalink ---
		$queried = get_queried_object();
		if ( ! $queried instanceof \WP_Post || ! $this->is_servable( $queried ) ) {
			return; // Not negotiable: WP continues with normal rendering.
		}

		// This URL varies by Accept: declare it to caches/CDNs/proxies whether
		// responding with Markdown or leaving HTML rendering to WordPress.
		$this->send_vary_header();

		if ( $this->prefers_markdown() ) {
			// The negotiated Markdown shares its URL with the HTML page: page
			// caches that key by URL only (observed on some LiteSpeed setups,
			// which ignore Vary: Accept) must never store this variant, or it
			// would be served to HTML clients too. .md URLs stay cacheable.
			LiteSpeedCompat::mark_nocache();
			$this->serve_markdown( $queried );
		}

		if ( $this->should_reject_unacceptable() ) {
			LiteSpeedCompat::mark_nocache();
			$this->send_not_acceptable();
			exit;
		}

		// Default: WordPress serves HTML (Vary: Accept already sent).
	}

	/**
	 * Hook: wp_head. Prints the alternate link only on supported public posts/CPTs.
	 */
	public function print_alternate_link(): void {
		$types = PostSupport::supported_post_types();

		// Explicit guard: is_singular([]) in WP is true for ANY singular content.
		// With no selected types, the plugin is inactive and must not print the link.
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
	 * Hook: save_post / deleted_post. Deletes the post's Markdown cache and the
	 * /llms.txt index so new posts, changes, and deletions are reflected immediately.
	 *
	 * Skips revisions and autosaves: save_post fires continuously while editing,
	 * and those IDs do not have their own cache.
	 */
	public function invalidate_cache( int $post_id ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		Cache::delete( 'sysmda_md_' . $post_id );
		Cache::delete( LlmsTxtController::CACHE_KEY );
	}

	// ─── Resolution ───────────────────────────────────────────────────────────

	/**
	 * Resolves the post from a REQUEST_URI ending in `.md`.
	 *
	 * Handles query strings and trailing slashes (`/slug.md/` → 301 to `/slug.md`).
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

		// Trailing slash: /slug.md/ → 301 to /slug.md.
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
	 * Whether the client explicitly prefers Markdown over HTML.
	 *
	 * Markdown is served only when explicitly requested:
	 * - `?format=markdown` (application override), or
	 * - `text/markdown` listed in Accept with q ≥ the effective q of
	 *   `text/html`.
	 *
	 * A wildcard-only Accept (`text/*` or a full wildcard), or a missing Accept,
	 * does NOT activate Markdown: HTML remains the site default. Clients that
	 * send a wildcard Accept (curl and many HTTP libraries) therefore receive HTML.
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
	 * Whether the client's Accept header rejects EVERY offered representation
	 * (neither HTML nor Markdown, and no wildcard), making it a candidate for
	 * `406 Not Acceptable`.
	 *
	 * Real clients (browsers, crawlers, agents) always send `text/html` or a
	 * wildcard and are never affected. Can be disabled through the
	 * `sysmda_markdown_strict_406` filter (RFC 9110 makes 406 optional, so serving
	 * the default representation is still valid).
	 */
	private function should_reject_unacceptable(): bool {
		/** Filter: send 406 when Accept allows neither HTML nor Markdown. */
		if ( ! apply_filters( 'sysmda_markdown_strict_406', true ) ) {
			return false;
		}

		if ( isset( $_GET['format'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return false;
		}

		$accept = $this->accept_header();
		if ( '' === $accept ) {
			return false; // No Accept header means any representation is acceptable.
		}

		return AcceptNegotiator::quality( $accept, 'text/html' ) <= 0.0
			&& AcceptNegotiator::quality( $accept, 'text/markdown' ) <= 0.0;
	}

	/**
	 * Normalized request `Accept` header (empty string when absent).
	 */
	private function accept_header(): string {
		return isset( $_SERVER['HTTP_ACCEPT'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Adds `Vary: Accept` without duplicating an existing Vary header that includes it.
	 */
	private function send_vary_header(): void {
		if ( headers_sent() ) {
			return;
		}

		foreach ( headers_list() as $sent ) {
			if ( 0 === stripos( $sent, 'vary:' ) && false !== stripos( $sent, 'accept' ) ) {
				return; // Already covered.
			}
		}

		header( 'Vary: Accept', false );
	}

	/**
	 * Minimal `406 Not Acceptable` response (the URL offers only HTML/Markdown).
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
	 * Builds an absolute URL using the trusted scheme/host from home_url() and the request path.
	 * Also supports subdirectory installations and prevents HTTP_HOST spoofing.
	 */
	private function build_site_url( string $path ): string {
		$home  = wp_parse_url( home_url() );
		$parts = is_array( $home ) ? $home : array();

		$scheme = isset( $parts['scheme'] ) ? $parts['scheme'] : ( is_ssl() ? 'https' : 'http' );
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';
		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';

		return $scheme . '://' . $host . $port . '/' . ltrim( $path, '/' );
	}

	// ─── Validation ───────────────────────────────────────────────────────────

	/**
	 * Checks whether the post can be served as Markdown (see PostSupport).
	 */
	private function is_servable( \WP_Post $post ): bool {
		return PostSupport::is_servable( $post );
	}

	/**
	 * Sets the 404 status while leaving template rendering to WordPress.
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
	 * Serves a post's Markdown representation and ends the request.
	 *
	 * Before the body, handles conditional requests (If-None-Match /
	 * If-Modified-Since): if the client already has the current version, returns
	 * 304 without a body. Otherwise sends the headers (including ETag and
	 * Last-Modified) and the Markdown. Used by both the .md suffix branch and
	 * content negotiation so validation logic remains centralized.
	 */
	private function serve_markdown( \WP_Post $post ): void {
		$version = $this->cache_version( $post );

		if ( $this->handle_conditional( $post, $version ) ) {
			exit; // 304 already sent, no body.
		}

		$this->send_headers( $post, $version );
		echo $this->get_markdown( $post ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Handles conditional requests. Returns true (and sends 304) when the client
	 * already has the current version of the resource.
	 *
	 * Validators:
	 * - Strong ETag = "{cache_version}" (changes after an edit, plugin update, or
	 *   settings save: it uses the same cache hash, so a 304 always means the body
	 *   would be identical to the cached one).
	 * - Last-Modified = post_modified_gmt (RFC 7231).
	 *
	 * If-None-Match takes precedence (RFC 9110): when present, it alone determines
	 * the result (match → 304, no match → full body), and If-Modified-Since is ignored.
	 */
	private function handle_conditional( \WP_Post $post, string $version ): bool {
		$etag        = '"' . $version . '"';
		$modified_ts = $this->last_modified_timestamp( $post );

		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		if ( '' !== $if_none_match ) {
			if ( self::etag_matches( $if_none_match, $etag ) ) {
				$this->send_not_modified( $etag, $modified_ts );
				return true;
			}
			return false; // INM is present but does not match: it takes precedence, so serve the full body.
		}

		$if_modified_since = isset( $_SERVER['HTTP_IF_MODIFIED_SINCE'] )
			? trim( (string) wp_unslash( $_SERVER['HTTP_IF_MODIFIED_SINCE'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		if ( '' !== $if_modified_since && $modified_ts > 0 ) {
			$since = strtotime( $if_modified_since );
			if ( false !== $since && $modified_ts <= $since ) {
				$this->send_not_modified( $etag, $modified_ts );
				return true;
			}
		}

		return false;
	}

	/**
	 * Checks whether an If-None-Match header matches the resource ETag.
	 *
	 * Handles the `*` wildcard, comma-separated ETag lists, and the weak `W/`
	 * prefix (removed before comparison, which still uses the quoted value).
	 *
	 * Public only so it can be tested in isolation (pure string logic).
	 */
	public static function etag_matches( string $header, string $etag ): bool {
		$header = trim( $header );

		if ( '*' === $header ) {
			return true;
		}

		foreach ( explode( ',', $header ) as $candidate ) {
			$candidate = trim( $candidate );

			if ( 0 === stripos( $candidate, 'W/' ) ) {
				$candidate = substr( $candidate, 2 );
			}

			if ( $candidate === $etag ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Unix timestamp of the last modification (from post_modified_gmt), or 0 if invalid.
	 */
	private function last_modified_timestamp( \WP_Post $post ): int {
		$modified = (string) $post->post_modified_gmt;

		if ( '' === $modified || '0000-00-00 00:00:00' === $modified ) {
			return 0;
		}

		$ts = strtotime( $modified . ' GMT' );

		return false !== $ts ? $ts : 0;
	}

	/**
	 * Sends a 304 Not Modified response: validation headers only, no body.
	 */
	private function send_not_modified( string $etag, int $modified_ts ): void {
		if ( headers_sent() ) {
			return;
		}

		status_header( 304 );
		header( 'ETag: ' . $etag );

		if ( $modified_ts > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_ts ) . ' GMT' );
		}
	}

	/**
	 * Retrieves Markdown from the transient cache or regenerates it.
	 *
	 * Key: `sysmda_md_{post_id}`. The value includes a version hash
	 * (post_modified_gmt + plugin version + settings salt) to detect changes
	 * without leaving orphaned keys. It is proactively invalidated:
	 * - when the post is edited (post_modified_gmt changes)
	 * - when the plugin is updated (SYSMDA_VERSION changes)
	 * - when settings are saved (the salt changes; see AdminSettings)
	 * - by the save_post hook through invalidate_cache().
	 */
	private function get_markdown( \WP_Post $post ): string {
		/** Filter: cache TTL in seconds. 0 disables the cache. */
		$ttl       = (int) apply_filters( 'sysmda_markdown_cache_ttl', DAY_IN_SECONDS, $post );
		$cache_key = 'sysmda_md_' . $post->ID;
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
	 * Cache validity hash: changes when the post is edited, the plugin is updated,
	 * or settings are saved (global salt).
	 */
	private function cache_version( \WP_Post $post ): string {
		$salt = (string) get_option( 'sysmda_cache_salt', '0' );

		return md5( (string) $post->post_modified_gmt . '|' . SYSMDA_VERSION . '|' . $salt );
	}

	/**
	 * Assembles front matter, H1 title, and converted body.
	 */
	private function build_markdown( \WP_Post $post ): string {
		$front_matter = $this->metadata->build_front_matter( $post );
		$html         = $this->renderer->render( $post );
		$body         = $this->converter->convert( $html );

		$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
		$title = trim( preg_replace( '/\s+/', ' ', $title ) );

		/** Filter: Markdown block between the # Title and body (subtitle, TL;DR, etc.). */
		$preamble = (string) apply_filters( 'sysmda_markdown_preamble', '', $post );

		$markdown = $front_matter . "\n# " . $title . "\n\n" . $preamble . $body;

		/** Filter: final Markdown (front matter + content). */
		$markdown = apply_filters( 'sysmda_markdown_output', $markdown, $post );

		return rtrim( $markdown ) . "\n";
	}

	/**
	 * Sends HTTP headers for the Markdown response.
	 *
	 * Always includes ETag and Last-Modified (the same validators used for
	 * conditional requests) so caches/proxies can store and revalidate them.
	 */
	private function send_headers( \WP_Post $post, string $version ): void {
		if ( headers_sent() ) {
			return;
		}

		status_header( 200 );
		header( 'Content-Type: text/markdown; charset=utf-8' );
		header( 'ETag: "' . $version . '"' );

		$modified_ts = $this->last_modified_timestamp( $post );
		if ( $modified_ts > 0 ) {
			header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_ts ) . ' GMT' );
		}

		/** Filter: X-Robots-Tag header. Empty string means the header is not sent. */
		$robots = apply_filters( 'sysmda_markdown_robots_header', 'noindex, follow', $post );

		if ( is_string( $robots ) && '' !== $robots ) {
			header( 'X-Robots-Tag: ' . $robots );
		}

		/**
		 * Filter: canonical URL pointing to the HTML original (Link rel="canonical" header).
		 * Empty string means the header is not sent.
		 */
		$canonical = apply_filters( 'sysmda_markdown_canonical_url', get_permalink( $post ), $post );

		if ( is_string( $canonical ) && '' !== $canonical ) {
			header( 'Link: <' . $canonical . '>; rel="canonical"', false );
		}
	}
}
