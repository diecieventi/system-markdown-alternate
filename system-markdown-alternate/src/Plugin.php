<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap del plugin.
 *
 * Responsabilità:
 * - costruire le dipendenze (controller, renderer, converter, ...);
 * - registrare gli hook WordPress.
 *
 * Hook previsti:
 *   add_action( 'template_redirect', [ $controller, 'maybe_render_markdown' ], 0 );
 *   add_action( 'wp_head',           [ $controller, 'print_alternate_link' ] );
 */
class Plugin {

	/**
	 * Registra hook e inizializza il controller.
	 *
	 * TODO (fase 2): implementare.
	 */
	public function boot(): void {
	}
}
