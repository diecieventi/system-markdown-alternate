<?php
/**
 * Plugin Name:       System Markdown Alternate
 * Plugin URI:        https://github.com/diecieventi/system-markdown-alternate
 * Description:       Exposes a clean Markdown version of your posts (readable by LLMs, agents and technical tools) by appending .md to the permalink.
 * Version:           0.20.0
 * Requires at least: 6.1
 * Requires PHP:      7.4
 * Author:            Diecieventi Digital Marketing
 * Author URI:        https://webdietrolequinte.it
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       system-markdown-alternate
 *
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

define( 'SYSMDA_VERSION', '0.20.0' );
define( 'SYSMDA_PLUGIN_FILE', __FILE__ );
define( 'SYSMDA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYSMDA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Autoloader Composer: carica sia il nostro namespace PSR-4 (Diecieventi\SystemMarkdownAlternate\)
 * sia league/html-to-markdown. Va generato con `composer install` (vedi bin/build.sh).
 */
$sysmda_autoload = SYSMDA_PLUGIN_DIR . 'vendor/autoload.php';

if ( ! is_readable( $sysmda_autoload ) ) {
	add_action(
		'admin_notices',
		static function () {
			echo '<div class="notice notice-error"><p>';
			echo esc_html__(
				'System Markdown Alternate: missing dependencies. Run "composer install" in the plugin folder, or install the built zip (DIST folder).',
				'system-markdown-alternate'
			);
			echo '</p></div>';
		}
	);

	return;
}

require_once $sysmda_autoload;

/*
 * Bootstrap. La logica vera vive in src/Plugin.php (registrazione hook e controller).
 */
add_action(
	'plugins_loaded',
	static function () {
		( new Plugin() )->boot();
	}
);
