<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce compatibility — keeps the store's own infrastructure pages out
 * of the Markdown surface.
 *
 * Cart, checkout and my-account are ordinary published `page` posts, so
 * nothing else in `PostSupport` catches them: they are ORDINARY WordPress
 * pages by every rule the plugin already applies (published, no password,
 * standard format), but their content is WooCommerce's own runtime chrome
 * ("Your cart is currently empty!") rather than anything an editor wrote.
 * Without a visitor session that is *all* their body ever contains, so a
 * `.md` for one of them is not a document worth an AI citing — the same
 * defect class the page-builder veto exists to prevent one level up. The
 * shop page is unaffected: that one is real, editable content.
 */
class WooCommerceCompat {

	/**
	 * WooCommerce's own page-option keys, in the shape `wc_get_page_id()` and
	 * the `woocommerce_{key}_page_id` options both use.
	 */
	const DEFAULT_PAGES = array( 'cart', 'checkout', 'myaccount' );

	/**
	 * Whether the post is one of WooCommerce's own infrastructure pages.
	 *
	 * Consulted by `PostSupport::is_servable()`, so the rule reaches the `.md`
	 * route, negotiation, `rel="alternate"`, `/llms.txt`, both shortcodes and
	 * the dynamic tag at once — the same reach as the other built-in rules.
	 *
	 * Reads `wc_get_page_id()` when WooCommerce is active, so any
	 * WooCommerce-side filtering of these IDs is respected, and falls back to
	 * WooCommerce's own options directly when the plugin is inactive:
	 * deactivating WooCommerce does not un-publish the pages it created, or
	 * change their body, so the exclusion has to survive the deactivation the
	 * same way `PostSupport::type_is_public()`'s saved-selection survival
	 * already does for a different rule. Both reads are cheap option lookups
	 * with no I/O, safe on the every-request path `is_servable()` runs on.
	 */
	public static function is_utility_page( \WP_Post $post ): bool {
		return in_array( (int) $post->ID, self::excluded_page_ids(), true );
	}

	/**
	 * @return int[] IDs of the WooCommerce pages currently excluded.
	 */
	private static function excluded_page_ids(): array {
		/**
		 * Filters which WooCommerce page keys are kept out of the Markdown.
		 *
		 * Defaults to `cart`, `checkout` and `myaccount`. Return an empty array
		 * to serve them all again, or a shorter list to exclude only some.
		 * Unrecognized keys resolve to no page and have no effect. The shop
		 * page is never in this list: it is real content, not infrastructure.
		 *
		 * @param string[] $keys WooCommerce page keys to exclude.
		 */
		$keys = (array) apply_filters( 'sysmda_markdown_excluded_woocommerce_pages', self::DEFAULT_PAGES );

		$ids = array();

		foreach ( $keys as $key ) {
			if ( ! is_string( $key ) || '' === $key ) {
				continue;
			}

			// wc_get_page_id() returns -1 for an unset page: absint() would turn
			// that into a false-positive match against a post ID of 0, which
			// never exists, but the explicit > 0 guard is what actually keeps it
			// out rather than relying on that coincidence.
			$id = function_exists( 'wc_get_page_id' )
				? (int) wc_get_page_id( $key )
				: (int) get_option( 'woocommerce_' . $key . '_page_id', 0 );

			if ( $id > 0 ) {
				$ids[] = $id;
			}
		}

		return $ids;
	}
}
