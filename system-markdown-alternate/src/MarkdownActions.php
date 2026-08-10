<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * GitHub-style Markdown actions: copy, view in a new tab, or download.
 *
 *   [sysmda_md_actions]           -> actions for the current post
 *   [sysmda_md_actions id="123"]  -> actions for a specific post
 *
 * Placement is entirely explicit: the component renders only where the
 * shortcode is used. Its assets are registered on wp_enqueue_scripts, loaded
 * early when the shortcode is visible in the queried post content, and loaded
 * late as a fallback when a template, widget, or secondary loop renders it.
 */
class MarkdownActions {

	/** Shortcode tag. Also hard-excluded from the Markdown pipeline. */
	const TAG = 'sysmda_md_actions';

	/** Handle shared by the stylesheet and script. */
	const HANDLE = 'sysmda-md-actions';

	/**
	 * Registers the shortcode and front-end assets.
	 */
	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Registers the assets, then enqueues them early when the main post carries
	 * the shortcode. The render callback covers every later/dynamic placement.
	 */
	public function register_assets(): void {
		self::register_asset_handles();

		if ( $this->request_renders_actions() ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Registers the shared handles once, including when a template renders the
	 * shortcode before wp_enqueue_scripts has run.
	 */
	private static function register_asset_handles(): void {
		if ( wp_style_is( self::HANDLE, 'registered' ) && wp_script_is( self::HANDLE, 'registered' ) ) {
			return;
		}

		wp_register_style(
			self::HANDLE,
			SYSMDA_PLUGIN_URL . 'assets/md-actions.css',
			array(),
			SYSMDA_VERSION
		);

		wp_register_script(
			self::HANDLE,
			SYSMDA_PLUGIN_URL . 'assets/md-actions.js',
			array(),
			SYSMDA_VERSION,
			true
		);

		wp_localize_script(
			self::HANDLE,
			'sysmdaMarkdownActionsL10n',
			array(
				'copied'  => __( 'Copied!', 'system-markdown-alternate' ),
				'copying' => __( 'Copying…', 'system-markdown-alternate' ),
				'failed'  => __( 'Copy failed', 'system-markdown-alternate' ),
			)
		);
	}

	/**
	 * Whether the queried post is known, before rendering, to contain the
	 * shortcode. Dynamic/template placements are intentionally left to the
	 * render callback, because no reliable pre-render scan can see them.
	 */
	private function request_renders_actions(): bool {
		if ( ! is_singular() ) {
			return false;
		}

		$post = get_queried_object();

		if ( ! $post instanceof \WP_Post || ! PostSupport::is_servable( $post ) ) {
			return false;
		}

		return has_shortcode( (string) $post->post_content, self::TAG );
	}

	/**
	 * Enqueues only handles registered for this request. WordPress de-duplicates
	 * repeated calls, so loops with many components still emit one CSS and JS.
	 */
	public static function enqueue_assets(): void {
		self::register_asset_handles();

		if ( wp_style_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_style( self::HANDLE );
		}

		if ( wp_script_is( self::HANDLE, 'registered' ) ) {
			wp_enqueue_script( self::HANDLE );
		}
	}

	/**
	 * Shortcode callback.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render_shortcode( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, self::TAG );

		$post = Shortcodes::resolve_post( (int) $atts['id'] );

		return $post instanceof \WP_Post ? self::render_for( $post ) : '';
	}

	/**
	 * Renders the component for a post, or nothing when it has no Markdown.
	 */
	public static function render_for( \WP_Post $post ): string {
		if ( ! PostSupport::is_servable( $post ) ) {
			return '';
		}

		$html = self::build_html(
			MetadataBuilder::markdown_url( $post ),
			MetadataBuilder::download_filename( $post ),
			wp_unique_id( 'sysmda-md-actions-menu-' )
		);

		if ( '' !== $html ) {
			self::enqueue_assets();
		}

		return $html;
	}

	/**
	 * Builds the stable, testable component markup from primitives only.
	 *
	 * The root ships hidden: this is a JavaScript control, so revealing it only
	 * after setup prevents a dead copy button or an unpositioned menu. View and
	 * download remain native links once the script has opened the disclosure.
	 */
	public static function build_html( string $url, string $filename, string $menu_id ): string {
		$url = esc_url( $url );

		if ( '' === $url || '' === $filename || '' === $menu_id ) {
			return '';
		}

		$copy_label     = __( 'Copy as Markdown', 'system-markdown-alternate' );
		$view_label     = __( 'View as Markdown', 'system-markdown-alternate' );
		$download_label = __( 'Download Markdown', 'system-markdown-alternate' );

		$html  = '<div class="sysmda-md-actions" data-sysmda-md-url="' . $url . '" hidden>';
		$html .= '<div class="sysmda-md-actions__group">';
		$html .= '<button type="button" class="sysmda-md-actions__copy" data-sysmda-action="copy" hidden>';
		$html .= self::visual( 'copy' );
		$html .= '<span class="sysmda-md-actions__label">' . esc_html( $copy_label ) . '</span>';
		$html .= '</button>';
		$html .= '<button type="button" class="sysmda-md-actions__toggle" aria-expanded="false" aria-controls="' . esc_attr( $menu_id ) . '" hidden>';
		$html .= '<span class="sysmda-md-actions__sr-only">' . esc_html__( 'More Markdown options', 'system-markdown-alternate' ) . '</span>';
		$html .= self::visual( 'chevron' );
		$html .= '</button>';
		$html .= '</div>';

		$html .= '<ul class="sysmda-md-actions__menu" id="' . esc_attr( $menu_id ) . '" aria-label="' . esc_attr__( 'Markdown actions', 'system-markdown-alternate' ) . '" hidden>';

		$html .= '<li class="sysmda-md-actions__row">';
		$html .= '<button type="button" class="sysmda-md-actions__item" data-sysmda-action="copy" hidden>';
		$html .= self::visual( 'copy', 'leading' );
		$html .= '<span class="sysmda-md-actions__label">' . esc_html( $copy_label ) . '</span>';
		$html .= '</button></li>';

		$html .= '<li class="sysmda-md-actions__row">';
		$html .= '<a class="sysmda-md-actions__item" href="' . $url . '" target="_blank" rel="noopener noreferrer">';
		$html .= self::visual( 'file', 'leading' );
		$html .= '<span class="sysmda-md-actions__label">' . esc_html( $view_label ) . '<span class="sysmda-md-actions__sr-only"> ' . esc_html__( '(opens in new tab)', 'system-markdown-alternate' ) . '</span></span>';
		$html .= self::visual( 'external', 'trailing' );
		$html .= '</a></li>';

		$html .= '<li class="sysmda-md-actions__row">';
		$html .= '<a class="sysmda-md-actions__item" href="' . $url . '" download="' . esc_attr( $filename ) . '">';
		$html .= self::visual( 'download', 'leading' );
		$html .= '<span class="sysmda-md-actions__label">' . esc_html( $download_label ) . '</span>';
		$html .= '</a></li>';

		$html .= '</ul>';
		$html .= '<span class="sysmda-md-actions__status" role="status" aria-live="polite"></span>';

		return $html . '</div>';
	}

	/**
	 * Small inline SVGs: no icon font, stylesheet, or external request.
	 */
	private static function visual( string $name, string $position = '' ): string {
		$paths = array(
			'copy'     => '<rect x="5.25" y="1.75" width="8" height="8" rx="1.25"></rect><path d="M10.75 10.25v2A1.75 1.75 0 0 1 9 14H3.75A1.75 1.75 0 0 1 2 12.25V7a1.75 1.75 0 0 1 1.75-1.75h2"></path>',
			'file'     => '<path d="M4 1.75h5.1L12.5 5.2v8.05A1.25 1.25 0 0 1 11.25 14h-7A1.25 1.25 0 0 1 3 12.75V3A1.25 1.25 0 0 1 4.25 1.75Z"></path><path d="M9 1.9V5.5h3.35"></path>',
			'download' => '<path d="M8 1.75v8.5"></path><path d="m4.75 7.25 3.25 3.25 3.25-3.25"></path><path d="M2.5 13.75h11"></path>',
			'external' => '<path d="M6.25 3H3.75A1.25 1.25 0 0 0 2.5 4.25v8A1.25 1.25 0 0 0 3.75 13.5h8A1.25 1.25 0 0 0 13 12.25v-2.5"></path><path d="M9 2.5h4.5V7"></path><path d="m7.25 8.75 6-6"></path>',
			'chevron'  => '<path d="m4.5 6 3.5 3.5L11.5 6"></path>',
		);

		if ( ! isset( $paths[ $name ] ) ) {
			return '';
		}

		$class = 'sysmda-md-actions__icon';

		if ( '' !== $position ) {
			$class .= ' sysmda-md-actions__icon--' . sanitize_html_class( $position );
		}

		return '<svg class="' . esc_attr( $class ) . '" viewBox="0 0 16 16" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . $paths[ $name ] . '</svg>';
	}
}
