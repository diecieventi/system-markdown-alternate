<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Helper di cache unico per il plugin.
 *
 * Se è presente un object cache persistente (Redis/Memcached, rilevato con
 * wp_using_ext_object_cache()) usa le API wp_cache_* su un gruppo dedicato,
 * evitando le scritture/letture su wp_options. Altrimenti fa fallback ai
 * transient (che a loro volta usano l'object cache solo se persistente).
 *
 * Nota: non memorizziamo mai il valore booleano false, quindi un `false` in
 * lettura indica sempre "miss".
 */
class Cache {

	const GROUP = 'sma';

	/**
	 * @param string $key Chiave (senza prefisso di gruppo).
	 * @return mixed Valore memorizzato, o false se assente.
	 */
	public static function get( string $key ) {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_get( $key, self::GROUP );
		}

		return get_transient( $key );
	}

	/**
	 * @param string $key   Chiave.
	 * @param mixed  $value Valore (serializzabile).
	 * @param int    $ttl   Durata in secondi (0 = nessuna scadenza).
	 */
	public static function set( string $key, $value, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );
			return;
		}

		set_transient( $key, $value, $ttl );
	}

	/**
	 * @param string $key Chiave da eliminare.
	 */
	public static function delete( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::GROUP );
			return;
		}

		delete_transient( $key );
	}
}
