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

	/** WP-Cron hook that rebuilds one post's cached Markdown after a save. */
	const PREWARM_HOOK = 'sysmda_prewarm_markdown';

	/**
	 * Delay before a queued pre-warm runs, in seconds.
	 *
	 * Not immediate on purpose. `save_post` fires before everything the document
	 * reads has necessarily landed: the block editor writes terms and meta
	 * through REST calls of their own, and ACF saves its fields on its own hook,
	 * so a rebuild racing the save would cache a document missing them. The
	 * window also debounces — a second save inside it finds the event already
	 * queued and does not add another.
	 */
	const PREWARM_DELAY = 30;

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
		// No enabled content type: the plugin is inactive and must not touch the
		// request at all — not even to redirect a .md URL it would then 404.
		if ( empty( PostSupport::supported_post_types() ) ) {
			return;
		}

		// --- .md suffix route ---
		$post = $this->resolve_requested_post();

		if ( $post instanceof \WP_Post ) {
			if ( ! $this->is_servable( $post ) ) {
				$this->force_404();
				return;
			}
			// Dedicated URL: storable by any cache, never reusable without
			// revalidating first. Sent before serve_markdown() so the conditional
			// 304 exit carries the same policy as the 200 (RFC 9110 §15.4.5).
			self::send_cache_control();
			$this->serve_markdown( $post );
		}

		// --- Content negotiation on the canonical permalink ---
		if ( ! $this->is_negotiable_request() ) {
			return; // Not negotiable: WP continues with normal rendering.
		}

		$queried = get_queried_object();

		// This URL varies by Accept: declare it to caches/CDNs/proxies whether
		// responding with Markdown or leaving HTML rendering to WordPress.
		$this->send_vary_header();

		if ( $this->prefers_markdown() ) {
			// The negotiated Markdown shares its URL with the HTML page: page
			// caches that key by URL only (observed on some LiteSpeed setups,
			// which ignore Vary: Accept) must never store this variant, or it
			// would be served to HTML clients too. .md URLs stay cacheable.
			// Sent before serve_markdown() so both the 200 and the conditional
			// 304 exits carry the no-cache invariant.
			$this->send_no_cache_headers();
			$this->serve_markdown( $queried );
		}

		if ( $this->should_reject_unacceptable() ) {
			$this->send_no_cache_headers();
			$this->send_not_acceptable();
			exit;
		}

		// Default: WordPress serves HTML (Vary: Accept already sent).
	}

	/**
	 * Whether the current request may be answered with the negotiated Markdown
	 * representation of the queried post.
	 *
	 * Only the canonical singular permalink negotiates. `is_singular` alone is
	 * not enough: it stays true for a post's feed, its oEmbed view, its trackback
	 * endpoint, its paged comments and the sub-pages of a `<!--nextpage-->` post,
	 * so `Accept: text/markdown` on `/my-post/feed/` used to return the article
	 * body instead of the feed. Those URLs are variants of the post, not the post,
	 * and `markdown_url()` never advertises them.
	 *
	 * Matches the guard used by print_alternate_link(): what declares
	 * `Vary: Accept` and what advertises a Markdown alternate stay in step.
	 */
	private function is_negotiable_request(): bool {
		if ( is_feed() || is_embed() || is_trackback() ) {
			return false;
		}

		$types = PostSupport::supported_post_types();

		// Explicit guard: is_singular([]) in WP is true for ANY singular content.
		// With no selected types the plugin is inactive and nothing negotiates.
		if ( empty( $types ) || ! is_singular( $types ) ) {
			return false;
		}

		// Paged comments (/comment-page-2/) and post sub-pages (/my-post/2/) are
		// separate URLs whose Markdown would duplicate the canonical one.
		if ( (int) get_query_var( 'cpage' ) > 0 || (int) get_query_var( 'page' ) > 1 ) {
			return false;
		}

		$queried = get_queried_object();

		return $queried instanceof \WP_Post && $this->is_servable( $queried );
	}

	/**
	 * Hook: wp_head. Prints the alternate link only on supported public posts/CPTs.
	 */
	public function print_alternate_link(): void {
		// The same predicate that decides whether this URL negotiates, and not
		// a parallel one: what advertises a Markdown alternate and what declares
		// `Vary: Accept` have to stay in step. They did not — this guard checked
		// only the enabled type and servability, so on an embed view (the one
		// excluded variant that still runs `wp_head`) the link was printed for a
		// URL that does not negotiate and sends no `Vary`.
		if ( ! $this->is_negotiable_request() ) {
			return;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post ) {
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

	/**
	 * Hook: save_post (priority 20, after invalidate_cache). Queues a background
	 * rebuild of the post's Markdown, so the first reader after an edit does not
	 * pay for the conversion.
	 *
	 * **Opt-in, off by default.** The cache fills itself on demand anyway, and
	 * the rebuild runs under WP-Cron, where there is no main query and no
	 * request context: a dynamic block or shortcode that inspects
	 * `is_singular()` or the queried object can render differently there than on
	 * a real front-end request, and the difference would be what gets cached.
	 * build_markdown() installs the post as the loop (which is what the `.md`
	 * route needs too), so anything reading the post itself is fine — it is the
	 * request-shaped checks that cannot be reproduced. A site that knows its
	 * Markdown does not depend on them can enable this and trade the risk for a
	 * warm cache; the honest default is to leave the work where it is observable.
	 */
	public function schedule_prewarm( int $post_id ): void {
		/**
		 * Filter: rebuild a post's Markdown cache in the background after every
		 * save, instead of on the first request that asks for it. Default false
		 * — see schedule_prewarm() for why WP-Cron is not a faithful stand-in
		 * for a front-end request. No effect when the body cache is disabled
		 * through `sysmda_markdown_cache_ttl`.
		 */
		if ( ! apply_filters( 'sysmda_markdown_prewarm', false, $post_id ) ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->is_servable( $post ) ) {
			return;
		}

		if ( ! $this->cache_enabled( $post ) ) {
			return;
		}

		$args = array( $post_id );

		// Same hook, same args: WP-Cron would keep both, so check first.
		if ( false !== wp_next_scheduled( self::PREWARM_HOOK, $args ) ) {
			return;
		}

		wp_schedule_single_event( time() + self::PREWARM_DELAY, self::PREWARM_HOOK, $args );
	}

	/**
	 * Hook: sysmda_prewarm_markdown (WP-Cron). Builds the post's Markdown and
	 * stores it under the current validator, so the next request is a cache hit.
	 *
	 * Everything is re-checked rather than trusted from scheduling time: between
	 * the save and this run the post may have been unpublished, password
	 * protected, given a non-standard post format, or deleted outright.
	 */
	public function prewarm( int $post_id ): void {
		$post = get_post( $post_id );

		if ( ! $post instanceof \WP_Post || ! $this->is_servable( $post ) ) {
			return;
		}

		if ( ! $this->cache_enabled( $post ) ) {
			return;
		}

		// Idempotent: get_markdown() stores the body when the entry is missing or
		// stale, and is a plain read when it is already warm.
		$this->get_markdown( $post, $this->cache_version( $post ) );
	}

	/**
	 * Hook: plugin deactivation. Drops every queued pre-warm event, including
	 * the per-post argument variants wp_clear_scheduled_hook() cannot reach.
	 */
	public static function clear_prewarm_events(): void {
		wp_unschedule_hook( self::PREWARM_HOOK );
	}

	/**
	 * Whether the body cache is enabled for this post (TTL greater than zero).
	 */
	private function cache_enabled( \WP_Post $post ): bool {
		/** Filter: cache TTL in seconds. 0 disables the cache. */
		return (int) apply_filters( 'sysmda_markdown_cache_ttl', DAY_IN_SECONDS, $post ) > 0;
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

		// Trailing slash: /slug.md/ → 301 to /slug.md. The suffix is matched
		// case-insensitively (a hand-typed or copied `.MD` should resolve too).
		if ( (bool) preg_match( '#\.md/+$#i', $path ) ) {
			$target = preg_replace( '#\.md/+$#i', '.md', $path );
			$query  = wp_parse_url( $request_uri, PHP_URL_QUERY );
			if ( $query ) {
				$target .= '?' . $query;
			}
			wp_safe_redirect( $target, 301 );
			exit;
		}

		if ( 0 !== strcasecmp( '.md', substr( $path, -3 ) ) ) {
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
		if ( self::has_markdown_format_override() ) {
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

		// An explicit `?format=markdown` settles the representation, so the
		// Accept header cannot make the request unacceptable. Only the
		// recognized value counts: `?format=banana` names no representation
		// this plugin serves, so it must not be able to switch the 406 off.
		if ( self::has_markdown_format_override() ) {
			return false;
		}

		$accept = $this->accept_header();
		if ( '' === $accept ) {
			return false; // No Accept header means any representation is acceptable.
		}

		// A header whose every media range is malformed (no `/`, or an invalid
		// qvalue) parses to nothing. That is a broken client, not a client
		// refusing HTML: answering 406 would turn a typo into an error page,
		// so it is treated exactly like a missing Accept.
		if ( array() === AcceptNegotiator::parse( $accept ) ) {
			return false;
		}

		return AcceptNegotiator::quality( $accept, 'text/html' ) <= 0.0
			&& AcceptNegotiator::quality( $accept, 'text/markdown' ) <= 0.0;
	}

	/**
	 * Whether the request carries the `?format=markdown` application override.
	 *
	 * The one recognized value, shared by every caller so they cannot drift:
	 * anything else in `format` names no representation this plugin serves and
	 * must behave exactly as if the parameter were absent. The strict
	 * comparison against a string also disposes of `?format[]=markdown`, which
	 * makes the value an array.
	 */
	private static function has_markdown_format_override(): bool {
		return isset( $_GET['format'] ) && 'markdown' === $_GET['format']; // phpcs:ignore WordPress.Security.NonceVerification
	}

	/**
	 * Normalized request `Accept` header (empty string when absent).
	 */
	private function accept_header(): string {
		return isset( $_SERVER['HTTP_ACCEPT'] ) ? trim( (string) wp_unslash( $_SERVER['HTTP_ACCEPT'] ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
	}

	/**
	 * Adds `Vary: Accept` without duplicating an existing Vary header that includes it.
	 *
	 * The comparison is over comma-separated **field names**, not substrings.
	 * `Vary: Accept-Encoding` and `Vary: Accept-Language` are both extremely
	 * common — the first is sent by practically every compressing stack — and a
	 * substring test read either as "already covered", so the header was never
	 * added and nothing partitioned the cache by media type. On the HTML branch
	 * that lets a cache store the HTML and later hand it to a Markdown-preferring
	 * request before PHP runs. They are different fields and cover nothing here.
	 *
	 * `Vary: *` is the one non-exact value that genuinely covers everything: it
	 * makes the response uncacheable by shared caches altogether.
	 */
	private function send_vary_header(): void {
		if ( headers_sent() ) {
			return;
		}

		if ( self::vary_covers_accept( headers_list() ) ) {
			return;
		}

		header( 'Vary: Accept', false );
	}

	/**
	 * Whether the headers already sent declare a `Vary` that covers `Accept`.
	 *
	 * Split out of send_vary_header() so the field-name comparison is testable
	 * without a live SAPI: `headers_list()` is empty under CLI, which is where
	 * the substring bug this replaced could hide indefinitely.
	 *
	 * @param string[] $sent Headers as returned by headers_list().
	 */
	public static function vary_covers_accept( array $sent ): bool {
		foreach ( $sent as $header ) {
			if ( 0 !== stripos( $header, 'vary:' ) ) {
				continue;
			}

			foreach ( explode( ',', substr( $header, strlen( 'vary:' ) ) ) as $field ) {
				$field = trim( $field );

				if ( '*' === $field || 0 === strcasecmp( 'accept', $field ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Marks the current response as non-cacheable for every cache in front of
	 * PHP (negotiated Markdown and 406 responses only, never `.md` URLs).
	 *
	 * Honouring `Vary: Accept` is a per-host property: the default LiteSpeed
	 * cache keys by URL only and ignores it, and CDNs may too. These responses
	 * share their URL with the HTML page, so the standard `Cache-Control`
	 * no-cache header is a server-agnostic security invariant — it must never
	 * depend on a specific cache plugin being active. The LiteSpeed-specific
	 * signals (header, DONOTCACHEPAGE, LSCWP action) stay in LiteSpeedCompat.
	 */
	private function send_no_cache_headers(): void {
		if ( ! headers_sent() ) {
			header( 'Cache-Control: no-cache, no-store, must-revalidate, private' );
		}

		LiteSpeedCompat::mark_nocache();
	}

	/**
	 * Sends the caching policy of a representation that has its own URL: the
	 * `.md` endpoint and `/llms.txt`. Storable by anything, reusable by nothing
	 * without asking first.
	 *
	 * Until `0.29.0` these responses sent no `Cache-Control` at all, on the
	 * assumption that saying nothing meant "always revalidate". It does not, in
	 * either direction:
	 *
	 * - RFC 9111 §4.2.2 lets a cache **invent** a lifetime when a response
	 *   carries none. The usual heuristic is a fraction of the age since
	 *   `Last-Modified`, which on an old post is weeks (Varnish's stock
	 *   `default_ttl` is a flat 120 s). "No header" is not "no freshness".
	 * - Saying nothing does not even reach the wire. This route resolves as an
	 *   error inside WordPress, so `WP::send_headers()` has already sent
	 *   `wp_get_nocache_headers()` — `no-store` included — by the time the
	 *   plugin runs. Measured in production: every `.md` carried
	 *   `no-cache, must-revalidate, max-age=0, no-store, private` plus the 1984
	 *   `Expires`, to anonymous clients too. `no-store` forbids keeping a copy
	 *   at all, so no client ever revalidated and the entire `ETag`/`304` path
	 *   was dead weight, while every hit paid for a full render.
	 *
	 * The header is therefore explicit, and the only correct value is one that
	 * grants storage while refusing reuse: `max-age=0` makes the response stale
	 * the moment it arrives and `must-revalidate` makes that binding, so a cache
	 * must revalidate before serving it — a `.md` can never be handed out after
	 * the article behind it changed. That is the goal the old "send nothing"
	 * decision was written for; this is the header that actually delivers it.
	 * `public` states what is true by construction: the representation never
	 * varies by visitor (protected content has no `.md`, drafts 404, and the
	 * body is built from cleaned blocks rather than `the_content`, so
	 * personalisation filters never run).
	 *
	 * Freshness for shared caches is still not imposed, but it is no longer
	 * unreachable either: `sysmda_cache_control` can return an `s-maxage`, and
	 * whoever does that takes on the purge responsibility that comes with it.
	 *
	 * NOT used for negotiated Markdown, which shares its URL with the HTML page
	 * and stays `no-store` — see send_no_cache_headers().
	 */
	public static function send_cache_control(): void {
		if ( headers_sent() ) {
			return;
		}

		// WordPress's nocache set travels with `Expires: Wed, 11 Jan 1984`.
		// Cache-Control wins over Expires wherever both are understood (RFC 9111
		// §5.3), but leaving a 1984 date behind is a trap for anything that
		// reads Expires first, and it contradicts the header sent below.
		header_remove( 'Expires' );

		$value = self::cache_control_value( self::representation_is_shared() );

		if ( '' === $value ) {
			// Explicit opt-out: no policy from the plugin, and none inherited
			// from WordPress either — genuinely no header, which is what the
			// filter promises.
			header_remove( 'Cache-Control' );
			return;
		}

		header( 'Cache-Control: ' . $value );
	}

	/**
	 * Whether the representation being produced is the shared, public one.
	 *
	 * The `.md` is defined as the **anonymous** representation of a post, and
	 * `public, max-age=0, must-revalidate` states exactly that. The premise is
	 * not free, though: the body is assembled with `render_block()` and
	 * `do_shortcode()`, and every stage passes through site filters, so a
	 * dynamic block or shortcode reading the current user, a cookie or a cart
	 * renders in the CALLER's context — "built from cleaned blocks rather than
	 * `the_content`" keeps `the_content` filters out, and nothing more.
	 *
	 * So an authenticated request may produce output that is not the public
	 * representation. Such a response must not be stored in the per-post body
	 * cache (shared by every visitor, keyed by post ID alone) and must not be
	 * storable by any cache in front of PHP. Anonymous traffic — which is what
	 * the audience for this endpoint is made of — is unaffected and keeps the
	 * full shared-cache behaviour.
	 *
	 * `is_user_logged_in()` is the tractable half of the question, not the
	 * whole of it: anonymous output can vary by cookie too (a cart, a
	 * geolocation, an A/B assignment). A site whose blocks do that should
	 * declare it through `sysmda_markdown_cache_dependencies` or veto the post
	 * with `sysmda_post_is_servable`; there is no way for the plugin to detect
	 * it.
	 */
	public static function representation_is_shared(): bool {
		return ! is_user_logged_in();
	}

	/**
	 * The `Cache-Control` value for the plugin's own URLs.
	 *
	 * Public and separate from the header call so the policy is testable
	 * without a live response.
	 *
	 * @param bool $shared Whether this response is the public representation.
	 */
	public static function cache_control_value( bool $shared = true ): string {
		if ( ! $shared ) {
			// Deliberately NOT filterable. `sysmda_cache_control` exists to let a
			// site grant a freshness lifetime to the public representation; it
			// must not be able to make a possibly personalized response
			// publicly cacheable, least of all by accident.
			return 'private, no-store, must-revalidate';
		}

		/**
		 * Filter: `Cache-Control` for the URLs the plugin owns (`.md` and
		 * `/llms.txt`). The default grants storage but forbids reuse without
		 * revalidation, which is what keeps a cached `.md` from outliving an
		 * edit. Returning a freshness lifetime (`s-maxage`, `max-age`) is
		 * supported and makes staleness possible again: the URL is invisible to
		 * page-cache plugins, which purge the permalink and not `permalink.md`,
		 * so nothing will clear it early. An empty string sends no header at all.
		 */
		$value = apply_filters( 'sysmda_cache_control', 'public, max-age=0, must-revalidate' );

		// Sanitized like every other filtered header value: a line break would
		// take the response down with a fatal error.
		return is_string( $value ) ? trim( sanitize_text_field( $value ) ) : '';
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
		// Counts 200 and 304 alike (an access is an access), for both the .md
		// suffix and the negotiated permalink: every path serving Markdown
		// goes through this method.
		$this->maybe_record_hit();

		$version = $this->cache_version( $post );

		if ( $this->handle_conditional( $post, $version ) ) {
			exit; // 304 already sent, no body.
		}

		$this->send_headers( $post, $version );
		echo $this->get_markdown( $post, $version ); // phpcs:ignore WordPress.Security.EscapeOutput
		exit;
	}

	/**
	 * Records the response in the daily `.md` hit counter when the opt-in
	 * `sysmda_md_hits_enabled` option is on (default off).
	 *
	 * The user agent is read only so HitCounter can classify bot vs human;
	 * it is never stored (count-only privacy decision, see HitCounter).
	 */
	private function maybe_record_hit(): void {
		if ( '1' !== get_option( 'sysmda_md_hits_enabled', '0' ) ) {
			return;
		}

		$ua = isset( $_SERVER['HTTP_USER_AGENT'] )
			? (string) wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: null;

		HitCounter::record( $ua );
	}

	/**
	 * Handles conditional requests. Returns true (and sends 304) when the client
	 * already has the current version of the resource.
	 *
	 * Validators:
	 * - Weak ETag = W/"{cache_version}" (changes after an edit, plugin update, or
	 *   settings save: it uses the same cache hash, so a 304 always means the body
	 *   would be identical to the cached one). See etag() for why it is weak.
	 * - Last-Modified = post_modified_gmt (RFC 7231).
	 *
	 * If-None-Match takes precedence (RFC 9110): when present, it alone determines
	 * the result (match → 304, no match → full body), and If-Modified-Since is ignored.
	 * If-Modified-Since is additionally ignored whenever the date is not a strong
	 * validator for this representation (see date_is_strong_validator()).
	 */
	private function handle_conditional( \WP_Post $post, string $version ): bool {
		// Conditional handling belongs to the shared representation, and the
		// precondition lives here rather than at the call site so no caller can
		// forget it. The two halves of the anonymous-representation rule have to
		// agree: get_markdown() rebuilds for an authenticated visitor precisely
		// because the shared body may not be theirs, so answering that same
		// visitor `304` on a validator describing the shared body hands them
		// exactly what the rebuild was meant to avoid — their browser reuses a
		// copy built for everyone, off an `If-None-Match` kept from an earlier
		// anonymous fetch. Such a request is always answered in full, and
		// send_headers() leaves the validators off it for the same reason.
		if ( ! self::representation_is_shared() ) {
			return false;
		}

		$etag        = self::etag( $version );
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

		// If-Modified-Since may only be trusted while `post_modified_gmt` fully
		// determines the representation. With custom taxonomies emitted it does
		// not: assigning or renaming a term changes the body without touching
		// that date, so honouring the date here would answer 304 with stale
		// terms for a client that sends no If-None-Match. The ETag does carry
		// the taxonomy fingerprint, so it stays the reliable validator and the
		// date is downgraded to informational (still sent in the response).
		if ( '' !== $if_modified_since && $modified_ts > 0 && $this->date_is_strong_validator( $post ) ) {
			$since = strtotime( $if_modified_since );
			if ( false !== $since && $modified_ts <= $since ) {
				$this->send_not_modified( $etag, $modified_ts );
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether `post_modified_gmt` alone determines the emitted Markdown, i.e.
	 * whether the date may be used as a validator at all. Unrelated to the
	 * weak/strong distinction of the ETag: this asks whether the date knows
	 * about every input, not how exactly the tag compares.
	 *
	 * False as soon as ANYTHING can change the output without touching the
	 * post's modification date. That means both fingerprints, not just the
	 * taxonomy one: a client sending `If-Modified-Since` without an
	 * `If-None-Match` never presents the ETag, so folding the out-of-post
	 * dependencies into the ETag alone would still answer `304` with a stale
	 * body after a synced pattern, featured image, description or ACF change.
	 * Every input added to cache_version() must be reflected here too.
	 *
	 * The salt is the third input, and the one the two fingerprints cannot
	 * describe: it moves for site-wide reasons that belong to no post at all —
	 * a settings save, the permalink structure, the home URL, the site
	 * timezone, an author rename, a category or tag rename. Each of those
	 * rewrites the output of posts whose `post_modified_gmt` has not moved, so
	 * once the salt is newer than the post, the date has stopped knowing about
	 * every input and may not answer `304` on its own. It becomes usable again
	 * for that post the next time the post itself is saved, which is exactly
	 * when the date starts telling the truth again.
	 */
	private function date_is_strong_validator( \WP_Post $post ): bool {
		if ( '' !== MetadataBuilder::taxonomies_fingerprint( $post )
			|| '' !== MetadataBuilder::dependencies_fingerprint( $post ) ) {
			return false;
		}

		$modified = $this->last_modified_timestamp( $post );

		// Strictly older, not "not newer". Both values have one-second
		// resolution, so an equal pair is ambiguous — a post saved and a
		// site-wide invalidation raised in the same second are
		// indistinguishable from each other, and if the salt came second the
		// date is already lying. Ambiguity resolves against the date: the cost
		// is that a post saved in the very second of a bump loses the
		// `If-Modified-Since` path until its next save, which is nothing next
		// to answering `304` with an invalidated body indefinitely.
		return $modified > 0 && self::salt_changed_at() < $modified;
	}

	/**
	 * When the cache salt last changed, as a Unix timestamp (0 when never).
	 *
	 * AdminSettings writes the salt as `<unix ts>-<random>`; the cast reads the
	 * leading integer and returns 0 for the `'0'` default, so a site that has
	 * never invalidated anything keeps the `If-Modified-Since` path fully
	 * available. Salts written before that shape existed were a bare `time()`
	 * and parse identically.
	 */
	private static function salt_changed_at(): int {
		return (int) get_option( 'sysmda_cache_salt', '0' );
	}

	/**
	 * The response ETag for a cache validator, as a **weak** entity tag.
	 *
	 * Weak is the honest answer, not a downgrade. A strong ETag promises the
	 * representation is identical **byte for byte** (RFC 9110 §8.8.1), and this
	 * value cannot promise that: it is computed from the post's modification
	 * date, the plugin version, the settings salt and the two dependency
	 * fingerprints — never from the bytes, which would mean generating the body
	 * before deciding whether to send it and giving up the whole point of the
	 * `304`. Output that is dynamic by nature (dynamic blocks, shortcodes, site
	 * filters) can still move without any of those inputs moving; that gap is
	 * what `sysmda_markdown_cache_dependencies` exists to close, and a validator
	 * with a documented escape hatch is by definition not byte-exact.
	 *
	 * Nothing is lost: strong comparison is only required by `If-Match` and
	 * `If-Range`, and this endpoint implements neither (`GET`/`HEAD` of a whole
	 * document). `If-None-Match` uses weak comparison in every case (RFC 9110
	 * §13.1.2), so conditional requests behave exactly as before. Intermediaries
	 * that weaken strong tags on their way out (Cloudflare does, on some plans)
	 * no longer make the header a claim the plugin cannot back.
	 */
	private static function etag( string $version ): string {
		return 'W/"' . $version . '"';
	}

	/**
	 * Checks whether an If-None-Match header matches the resource ETag.
	 *
	 * Handles the `*` wildcard and comma-separated ETag lists, comparing with the
	 * weak comparison function required for `If-None-Match` (RFC 9110 §8.8.3.2):
	 * the `W/` flag is ignored on both sides, so a client that received a tag
	 * before or after an intermediary weakened it still revalidates.
	 *
	 * Public only so it can be tested in isolation (pure string logic).
	 */
	public static function etag_matches( string $header, string $etag ): bool {
		$header = trim( $header );

		if ( '*' === $header ) {
			return true;
		}

		$etag = self::normalize_etag( $etag );

		foreach ( explode( ',', $header ) as $candidate ) {
			if ( self::normalize_etag( $candidate ) === $etag ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Reduces an entity tag to the opaque value both sides are compared on.
	 *
	 * Drops the `W/` weakness flag (weak comparison) and the content-coding
	 * suffix Apache appends inside the quotes when it compresses a response:
	 * `mod_deflate` turns `"abc"` into `"abc-gzip"` and `mod_brotli` into
	 * `"abc-br"`, both by default (`DeflateAlterETag AddSuffix`). The client
	 * echoes back what it received, so without this every compressed response on
	 * a stock Apache revalidates with a full body instead of a `304` — silently,
	 * since nothing looks broken.
	 */
	private static function normalize_etag( string $value ): string {
		$value = trim( $value );

		if ( 0 === stripos( $value, 'W/' ) ) {
			$value = substr( $value, 2 );
		}

		return (string) preg_replace( '/-(?:gzip|br)"$/', '"', $value );
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
	 *
	 * @param string $version Validator computed by the caller (also the ETag), so
	 *                        the hash — and the term lookups behind it — are not
	 *                        recomputed for the same response.
	 */
	private function get_markdown( \WP_Post $post, string $version ): string {
		/** Filter: cache TTL in seconds. 0 disables the cache. */
		$ttl       = (int) apply_filters( 'sysmda_markdown_cache_ttl', DAY_IN_SECONDS, $post );
		$cache_key = 'sysmda_md_' . $post->ID;

		// The entry is keyed by post ID alone and shared by every visitor, so an
		// authenticated request neither reads nor writes it: a dynamic block or
		// shortcode rendering in that visitor's context would otherwise be
		// served to everyone else for the rest of the TTL, and conversely the
		// visitor would be handed a copy built for someone else. See
		// representation_is_shared().
		if ( ! self::representation_is_shared() ) {
			return $this->build_markdown( $post );
		}

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
	 *
	 * This value is also the ETag, so it must change whenever the emitted
	 * Markdown changes. Two families of input do NOT touch `post_modified_gmt`
	 * and are therefore fingerprinted in separately:
	 *
	 * - the emitted taxonomy terms (assignments and renames);
	 * - everything read from outside the post row — synced patterns, featured
	 *   image and its alt text, the Rank Math description, ACF fields, plus
	 *   whatever a site declares through `sysmda_markdown_cache_dependencies`.
	 *
	 * Without them a conditional request keeps answering `304` with stale
	 * content even when the body cache is disabled. Both fingerprints are empty
	 * when they have nothing to describe, which leaves the hash byte-identical
	 * for posts that have neither (no mass invalidation on upgrade).
	 */
	private function cache_version( \WP_Post $post ): string {
		$salt       = (string) get_option( 'sysmda_cache_salt', '0' );
		$taxonomies = MetadataBuilder::taxonomies_fingerprint( $post );
		$taxonomies = '' !== $taxonomies ? '|' . $taxonomies : '';

		$dependencies = MetadataBuilder::dependencies_fingerprint( $post );
		$dependencies = '' !== $dependencies ? '|' . $dependencies : '';

		return md5( (string) $post->post_modified_gmt . '|' . SYSMDA_VERSION . '|' . $salt . $taxonomies . $dependencies );
	}

	/**
	 * Assembles front matter, H1 title, and converted body.
	 *
	 * The post is installed as the global `$post` (and the loop is set up) for
	 * the duration of the conversion. On the `.md` suffix route the main query
	 * resolved `/slug.md`, which matches nothing, so WordPress 404s and leaves
	 * `$GLOBALS['post']` null: dynamic blocks and shortcodes falling back to
	 * `get_the_ID()` would render against no post at all, and the same content
	 * would convert differently depending on whether it was reached through the
	 * `.md` suffix or through negotiation on the permalink.
	 */
	private function build_markdown( \WP_Post $post ): string {
		$previous_post = isset( $GLOBALS['post'] ) ? $GLOBALS['post'] : null;

		$GLOBALS['post'] = $post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restored below; render_block()/do_shortcode() need the loop context.
		setup_postdata( $post );

		try {
			// The whole assembly runs inside the post context, the filters
			// included: `sysmda_markdown_preamble` is where the ACF integration
			// renders the TL;DR through the same shortcode pipeline as the body,
			// so it needs the loop just as much.
			$front_matter = $this->metadata->build_front_matter( $post );
			$html         = $this->renderer->render( $post );
			$body         = $this->converter->convert( $html );

			$title = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );
			$title = trim( preg_replace( '/\s+/', ' ', $title ) );

			/** Filter: Markdown block between the # Title and body (subtitle, TL;DR, etc.). */
			$preamble = (string) apply_filters( 'sysmda_markdown_preamble', '', $post );

			$markdown = self::assemble_document( $front_matter, $title, $preamble, $body );

			/** Filter: final Markdown (front matter + content). */
			$markdown = apply_filters( 'sysmda_markdown_output', $markdown, $post );
		} finally {
			if ( $previous_post instanceof \WP_Post ) {
				$GLOBALS['post'] = $previous_post; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restoring the caller's value.
				setup_postdata( $previous_post );
			} else {
				unset( $GLOBALS['post'] );
				wp_reset_postdata();
			}
		}

		return rtrim( $markdown ) . "\n";
	}

	/**
	 * Joins the document parts in the documented order (see
	 * `docs/output-format.md` → *Document structure*).
	 *
	 * Public and static so the layout is testable without a live request, like
	 * cache_control_value(). It deliberately does NOT right-trim: that happens
	 * after `sysmda_markdown_output`, so a filter cannot leak trailing
	 * whitespace into the response.
	 */
	public static function assemble_document( string $front_matter, string $title, string $preamble, string $body ): string {
		// The blank line separating the front matter from the H1 belongs to the
		// block, not to the heading. With the block suppressed
		// (`sysmda_front_matter_enabled`) the document has to start with `# `,
		// not with an empty line — a leading newline would be indistinguishable
		// from a truncated front matter to anything parsing the response.
		$prefix = '' !== $front_matter ? $front_matter . "\n" : '';

		return $prefix . '# ' . $title . "\n\n" . $preamble . $body;
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

		// Both validators describe the SHARED representation — the version hashes
		// the post, the plugin version, the salt and the two fingerprints, and
		// none of them knows who is asking. On a response rebuilt for an
		// authenticated visitor they would be a claim this plugin cannot back,
		// which is the same rule that made the ETag weak in 0.28.0. The response
		// is `no-store` anyway, so there is nothing for them to validate later.
		if ( self::representation_is_shared() ) {
			header( 'ETag: ' . self::etag( $version ) );

			$modified_ts = $this->last_modified_timestamp( $post );
			if ( $modified_ts > 0 ) {
				header( 'Last-Modified: ' . gmdate( 'D, d M Y H:i:s', $modified_ts ) . ' GMT' );
			}
		}

		/** Filter: X-Robots-Tag header. Empty string means the header is not sent. */
		$robots = apply_filters( 'sysmda_markdown_robots_header', 'noindex, follow', $post );

		// Both filter results are sanitized before reaching header(): a value
		// carrying a line break is rejected by PHP with a fatal error, and a
		// public extension point must not be able to take the response down.
		$robots = is_string( $robots ) ? sanitize_text_field( $robots ) : '';

		if ( '' !== $robots ) {
			header( 'X-Robots-Tag: ' . $robots );
		}

		/**
		 * Filter: canonical URL pointing to the HTML original (Link rel="canonical" header).
		 * Empty string means the header is not sent.
		 */
		$canonical = apply_filters( 'sysmda_markdown_canonical_url', get_permalink( $post ), $post );

		$canonical = is_string( $canonical ) ? esc_url_raw( $canonical ) : '';

		if ( '' !== $canonical ) {
			header( 'Link: <' . $canonical . '>; rel="canonical"', false );
		}
	}
}
