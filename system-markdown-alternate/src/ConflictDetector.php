<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Rileva possibili conflitti sull'endpoint /llms.txt usando solo segnali
 * locali e stabili (nessuna richiesta di rete, nessuna lettura di opzioni
 * interne di terzi):
 * - plugin SEO noti ATTIVI che potrebbero generare /llms.txt;
 * - un file fisico llms.txt nella root.
 *
 * Pensato per il pannello admin (informativo): avvisa, poi decide l'utente.
 */
class ConflictDetector {

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
}
