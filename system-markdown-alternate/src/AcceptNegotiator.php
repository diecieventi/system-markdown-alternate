<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Parser dell'header HTTP `Accept` con q-values (RFC 9110 §12.5.1).
 *
 * Espone la "qualità" (preferenza del client, 0..1) per un tipo MIME concreto,
 * applicando la regola del range più specifico: match esatto, poi wildcard di
 * sottotipo (`text/*`), poi wildcard totale (qualsiasi tipo).
 * È volutamente privo di dipendenze WordPress per restare testabile in isolamento.
 */
class AcceptNegotiator {

	/**
	 * Scompone un header Accept in coppie media-range => q (0..1).
	 *
	 * I duplicati collassano sul q massimo; i range malformati (senza `/`) sono
	 * ignorati; un `q` non numerico vale 1.0; i valori sono limitati a [0,1].
	 *
	 * @return array<string,float>
	 */
	public static function parse( string $accept ): array {
		$ranges = array();

		foreach ( explode( ',', $accept ) as $part ) {
			$part = trim( $part );
			if ( '' === $part ) {
				continue;
			}

			$segments = explode( ';', $part );
			$range    = strtolower( trim( (string) array_shift( $segments ) ) );

			if ( '' === $range || false === strpos( $range, '/' ) ) {
				continue;
			}

			$q = 1.0;
			foreach ( $segments as $param ) {
				$param = trim( $param );
				if ( 0 === stripos( $param, 'q=' ) ) {
					$value = substr( $param, 2 );
					if ( is_numeric( $value ) ) {
						$q = (float) $value;
					}
					break;
				}
			}

			$q = max( 0.0, min( 1.0, $q ) );

			if ( ! isset( $ranges[ $range ] ) || $q > $ranges[ $range ] ) {
				$ranges[ $range ] = $q;
			}
		}

		return $ranges;
	}

	/**
	 * Qualità effettiva per un tipo MIME concreto (es. `text/markdown`),
	 * applicando la regola del range più specifico: match esatto, poi `tipo/*`,
	 * poi il wildcard totale. Ritorna 0 se il tipo non è accettato.
	 */
	public static function quality( string $accept, string $type ): float {
		$type   = strtolower( $type );
		$ranges = self::parse( $accept );

		if ( isset( $ranges[ $type ] ) ) {
			return $ranges[ $type ];
		}

		$main = explode( '/', $type )[0];
		if ( isset( $ranges[ $main . '/*' ] ) ) {
			return $ranges[ $main . '/*' ];
		}

		if ( isset( $ranges['*/*'] ) ) {
			return $ranges['*/*'];
		}

		return 0.0;
	}

	/**
	 * Qualità per un match ESATTO del tipo (nessun fallback su wildcard).
	 * Ritorna `null` se il tipo non è elencato esplicitamente nell'Accept.
	 */
	public static function explicit_quality( string $accept, string $type ): ?float {
		$ranges = self::parse( $accept );
		$type   = strtolower( $type );

		return array_key_exists( $type, $ranges ) ? $ranges[ $type ] : null;
	}
}
