<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Rileva possibili conflitti sull'endpoint /llms.txt:
 * - plugin SEO noti ATTIVI che potrebbero generare /llms.txt (rilevamento per
 *   presenza, stabile nel tempo: non leggiamo le opzioni interne di terzi, che
 *   cambierebbero senza preavviso e ci costringerebbero a manutenzione continua);
 * - un file fisico llms.txt nella root;
 * - on-demand, una risposta HTTP all'URL (best effort).
 *
 * Pensato per il pannello admin (informativo): avvisa, poi decide l'utente.
 */
class ConflictDetector {

	/** Cache del controllo HTTP loopback. */
	const AUDIT_CACHE_KEY = 'sma_llms_audit';

	/**
	 * Plugin SEO noti che POSSONO gestire /llms.txt, con il rispettivo check di
	 * "attivo" (costante/classe definita quando il plugin è caricato).
	 *
	 * @return array<string,bool>
	 */
	private function known_providers(): array {
		return array(
			'Rank Math'      => defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ),
			'Yoast SEO'      => defined( 'WPSEO_VERSION' ),
			'All in One SEO' => defined( 'AIOSEO_VERSION' ),
			'SEOPress'       => defined( 'SEOPRESS_VERSION' ) || defined( 'SEOPRESS_PRO_VERSION' ),
		);
	}

	/**
	 * Nomi dei plugin SEO attivi che potrebbero gestire /llms.txt.
	 *
	 * @return string[]
	 */
	public function detected_providers(): array {
		return array_keys( array_filter( $this->known_providers() ) );
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
