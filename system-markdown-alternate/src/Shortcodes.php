<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * The plugin's shortcodes, both resolving their post the same way.
 *
 *   [sysmda_md_url]       → the URL of the post's Markdown version, as bare text
 *   [sysmda_md_download]  → an anchor that downloads it instead of opening it
 *
 * Each keeps a single return type: [sysmda_md_url] is always a URL, so it stays
 * safe inside an `href`, and [sysmda_md_download] is always markup.
 *
 * URLs are calculated from the permalink at runtime (nothing stored in the
 * database). Both return an empty string when the post is not servable —
 * unsupported type, unpublished, password-protected, or a non-standard post
 * format — so neither can print a link to a 404.
 */
class Shortcodes {

	public function register(): void {
		add_shortcode( 'sysmda_md_url', array( $this, 'render_url' ) );
		add_shortcode( 'sysmda_md_download', array( $this, 'render_download' ) );
	}

	/**
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render_url( $atts ): string {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'sysmda_md_url' );

		$post = self::resolve_post( (int) $atts['id'] );

		if ( ! $post instanceof \WP_Post || ! PostSupport::is_servable( $post ) ) {
			return '';
		}

		return esc_url( MetadataBuilder::markdown_url( $post ) );
	}

	/**
	 * Renders a link that downloads the Markdown instead of opening it.
	 *
	 *   [sysmda_md_download]                 → link with the default label
	 *   [sysmda_md_download text="Save it"]  → custom label
	 *   [sysmda_md_download id="123"]        → a specific post
	 *
	 * Two belts, either of which works alone: the HTML `download` attribute
	 * covers a click in a browser, and the `?download=1` URL makes the server
	 * answer with `Content-Disposition: attachment`, which also covers clients
	 * that never parse the markup. Same empty return as [sysmda_md_url] when the
	 * post is not servable, so a link to a 404 is never printed.
	 *
	 * A separate shortcode rather than attributes on [sysmda_md_url]: that one
	 * always returns a bare URL, and making its return type depend on an
	 * attribute would break the common `<a href="[sysmda_md_url]">` usage the day
	 * someone passed a label.
	 *
	 * The markup is a bare anchor carrying ONE class and nothing else — no
	 * inline styles, no stylesheet, no script. `.sysmda-md-download` exists purely
	 * so a theme can style it; the plugin never ships CSS for it. Keep it that
	 * way: the front-end button removed in 0.34.0 started out this small.
	 *
	 * @param array<string,mixed>|string $atts Shortcode attributes.
	 */
	public function render_download( $atts ): string {
		$atts = shortcode_atts(
			array(
				'id'   => 0,
				'text' => '',
			),
			$atts,
			'sysmda_md_download'
		);

		$post = self::resolve_post( (int) $atts['id'] );

		if ( ! $post instanceof \WP_Post || ! PostSupport::is_servable( $post ) ) {
			return '';
		}

		$text = trim( (string) $atts['text'] );

		if ( '' === $text ) {
			$text = __( 'Download MD', 'system-markdown-alternate' );
		}

		return sprintf(
			'<a class="sysmda-md-download" href="%s" download="%s">%s</a>',
			esc_url( MetadataBuilder::markdown_download_url( $post ) ),
			esc_attr( MetadataBuilder::download_filename( $post ) ),
			esc_html( $text )
		);
	}

	/**
	 * Resolves the post from an explicit ID, the current loop, or the queried object.
	 *
	 * The loop comes before the queried object: inside a secondary loop (related
	 * posts, a query block, a shortcode in a widget on a single post) the queried
	 * object is still the *main* post, so preferring it made every item render the
	 * same URL. get_post() follows the global `$post`, which is what the
	 * surrounding loop sets, and falls back to the main post outside any loop.
	 *
	 * Static because it depends on no instance state. It was made public in
	 * 0.31.0 so the Markdown button could resolve its post identically; that
	 * button is gone as of 0.34.0, but the signature is kept — the
	 * loop-before-queried-object reasoning above is subtle enough to be worth
	 * sharing rather than copying, and narrowing it again would buy nothing.
	 */
	public static function resolve_post( int $id ): ?\WP_Post {
		if ( $id > 0 ) {
			$post = get_post( $id );
			return $post instanceof \WP_Post ? $post : null;
		}

		$post = get_post();
		if ( $post instanceof \WP_Post ) {
			return $post;
		}

		$queried = get_queried_object();
		return $queried instanceof \WP_Post ? $queried : null;
	}
}
