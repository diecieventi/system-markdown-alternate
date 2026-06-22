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
		$shortcodes = new ShortcodeCleaner();
		$renderer   = new ContentRenderer( new BlockCleaner(), $shortcodes );

		$this->controller = new MarkdownController(
			$renderer,
			new MarkdownConverter(),
			new MetadataBuilder( $shortcodes )
		);

		// Priorità 0: intercettiamo le richieste *.md prima del caricamento del template.
		add_action( 'template_redirect', array( $this->controller, 'maybe_render_markdown' ), 0 );

		// Link alternate nel <head> dei post/CPT supportati.
		add_action( 'wp_head', array( $this->controller, 'print_alternate_link' ) );

		// Invalida la cache Markdown quando un post viene salvato o eliminato.
		add_action( 'save_post', array( $this->controller, 'invalidate_cache' ) );
		add_action( 'deleted_post', array( $this->controller, 'invalidate_cache' ) );

		// Endpoint /llms.txt.
		$llms = new LlmsTxtController();
		add_action( 'template_redirect', array( $llms, 'maybe_render_llms_txt' ), 0 );

		// Integrazione ACF (opt-in tramite filtro sma_acf_field_keys).
		$acf = new AcfIntegration();
		add_filter( 'sma_markdown_source_content', array( $acf, 'append_fields' ), 20, 2 );

		// Pannello admin.
		if ( is_admin() ) {
			( new AdminSettings() )->boot();
		}
	}
}
