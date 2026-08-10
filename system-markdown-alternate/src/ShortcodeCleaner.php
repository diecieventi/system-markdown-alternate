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
	 * the Markdown. These are merged in *after* `apply_filters`, so that site
	 * code returning a narrowed list — the supported way to drop a built-in
	 * default since `0.40.0` — cannot drop these two with it.
	 */
	const ALWAYS_EXCLUDED = array( 'sysmda_md_button', 'sysmda_md_actions' );

	/**
	 * Built-in excluded shortcodes: the default value of the filter below.
	 *
	 * A constant rather than a local array because the settings page shows this
	 * list to the user and used to keep its own copy of it, which is a drift
	 * waiting to happen — adding a tag here and forgetting there makes the panel
	 * describe defaults the plugin does not have.
	 *
	 * The bar for adding one is that the tag can only ever produce interface,
	 * never text the author wrote: forms and navigation qualify, anything that
	 * can carry content does not. A wrong entry here deletes real content on
	 * every site that updates.
	 */
	const DEFAULT_EXCLUDED = array(
		// Forms.
		'contact-form-7',
		'gravityform',
		'wpforms',
		'fluentform',
		'ninja_form', // Ninja Forms (singular tag, unquoted numeric id).
		// Formidable Forms. Its other tags (frm-show-entry, formresults,
		// frm-stats, frm-graph, …) display submitted entry data — real
		// content — and are deliberately NOT excluded.
		'formidable',
		// Newsletter subscription forms. This is the category that produced the
		// symptom the rule above is written against: a label, a submit button
		// and a GDPR consent paragraph landing in the middle of an article.
		'mailerlite_form',
		'mc4wp_form',
		'mailpoet_form',
		'newsletter_form', // The Newsletter Plugin. NOT the bare `newsletter`
							// tag, which is its public-page shortcode and far
							// too generic a word to claim by default.
		'sibwp_form',      // Brevo (formerly Sendinblue).
		// Tables of contents: navigation, not content.
		'lwptoc',               // LuckyWP.
		'ez-toc',               // Easy Table of Contents…
		'ez-toc-widget-sticky', // …and its sticky widget.
		'toc',                  // Registered by Easy TOC as well.
	);

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

		// Code regions are masked for the same reason expansion masks them: a
		// tag inside a code sample is being *shown*, not used, so removing it
		// would silently gut an article that documents the shortcode. See
		// CodeRegions.
		return CodeRegions::protect(
			$content,
			static function ( string $chunk ) use ( $pattern ): string {
				$result = preg_replace_callback(
					'/' . $pattern . '/s',
					static function ( $m ) {
						// Escaped shortcode using [[...]]: preserve it.
						if ( '[' === $m[1] && ']' === $m[6] ) {
							return $m[0];
						}
						return '';
					},
					$chunk
				);

				return null === $result ? $chunk : $result;
			}
		);
	}

	/**
	 * Filterable list of shortcodes to remove.
	 *
	 * @return string[]
	 */
	private function excluded_shortcodes(): array {
		/** Filters shortcodes excluded from Markdown. */
		$tags = (array) apply_filters( 'sysmda_markdown_excluded_shortcodes', self::DEFAULT_EXCLUDED );

		return array_values( array_unique( array_merge( $tags, self::ALWAYS_EXCLUDED ) ) );
	}
}
