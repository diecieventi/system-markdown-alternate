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

	/**
	 * Taxonomies never emitted under `taxonomies:`.
	 *
	 * `category` and `post_tag` already have their own `categories`/`tags` keys;
	 * `post_format` is registered public but is a presentational flag
	 * (`post-format-aside`), not editorial metadata.
	 */
	const EXCLUDED_TAXONOMIES = array( 'category', 'post_tag', 'post_format' );

	/** @var ShortcodeCleaner */
	private $shortcodes;

	/** @var ContentRenderer */
	private $renderer;

	public function __construct( ShortcodeCleaner $shortcodes, ContentRenderer $renderer ) {
		$this->shortcodes = $shortcodes;
		$this->renderer   = $renderer;
	}

	/**
	 * @param \WP_Post $post Reference post.
	 * @return string Front matter block (--- ... ---) with a trailing newline,
	 *                or '' when the block is disabled by filter.
	 */
	public function build_front_matter( \WP_Post $post ): string {
		/**
		 * Filter: whether to emit the YAML front-matter block at all.
		 *
		 * Default on, and that default is a deliberate position rather than an
		 * oversight: the block carries the title, canonical URL, dates, author,
		 * description and terms, none of which a consumer can recover from the
		 * body alone. A `.md` that lands in an agent's context window without
		 * them is a document with no provenance — which is exactly what the
		 * `url:` key exists to prevent.
		 *
		 * Some conventions nevertheless treat front matter as build-time input
		 * that should be stripped before serving, so a site that answers to one
		 * of them can opt out. Returning false emits the `# Title` heading as
		 * the first line of the document; nothing else about the body changes.
		 *
		 * Toggling this from site code does not by itself invalidate cached
		 * responses (it is a code change, invisible to the validator, like every
		 * other output filter): bump the settings salt by saving the settings
		 * page, or declare the switch through
		 * `sysmda_markdown_cache_dependencies`.
		 */
		if ( ! apply_filters( 'sysmda_front_matter_enabled', true, $post ) ) {
			return '';
		}

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

		$this->append_taxonomies( $lines, $post );

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
	 * File name offered by the `download` attribute of [sysmda_md_download].
	 *
	 * ASCII-only on purpose. The slug is percent-decoded first (WordPress stores
	 * non-Latin slugs encoded, so skipping this yields names like
	 * `d0bfd180d0b8`), transliterated by remove_accents(), then reduced to the
	 * safe set. Falls back to the post ID when nothing survives: a fully
	 * non-Latin slug, or a post saved without one.
	 *
	 * The strict character set is not only about tidy file names — it is what
	 * makes the value safe to interpolate into markup and, should this ever be
	 * reused in a header, into a quoted header value.
	 */
	public static function download_filename( \WP_Post $post ): string {
		$slug = sanitize_file_name( remove_accents( rawurldecode( (string) $post->post_name ) ) );
		$slug = (string) preg_replace( '/[^A-Za-z0-9._-]/', '', $slug );

		if ( '' === $slug ) {
			$slug = 'post-' . (int) $post->ID;
		}

		return $slug . '.md';
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
	 * @param string   $key   YAML key (for example "categories").
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
	 * Appends the optional nested `taxonomies:` mapping.
	 *
	 * Emitted last, after `description`: the documented output format is an
	 * append-only contract (see docs/output-format.md), so a new block goes at
	 * the end rather than between existing keys.
	 *
	 * @param string[] $lines Reference to the array of front matter lines.
	 */
	private function append_taxonomies( array &$lines, \WP_Post $post ): void {
		$taxonomies = self::taxonomy_terms( $post );

		if ( empty( $taxonomies ) ) {
			return;
		}

		$lines[] = 'taxonomies:';

		foreach ( $taxonomies as $slug => $names ) {
			$lines[] = '  ' . $slug . ':';

			foreach ( $names as $name ) {
				$lines[] = '    - ' . $this->scalar( $name );
			}
		}
	}

	/**
	 * Custom taxonomies and their term names for a post, ready to emit.
	 *
	 * Nothing is emitted implicitly: the list of taxonomies is the **explicit
	 * selection** made in the settings page, which AdminSettings feeds into
	 * `sysmda_front_matter_taxonomy_slugs` at priority 5. There is deliberately
	 * no auto-detection from the taxonomy registry — `public` /
	 * `publicly_queryable` describe how WordPress routes a taxonomy, not whether
	 * its terms belong in a machine-readable representation, and auto-detection
	 * meant every newly registered taxonomy started publishing itself (see
	 * docs/f3-2-taxonomy-selection-plan.md).
	 *
	 * With nothing selected the result is empty, so the front matter and the
	 * cache validator that fingerprints it stay byte-identical to a site without
	 * the feature.
	 *
	 * @return array<string, string[]> Taxonomy slug => term names, both sorted.
	 */
	public static function taxonomy_terms( \WP_Post $post ): array {
		$slugs = self::selected_taxonomy_slugs( $post );

		return self::taxonomy_terms_for_slugs( $post, $slugs );
	}

	/**
	 * The effective, validated custom-taxonomy selection for a post.
	 *
	 * @return string[]
	 */
	private static function selected_taxonomy_slugs( \WP_Post $post ): array {
		/**
		 * Filters which taxonomy slugs are emitted in the front matter.
		 *
		 * The default is the selection saved in the panel (empty until the site
		 * owner picks something); this filter may narrow it and extend it, and
		 * naming a non-public taxonomy is a deliberate opt-in. The
		 * always-excluded set and invalid slugs are stripped afterwards.
		 */
		$slugs = (array) apply_filters( 'sysmda_front_matter_taxonomy_slugs', array(), $post );

		/**
		 * Filters whether custom taxonomies are added to the front matter.
		 *
		 * Kill switch: the default is "yes as soon as something is selected", so
		 * returning false suppresses the block whatever the selection is. Code
		 * that supplies slugs through the filter above with no saved selection
		 * needs no special handling — a non-empty list is enough to turn the
		 * block on.
		 */
		if ( ! apply_filters( 'sysmda_front_matter_taxonomies', ! empty( $slugs ) ) ) {
			return array();
		}

		$selected = array();
		foreach ( $slugs as $slug ) {
			if ( self::is_emittable_taxonomy( $slug ) ) {
				$selected[] = $slug;
			}
		}

		return array_values( array_unique( $selected ) );
	}

	/**
	 * Resolves term names for an already validated taxonomy selection.
	 *
	 * @param string[] $slugs Effective taxonomy slugs.
	 * @return array<string, string[]>
	 */
	private static function taxonomy_terms_for_slugs( \WP_Post $post, array $slugs ): array {
		$raw = array();

		foreach ( $slugs as $slug ) {
			$terms = get_the_terms( $post, $slug );

			// false (no terms) or WP_Error (taxonomy not registered).
			if ( ! is_array( $terms ) ) {
				continue;
			}

			$raw[ $slug ] = wp_list_pluck( $terms, 'name' );
		}

		return self::normalize_taxonomies( $raw );
	}

	/**
	 * Fingerprint of the taxonomy data emitted for a post.
	 *
	 * Folded into the cache validator by MarkdownController::cache_version().
	 * Term assignments and renames do not touch `post_modified_gmt`, so without
	 * this a conditional request would keep answering `304` with outdated terms
	 * — even with the body cache disabled. A selected taxonomy keeps a fingerprint
	 * when the post has no terms, because removing the last term must not make the
	 * old post date strong again. Returns an empty string only when the feature is
	 * off, leaving the validator byte-identical to earlier versions.
	 */
	public static function taxonomies_fingerprint( \WP_Post $post ): string {
		$slugs = self::selected_taxonomy_slugs( $post );

		if ( empty( $slugs ) ) {
			return '';
		}

		$taxonomies = self::taxonomy_terms_for_slugs( $post, $slugs );

		return md5( (string) wp_json_encode( $taxonomies ) );
	}

	/**
	 * Fingerprint of everything the emitted Markdown reads from OUTSIDE the post
	 * row, and that therefore changes without moving `post_modified_gmt`.
	 *
	 * Same contract as taxonomies_fingerprint(), same reason: this value is
	 * folded into MarkdownController::cache_version(), which also feeds the weak
	 * ETag. Editing a synced pattern, swapping the featured image, rewriting the
	 * Rank Math description or changing an ACF field all change the body while
	 * the post row stays untouched — so without this a client holding the old
	 * validator keeps receiving `304` with stale content, body cache or not.
	 *
	 * Covered here are only the dependencies the plugin itself reads and can
	 * therefore fingerprint. Output that is dynamic by nature (dynamic blocks,
	 * shortcodes, site filters reading options or remote data) cannot be:
	 * `sysmda_markdown_cache_dependencies` is the documented way for a site to
	 * contribute its own validator inputs.
	 *
	 * Returns an empty string when the post has none of them, which keeps the
	 * validator byte-identical for plain posts (no mass invalidation on upgrade).
	 */
	public static function dependencies_fingerprint( \WP_Post $post ): string {
		$parts = array();

		$seen = array();
		self::collect_pattern_refs( parse_blocks( (string) $post->post_content ), $seen, $parts );

		$thumb_id = (int) get_post_thumbnail_id( $post );
		if ( $thumb_id > 0 ) {
			$thumb = get_post( $thumb_id );
			// `_wp_attached_file` is in there because what the front matter
			// prints is the resolved URL, not the ID: a plugin that swaps the
			// file behind an existing attachment rewrites `featured_image`
			// while leaving the attachment's own row, and its alt text, alone.
			$parts[] = 'thumb:' . $thumb_id
				. ':' . ( $thumb instanceof \WP_Post ? (string) $thumb->post_modified_gmt : '' )
				. ':' . (string) get_post_meta( $thumb_id, '_wp_attachment_image_alt', true )
				. ':' . (string) get_post_meta( $thumb_id, '_wp_attached_file', true );
		}

		$rank_math = get_post_meta( $post->ID, 'rank_math_description', true );
		if ( is_string( $rank_math ) && '' !== $rank_math ) {
			$parts[] = 'desc:' . md5( $rank_math );
		}

		self::collect_acf_dependencies( $post, $seen, $parts );

		/**
		 * Filter: extra cache-validator inputs for output this plugin cannot
		 * fingerprint by itself (dynamic blocks, shortcodes, site filters).
		 * Return a list of scalars; anything that changes the emitted Markdown
		 * belongs here, or conditional requests will answer 304 with stale content.
		 */
		$extra = apply_filters( 'sysmda_markdown_cache_dependencies', array(), $post );
		foreach ( (array) $extra as $value ) {
			if ( is_scalar( $value ) ) {
				$parts[] = 'x:' . (string) $value;
			}
		}

		return empty( $parts ) ? '' : md5( implode( '|', $parts ) );
	}

	/**
	 * Collects a fingerprint part for every `core/block` (synced pattern) the
	 * body can contain, following references **transitively**.
	 *
	 * It has to walk into each referenced `wp_block`'s own content, not just the
	 * article's parse tree: `BlockCleaner::expand_reusable()` expands patterns
	 * recursively, so an article → pattern A → pattern B chain renders B's
	 * content. Recording only A's timestamp would leave the validator stale when
	 * B alone is edited — the exact failure this fingerprint exists to prevent,
	 * one level down.
	 *
	 * `$seen` is both the cycle guard (a pattern that references itself, directly
	 * or through another, would recurse forever — BlockCleaner guards the same
	 * way) and the deduplicator for a pattern used more than once.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param array  $seen   Reference IDs already visited, by ID.
	 * @param array  $parts  Fingerprint parts, appended to.
	 */
	private static function collect_pattern_refs( array $blocks, array &$seen, array &$parts ): void {
		foreach ( $blocks as $block ) {
			if ( isset( $block['blockName'] ) && 'core/block' === $block['blockName'] ) {
				$ref = isset( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;

				if ( $ref > 0 && ! isset( $seen[ $ref ] ) ) {
					$seen[ $ref ] = true;
					$pattern      = get_post( $ref );

					if ( $pattern instanceof \WP_Post ) {
						$parts[] = 'block:' . $ref . ':' . (string) $pattern->post_modified_gmt;
						self::collect_pattern_refs( parse_blocks( (string) $pattern->post_content ), $seen, $parts );
					} else {
						$parts[] = 'block:' . $ref . ':missing';
					}
				}
			}

			if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
				self::collect_pattern_refs( $block['innerBlocks'], $seen, $parts );
			}
		}
	}

	/**
	 * Collects fingerprint parts for the ACF fields the bundled integration
	 * appends, including synced patterns expanded from generic source fields.
	 *
	 * Reads the same fields through the same filters AcfIntegration uses, so the
	 * validator cannot drift from what is actually emitted. Empty when ACF is
	 * not active — `get_field()` is its own availability check, exactly as in
	 * AcfIntegration.
	 *
	 * Generic fields join the post source before block rendering, so a
	 * `core/block` reference inside one follows the same transitive dependency
	 * graph as a reference in `post_content`. Subtitle and TL;DR fields are
	 * fingerprinted as values but are not parsed for patterns because their
	 * integration paths do not expand blocks.
	 *
	 * @param array $seen  Pattern IDs already visited, by ID.
	 * @param array $parts Fingerprint parts, appended to.
	 */
	private static function collect_acf_dependencies( \WP_Post $post, array &$seen, array &$parts ): void {
		if ( ! function_exists( 'get_field' ) ) {
			return;
		}

		$source_keys = array();
		foreach ( (array) apply_filters( 'sysmda_acf_field_keys', array(), $post ) as $key ) {
			$source_keys[] = (string) $key;
		}

		$keys   = $source_keys;
		$keys[] = (string) apply_filters( 'sysmda_acf_subtitle_key', '', $post );
		$keys[] = (string) apply_filters( 'sysmda_acf_tldr_key', '', $post );

		foreach ( $keys as $key ) {
			$key = (string) $key;
			if ( '' === $key ) {
				continue;
			}

			$value   = get_field( $key, $post->ID );
			$parts[] = 'acf:' . $key . ':' . md5( (string) wp_json_encode( $value ) );

			if ( in_array( $key, $source_keys, true ) && is_string( $value ) && has_blocks( $value ) ) {
				self::collect_pattern_refs( parse_blocks( $value ), $seen, $parts );
			}
		}
	}

	/**
	 * Normalizes a raw "taxonomy slug => term names" map into the emitted shape.
	 *
	 * Pure (no WordPress calls) so the filtering and ordering rules are directly
	 * testable. Drops the always-excluded taxonomies, slugs that are not valid
	 * taxonomy names, non-string or empty term names, and taxonomies left with
	 * no terms; deduplicates names.
	 *
	 * Sorting uses SORT_STRING, i.e. **byte order and not locale collation**:
	 * deliberate, so the output never depends on the server locale (accented
	 * names therefore sort after unaccented ones).
	 *
	 * @param array $raw Taxonomy slug => list of term names.
	 * @return array<string, string[]>
	 */
	public static function normalize_taxonomies( array $raw ): array {
		$clean = array();

		foreach ( $raw as $slug => $names ) {
			$slug = (string) $slug;

			if ( ! self::is_emittable_taxonomy( $slug ) || ! is_array( $names ) ) {
				continue;
			}

			$list = array();

			foreach ( $names as $name ) {
				if ( ! is_string( $name ) ) {
					continue;
				}

				$name = trim( $name );

				if ( '' !== $name && ! in_array( $name, $list, true ) ) {
					$list[] = $name;
				}
			}

			if ( empty( $list ) ) {
				continue;
			}

			sort( $list, SORT_STRING );
			$clean[ $slug ] = $list;
		}

		ksort( $clean, SORT_STRING );

		return $clean;
	}

	/**
	 * Taxonomies the settings page may offer for selection.
	 *
	 * The union of the taxonomies registered for the given post types, keyed by
	 * slug and sorted by slug. Used for the checkbox list in the panel and for
	 * the one-time migration off the 0.24.x checkbox — never to decide what gets
	 * emitted, which is the saved selection alone.
	 *
	 * @param string[] $post_types Post types to collect taxonomies from.
	 * @return array<string, object> Taxonomy slug => taxonomy object.
	 */
	public static function candidate_taxonomies( array $post_types ): array {
		$found = array();

		foreach ( $post_types as $post_type ) {
			if ( ! is_string( $post_type ) || '' === $post_type ) {
				continue;
			}

			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $slug => $taxonomy ) {
				$found[ (string) $slug ] = $taxonomy;
			}
		}

		return self::filter_candidates( $found );
	}

	/**
	 * Keeps the taxonomies that make sense to offer in the panel: emittable slugs
	 * (so `category`/`post_tag`/`post_format` never show up) that the site owner
	 * can actually recognize, i.e. public or with an admin UI. Pure, so the rule
	 * is directly testable.
	 *
	 * @param array<string, object> $taxonomies Taxonomy slug => taxonomy object.
	 * @return array<string, object> Filtered map, sorted by slug (byte order).
	 */
	public static function filter_candidates( array $taxonomies ): array {
		$clean = array();

		foreach ( $taxonomies as $slug => $taxonomy ) {
			$slug = (string) $slug;

			if ( ! self::is_emittable_taxonomy( $slug ) || ! is_object( $taxonomy ) ) {
				continue;
			}

			if ( empty( $taxonomy->public ) && empty( $taxonomy->show_ui ) ) {
				continue; // Pure plumbing: invisible in wp-admin and on the front end.
			}

			$clean[ $slug ] = $taxonomy;
		}

		ksort( $clean, SORT_STRING );

		return $clean;
	}

	/**
	 * Whether a taxonomy is public in the full sense: intended for public use
	 * **and** publicly queryable (it has term archives).
	 *
	 * Advisory only — it labels a row in the settings page and seeds the
	 * migration off the 0.24.x checkbox. It is deliberately NOT a gate on the
	 * output: `publicly_queryable => false` is the usual shape of an
	 * editorial-internal taxonomy, but a theme may still print its terms, so the
	 * decision stays the site owner's.
	 *
	 * @param mixed $taxonomy Taxonomy object (WP_Taxonomy).
	 */
	public static function is_public_taxonomy( $taxonomy ): bool {
		return is_object( $taxonomy )
			&& ! empty( $taxonomy->public )
			&& ! empty( $taxonomy->publicly_queryable );
	}

	/**
	 * Whether a slug may become a `taxonomies:` key: a string, not one of the
	 * always-excluded taxonomies, and a valid taxonomy name (so a filter cannot
	 * inject something that would break the YAML).
	 *
	 * @param mixed $slug Candidate taxonomy slug.
	 */
	private static function is_emittable_taxonomy( $slug ): bool {
		return is_string( $slug )
			&& ! in_array( $slug, self::EXCLUDED_TAXONOMIES, true )
			&& 1 === preg_match( '/^[a-z0-9_-]+$/i', $slug );
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
	 *
	 * The last fallback reads the post content rather than the rendered body —
	 * deliberately, since it also runs per entry when building `/llms.txt`,
	 * where rendering every listed post would be prohibitive. That shortcut is
	 * what made it leak: the exclusion rules are applied by the render pipeline,
	 * so a `md-exclude` section the body never publishes was summarised into the
	 * front matter regardless. The content therefore goes through the same
	 * exclusion pass first (a no-op, and byte-identical, for the content that
	 * carries no such class — which is nearly all of it).
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
		$raw = $this->renderer->strip_excluded_content( $raw ); // Removes md-exclude regions, as the body does.
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

		// Whatever control characters are left are dropped. `\s` above has
		// already turned tab, newline and friends into spaces; what remains is
		// the rest of C0, DEL and the C1 block (`\xC2\x80`-`\xC2\x9F` in UTF-8),
		// none of which may appear raw inside a YAML double-quoted scalar. They
		// are not reachable from the WordPress admin, but a title or description
		// can also arrive from an import, a REST write or one of this plugin's
		// own filters — and this function's contract is that the result parses
		// as YAML whatever the source string was. Byte classes, deliberately
		// without the `u` modifier: preg_replace() returns null on invalid UTF-8
		// with it, and the bytes of a multibyte character are all >= 0x80 and so
		// are never matched here anyway.
		$value = (string) preg_replace( '/[\x00-\x1F\x7F]|\xC2[\x80-\x9F]/', '', (string) $value );

		$value = trim( $value );
		$value = str_replace( '\\', '\\\\', $value );
		$value = str_replace( '"', '\\"', $value );

		return '"' . $value . '"';
	}
}
