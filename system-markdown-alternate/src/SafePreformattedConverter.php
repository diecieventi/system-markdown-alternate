<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces the library's `<pre>` converter so that a `<pre>` carrying no
 * `<code>` child gets a content-sized fence too.
 *
 * In the plugin's own pipeline this branch is normally unreachable:
 * ContentRenderer::normalize_code_blocks() rewrites every `<pre>` to hold
 * exactly one `<code>`, so SafeCodeConverter has already produced the fence and
 * this converter only passes it through. It is registered anyway because
 * process_dom() bails out and returns the unprocessed HTML on a parse failure,
 * and because "the converter never emits a fence the content can break" should
 * be one invariant rather than one that holds on the usual path.
 */
class SafePreformattedConverter implements ConverterInterface {

	public function convert( ElementInterface $element ): string {
		$content = html_entity_decode( $element->getChildrenAsString() );
		$content = (string) preg_replace( '/<pre\b[^>]*>/', '', $content );
		$content = str_replace( '</pre>', '', $content );

		// A nested <code> has already been converted, delimiters included, so
		// there is nothing left to wrap.
		$trimmed = trim( $content );
		if ( '' !== $trimmed && 0 === strpos( $trimmed, '`' ) && '`' === substr( $trimmed, -1 ) ) {
			return $content . "\n\n";
		}

		if ( '' === $content ) {
			return "```\n```\n\n";
		}

		$content = (string) preg_replace( '/\r\n|\r|\n/', "\n", $content );

		if ( "\n" !== substr( $content, -1 ) ) {
			$content .= "\n";
		}

		$fence = CodeFence::block_delimiter( $content );

		return $fence . "\n" . $content . $fence . "\n\n";
	}

	/**
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'pre' );
	}
}
