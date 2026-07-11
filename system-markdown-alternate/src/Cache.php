<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Central cache helper for the plugin.
 *
 * When a persistent object cache is available (Redis/Memcached, detected with
 * wp_using_ext_object_cache()), it uses the wp_cache_* APIs with a dedicated
 * group and avoids reads/writes in wp_options. Otherwise it falls back to
 * transients (which use the object cache only when it is persistent).
 *
 * Note: the boolean false is never stored, so a `false` read always means a miss.
 */
class Cache {

	const GROUP = 'sysmda';

	/**
	 * @param string $key Key (without the group prefix).
	 * @return mixed Stored value, or false when absent.
	 */
	public static function get( string $key ) {
		if ( wp_using_ext_object_cache() ) {
			return wp_cache_get( $key, self::GROUP );
		}

		return get_transient( $key );
	}

	/**
	 * @param string $key   Key.
	 * @param mixed  $value Value (serializable).
	 * @param int    $ttl   Lifetime in seconds (0 = no expiration).
	 */
	public static function set( string $key, $value, int $ttl ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_set( $key, $value, self::GROUP, $ttl );
			return;
		}

		set_transient( $key, $value, $ttl );
	}

	/**
	 * @param string $key Key to delete.
	 */
	public static function delete( string $key ): void {
		if ( wp_using_ext_object_cache() ) {
			wp_cache_delete( $key, self::GROUP );
			return;
		}

		delete_transient( $key );
	}
}
