<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Rileva possibili conflitti sull'endpoint /llms.txt: altri plugin SEO con la
 * funzione llms.txt attiva, un file fisico nella root, o una risposta HTTP 200.
 *
 * Pensato per essere usato nel pannello admin (informativo): le rilevazioni via
 * opzione sono economiche; il controllo HTTP loopback è on-demand e cachato.
 */
class ConflictDetector {

	/** Cache del controllo HTTP loopback. */
	const AUDIT_CACHE_KEY = 'sma_llms_audit';

	/**
	 * Stato per provider noto.
	 *
	 * enabled: true = funzione llms.txt attiva; false = presente ma spenta;
	 *          null = non determinabile (verificare manualmente).
	 *
	 * @return array<string,array{name:string,detected:bool,enabled:?bool,confidence:string}>
	 */
	public function providers(): array {
		return array(
			'rank_math' => $this->rank_math(),
			'yoast'     => $this->yoast(),
			'aioseo'    => $this->aioseo(),
			'seopress'  => $this->seopress(),
		);
	}

	/** Provider con funzione llms.txt sicuramente attiva. */
	public function active_providers(): array {
		return array_filter( $this->providers(), static fn( $p ) => true === $p['enabled'] );
	}

	/** Provider rilevati ma con stato llms.txt ignoto (da verificare a mano). */
	public function unknown_providers(): array {
		return array_filter( $this->providers(), static fn( $p ) => $p['detected'] && null === $p['enabled'] );
	}

	/**
	 * File fisico llms.txt nella root: il web server lo serve PRIMA di WordPress,
	 * quindi qualsiasi endpoint PHP (il nostro o di altri plugin) viene ignorato.
	 */
	public function physical_file_exists(): bool {
		return file_exists( trailingslashit( ABSPATH ) . 'llms.txt' );
	}

	/**
	 * Controllo HTTP loopback di /llms.txt.
	 *
	 * @param bool $force true = esegue la richiesta ora e aggiorna la cache;
	 *                    false = restituisce solo l'ultimo risultato cachato (o null).
	 * @return array{reachable:bool,status:?int,content_type:?string,error:?string}|null
	 */
	public function endpoint_status( bool $force ): ?array {
		if ( ! $force ) {
			$cached = Cache::get( self::AUDIT_CACHE_KEY );
			return is_array( $cached ) ? $cached : null;
		}

		$result = $this->probe();
		Cache::set( self::AUDIT_CACHE_KEY, $result, 6 * HOUR_IN_SECONDS );

		return $result;
	}

	// ─── Provider ───────────────────────────────────────────────────────────

	private function rank_math(): array {
		$detected = defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' );
		// Modulo attivo: Rank Math salva gli slug attivi in 'rank_math_modules'.
		$enabled = $detected
			? in_array( 'llms-txt', (array) get_option( 'rank_math_modules', array() ), true )
			: null;

		return array(
			'name'       => 'Rank Math',
			'detected'   => $detected,
			'enabled'    => $enabled,
			'confidence' => 'high',
		);
	}

	private function yoast(): array {
		$detected = defined( 'WPSEO_VERSION' ) || class_exists( 'WPSEO_Options' );
		$enabled  = null;

		if ( $detected && class_exists( 'WPSEO_Options' ) ) {
			foreach ( array( 'enable_llms_txt', 'llms_txt' ) as $key ) {
				$value = \WPSEO_Options::get( $key, null );
				if ( null !== $value ) {
					$enabled = (bool) $value;
					break;
				}
			}
		}

		return array(
			'name'       => 'Yoast SEO',
			'detected'   => $detected,
			'enabled'    => $enabled,
			'confidence' => 'medium',
		);
	}

	private function aioseo(): array {
		$detected = defined( 'AIOSEO_VERSION' );

		return array(
			'name'       => 'All in One SEO',
			'detected'   => $detected,
			'enabled'    => null, // Nome opzione non verificato: stato ignoto.
			'confidence' => 'low',
		);
	}

	private function seopress(): array {
		$detected = defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' );

		return array(
			'name'       => 'SEOPress',
			'detected'   => $detected,
			'enabled'    => null, // Spesso virtuale via rewrite: affidarsi al check URL.
			'confidence' => 'low',
		);
	}

	// ─── Loopback ─────────────────────────────────────────────────────────────

	private function probe(): array {
		$response = wp_remote_get(
			home_url( '/llms.txt' ),
			array(
				'timeout'             => 5,
				'redirection'         => 3,
				'limit_response_size' => 2048,
				// UA da browser: molti WAF (es. RunCloud 8G) bloccano gli UA "bot",
				// dando un falso negativo su un endpoint che per i browser funziona.
				'user-agent'          => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'reachable'    => false,
				'status'       => null,
				'content_type' => null,
				'error'        => $response->get_error_message(),
			);
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$ct     = wp_remote_retrieve_header( $response, 'content-type' );

		return array(
			'reachable'    => $status >= 200 && $status < 300,
			'status'       => $status,
			'content_type' => $ct ?: null,
			'error'        => null,
		);
	}
}
