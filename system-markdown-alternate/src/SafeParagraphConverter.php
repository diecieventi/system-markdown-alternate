<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

use League\HTMLToMarkdown\Converter\ConverterInterface;
use League\HTMLToMarkdown\Converter\ParagraphConverter;
use League\HTMLToMarkdown\ElementInterface;

defined( 'ABSPATH' ) || exit;

/**
 * Wraps the library's paragraph converter to escape a backtick fence written as
 * ordinary prose.
 *
 * The library escapes a line-initial `~~~` and `---` but not ` ``` `, so a
 * paragraph whose text is three backticks — an author writing about Markdown,
 * or pasted terminal output — was emitted verbatim and opened a code fence that
 * swallowed every following block to the end of the document. The delimiter
 * sizing in CodeFence protects code *inside* a code block; this protects the
 * document from a delimiter that was never code at all.
 *
 * Composition rather than inheritance: only the output is post-processed, so
 * the library's own escaping rules keep applying unchanged.
 */
class SafeParagraphConverter implements ConverterInterface {

	/**
	 * A whole line consisting of a backtick run plus an info string.
	 *
	 * The info string may not contain a backtick (CommonMark §4.5), and that
	 * exclusion is what keeps an inline code span off this pattern: a span is
	 * delimiter-content-delimiter, so its closing run always puts a backtick
	 * later on the line, and the line stops matching.
	 */
	const FENCE_LINE = '/^([ \t]*)(`{3,})([^`\n]*)$/m';

	/** @var ParagraphConverter */
	private $inner;

	public function __construct() {
		$this->inner = new ParagraphConverter();
	}

	public function convert( ElementInterface $element ): string {
		$markdown = $this->inner->convert( $element );

		return (string) preg_replace( self::FENCE_LINE, '$1\\\\$2$3', $markdown );
	}

	/**
	 * @return string[]
	 */
	public function getSupportedTags(): array {
		return array( 'p' );
	}
}
