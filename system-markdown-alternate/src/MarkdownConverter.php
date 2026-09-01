<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

use League\HTMLToMarkdown\Converter\TableConverter;
use League\HTMLToMarkdown\HtmlConverter;

defined( 'ABSPATH' ) || exit;

/**
 * Converts clean HTML to Markdown using league/html-to-markdown.
 */
class MarkdownConverter {

	/**
	 * @param string $html HTML ready for conversion.
	 * @return string Body Markdown (without front matter).
	 */
	public function convert( string $html ): string {
		$html = trim( $html );

		if ( '' === $html ) {
			return '';
		}

		try {
			$markdown = $this->converter()->convert( $html );
		} catch ( \Throwable $e ) {
			// Robust fallback: extract plain text instead of breaking the response.
			$markdown = wp_strip_all_tags( $html );
		}

		return $this->normalize_whitespace( $markdown );
	}

	/**
	 * Builds the configured converter.
	 *
	 * `TableConverter` ships with the library but is NOT part of its default
	 * environment: without it `strip_tags` removed every table tag and glued the
	 * cells together ("NamePriceCoffee2"), which is worse than useless for a
	 * machine-readable representation. Registered explicitly, it produces GFM
	 * pipe tables and escapes `|` inside cells.
	 *
	 * The code-element and paragraph converters replace library defaults rather
	 * than adding to them (`Environment::addConverter()` keys by tag, so the last
	 * registration for a tag wins). Both close the same class of defect: a
	 * Markdown delimiter must be selected after inspecting what it wraps, or the
	 * content can escape its own construct and corrupt the rest of the document.
	 * See CodeFence.
	 */
	private function converter(): HtmlConverter {
		$converter = new HtmlConverter(
			array(
				'header_style'    => 'atx',   // # Heading
				'strip_tags'      => true,
				'remove_nodes'    => 'script style iframe',
				'hard_break'      => false,
				'list_item_style' => '-',
			)
		);

		$environment = $converter->getEnvironment();

		$environment->addConverter( new TableConverter() );
		$environment->addConverter( new CodeElementConverter() );
		$environment->addConverter( new SafeParagraphConverter() );
		$environment->addConverter( new SafeImageConverter( $this ) );
		$environment->addConverter( new SafeLinkConverter() );

		return $converter;
	}

	/**
	 * Turns a plain-text value into inline Markdown that renders as that exact
	 * text.
	 *
	 * For values that are text and were never markup: an ACF text field, a label,
	 * anything a caller is about to place inside Markdown delimiters of its own.
	 * The invariant is that the value stays text — `A *literal* marker` must read
	 * back the way it was typed, rather than having its asterisks parsed.
	 *
	 * The escaping rule is **the library's own**, reached by handing it the value
	 * as a text node, and that is the point: the body of every document is
	 * escaped by the same converter, so a second copy of the rule here would be
	 * one more thing to keep in step with an upgrade. What is escaped therefore
	 * matches the body exactly — `*`, `_`, `[`, `]`, `\` and a leading `#` — and
	 * so does what is not: a backtick pair still forms a code span, and `&` and
	 * `<` still come back as entities, in a subtitle for the same reason they do
	 * in a paragraph.
	 *
	 * The emphasis (or any other) delimiter is deliberately left to the caller
	 * rather than obtained by converting an `<em>`: the library's emphasis
	 * converter tests its value with `! trim( $value )`, so a subtitle of exactly
	 * `0` is falsy and comes back with the delimiters silently dropped.
	 *
	 * ENT_SUBSTITUTE is load-bearing, not decoration: htmlspecialchars() returns
	 * an empty string for a value carrying an invalid UTF-8 byte, so without it a
	 * subtitle would silently disappear over one bad character rather than losing
	 * that character. Measured, not reasoned about.
	 */
	public function escape_inline( string $text ): string {
		if ( '' === $text ) {
			return '';
		}

		$escaped = htmlspecialchars( $text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );

		return trim( $this->convert( '<p>' . $escaped . '</p>' ) );
	}

	/**
	 * Escapes `\` and `"` inside a Markdown link title.
	 *
	 * A link title is a quoted string embedded directly in the destination
	 * parenthesis (`[text](url "title")`), not inline document text, so this is
	 * deliberately NOT escape_inline(): a title never needs protecting from `*`,
	 * `_`, `[` or `]`, which render literally inside a quoted string.
	 *
	 * Order matters: backslashes are escaped first, so the backslash this method
	 * itself adds in front of a quote is never re-escaped by the second pass.
	 * (`str_replace()` with parallel arrays applies each pair in order, feeding
	 * the previous pair's result into the next.)
	 */
	public static function escape_link_title( string $title ): string {
		return str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $title );
	}

	/**
	 * Wraps a destination in `<…>` when it carries whitespace or a parenthesis,
	 * either of which would otherwise close Markdown's `(…)` destination syntax
	 * early. A literal `<` or `>` inside is percent-encoded first, so the
	 * wrapper itself cannot be closed from within.
	 *
	 * An ordinary WordPress-sanitized internal URL never contains any of these
	 * characters, so this leaves the overwhelming majority of destinations
	 * byte-identical to the library's own (unescaped) behaviour.
	 */
	public static function wrap_destination( string $url ): string {
		if ( 1 !== preg_match( '/[\s()]/', $url ) ) {
			return $url;
		}

		return '<' . str_replace( array( '<', '>' ), array( '%3C', '%3E' ), $url ) . '>';
	}

	/**
	 * Removes trailing whitespace and collapses multiple blank lines, leaving
	 * fenced code blocks byte-for-byte alone.
	 *
	 * Both rules would otherwise rewrite the code itself: trailing spaces are
	 * significant in a Markdown sample (two of them are a hard break) and runs of
	 * blank lines carry meaning in REPL transcripts, diffs and patch bodies.
	 * CodeElementConverter always fences preformatted content, so tracking fences
	 * covers every code block this pipeline can produce.
	 */
	private function normalize_whitespace( string $markdown ): string {
		$markdown = str_replace( "\r\n", "\n", $markdown );

		$out   = '';
		$plain = '';
		$open  = null;

		foreach ( explode( "\n", $markdown ) as $line ) {
			if ( null === $open ) {
				$opens = self::fence_opens( $line );

				if ( null === $opens ) {
					$plain .= rtrim( $line, " \t" ) . "\n";
					continue;
				}

				// A fence opens: flush the normalized text collected before it.
				$out  .= self::collapse_blank_lines( $plain );
				$plain = '';
				$open  = $opens;
				$out  .= $line . "\n";
				continue;
			}

			$out .= $line . "\n"; // Verbatim while inside the fence.

			if ( self::closes_fence( $line, $open ) ) {
				$open = null;
			}
		}

		$out .= self::collapse_blank_lines( $plain );

		return trim( $out ) . "\n";
	}

	/**
	 * A fence delimiter, with whatever block containers hold it.
	 *
	 * The delimiter is not always at the left margin. `<blockquote><pre>` comes
	 * out of the converter as ``> ```php ``, `<li><pre>` as ``- ```php `` with
	 * its body indented four spaces, and the two nest. Matching only
	 * `^ {0,3}` missed every one of those, so the code inside them was
	 * normalized as prose: trailing spaces (a Markdown hard break, and
	 * meaningful in a transcript or a diff) were trimmed, and runs of blank
	 * lines collapsed. That is precisely the rewriting the fence tracking
	 * exists to prevent.
	 *
	 * Being permissive here is the safe direction: a false positive preserves a
	 * region of prose verbatim, while a false negative rewrites code.
	 */
	const FENCE_PATTERN = '/^(?P<prefix>(?:[ \t]*(?:>|[-*+][ \t]|\d{1,9}[.)][ \t]))*)(?P<indent>[ \t]*)(?P<fence>`{3,}|~{3,})/';

	/**
	 * Everything the fence logic needs about a delimiter line, or null when the
	 * line is not one.
	 *
	 * Three fields, each earning its place:
	 * - `marker`: the backtick/tilde run itself;
	 * - `depth`: the run of `>`, i.e. the blockquote nesting. Indentation and
	 *   list markers are dropped from it on purpose — a fence opened as
	 *   ``- ```php `` is closed by an indented ``` ``` ``` four spaces in, so
	 *   comparing literal prefixes would never match, while the quote depth is
	 *   the part that does stay identical;
	 * - `indent`: the whitespace left AFTER the last block marker, which is the
	 *   only part CommonMark counts. Measuring the whole prefix instead is
	 *   wrong the moment anything nests: a `<pre>` in a second-level list comes
	 *   out as ``    - ```php ``, where the four spaces position the nested list
	 *   and belong to it, not to the delimiter. A blockquote marker also takes
	 *   one optional space with it (`> ` is the marker, not one space of
	 *   indentation), so that space is discounted too;
	 * - `list`: whether a list marker opened this line.
	 *
	 * @return array{marker:string,depth:string,indent:int,list:bool}|null
	 */
	private static function fence_parts( string $line ): ?array {
		if ( 1 !== preg_match( self::FENCE_PATTERN, $line, $matches ) ) {
			return null;
		}

		$prefix = $matches['prefix'];
		$indent = strlen( $matches['indent'] );

		// A list marker swallows its own separator, so `indent` is already
		// measured from the item's content column. `>` does not, so the single
		// space that belongs to the marker is discounted here.
		if ( '>' === substr( rtrim( $prefix ), -1 ) && $indent > 0 ) {
			--$indent;
		}

		return array(
			'marker' => $matches['fence'],
			'depth'  => (string) preg_replace( '/[^>]/', '', $prefix ),
			'indent' => $indent,
			'list'   => 1 === preg_match( '/[-*+]|\d/', $prefix ),
		);
	}

	/**
	 * How much indentation a fence delimiter may carry before it stops being one.
	 *
	 * CommonMark §4.5 allows up to three spaces; beyond that the line is
	 * indented content, not a delimiter. Honouring that limit is what keeps a
	 * ``` ``` ``` sitting four spaces deep INSIDE a top-level fence — an
	 * ordinary thing to find in a sample that itself shows fenced code — from
	 * closing that fence early and handing the rest of the document to the
	 * prose rules.
	 */
	const MAX_FENCE_INDENT = 3;

	/**
	 * The fence opened by this line, or null when it opens none.
	 */
	private static function fence_opens( string $line ): ?array {
		$parts = self::fence_parts( $line );

		if ( null === $parts || $parts['indent'] > self::MAX_FENCE_INDENT ) {
			return null;
		}

		return $parts;
	}

	/**
	 * Whether the line closes the fence currently open: same delimiter character,
	 * at least as long as the opening run, the same blockquote nesting, an
	 * acceptable indentation, and nothing but whitespace after it (an info string
	 * marks an opening fence, never a closing one).
	 *
	 * The indentation rule is where the two nesting shapes part company. A fence
	 * opened by a LIST marker (``- ```php ``) has its body — closing delimiter
	 * included — indented to the item's content column, four spaces in the
	 * converter's output, and that indentation is structural rather than
	 * content. Anywhere else the CommonMark limit applies, so an indented
	 * delimiter is code and leaves the fence open.
	 *
	 * @param array $open The parts of the line that opened the fence.
	 */
	private static function closes_fence( string $line, array $open ): bool {
		$parts = self::fence_parts( $line );

		if ( null === $parts ) {
			return false;
		}

		if ( $parts['marker'][0] !== $open['marker'][0]
			|| strlen( $parts['marker'] ) < strlen( $open['marker'] )
			|| $parts['depth'] !== $open['depth'] ) {
			return false;
		}

		if ( ! $open['list'] && $parts['indent'] > self::MAX_FENCE_INDENT ) {
			return false;
		}

		$after = substr( $line, (int) strpos( $line, $parts['marker'] ) + strlen( $parts['marker'] ) );

		return '' === trim( $after );
	}

	/**
	 * Collapses runs of blank lines down to a single blank line.
	 */
	private static function collapse_blank_lines( string $text ): string {
		return (string) preg_replace( '/\n{3,}/', "\n\n", $text );
	}
}
