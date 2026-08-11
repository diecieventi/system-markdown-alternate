<?php
/**
 * Plugin Name: System Markdown Alternate — documentation site
 * Description: Registers the `doc` content type and its category taxonomy that power the plugin's documentation site. Site scaffolding, not part of the distributed plugin.
 * Version:     0.1.0
 * Author:      Diecieventi Digital Marketing
 * License:     GPL-2.0-or-later
 *
 * The `smadocs_` prefix is deliberate: the plugin's uninstall.php deletes every
 * `sysmda_*` option, so anything this file stores must sit outside that namespace
 * or uninstalling the plugin would wipe the documentation site's own state.
 *
 * @package SystemMarkdownAlternate\DocsSite
 */

defined( 'ABSPATH' ) || exit;

const SMADOCS_REWRITE_VERSION = '2';

/**
 * Register the documentation content type and its taxonomy.
 *
 * `public => true` is load-bearing rather than cosmetic: the plugin drops any
 * saved post type that is not currently registered public from
 * `sysmda_markdown_supported_post_types`, so a private type here would silently
 * stop serving `.md` for the whole documentation site.
 *
 * The taxonomy is registered FIRST, and the order is load-bearing too. Rewrite
 * rules are emitted in the order their permastructs were added, and a post type
 * rewritten under `docs` also generates the attachment rule
 * `docs/[^/]+/([^/]+)/?$` — which matches `docs/category/troubleshooting/` and
 * resolves it to a non-existent attachment. Registering the post type first put
 * that greedy rule ahead of `docs/category/([^/]+)/?$`, so every category
 * archive answered 404 while the rule for it sat in the table unused. Do not
 * swap these two calls back.
 */
function smadocs_register_content_type(): void {
	register_taxonomy(
		'doc_category',
		'doc',
		array(
			'labels'            => array(
				'name'          => 'Doc categories',
				'singular_name' => 'Doc category',
				'menu_name'     => 'Categories',
			),
			'public'            => true,
			'hierarchical'      => true,
			'show_in_rest'      => true,
			'show_admin_column' => true,
			'rewrite'           => array(
				'slug'       => 'docs/category',
				'with_front' => false,
			),
		)
	);

	register_post_type(
		'doc',
		array(
			'labels'        => array(
				'name'               => 'Docs',
				'singular_name'      => 'Doc',
				'add_new_item'       => 'Add new doc',
				'edit_item'          => 'Edit doc',
				'search_items'       => 'Search docs',
				'not_found'          => 'No docs found',
				'all_items'          => 'All docs',
				'menu_name'          => 'Docs',
			),
			'public'        => true,
			'show_in_rest'  => true,
			'menu_icon'     => 'dashicons-book-alt',
			'menu_position' => 20,
			'has_archive'   => 'docs',
			'rewrite'       => array(
				'slug'       => 'docs',
				'with_front' => false,
			),
			'supports'      => array( 'title', 'editor', 'excerpt', 'revisions', 'thumbnail', 'custom-fields' ),
		)
	);
}
add_action( 'init', 'smadocs_register_content_type' );

/**
 * Flush the rewrite rules once per registration change.
 *
 * Registering a post type from an mu-plugin never fires an activation hook, so
 * the `/docs/` archive 404s until the rules are rebuilt. Gated on a stored
 * version so this costs nothing on ordinary requests.
 */
function smadocs_maybe_flush_rewrites(): void {
	if ( get_option( 'smadocs_rewrite_version' ) === SMADOCS_REWRITE_VERSION ) {
		return;
	}

	flush_rewrite_rules( false );
	update_option( 'smadocs_rewrite_version', SMADOCS_REWRITE_VERSION, false );
}
add_action( 'init', 'smadocs_maybe_flush_rewrites', 99 );

/**
 * Order the category archives the way the documentation reads, not alphabetically.
 *
 * Term meta `smadocs_order` is set when the terms are created; terms without it
 * sort last.
 */
function smadocs_order_categories( $terms, $taxonomies ) {
	if ( ! in_array( 'doc_category', (array) $taxonomies, true ) || ! is_array( $terms ) ) {
		return $terms;
	}

	$ordered = $terms;
	usort(
		$ordered,
		static function ( $a, $b ) {
			if ( ! $a instanceof WP_Term || ! $b instanceof WP_Term ) {
				return 0;
			}

			$a_order = (int) get_term_meta( $a->term_id, 'smadocs_order', true ) ?: PHP_INT_MAX;
			$b_order = (int) get_term_meta( $b->term_id, 'smadocs_order', true ) ?: PHP_INT_MAX;

			return $a_order <=> $b_order;
		}
	);

	return $ordered;
}
add_filter( 'get_terms', 'smadocs_order_categories', 10, 2 );
