<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the library's `<code>` converter with one that sizes its delimiters
 * to the content (see CodeFence for why).
 *
 * Registered over the default in MarkdownConverter. Everything other than the
 * delimiter choice — language detection, entity decoding, the block/span
 * decision — mirrors the library's own converter, so this changes exactly one
 * behaviour and inherits no drift.
 */
class SafeCodeConverter implements ConverterInterface {

	public function convert( ElementInterface $element ): string {
		$code = html_entity_decode( $element->getChildrenAsString() );

		// The children string still carries the tags themselves.
		$code = (string) preg_replace( '/<code\b[^>]*>/', '', $code );
		$code = str_replace( '</code>', '', $code );

		if ( $this->should_be_block( $element, $code ) ) {
			$fence = CodeFence::block_delimiter( $code );

			return $fence . CodeFence::info_string( $this->language( $element ) ) . "\n" . $code . "\n" . $fence;
		}

		$code      = (string) preg_replace( '/\r\n|\r|\n/', '', $code );
		$delimiter = CodeFence::inline_delimiter( $code );
		$padding   = CodeFence::needs_padding( $code ) ? ' ' : '';

		return $delimiter . $padding . $code . $padding . $delimiter;
	}

	/**
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'code' );
	}

	/**
	 * Language taken from a `language-*` class, as the library does.
	 */
	private function language( ElementInterface $element ): string {
		$classes = $element->getAttribute( 'class' );

		if ( ! $classes ) {
			return '';
		}

		foreach ( explode( ' ', $classes ) as $class ) {
			if ( false !== strpos( $class, 'language-' ) ) {
				return str_replace( 'language-', '', $class );
			}
		}

		return '';
	}

	/**
	 * A `<code>` inside a `<pre>` is a block; the library's second test covers
	 * spans that already contain a delimiter pair.
	 */
	private function should_be_block( ElementInterface $element, string $code ): bool {
		$parent = $element->getParent();

		if ( null !== $parent && 'pre' === $parent->getTagName() ) {
			return true;
		}

		return 1 === preg_match( '/[^\s]` `/', $code );
	}
}
