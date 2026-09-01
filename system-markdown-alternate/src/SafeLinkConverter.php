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
 * The mailto-autolink branch is left exactly as the library computes it:
 * `FILTER_VALIDATE_EMAIL` rejects any embedded whitespace or control
 * character in the whole string, so an href reaching it has already been
 * proven not to carry anything wrap_destination() exists to guard against.
 *
 * The plain autolink branch needed one hardening of its own gate (caught by
 * Codex on PR #133, before it shipped): the library's own `isValidAutolink()`
 * regex has no end anchor, so `preg_match()` only has to find that pattern as
 * a PREFIX of `$href`, not consume the whole string. For an href/text pair
 * like `https://example.com/my file` (a space, with text equal to href), the
 * unanchored regex still reports a match on the leading
 * `https://example.com/my` and the branch fires, emitting the literal href
 * inside `<…>` — a CommonMark *autolink*, whose grammar forbids any ASCII
 * whitespace at all (unlike the *bracketed link destination* form
 * `wrap_destination()` produces, which explicitly allows it). The result
 * renders as literal text in a strict CommonMark parser, silently losing the
 * link. `is_valid_autolink()` now requires the match to consume `$href` in
 * full, so a space (or any other character the character class already
 * excludes) routes the pair to the `else` branch instead, which wraps it
 * correctly.
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
	 * Whether $href is safe to emit as a bare CommonMark autolink (`<href>`).
	 *
	 * Same character class as the library's own `isValidAutolink()`, but the
	 * match is required to consume the ENTIRE string, not merely a prefix of
	 * it: `preg_match()` reports success the moment it finds the pattern
	 * anywhere permitted by the regex, so an unanchored version of this check
	 * would accept `https://example.com/my file` on the strength of its
	 * leading `https://example.com/my` alone. The excluded character class
	 * already forbids whitespace and `<`/`>`; comparing the match against the
	 * whole input is what makes that exclusion actually apply to the string
	 * as a whole rather than to a prefix of it.
	 */
	private function is_valid_autolink( string $href ): bool {
		$use_autolinks = $this->config->getOption( 'use_autolinks' );

		if ( ! $use_autolinks ) {
			return false;
		}

		return 1 === preg_match( '/^[A-Za-z][A-Za-z0-9.+-]{1,31}:[^<>\x00-\x20]*/i', $href, $matches )
			&& $matches[0] === $href;
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
