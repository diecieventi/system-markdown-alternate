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

		if ( '1' !== get_option( 'sysmda_llms_txt_enabled', '1' ) ) {
			return; // Disabled in the admin panel.
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
	 * Prints the /llms.txt output, serving it from cache when available.
	 */
	private function render(): void {
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, follow' );
		}

		/** Filter: /llms.txt cache TTL in seconds. 0 disables caching. */
		$ttl     = (int) apply_filters( 'sysmda_llms_txt_cache_ttl', DAY_IN_SECONDS );
		$version = md5( SYSMDA_VERSION . '|' . (string) get_option( 'sysmda_cache_salt', '0' ) );

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

			$posts = get_posts(
				array(
					'post_type'              => $post_type,
					'post_status'            => 'publish',
					'has_password'           => false, // Excludes protected content (like the .md endpoint).
					'posts_per_page'         => $limit,
					'orderby'                => 'date',
					'order'                  => 'DESC',
					'no_found_rows'          => true,
					'update_post_meta_cache' => $enriched, // Enriched descriptions read post meta.
					'update_post_term_cache' => false,     // Terms are never read.
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
