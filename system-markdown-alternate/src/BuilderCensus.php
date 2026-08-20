<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * What each content type is actually built with, for the settings panel.
 *
 * Purely advisory: it never filters, enables or disables anything. Its whole
 * job is to make the page-builder veto visible before it surprises anybody —
 * the answer to "are my articles affected?" should take three seconds, not an
 * audit. Mixed types are the normal case (pages in a builder, articles in the
 * ordinary editor), which is why the panel shows a **breakdown** per type
 * rather than a single label.
 *
 * Three properties are deliberate:
 *
 * - **Computed on the settings screen only**, never on the front end, and
 *   cached through the plugin's `Cache` helper so reloading the panel is free.
 *   Writing it must not invalidate every cached Markdown body on the site, and
 *   it cannot: with a persistent object cache the value never reaches
 *   `wp_options` at all, and on the transient fallback it lands under an option
 *   name beginning with `_transient_`, which
 *   `AdminSettings::maybe_bump_cache_salt()` does not match. Asserted in the
 *   suite rather than assumed — the same rule as the hit-counter buckets.
 * - **One query.** The classification is a `CASE` chain built from
 *   `BuilderDetector::RENDER_MODE_META`, in the same order, so the census and
 *   the veto can never disagree about what a post is: adding a builder to the
 *   detector adds it here with no second edit.
 * - **Revisions are excluded twice over**, by `post_status = 'publish'` and by
 *   the post-type list. That is not belt and braces for its own sake: a Bricks
 *   revision carries `_bricks_page_content_2` but *not* `_bricks_editor_mode`,
 *   so a census counting payload rows would report the same page several times.
 *   Keying on the render-mode meta — the same rule the detector follows — is
 *   what makes the count right, and the guards keep it right for the builders
 *   with no mode flag.
 */
class BuilderCensus {

	/** Cached breakdown; the key carries a digest of the post types asked for. */
	const CACHE_KEY = 'sysmda_builder_census';

	/** Short enough that a page built five minutes ago shows up while you look. */
	const TTL = 300;

	/**
	 * Published posts per type, split by what renders them.
	 *
	 * @param string[] $post_types Public post types to count.
	 * @return array<string,array<string,int>> type => kind => count, where a
	 *         kind is a `BuilderDetector` key, `gutenberg` or `classic`.
	 */
	public static function breakdown( array $post_types ): array {
		$post_types = array_values( array_unique( array_filter( $post_types, 'is_string' ) ) );

		if ( empty( $post_types ) ) {
			return array();
		}

		sort( $post_types );

		$key    = self::CACHE_KEY . '_' . substr( md5( implode( ',', $post_types ) ), 0, 8 );
		$cached = Cache::get( $key );

		if ( is_array( $cached ) ) {
			return $cached;
		}

		$breakdown = self::query( $post_types );

		Cache::set( $key, $breakdown, self::TTL );

		return $breakdown;
	}

	/**
	 * Runs the census. Public so the panel can bypass the cache if it ever needs
	 * to; everything else should call breakdown().
	 *
	 * @param string[] $post_types Post types to count, already normalized.
	 * @return array<string,array<string,int>>
	 */
	public static function query( array $post_types ): array {
		global $wpdb;

		$cases = array();
		$args  = array();

		foreach ( BuilderDetector::RENDER_MODE_META as $builder => $spec ) {
			list( $meta_key, $accepted ) = $spec;

			if ( array() === $accepted ) {
				// No mode flag: a stored, non-empty payload is what renders.
				// `<> '0'` mirrors the detector's empty() test exactly.
				$cases[] = "WHEN EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value <> '' AND m.meta_value <> '0' ) THEN %s";
				$args[]  = $meta_key;
				$args[]  = $builder;
				continue;
			}

			$placeholders = implode( ', ', array_fill( 0, count( $accepted ), '%s' ) );

			$cases[] = "WHEN EXISTS ( SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s AND m.meta_value IN ( {$placeholders} ) ) THEN %s";
			$args[]  = $meta_key;

			foreach ( $accepted as $value ) {
				$args[] = (string) $value;
			}

			$args[] = $builder;
		}

		// Whatever no builder claimed is ordinary content, split the way the
		// conversion pipeline itself splits it: has_blocks() is "does the
		// content carry a block delimiter".
		$args[] = '%' . $wpdb->esc_like( '<!-- wp:' ) . '%';

		$types_in = implode( ', ', array_fill( 0, count( $post_types ), '%s' ) );
		$args     = array_merge( $args, $post_types );

		$sql = 'SELECT p.post_type AS post_type, CASE ' . implode( ' ', $cases ) // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Placeholder-only fragments built above; every value is passed to prepare() as an argument.
			. " WHEN p.post_content LIKE %s THEN 'gutenberg' ELSE 'classic' END AS kind, COUNT(*) AS n"
			. " FROM {$wpdb->posts} p"
			. " WHERE p.post_status = 'publish' AND p.post_type IN ( {$types_in} )"
			. ' GROUP BY p.post_type, kind';

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Admin-only census with no core API to express it; the result is cached in a transient by breakdown(), and $sql is a placeholder-only template assembled from a class constant.
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, $args ), ARRAY_A );

		return self::tally( is_array( $rows ) ? $rows : array(), $post_types );
	}

	/**
	 * Folds the query's rows into type => kind => count.
	 *
	 * Split out from the query so the shape can be tested without a database.
	 * Types with no published post keep an empty entry, so the panel can say
	 * nothing rather than imply the census failed.
	 *
	 * @param array<int,array<string,mixed>> $rows       Rows of post_type, kind, n.
	 * @param string[]                       $post_types Types that were asked for.
	 * @return array<string,array<string,int>>
	 */
	public static function tally( array $rows, array $post_types ): array {
		$breakdown = array_fill_keys( $post_types, array() );

		foreach ( $rows as $row ) {
			$type = isset( $row['post_type'] ) ? (string) $row['post_type'] : '';
			$kind = isset( $row['kind'] ) ? (string) $row['kind'] : '';
			$n    = isset( $row['n'] ) ? (int) $row['n'] : 0;

			if ( '' === $type || '' === $kind || $n <= 0 || ! isset( $breakdown[ $type ] ) ) {
				continue;
			}

			$breakdown[ $type ][ $kind ] = ( isset( $breakdown[ $type ][ $kind ] ) ? $breakdown[ $type ][ $kind ] : 0 ) + $n;
		}

		// Builder rows first, in the detector's order, so the part that carries
		// a warning is the part the eye reaches first.
		$order = array_merge( array_keys( BuilderDetector::RENDER_MODE_META ), array( 'gutenberg', 'classic' ) );

		foreach ( $breakdown as $type => $counts ) {
			$sorted = array();

			foreach ( $order as $kind ) {
				if ( isset( $counts[ $kind ] ) ) {
					$sorted[ $kind ] = $counts[ $kind ];
				}
			}

			$breakdown[ $type ] = $sorted;
		}

		return $breakdown;
	}
}
