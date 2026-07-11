<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Plugin bootstrap: builds dependencies and registers WordPress hooks.
 */
class Plugin {

	/**
	 * @var MarkdownController
	 */
	private $controller;

	/**
	 * Builds the dependency graph and attaches hooks.
	 */
	public function boot(): void {
		$shortcodes = new ShortcodeCleaner();
		$renderer   = new ContentRenderer( new BlockCleaner( $shortcodes ), $shortcodes );
		$converter  = new MarkdownConverter();
		$metadata   = new MetadataBuilder( $shortcodes );

		$this->controller = new MarkdownController( $renderer, $converter, $metadata );

		// Priority 0: intercept *.md requests before the template loads.
		add_action( 'template_redirect', array( $this->controller, 'maybe_render_markdown' ), 0 );

		// Alternate link in the <head> of supported posts/CPTs.
		add_action( 'wp_head', array( $this->controller, 'print_alternate_link' ) );

		// Invalidate the Markdown cache when a post is saved or deleted.
		add_action( 'save_post', array( $this->controller, 'invalidate_cache' ) );
		add_action( 'deleted_post', array( $this->controller, 'invalidate_cache' ) );

		// Endpoint /llms.txt.
		$llms = new LlmsTxtController( $metadata );
		add_action( 'template_redirect', array( $llms, 'maybe_render_llms_txt' ), 0 );

		// ACF integration (opt-in through sysmda_acf_field_keys, sysmda_acf_subtitle_key, and sysmda_acf_tldr_key filters).
		$acf = new AcfIntegration( $converter, $renderer );
		add_filter( 'sysmda_markdown_source_content', array( $acf, 'append_fields' ), 20, 2 );
		add_filter( 'sysmda_markdown_preamble', array( $acf, 'build_preamble' ), 20, 2 );

		// [sysmda_md_url] shortcode for the dynamic .md URL.
		( new Shortcodes() )->register();

		// GenerateBlocks {{sysmda_md_url}} dynamic tag (automatically active with GB 2.x).
		( new DynamicTags() )->register();

		// AdminSettings registers filters in every context (including the front end).
		// The admin panel is attached through admin_menu/admin_init, which fire only
		// in the admin area, so no is_admin() guard is needed.
		( new AdminSettings() )->boot();
	}
}
