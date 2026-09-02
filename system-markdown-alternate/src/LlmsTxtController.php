<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Serves the /llms.txt endpoint: an index of the site's Markdown content.
 *
 * Two modes:
 * - basic (default): site name, tagline, and a list for each post type (manual excerpt).
 * - enriched (`sysmda_llms_txt_enriched` toggle): adds a site summary, a key
 *   content section, a description for each entry (using the same front matter
 *   fallback chain), and moves overflow into an `Optional` section (an
 *   untranslated llms.txt specification keyword). When the toggle is off, the
 *   output remains identical to basic mode.
 *
 * Cross-cutting option (`sysmda_llms_txt_lastmod`, off by default): adds each
 * entry's last-modified date as `(updated: YYYY-MM-DD)` in the notes after the
 * `:`, allowing crawlers to identify changed content without fetching every
 * URL again. Applies to both basic and enriched modes.
 */
class LlmsTxtController {

	/** Cache key for the /llms.txt output. */
	const CACHE_KEY = 'sysmda_llms_txt';

	/** @var MetadataBuilder */
	private $metadata;

	public function __construct( MetadataBuilder $metadata ) {
		$this->metadata = $metadata;
	}

	/**
	 * Hook: template_redirect (priority 0).
	 *
	 * Intercepts /llms.txt and serves the text file; returns immediately for any other path.
	 */
	public function maybe_render_llms_txt(): void {
		if ( empty( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}

		$uri  = wp_unslash( $_SERVER['REQUEST_URI'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		$path = (string) wp_parse_url( $uri, PHP_URL_PATH );

		$home_path = rtrim( (string) wp_parse_url( home_url(), PHP_URL_PATH ), '/' );
		$expected  = $home_path . '/llms.txt';

		// Check the URI first (an inexpensive string operation) before reading options.
		if ( $path !== $expected && $path !== $expected . '/' ) {
			return;
		}

		if ( ! self::is_enabled() ) {
			return; // Disabled (the default) or not yet enabled in the admin panel.
		}

		// With no enabled content type there is nothing to index: the endpoint
		// would answer a bare site name and a tagline. Stay out of the way
		// until the site owner has selected something, even if they enabled
		// this toggle first.
		if ( empty( PostSupport::supported_post_types() ) ) {
			return;
		}

		// Trailing slash: redirect /llms.txt/ to /llms.txt with a 301.
		if ( $path === $expected . '/' ) {
			wp_safe_redirect( home_url( '/llms.txt' ), 301 );
			exit;
		}

		$this->render();
		exit;
	}

	/**
	 * Whether the /llms.txt endpoint is enabled in the admin panel.
	 *
	 * Off by default (`'0'`): the endpoint intercepts /llms.txt at the earliest
	 * template_redirect priority and exits the moment it renders, which would
	 * otherwise take the URL over from any other plugin already serving it the
	 * moment a site owner enables a single post type for the unrelated .md
	 * feature. Serving this file is therefore always a manual, explicit opt-in
	 * from the panel — never on by construction. See the durable decision in
	 * AGENTS.md.
	 *
	 * The single source of truth for that default, called from here,
	 * `MarkdownController::should_advertise_llms_txt()` and
	 * `AdminSettings::render_llmstxt_aside()` — not re-read as a literal
	 * `get_option()` in each of them, which is exactly how the option's
	 * default drifted out of step across four call sites before. Public
	 * (rather than mirrored privately in each caller) precisely so those other
	 * classes have one method to call instead of one literal to keep in sync.
	 */
	public static function is_enabled(): bool {
		return '1' === get_option( 'sysmda_llms_txt_enabled', '0' );
	}

	/**
	 * Validity hash of the cached /llms.txt body.
	 *
	 * Beyond the plugin version and the settings salt it covers the **site
	 * identity printed in the file itself** — the name is the `# ` heading and
	 * the tagline the blockquote under it. Both are edited in Settings →
	 * General, which never fires `save_post`, so renaming the site used to leave
	 * the old name in the index for up to a full TTL.
	 *
	 * Deliberately NOT covered: a post's format. Changing it does alter which
	 * posts are servable, but it is set from the editor, where saving already
	 * clears this cache through `save_post`; the remaining paths are
	 * programmatic term writes. Post formats are not part of how this site
	 * classifies content (see the durable decision in AGENTS.md), so paying a
	 * `set_object_terms` hook on every term write to close that gap is not
	 * worth it.
	 */
	private function cache_version(): string {
		return md5(
			SYSMDA_VERSION
			. '|' . (string) get_option( 'sysmda_cache_salt', '0' )
			. '|' . (string) get_bloginfo( 'name' )
			. '|' . (string) get_bloginfo( 'description' )
		);
	}

	/**
	 * Prints the /llms.txt output, serving it from cache when available and
	 * answering conditional requests with `304 Not Modified`.
	 *
	 * The index is the plugin's largest response and the one most likely to be
	 * polled on a schedule, so it gets the same treatment as a `.md` URL:
	 * validators, an explicit caching policy, and no reuse without asking.
	 */
	private function render(): void {
		/** Filter: /llms.txt cache TTL in seconds. 0 disables caching. */
		$ttl       = (int) apply_filters( 'sysmda_llms_txt_cache_ttl', DAY_IN_SECONDS );
		$use_cache = self::uses_shared_body_cache( $ttl );
		$version   = $this->cache_version();
		$body      = null;

		if ( $use_cache ) {
			$cached = Cache::get( self::CACHE_KEY );
			if ( is_array( $cached ) && isset( $cached['v'], $cached['txt'] ) && $cached['v'] === $version ) {
				$body = (string) $cached['txt'];
			}
		}

		if ( null === $body ) {
			$body = $this->build();

			if ( $use_cache ) {
				Cache::set(
					self::CACHE_KEY,
					array(
						'v'   => $version,
						'txt' => $body,
					),
					$ttl
				);
			}
		}

		$etag = self::body_etag( $body );

		if ( $this->handle_conditional( $etag ) ) {
			return; // 304 already sent, no body.
		}

		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, follow' );
			header( 'ETag: ' . $etag );
			MarkdownController::send_cache_control();
		}

		echo $body; // phpcs:ignore WordPress.Security.EscapeOutput
	}

	/**
	 * Whether this request may read and populate the shared index body cache.
	 *
	 * Authenticated rendering may be visitor-dependent through the servability
	 * filter, so it must be rebuilt without touching the anonymous cache. The
	 * body-derived ETag remains safe because it describes those rebuilt bytes.
	 */
	private static function uses_shared_body_cache( int $ttl ): bool {
		return $ttl > 0 && MarkdownController::representation_is_shared();
	}

	/**
	 * The index's `ETag`, hashed from the bytes about to be sent.
	 *
	 * Deliberately **not** derived from cache_version(), which is what the `.md`
	 * endpoint has to do (it cannot afford to build a body only to discard it).
	 * Here the body already exists before the response is written, so hashing it
	 * costs nothing and buys a validator that cannot be wrong: the version does
	 * not cover the posts listed in the file — a new post is picked up by
	 * deleting the cache entry, not by moving the version — so using it as an
	 * `ETag` would answer `304` with an index missing that post. Hashing the
	 * bytes makes this the one strong `ETag` in the plugin, and it means an
	 * index that was rebuilt but came out identical still revalidates.
	 *
	 * Public only so it can be tested in isolation (like markdown_url()).
	 */
	public static function body_etag( string $body ): string {
		return '"' . md5( $body ) . '"';
	}

	/**
	 * Answers a conditional request. Returns true (and sends the `304`) when the
	 * client already holds this exact index.
	 *
	 * `If-None-Match` only: unlike a post, the index has no single modification
	 * date to put in `Last-Modified` — it is built from many posts plus the site
	 * identity — and inventing one would be a validator that lies. Without the
	 * header there is nothing for `If-Modified-Since` to compare against, so it
	 * is not honoured either.
	 */
	private function handle_conditional( string $etag ): bool {
		$if_none_match = isset( $_SERVER['HTTP_IF_NONE_MATCH'] )
			? trim( (string) wp_unslash( $_SERVER['HTTP_IF_NONE_MATCH'] ) ) // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
			: '';

		if ( '' === $if_none_match || ! MarkdownController::etag_matches( $if_none_match, $etag ) ) {
			return false;
		}

		if ( ! headers_sent() ) {
			status_header( 304 );
			header( 'ETag: ' . $etag );
			MarkdownController::send_cache_control();
		}

		return true;
	}

	/**
	 * Builds the /llms.txt content.
	 */
	private function build(): string {
		$post_types = PostSupport::supported_post_types();

		/** Filter: enables enriched output (summary, key content, descriptions, Optional). */
		$enriched = (bool) apply_filters( 'sysmda_llms_txt_enriched', false );

		/** Filter: adds the last-modified date `(updated: YYYY-MM-DD)` to each entry. */
		$with_lastmod = (bool) apply_filters( 'sysmda_llms_txt_lastmod', false );

		$lines   = array();
		$lines[] = '# ' . get_bloginfo( 'name' );

		$tagline = get_bloginfo( 'description' );
		if ( '' !== $tagline ) {
			$lines[] = '';
			$lines[] = '> ' . $tagline;
		}

		if ( $enriched ) {
			/** Filter: site summary paragraph after the tagline ('' = none). */
			$summary = trim( wp_strip_all_tags( (string) apply_filters( 'sysmda_llms_txt_summary', '' ) ) );
			if ( '' !== $summary ) {
				$lines[] = '';
				$lines[] = preg_replace( '/\s+/', ' ', $summary );
			}

			$key_items = $this->key_content_items( $with_lastmod );
			if ( ! empty( $key_items ) ) {
				$lines[] = '';
				$lines[] = '## ' . __( 'Key content', 'system-markdown-alternate' );
				$lines[] = '';
				foreach ( $key_items as $item ) {
					$lines[] = $item;
				}
			}
		}

		$optional = array(); // Label => overflow lines (enriched mode only).

		foreach ( $post_types as $post_type ) {
			$obj   = get_post_type_object( $post_type );
			$label = $obj ? $obj->labels->name : $post_type;

			/** Filter: maximum number of posts per type in the llms.txt index. */
			$limit = (int) apply_filters( 'sysmda_llms_txt_max_posts', 500, $post_type );

			/**
			 * Filter: in enriched mode, the number of posts per type in the main
			 * section; overflow (up to the maximum) goes under `## Optional`.
			 */
			$main_limit = $enriched ? (int) apply_filters( 'sysmda_llms_txt_main_posts', 25, $post_type ) : $limit;

			$posts = $this->servable_posts( $post_type, $limit );

			if ( empty( $posts ) ) {
				continue;
			}

			$lines[] = '';
			$lines[] = '## ' . $label;
			$lines[] = '';

			foreach ( array_values( $posts ) as $i => $post ) {
				if ( $enriched && $i >= $main_limit ) {
					$optional[ $label ][] = $this->item_line( $post, false, $with_lastmod );
					continue;
				}

				$lines[] = $this->item_line( $post, $enriched, $with_lastmod );
			}
		}

		if ( $enriched && ! empty( $optional ) ) {
			// "Optional" is an llms.txt specification keyword and is not translated.
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
			/** Filter: free-form block appended to /llms.txt ('' = none; hook for policy/LLM signals). */
			$footer = trim( (string) apply_filters( 'sysmda_llms_txt_footer', '' ) );
			if ( '' !== $footer ) {
				$lines[] = '';
				$lines[] = $footer;
			}
		}

		return implode( "\n", $lines ) . "\n";
	}

	/**
	 * How many batches servable_posts() will read before giving up.
	 *
	 * Bounds the work on a site whose newest content is overwhelmingly
	 * ineligible; the index is simply shorter than requested there, which is
	 * the same outcome as before and no worse.
	 */
	const MAX_QUERY_PAGES = 5;

	/**
	 * The most recent posts of a type that actually have a `.md`, up to $limit.
	 *
	 * Every listed entry must resolve: the index links to `.md` URLs, so a post
	 * the endpoint would 404 — a non-standard post format, a type that is no
	 * longer public, one a site filter vetoes — has no business being
	 * advertised here. Filtering through PostSupport keeps that in step
	 * automatically.
	 *
	 * The filtering is why this pages. Asking for exactly $limit rows and
	 * filtering afterwards yields FEWER than $limit entries as soon as the
	 * newest batch contains an ineligible post, and the older eligible posts
	 * behind it are never reached at all — in the extreme, a whole section
	 * disappears while the site still has servable content of that type.
	 *
	 * @return \WP_Post[]
	 */
	private function servable_posts( string $post_type, int $limit ): array {
		if ( $limit < 1 ) {
			return array();
		}

		$collected = array();

		for ( $paged = 1; $paged <= self::MAX_QUERY_PAGES; $paged++ ) {
			$batch = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'has_password'           => false, // Excludes protected content (like the .md endpoint).
					'posts_per_page'         => $limit,
					'paged'                  => $paged,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					// Both caches are primed in one query for the whole batch,
					// for the same reason: the servability check reads each
					// post's format (a term lookup) and its page-builder render
					// mode (a meta lookup), and an unprimed cache turns each of
					// those into a query PER POST — up to `posts_per_page` ×
					// MAX_QUERY_PAGES of them, on a route that runs whenever the
					// index cache is cold.
					//
					// Meta priming used to be tied to $enriched, back when the
					// descriptions were the only meta reader. It costs nothing
					// extra in memory to prime it always: an unprimed
					// get_post_meta() still loads every meta row for that post,
					// just one post at a time. Only the query count differs.
					'update_post_meta_cache' => true,
					'update_post_term_cache' => true,
				)
			);

			foreach ( $batch as $post ) {
				if ( ! PostSupport::is_servable( $post ) ) {
					continue;
				}

				$collected[] = $post;

				if ( count( $collected ) >= $limit ) {
					return $collected;
				}
			}

			if ( count( $batch ) < $limit ) {
				break; // The type is exhausted: no later page can add anything.
			}
		}

		return $collected;
	}

	/**
	 * List entry for a post: `- [title](url.md)` plus an optional description.
	 *
	 * @param bool $with_description In enriched mode, uses the front matter description
	 *                               chain (Rank Math => excerpt => trimmed content);
	 *                               otherwise uses only the manual excerpt (basic behavior).
	 * @param bool $with_lastmod     Adds `(updated: YYYY-MM-DD)` to the notes after the `:`
	 *                               (after the description, if present; otherwise as the only note).
	 */
	private function item_line( \WP_Post $post, bool $with_description, bool $with_lastmod = false ): string {
		$md_url = MetadataBuilder::markdown_url( $post );
		$title  = html_entity_decode( wp_strip_all_tags( get_the_title( $post ) ), ENT_QUOTES, 'UTF-8' );

		$description = '';

		if ( $with_description ) {
			$raw = $this->metadata->description( $post );
			if ( '' !== $raw ) {
				$description = ': ' . self::normalize_inline( wp_trim_words( wp_strip_all_tags( $raw ), 30, '…' ) );
			}
		} elseif ( has_excerpt( $post ) ) {
			$raw         = wp_strip_all_tags( get_the_excerpt( $post ) );
			$description = ': ' . self::normalize_inline( wp_trim_words( $raw, 20, '…' ) );
		}

		if ( $with_lastmod ) {
			$suffix = self::lastmod_suffix( (string) $post->post_modified_gmt );
			if ( '' !== $suffix ) {
				$description .= ( '' === $description ? ': ' : ' ' ) . $suffix;
			}
		}

		return '- [' . self::escape_link_text( $title ) . '](' . $md_url . ')' . $description;
	}

	/**
	 * Last-modified suffix for an index entry: `(updated: YYYY-MM-DD)`, with the
	 * ISO 8601 date extracted from `post_modified_gmt`. The English `updated:`
	 * label is not translated (the same convention as the llms.txt specification's
	 * `Optional` keyword). Returns '' for empty, zero (`0000-00-00 …`), or
	 * unrecognized dates.
	 *
	 * Public only so it can be tested in isolation (like markdown_url()).
	 */
	public static function lastmod_suffix( string $post_modified_gmt ): string {
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}/', $post_modified_gmt, $m ) || '0000-00-00' === $m[0] ) {
			return '';
		}

		return '(updated: ' . $m[0] . ')';
	}

	/**
	 * Normalizes text to a single line: newlines and control characters
	 * (`\x00-\x1F`, `\x7F`) become spaces, repeated whitespace is collapsed,
	 * and leading and trailing whitespace is removed.
	 *
	 * Ensures every index entry occupies one line; otherwise, a title or
	 * description containing newlines would break the file structure.
	 *
	 * Public only so it can be tested in isolation (like markdown_url()).
	 */
	public static function normalize_inline( string $text ): string {
		$text = preg_replace( '/[\x00-\x1F\x7F]+/u', ' ', $text );
		$text = preg_replace( '/\s+/', ' ', (string) $text );

		return trim( (string) $text );
	}

	/**
	 * Prepares text for use as Markdown *link text* (`[text](url)`): normalizes it
	 * to a single line and escapes characters that would break link syntax
	 * (`\`, `[`, `]`, `(`, `)`). The backslash must be escaped first to avoid
	 * doubling the escape sequences introduced afterward.
	 *
	 * Public only so it can be tested in isolation (like markdown_url()).
	 */
	public static function escape_link_text( string $text ): string {
		$text = self::normalize_inline( $text );

		return str_replace(
			array( '\\', '[', ']', '(', ')' ),
			array( '\\\\', '\\[', '\\]', '\\(', '\\)' ),
			$text
		);
	}

	/**
	 * Lines for the "Key content" section: resolves configured entries (numeric
	 * IDs or URLs, one per line), keeps only servable posts, and deduplicates by ID.
	 *
	 * @param bool $with_lastmod Adds the last-modified date to each entry.
	 *
	 * @return string[]
	 */
	private function key_content_items( bool $with_lastmod = false ): array {
		/** Filter: key content for /llms.txt (numeric IDs or URLs). */
		$entries = (array) apply_filters( 'sysmda_llms_txt_key_content', array() );

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
			$items[]          = $this->item_line( $post, true, $with_lastmod );
		}

		return $items;
	}
}
