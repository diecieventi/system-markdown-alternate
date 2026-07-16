<?php
/**
 * Uninstall System Markdown Alternate.
 *
 * Removes all plugin options and cached data (transients).
 * WordPress runs this only when the plugin is deleted.
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
	'sysmda_cache_salt',
	'sysmda_dynamic_tag_enabled', // Legacy option (Dynamic Tag toggle removed in 0.8.0).
);

foreach ( $sysmda_options as $sysmda_option ) {
	delete_option( $sysmda_option );
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

// Persistent object cache: flush the group when the API is available.
if ( function_exists( 'wp_cache_flush_group' ) && wp_using_ext_object_cache() ) {
	wp_cache_flush_group( 'sysmda' );
}

// Remove the LiteSpeed compatibility block from .htaccess, if present.
require_once __DIR__ . '/src/LiteSpeedCompat.php';
\Diecieventi\SystemMarkdownAlternate\LiteSpeedCompat::remove_rules();
