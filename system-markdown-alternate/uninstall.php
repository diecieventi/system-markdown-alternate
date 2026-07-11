<?php
/**
 * Disinstallazione di System Markdown Alternate.
 *
 * Rimuove tutte le opzioni del plugin e i dati in cache (transient).
 * Eseguito da WordPress solo alla cancellazione del plugin.
 *
 * @package SystemMarkdownAlternate
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$sma_options = array(
	'sma_cache_ttl',
	'sma_excluded_shortcodes',
	'sma_excluded_block_names',
	'sma_excluded_classes',
	'sma_supported_post_types',
	'sma_robots_header',
	'sma_acf_subtitle_key',
	'sma_acf_tldr_key',
	'sma_llms_txt_enabled',
	'sma_llms_txt_enriched',
	'sma_llms_txt_lastmod',
	'sma_llms_txt_summary',
	'sma_llms_txt_key_content',
	'sma_cache_salt',
	'sma_dynamic_tag_enabled', // opzione legacy (toggle Dynamic Tag rimosso in 0.8.0).
);

foreach ( $sma_options as $sma_option ) {
	delete_option( $sma_option );
}

// Transient del plugin (chiave + timeout). Coperti sia il caso DB sia,
// per sicurezza, eventuali residui anche con object cache attivo.
// Query diretta deliberata: le chiavi dei transient non sono note singolarmente
// e nessuna cache è rilevante durante la disinstallazione.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_sma\_md\_%'
	    OR option_name LIKE '\_transient\_timeout\_sma\_md\_%'
	    OR option_name LIKE '\_transient\_sma\_llms\_%'
	    OR option_name LIKE '\_transient\_timeout\_sma\_llms\_%'"
);

// Object cache persistente: svuota il gruppo se l'API è disponibile.
if ( function_exists( 'wp_cache_flush_group' ) && wp_using_ext_object_cache() ) {
	wp_cache_flush_group( 'sma' );
}
