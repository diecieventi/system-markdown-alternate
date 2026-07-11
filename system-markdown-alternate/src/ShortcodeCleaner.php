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
		return (array) apply_filters( 'sysmda_markdown_excluded_shortcodes', $defaults );
	}
}
