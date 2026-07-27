<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Removes unwanted shortcodes from content before conversion.
 */
class ShortcodeCleaner {

	/**
	 * Shortcodes stripped whatever the filter says.
	 *
	 * `sysmda_md_button` renders a UI control — a dropdown of clipboard buttons
	 * and links — which has no meaning in a Markdown document. Leaving it to the
	 * default list below would not be enough: AdminSettings bridges a saved option
	 * that *replaces* those defaults, so anyone who had edited the "Excluded
	 * shortcodes" textarea would find the button converted into their `.md`.
	 * Same shape as MetadataBuilder::EXCLUDED_TAXONOMIES, applied after the filter.
	 */
	const ALWAYS_EXCLUDED = array( MarkdownButton::TAG );

	/**
	 * @param string $content Source content.
	 * @return string Content without excluded shortcodes.
	 */
	public function strip( string $content ): string {
		$tags = $this->excluded_shortcodes();

		if ( empty( $tags ) || false === strpos( $content, '[' ) ) {
			return $content;
		}

		$pattern = get_shortcode_regex( $tags );

		$result = preg_replace_callback(
			'/' . $pattern . '/s',
			static function ( $m ) {
				// Escaped shortcode using [[...]]: preserve it.
				if ( '[' === $m[1] && ']' === $m[6] ) {
					return $m[0];
				}
				return '';
			},
			$content
		);

		return null === $result ? $content : $result;
	}

	/**
	 * Filterable list of shortcodes to remove.
	 *
	 * @return string[]
	 */
	private function excluded_shortcodes(): array {
		$defaults = array(
			'contact-form-7',
			'gravityform',
			'wpforms',
			'mailerlite_form',
			'lwptoc', // LuckyWP Table of Contents: navigation, not content.
		);

		/** Filters shortcodes excluded from Markdown. */
		$tags = (array) apply_filters( 'sysmda_markdown_excluded_shortcodes', $defaults );

		return array_values( array_unique( array_merge( $tags, self::ALWAYS_EXCLUDED ) ) );
	}
}
