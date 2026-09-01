<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

use League\HTMLToMarkdown\Configuration;
use League\HTMLToMarkdown\ConfigurationAwareInterface;
use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Escapes a link's title and wraps its destination, replicating the
 * library's own LinkConverter branch by branch so every case the fix does
 * not touch stays byte-identical.
 *
 * Link TEXT is not re-escaped here: it reaches this converter already
 * converted by the child text-node converters, which apply the same escaping
 * every other text node in the document gets. Only the two attributes the
 * library interpolates raw need the fix — `title` (a quoted string) through
 * MarkdownConverter::escape_link_title(), and `href` (a bare destination)
 * through MarkdownConverter::wrap_destination() — the same defect family
 * `0.46.1` and `0.47.1` already fixed once each for a hand-placed value.
 *
 * The autolink and mailto-autolink branches are deliberately left exactly as
 * the library computes them: an href reaching either one has, by the
 * pattern/equality tests that select it, already been proven not to carry the
 * characters wrap_destination() exists to guard against.
 */
class SafeLinkConverter implements ConverterInterface, ConfigurationAwareInterface {

	/** @var Configuration */
	private $config;

	public function setConfig( Configuration $config ): void {
		$this->config = $config;
	}

	public function convert( ElementInterface $element ): string {
		$href  = $element->getAttribute( 'href' );
		$title = $element->getAttribute( 'title' );
		$text  = trim( $element->getValue(), "\t\n\r\0\x0B" );

		if ( '' !== $title ) {
			$markdown = '[' . $text . '](' . MarkdownConverter::wrap_destination( $href ) . ' "' . MarkdownConverter::escape_link_title( $title ) . '")';
		} elseif ( $href === $text && $this->is_valid_autolink( $href ) ) {
			$markdown = '<' . $href . '>';
		} elseif ( 'mailto:' . $text === $href && $this->is_valid_email( $text ) ) {
			$markdown = '<' . $text . '>';
		} else {
			$markdown = '[' . $text . '](' . MarkdownConverter::wrap_destination( $href ) . ')';
		}

		if ( ! $href ) {
			$markdown = $this->should_strip()
				? $text
				: html_entity_decode( $element->getChildrenAsString() );
		}

		return $markdown;
	}

	/**
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'a' );
	}

	/**
	 * Mirrors the library's LinkConverter::isValidAutolink() exactly.
	 */
	private function is_valid_autolink( string $href ): bool {
		$use_autolinks = $this->config->getOption( 'use_autolinks' );

		return $use_autolinks && ( 1 === preg_match( '/^[A-Za-z][A-Za-z0-9.+-]{1,31}:[^<>\x00-\x20]*/i', $href ) );
	}

	/**
	 * Mirrors the library's LinkConverter::isValidEmail() exactly.
	 */
	private function is_valid_email( string $email ): bool {
		return false !== filter_var( $email, FILTER_VALIDATE_EMAIL );
	}

	/**
	 * Mirrors the library's LinkConverter::shouldStrip() exactly.
	 */
	private function should_strip(): bool {
		return (bool) ( $this->config->getOption( 'strip_placeholder_links' ) ?? false );
	}
}
