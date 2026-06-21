<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Bootstrap del plugin: costruisce le dipendenze e registra gli hook WordPress.
 */
class Plugin {

	/**
	 * @var MarkdownController
	 */
	private $controller;

	/**
	 * Costruisce il grafo delle dipendenze e aggancia gli hook.
	 */
	public function boot(): void {
		$renderer = new ContentRenderer( new BlockCleaner(), new ShortcodeCleaner() );

		$this->controller = new MarkdownController(
			$renderer,
			new MarkdownConverter(),
			new MetadataBuilder()
		);

		// Priorità 0: intercettiamo le richieste *.md prima del caricamento del template.
		add_action( 'template_redirect', array( $this->controller, 'maybe_render_markdown' ), 0 );

		// Link alternate nel <head> dei singoli articoli.
		add_action( 'wp_head', array( $this->controller, 'print_alternate_link' ) );
	}
}
