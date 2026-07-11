<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

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
		// Traduzioni: nessun load_plugin_textdomain() — la distribuzione è via
		// wordpress.org, che consegna i language pack da translate.wordpress.org
		// e li carica automaticamente (WP >= 4.6).
		$shortcodes = new ShortcodeCleaner();
		$renderer   = new ContentRenderer( new BlockCleaner( $shortcodes ), $shortcodes );
		$converter  = new MarkdownConverter();
		$metadata   = new MetadataBuilder( $shortcodes );

		$this->controller = new MarkdownController( $renderer, $converter, $metadata );

		// Priorità 0: intercettiamo le richieste *.md prima del caricamento del template.
		add_action( 'template_redirect', array( $this->controller, 'maybe_render_markdown' ), 0 );

		// Link alternate nel <head> dei post/CPT supportati.
		add_action( 'wp_head', array( $this->controller, 'print_alternate_link' ) );

		// Invalida la cache Markdown quando un post viene salvato o eliminato.
		add_action( 'save_post', array( $this->controller, 'invalidate_cache' ) );
		add_action( 'deleted_post', array( $this->controller, 'invalidate_cache' ) );

		// Endpoint /llms.txt.
		$llms = new LlmsTxtController( $metadata );
		add_action( 'template_redirect', array( $llms, 'maybe_render_llms_txt' ), 0 );

		// Integrazione ACF (opt-in tramite filtri sysmda_acf_field_keys, sysmda_acf_subtitle_key, sysmda_acf_tldr_key).
		$acf = new AcfIntegration( $converter, $renderer );
		add_filter( 'sysmda_markdown_source_content', array( $acf, 'append_fields' ), 20, 2 );
		add_filter( 'sysmda_markdown_preamble', array( $acf, 'build_preamble' ), 20, 2 );

		// Shortcode [sysmda_md_url] per l'URL dinamico del .md.
		( new Shortcodes() )->register();

		// Dynamic Tag GenerateBlocks {{sysmda_md_url}} (auto-attivo se GB 2.x è presente).
		( new DynamicTags() )->register();

		// AdminSettings: registra i filtri su tutti i contesti (front-end incluso),
		// il pannello admin viene agganciato da admin_menu/admin_init che sparano
		// solo nell'admin da soli — nessun gate is_admin() necessario.
		( new AdminSettings() )->boot();
	}
}
