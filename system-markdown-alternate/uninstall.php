<?php
/**
 * Disinstallazione di System Markdown Alternate.
 *
 * Rimuove tutte le opzioni del plugin e i dati in cache (transient).
 * Eseguito da WordPress solo alla cancellazione del plugin.
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
	'sysmda_cache_salt',
	'sysmda_dynamic_tag_enabled', // opzione legacy (toggle Dynamic Tag rimosso in 0.8.0).
);

foreach ( $sysmda_options as $sysmda_option ) {
	delete_option( $sysmda_option );
}

// Transient del plugin (chiave + timeout). Coperti sia il caso DB sia,
// per sicurezza, eventuali residui anche con object cache attivo.
// Query diretta deliberata: le chiavi dei transient non sono note singolarmente
// e nessuna cache è rilevante durante la disinstallazione.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_sysmda\_md\_%'
	    OR option_name LIKE '\_transient\_timeout\_sysmda\_md\_%'
	    OR option_name LIKE '\_transient\_sysmda\_llms\_%'
	    OR option_name LIKE '\_transient\_timeout\_sysmda\_llms\_%'"
);

// Object cache persistente: svuota il gruppo se l'API è disponibile.
if ( function_exists( 'wp_cache_flush_group' ) && wp_using_ext_object_cache() ) {
	wp_cache_flush_group( 'sysmda' );
}
