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
	 * `sysmda_md_button` is the front-end button removed in 0.34.0. The tag is
	 * kept here on purpose: it is no longer registered, so any left behind in old
	 * post content would otherwise survive as literal text. `sysmda_md_actions`
	 * is the current opt-in human control and must never publish its own UI into
	 * the Markdown. Stripping happens after `apply_filters` because the saved
	 * "Excluded shortcodes" option *replaces* the defaults below rather than
	 * adding to them.
	 */
	const ALWAYS_EXCLUDED = array( 'sysmda_md_button', 'sysmda_md_actions' );

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
