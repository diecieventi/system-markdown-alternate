<?php
/**
 * Plugin Name:       System Markdown Alternate
 * Plugin URI:        https://github.com/system4pc/system-markdown-alternate
 * Description:       Espone una versione Markdown pulita (leggibile da LLM, agenti e tool tecnici) degli articoli del blog tramite URL con suffisso .md.
 * Version:           0.10.1
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Diecieventi Digital Marketing
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       system-markdown-alternate
 *
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

define( 'SMA_VERSION', '0.10.1' );
define( 'SMA_PLUGIN_FILE', __FILE__ );
define( 'SMA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SMA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloader Composer: carica sia il nostro namespace PSR-4 (SystemMarkdownAlternate\)
 * sia league/html-to-markdown. Va generato con `composer install` (vedi bin/build.sh).
 */
$sma_autoload = SMA_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! is_readable( $sma_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'System Markdown Alternate: dipendenze mancanti. Esegui "composer install" nella cartella del plugin oppure installa lo zip buildato (cartella DIST).',
				'system-markdown-alternate'
			);
			echo '</p></div>';
		}
	);

	return;
}

require_once $sma_autoload;

/*
 * Bootstrap. La logica vera vive in src/Plugin.php (registrazione hook e controller).
 */
add_action(
	'plugins_loaded',
	static function () {
		( new Plugin() )->boot();
	}
);
