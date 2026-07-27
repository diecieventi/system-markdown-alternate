<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Front-end "Markdown" button: a small dropdown that tells a human reader the
 * `.md` representation exists and hands it over in one click.
 *
 * Until now discovery was machine-only (the `rel="alternate"` link, `/llms.txt`,
 * content negotiation). This is the human-facing counterpart:
 *
 *   [sysmda_md_button]           → button for the current post
 *   [sysmda_md_button id="123"]  → button for a specific post
 *
 * Placement is entirely the author's: there is no automatic insertion. It renders
 * where the shortcode is, and nowhere else.
 *
 * Returns an empty string when the post is not servable, so the button never
 * advertises a URL that would 404 — same rule as [sysmda_md_url].
 *
 * Accessibility: this is a **disclosure**, not a `role="menu"`. Two of the four
 * entries are ordinary links whose value is precisely their native behaviour
 * (open in a new tab, copy link address, middle-click); `role="menu"` would
 * capture the arrow keys and strip that away.
 *
 * Progressive enhancement: the toggle and the two clipboard buttons are rendered
 * `hidden` and unhidden by the script, which also marks the root element. Without
 * JavaScript the reader sees a plain list of the two links that do work, and
 * never a dead control.
 */
class MarkdownButton {

	/** Shortcode tag. Also hard-excluded from the Markdown pipeline (ShortcodeCleaner). */
	const TAG = 'sysmda_md_button';

	/** Handle shared by the stylesheet and the script. */
	const HANDLE = 'sysmda-md-button';

	/** Menu entries, in default render order. */
	const ITEMS = array( 'copy-link', 'view', 'download', 'copy-content' );

	public function register(): void {
		add_shortcode( self::TAG, array( $this, 'render_shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'register_assets' ) );
	}

	/**
	 * Hook: wp_enqueue_scripts. Registers the assets and enqueues them when this
	 * request is going to render a button.
	 *
	 * Registering always, enqueuing conditionally, is what lets render_for() turn
	 * the assets on later for a button that comes from a template, a widget or a
	 * GenerateBlocks element — places has_shortcode() on the post content cannot
	 * see. Styles enqueued that late still print, in the footer.
	 */
	public function register_assets(): void {
		/**
		 * Filters whether the button stylesheet is enqueued.
		 *
		 * Return false to ship the markup unstyled and take it over from the
		 * theme; the script does not depend on the stylesheet.
		 */
		if ( apply_filters( 'sysmda_md_button_enqueue_style', true ) ) {
			wp_register_style( self::HANDLE, SYSMDA_PLUGIN_URL . 'assets/md-button.css', array(), SYSMDA_VERSION );
		}

		wp_register_script( self::HANDLE, SYSMDA_PLUGIN_URL . 'assets/md-button.js', array(), SYSMDA_VERSION, true );

		wp_localize_script(
			self::HANDLE,
			'sysmdaMdButtonL10n',
			array(
				'copied'  => __( 'Copied!', 'system-markdown-alternate' ),
				'failed'  => __( 'Copy failed', 'system-markdown-alternate' ),
				'copying' => __( 'Copying…', 'system-markdown-alternate' ),
			)
		);

		if ( $this->request_renders_button() ) {
			self::enqueue_assets();
		}
	}

	/**
	 * Whether this request is known, before rendering, to output a button.
	 *
	 * Only the case visible from the queried post: the shortcode sitting in the
	 * post content. Anything else — a template, a widget, a GenerateBlocks
	 * element — is caught by the late enqueue in render_for().
	 */
	private function request_renders_button(): bool {
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
	 * Enqueues the registered handles.
	 *
	 * Guarded on registration: outside the front end (and when the stylesheet is
	 * filtered off) nothing is registered, and enqueuing an unknown handle would
	 * only produce a notice.
	 */
	public static function enqueue_assets(): void {
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
	 * Markup of the button for a post, or '' when the post has no `.md`.
	 */
	public static function render_for( \WP_Post $post ): string {
		if ( ! PostSupport::is_servable( $post ) ) {
			return '';
		}

		/** Filters the label of the button that opens the menu. */
		$label = apply_filters( 'sysmda_md_button_label', __( 'Markdown', 'system-markdown-alternate' ), $post );
		$label = is_string( $label ) ? $label : '';

		/**
		 * Filters which entries the menu offers, in render order.
		 *
		 * Valid keys are those in MarkdownButton::ITEMS; anything else is dropped.
		 * An empty list suppresses the button entirely.
		 */
		$items = apply_filters( 'sysmda_md_button_items', self::ITEMS, $post );

		$html = self::build_html(
			MetadataBuilder::markdown_url( $post ),
			self::download_filename( $post ),
			$label,
			(array) $items,
			wp_unique_id( 'sysmda-md-menu-' )
		);

		if ( '' !== $html ) {
			self::enqueue_assets();
		}

		/** Filters the final button markup (empty string when nothing is rendered). */
		return (string) apply_filters( 'sysmda_md_button_html', $html, $post );
	}

	/**
	 * Builds the markup.
	 *
	 * Deliberately takes primitives only and touches no post, option or request
	 * state, so the golden markup assertions run in the WP-less test harness.
	 *
	 * @param string   $url      Markdown URL of the post.
	 * @param string   $filename Name proposed by the download entry.
	 * @param string   $label    Label of the toggle.
	 * @param string[] $items    Requested entries (sanitized here).
	 * @param string   $menu_id  Unique DOM id tying the toggle to its menu. Taken
	 *                           as an argument rather than derived from the post:
	 *                           the shortcode can appear twice on one page, or once
	 *                           inside a query loop, and two menus sharing an id
	 *                           would make aria-controls ambiguous.
	 */
	public static function build_html( string $url, string $filename, string $label, array $items, string $menu_id ): string {
		$items = self::sanitize_items( $items );

		// Escaped once, up front, and checked afterwards: esc_url() empties
		// anything that is not a permitted scheme, and a URL that survives the
		// raw check only to be emptied here would render a button whose every
		// entry points at nothing. No button beats a broken one.
		$url = esc_url( $url );

		if ( '' === $url || '' === $label || '' === $menu_id || empty( $items ) ) {
			return '';
		}

		$labels = self::item_labels();

		$html = '<div class="sysmda-md-button" data-sysmda-md-url="' . $url . '">';

		$html .= '<button type="button" class="sysmda-md-button__toggle" aria-expanded="false" aria-controls="'
			. esc_attr( $menu_id ) . '" hidden>'
			. esc_html( $label )
			. '<span class="sysmda-md-button__chevron" aria-hidden="true"></span>'
			. '</button>';

		$html .= '<ul class="sysmda-md-button__menu" id="' . esc_attr( $menu_id ) . '">';

		foreach ( $items as $item ) {
			$text = isset( $labels[ $item ] ) ? $labels[ $item ] : $item;

			$html .= '<li class="sysmda-md-button__row">' . self::build_item( $item, $text, $url, $filename ) . '</li>';
		}

		$html .= '</ul>';

		// Announces the outcome of a copy to screen readers without moving focus.
		$html .= '<span class="sysmda-md-button__status" role="status" aria-live="polite"></span>';

		return $html . '</div>';
	}

	/**
	 * One menu entry: a real link where the browser can do the work, a button
	 * where the clipboard is involved.
	 *
	 * `$url` arrives already through esc_url() — escaping it twice would turn
	 * every `&` in a `?format=markdown` fallback URL into `&amp;amp;`.
	 */
	private static function build_item( string $item, string $text, string $url, string $filename ): string {
		if ( 'view' === $item ) {
			return '<a class="sysmda-md-button__item" href="' . $url . '" target="_blank" rel="noopener">'
				. esc_html( $text ) . '</a>';
		}

		// No target here: browsers ignore `download` when it is combined with
		// target="_blank". The attribute works at all because the .md URL is
		// same-origin, which is what keeps this free of any server-side change.
		if ( 'download' === $item ) {
			return '<a class="sysmda-md-button__item" href="' . $url . '" download="' . esc_attr( $filename ) . '">'
				. esc_html( $text ) . '</a>';
		}

		return '<button type="button" class="sysmda-md-button__item" data-sysmda-action="' . esc_attr( $item ) . '" hidden>'
			. esc_html( $text ) . '</button>';
	}

	/**
	 * Translated label of each entry.
	 *
	 * @return array<string,string>
	 */
	public static function item_labels(): array {
		return array(
			'copy-link'    => __( 'Copy Markdown link', 'system-markdown-alternate' ),
			'view'         => __( 'View as Markdown', 'system-markdown-alternate' ),
			'download'     => __( 'Download Markdown', 'system-markdown-alternate' ),
			'copy-content' => __( 'Copy Markdown content', 'system-markdown-alternate' ),
		);
	}

	/**
	 * Name the download entry proposes, derived from the slug.
	 */
	public static function download_filename( \WP_Post $post ): string {
		$slug = sanitize_file_name( (string) $post->post_name );

		if ( '' === $slug ) {
			$slug = 'post-' . (int) $post->ID;
		}

		return $slug . '.md';
	}

	/**
	 * Keeps only known entries, in the order given, without repeats.
	 *
	 * @param mixed $value
	 * @return string[]
	 */
	public static function sanitize_items( $value ): array {
		$out = array();

		foreach ( (array) $value as $item ) {
			if ( is_string( $item ) && in_array( $item, self::ITEMS, true ) && ! in_array( $item, $out, true ) ) {
				$out[] = $item;
			}
		}

		return $out;
	}
}
