<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Parses the HTTP `Accept` header with q-values (RFC 9110 §12.5.1).
 *
 * Exposes the "quality" (client preference, 0..1) for a concrete MIME type,
 * applying the most-specific-range rule: exact match, subtype wildcard
 * (`text/*`), then the full wildcard (any type).
 * It intentionally has no WordPress dependencies so it remains testable in isolation.
 */
class AcceptNegotiator {

	/**
	 * Splits an Accept header into media-range => q (0..1) pairs.
	 *
	 * Duplicate ranges collapse to their highest q; malformed ranges (without `/`)
	 * are ignored; values are clamped to [0,1].
	 *
	 * A non-numeric `q` makes the whole media range unusable, so the range is
	 * **dropped**. It used to be treated as the default 1.0, which turned
	 * `text/markdown;q=banana` into the strongest possible preference and served
	 * Markdown to a client that never asked for it — the opposite of the "only
	 * when explicitly preferred" rule. A numeric weight is kept even when out of
	 * range (`q=7` clamps to 1.0): it still expresses a preference.
	 * Dropping it can leave no range at all; callers must treat an empty result
	 * as "no usable preference", never as "nothing is acceptable" (see
	 * MarkdownController::should_reject_unacceptable()).
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

			$q       = 1.0;
			$invalid = false;
			foreach ( $segments as $param ) {
				$param = trim( $param );
				if ( 0 === stripos( $param, 'q=' ) ) {
					$value = trim( substr( $param, 2 ) );

					// A numeric weight is kept and clamped below, out-of-range
					// values included: `q=7` still expresses a preference. A
					// non-numeric one expresses nothing at all, so the range
					// goes rather than silently becoming the strongest.
					if ( is_numeric( $value ) ) {
						$q = (float) $value;
					} else {
						$invalid = true;
					}
					break;
				}
			}

			if ( $invalid ) {
				continue;
			}

			$q = max( 0.0, min( 1.0, $q ) );

			if ( ! isset( $ranges[ $range ] ) || $q > $ranges[ $range ] ) {
				$ranges[ $range ] = $q;
			}
		}

		return $ranges;
	}

	/**
	 * Effective quality for a concrete MIME type (for example `text/markdown`),
	 * applying the most-specific-range rule: exact match, then `type/*`, then
	 * the full wildcard. Returns 0 when the type is not accepted.
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
	 * Quality for an EXACT type match (without wildcard fallback).
	 * Returns `null` when the type is not explicitly listed in Accept.
	 */
	public static function explicit_quality( string $accept, string $type ): ?float {
		$ranges = self::parse( $accept );
		$type   = strtolower( $type );

		return array_key_exists( $type, $ranges ) ? $ranges[ $type ] : null;
	}
}
