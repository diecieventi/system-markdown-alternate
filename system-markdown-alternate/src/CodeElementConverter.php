<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;
use League\HTMLToMarkdown\PreConverterInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Converts inline code and preformatted blocks with content-sized delimiters.
 */
class CodeElementConverter implements ConverterInterface, PreConverterInterface {

	/** @var array<string,bool> Pre elements that originally had one code child. */
	private $single_code_pre = array();

	/**
	 * Records pre structure before the library replaces converted children.
	 */
	public function preConvert( ElementInterface $element ): void {
		if ( 'pre' !== strtolower( $element->getTagName() ) ) {
			return;
		}

		$children = $element->getChildren();

		if ( 1 === count( $children ) && 'code' === strtolower( $children[0]->getTagName() ) ) {
			$this->single_code_pre[ spl_object_hash( $element ) ] = true;
		}
	}

	/**
	 * Converts a supported element using its structural role.
	 */
	public function convert( ElementInterface $element ): string {
		$tag = strtolower( $element->getTagName() );

		if ( 'code' === $tag ) {
			return $this->convert_code( $element );
		}

		if ( 'pre' === $tag ) {
			return $this->convert_pre( $element );
		}

		return $element->getValue();
	}

	/**
	 * Tags owned by this converter.
	 *
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'code', 'pre' );
	}

	/**
	 * Converts code according to its immediate parent, never its contents.
	 */
	private function convert_code( ElementInterface $element ): string {
		$value  = $this->normalize_line_endings( $element->getValue() );
		$parent = $element->getParent();

		if ( null !== $parent && 'pre' === strtolower( $parent->getTagName() ) ) {
			return $this->render_block( $value, $this->language( $element ) );
		}

		return $this->render_inline( $value );
	}

	/**
	 * Passes through one safe child block or fences the complete pre value.
	 */
	private function convert_pre( ElementInterface $element ): string {
		$value           = $this->normalize_line_endings( $element->getValue() );
		$key             = spl_object_hash( $element );
		$from_code_child = isset( $this->single_code_pre[ $key ] );

		unset( $this->single_code_pre[ $key ] );

		if ( $from_code_child && CodeFence::is_safely_fenced( $value ) ) {
			$block = $value;
		} else {
			$block = $this->render_block( $value, $this->language( $element ) );
		}

		// The pre element owns block separation. Keeping it out of the child code
		// result lets an unsafe pre with several children wrap their exact combined
		// Markdown instead of manufacturing blank lines between them.
		return "\n" . $block . "\n\n";
	}

	/**
	 * Renders a CommonMark code span while preserving its decoded text value.
	 */
	private function render_inline( string $value ): string {
		// CommonMark §6.1 maps each line ending in a code span to one space.
		$value = str_replace( "\n", ' ', $this->normalize_line_endings( $value ) );

		// No CommonMark delimiter pair represents an empty code span. Emitting no
		// bytes preserves the empty element instead of introducing invalid syntax.
		if ( '' === $value ) {
			return '';
		}

		$delimiter = CodeFence::inline_delimiter( $value );
		$padding   = CodeFence::needs_padding( $value ) ? ' ' : '';

		return $delimiter . $padding . $value . $padding . $delimiter;
	}

	/**
	 * Renders one fenced block without inventing an extra trailing blank line.
	 */
	private function render_block( string $value, string $language = '' ): string {
		$value     = $this->normalize_line_endings( $value );
		$delimiter = CodeFence::block_delimiter( $value );
		$info      = CodeFence::info_string( $language );
		$block     = $delimiter . $info . "\n";

		if ( '' !== $value ) {
			$block .= $value;

			if ( "\n" !== substr( $value, -1 ) ) {
				$block .= "\n";
			}
		}

		return $block . $delimiter;
	}

	/**
	 * Resolves the first valid language using the documented fallback order.
	 */
	private function language( ElementInterface $element ): string {
		$language = $this->language_from_element( $element );

		if ( '' !== $language ) {
			return $language;
		}

		if ( 'code' !== strtolower( $element->getTagName() ) ) {
			return '';
		}

		$parent = $element->getParent();

		if ( null === $parent || 'pre' !== strtolower( $parent->getTagName() ) ) {
			return '';
		}

		return $this->language_from_element( $parent );
	}

	/**
	 * Resolves class tokens before data-language and data-lang.
	 */
	private function language_from_element( ElementInterface $element ): string {
		$language = $this->language_from_classes( $element->getAttribute( 'class' ) );

		if ( '' !== $language ) {
			return $language;
		}

		foreach ( array( 'data-language', 'data-lang' ) as $attribute ) {
			$language = CodeFence::info_string( $element->getAttribute( $attribute ) );

			if ( '' !== $language ) {
				return $language;
			}
		}

		return '';
	}

	/**
	 * Returns the first valid, anchored language-* class token.
	 */
	private function language_from_classes( string $classes ): string {
		$tokens = preg_split( '/\s+/', trim( $classes ) );

		if ( false === $tokens ) {
			return '';
		}

		foreach ( $tokens as $token ) {
			if ( 1 !== preg_match( '/^language-(.+)$/', $token, $match ) ) {
				continue;
			}

			$language = CodeFence::info_string( $match[1] );

			if ( '' !== $language ) {
				return $language;
			}
		}

		return '';
	}

	/**
	 * Normalizes every HTML/CommonMark line-ending form to LF.
	 */
	private function normalize_line_endings( string $value ): string {
		return str_replace( array( "\r\n", "\r" ), "\n", $value );
	}
}
