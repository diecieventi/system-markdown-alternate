<?php
/**
 * Plugin Name:       System Markdown Alternate
 * Plugin URI:        https://github.com/diecieventi/system-markdown-alternate
 * Description:       Exposes a clean Markdown version of your posts (readable by LLMs, agents and technical tools) by appending .md to the permalink.
 * Version:           0.23.2
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

define( 'SYSMDA_VERSION', '0.23.2' );
define( 'SYSMDA_PLUGIN_FILE', __FILE__ );
define( 'SYSMDA_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SYSMDA_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

/*
 * Composer autoloader: loads both our PSR-4 namespace (Diecieventi\SystemMarkdownAlternate\)
 * and league/html-to-markdown. Generate it with `composer install` (see bin/build.sh).
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
 * Purge the LiteSpeed page cache on activation AND deactivation: entries cached
 * while the plugin was inactive carry no `Vary: Accept` (and, on deactivation,
 * negotiated entries would keep the plugin's headers), producing mixed
 * HTML/Markdown representations that are very hard to diagnose. No-op when the
 * LiteSpeed Cache plugin is absent.
 */
register_activation_hook( __FILE__, array( LiteSpeedCompat::class, 'purge_litespeed_cache' ) );
register_deactivation_hook( __FILE__, array( LiteSpeedCompat::class, 'purge_litespeed_cache' ) );

/*
 * Bootstrap. The application logic lives in src/Plugin.php (hook and controller registration).
 */
add_action(
	'plugins_loaded',
	static function () {
		( new Plugin() )->boot();
	}
);
