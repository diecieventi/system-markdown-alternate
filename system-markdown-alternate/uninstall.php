<?php
/**
 * Uninstall System Markdown Alternate.
 *
 * Removes all plugin options and cached data (transients).
 * WordPress runs this only when the plugin is deleted.
 *
 * On multisite the cleanup runs for every site in the network: options and
 * transients live per site, so deleting a network-activated plugin without the
 * loop would leave rows behind on every site but the current one.
 *
 * @package Diecieventi\SystemMarkdownAlternate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$sysmda_options = array(
	'sysmda_cache_ttl',
	'sysmda_excluded_shortcodes',
	'sysmda_excluded_block_names',
	'sysmda_excluded_classes',
	'sysmda_supported_post_types',
	'sysmda_robots_header',
	'sysmda_front_matter_taxonomy_slugs',
	'sysmda_acf_subtitle_key',
	'sysmda_acf_tldr_key',
	'sysmda_llms_txt_enabled',
	'sysmda_llms_txt_enriched',
	'sysmda_llms_txt_lastmod',
	'sysmda_llms_txt_summary',
	'sysmda_llms_txt_key_content',
	'sysmda_litespeed_htaccess',
	'sysmda_md_hits',
	'sysmda_md_hits_enabled',
	'sysmda_md_button_items',
	'sysmda_cache_salt',
	'sysmda_dynamic_tag_enabled', // Legacy option (Dynamic Tag toggle removed in 0.8.0).
	'sysmda_md_button_position', // Legacy option (button auto-insert removed in 0.32.0).
	'sysmda_front_matter_taxonomies', // Legacy option (checkbox replaced by the taxonomy selection in 0.25.0).
);

/**
 * Removes the plugin's options and transients from the current site.
 *
 * @param string[] $options Option names to delete.
 */
$sysmda_clean_site = static function ( array $options ) use ( $wpdb ) {
	foreach ( $options as $option ) {
		delete_option( $option );
	}

	// Plugin transients (key and timeout). Cover both database storage and, as a
	// precaution, any leftovers when an object cache is active.
	// The direct query is deliberate: individual transient keys are unknown and
	// no cache is relevant during uninstall.
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->query(
		"DELETE FROM {$wpdb->options}
		 WHERE option_name LIKE '\_transient\_sysmda\_md\_%'
		    OR option_name LIKE '\_transient\_timeout\_sysmda\_md\_%'
		    OR option_name LIKE '\_transient\_sysmda\_llms\_%'
		    OR option_name LIKE '\_transient\_timeout\_sysmda\_llms\_%'"
	);
};

if ( is_multisite() ) {
	// Batched: a large network must not be walked in a single unbounded query.
	$sysmda_batch  = 100;
	$sysmda_offset = 0;

	do {
		$sysmda_sites = get_sites(
			array(
				'fields'                 => 'ids',
				'number'                 => $sysmda_batch,
				'offset'                 => $sysmda_offset,
				'update_site_meta_cache' => false,
			)
		);

		$sysmda_found = count( $sysmda_sites );

		foreach ( $sysmda_sites as $sysmda_site_id ) {
			switch_to_blog( (int) $sysmda_site_id );
			$sysmda_clean_site( $sysmda_options );
			restore_current_blog();
		}

		$sysmda_offset += $sysmda_batch;
	} while ( $sysmda_found === $sysmda_batch );
} else {
	$sysmda_clean_site( $sysmda_options );
}

// Persistent object cache: flush the group when the API is available.
if ( function_exists( 'wp_cache_flush_group' ) && wp_using_ext_object_cache() ) {
	wp_cache_flush_group( 'sysmda' );
}

// Remove the LiteSpeed compatibility block from .htaccess, if present. One file
// per network, so this stays outside the per-site loop.
require_once __DIR__ . '/src/LiteSpeedCompat.php';
\Diecieventi\SystemMarkdownAlternate\LiteSpeedCompat::remove_rules();
