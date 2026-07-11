<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Detects possible /llms.txt endpoint conflicts using only stable local signals
 * (no network requests and no reads of third-party internal options):
 * - known ACTIVE SEO plugins that might generate /llms.txt;
 * - a physical llms.txt file in the root directory.
 *
 * Intended as an informational admin notice: it warns and lets the user decide.
 */
class ConflictDetector {

	/**
	 * Known SEO plugins that MAY handle /llms.txt, with their corresponding
	 * "active" check (constant/class defined when the plugin is loaded).
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
	 * Names of active SEO plugins that might handle /llms.txt.
	 *
	 * @return string[]
	 */
	public function detected_providers(): array {
		return array_keys( array_filter( $this->known_providers() ) );
	}

	/**
	 * A physical llms.txt file in the root is served BEFORE WordPress by the web
	 * server, so every PHP endpoint (ours or another plugin's) is ignored.
	 */
	public function physical_file_exists(): bool {
		return file_exists( trailingslashit( ABSPATH ) . 'llms.txt' );
	}
}
