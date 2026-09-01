<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Escapes an image's alt/title text and wraps a destination that would
 * otherwise break out of Markdown's image syntax.
 *
 * The library interpolates `alt`, `src` and `title` into `![alt](src "title")`
 * with no escaping at all, so `alt="Note] end"` closed the label early and
 * `title='He said "hi"'` broke the quoted title out of its own delimiters —
 * the third occurrence of the defect family `0.46.1` and `0.47.1` fixed for an
 * ACF subtitle and a custom-field value: a value placed into the document is
 * text, and text is escaped by the same converter the body uses.
 *
 * `alt` is escaped with MarkdownConverter::escape_inline(), the plugin's
 * existing answer to "this value is text" — it hands the value to the library
 * as a text node, so an image alt escapes precisely what a paragraph escapes,
 * and no second copy of the rule exists to drift. `title` is a quoted string,
 * not inline text, so it goes through MarkdownConverter::escape_link_title()
 * instead. `src` is wrapped through MarkdownConverter::wrap_destination(), the
 * same helper SafeLinkConverter uses for `href`.
 */
class SafeImageConverter implements ConverterInterface {

	/** @var MarkdownConverter */
	private $markdown;

	public function __construct( MarkdownConverter $markdown ) {
		$this->markdown = $markdown;
	}

	public function convert( ElementInterface $element ): string {
		$src   = MarkdownConverter::wrap_destination( $element->getAttribute( 'src' ) );
		$alt   = $this->markdown->escape_inline( $element->getAttribute( 'alt' ) );
		$title = $element->getAttribute( 'title' );

		if ( '' !== $title ) {
			// No newlines added: <img> should be in a block-level element (matches
			// the library's own ImageConverter).
			return '![' . $alt . '](' . $src . ' "' . MarkdownConverter::escape_link_title( $title ) . '")';
		}

		return '![' . $alt . '](' . $src . ')';
	}

	/**
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'img' );
	}
}
