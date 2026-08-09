<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Produces clean HTML ready for Markdown conversion.
 */
class ContentRenderer {

	/** CSS classes that mark content for exclusion from Markdown. */
	const EXCLUDED_CLASSES = array( 'no-md', 'md-exclude', 'exclude-from-markdown' );

	/**
	 * Wrapper element used to parse the fragment in process_dom().
	 *
	 * Deliberately NOT a `div`: content carrying an unbalanced `</div>` (custom
	 * HTML blocks, migrated content, legacy column shortcodes) would close the
	 * wrapper early, and everything after it — being a sibling of the wrapper
	 * rather than a child — was silently dropped from the output. An element
	 * name no real content closes cannot be ended by accident.
	 */
	const ROOT_TAG = 'sysmda-root';

	/**
	 * Tags that may not be nested inside a `<p>`, so a `<figure>` containing one
	 * is never rewritten into a paragraph (see unwrap_figures()).
	 */
	const BLOCK_TAGS = array( 'table', 'pre', 'ul', 'ol', 'dl', 'blockquote', 'div', 'figure', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6' );

	/** @var BlockCleaner */
	private $blocks;

	/** @var ShortcodeCleaner */
	private $shortcodes;

	public function __construct( BlockCleaner $blocks, ShortcodeCleaner $shortcodes ) {
		$this->blocks     = $blocks;
		$this->shortcodes = $shortcodes;
	}

	/**
	 * @param \WP_Post $post Post to render.
	 * @return string HTML ready for conversion.
	 */
	public function render( \WP_Post $post ): string {
		/** Filters source content (extension point for ACF/custom content). */
		$content = (string) apply_filters( 'sysmda_markdown_source_content', $post->post_content, $post );

		// 1. Remove excluded shortcodes from the raw source (including inside blocks).
		$content = $this->shortcodes->strip( $content );

		// 2. Parse and clean blocks, then render only the remaining blocks.
		if ( has_blocks( $content ) ) {
			$blocks = $this->blocks->clean( parse_blocks( $content ) );

			$html = '';
			foreach ( $blocks as $block ) {
				$html .= render_block( $block );
			}
		} else {
			// Classic content: skip the_content to avoid injected related content/CTAs.
			$html = wpautop( do_shortcode( $content ) );
		}

		// 3-5. DOM pass: normalize code blocks, remove excluded classes, absolutize URLs.
		$html = $this->process_dom( $html, (string) get_permalink( $post ) );

		/** Filters rendered clean HTML before conversion. */
		return (string) apply_filters( 'sysmda_markdown_rendered_html', $html, $post );
	}

	/**
	 * Processes an HTML fragment (for example an ACF WYSIWYG field) through the
	 * same pipeline as classic content: excluded shortcode removal,
	 * shortcode/wpautop processing, and a DOM pass (class exclusions, code
	 * normalization, and absolute URLs resolved against the post permalink).
	 */
	public function render_fragment( string $html, \WP_Post $post ): string {
		$html = $this->shortcodes->strip( $html );
		$html = wpautop( do_shortcode( $html ) );

		return $this->process_dom( $html, (string) get_permalink( $post ) );
	}

	/**
	 * Single DOM pass for code blocks, excluded classes, and absolute URLs.
	 *
	 * @param string $base Base URL (post permalink) for resolving relative URLs.
	 */
	private function process_dom( string $html, string $base ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );

		$dom     = new \DOMDocument( '1.0', 'UTF-8' );
		$wrapped = '<?xml encoding="UTF-8"?><' . self::ROOT_TAG . '>' . $html . '</' . self::ROOT_TAG . '>';
		$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $dom->getElementsByTagName( self::ROOT_TAG )->item( 0 );
		if ( ! $root instanceof \DOMElement ) {
			return $html;
		}

		$removed = $this->remove_excluded_nodes( $dom );
		$this->flatten_definition_lists( $dom );
		$this->unwrap_figures( $dom );
		$this->normalize_code_blocks( $dom );
		$this->absolutize_urls( $dom, $base );

		$out = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		// Non-empty input that comes back empty means the parse went wrong, and
		// the unprocessed HTML is a better answer than nothing. Skipped when an
		// exclusion rule actually removed something: emptying the body is then
		// the intended outcome, and falling back would republish excluded content.
		if ( 0 === $removed && '' === trim( $out ) ) {
			return $html;
		}

		return $out;
	}

	/**
	 * Removes DOM elements carrying an excluded class (including nested elements).
	 *
	 * @return int Number of removed elements.
	 */
	private function remove_excluded_nodes( \DOMDocument $dom ): int {
		$xpath = new \DOMXPath( $dom );

		/** Filters CSS classes whose elements are removed from Markdown output. */
		$excluded_classes = (array) apply_filters( 'sysmda_markdown_excluded_classes', self::EXCLUDED_CLASSES );

		$removed = 0;

		foreach ( $excluded_classes as $class ) {
			$class = is_string( $class ) ? trim( $class ) : '';

			// The class is interpolated into an XPath expression, so anything but
			// a plain CSS token is rejected: a quote would make query() return
			// false and take the whole response down with a TypeError.
			if ( 1 !== preg_match( '/^[A-Za-z0-9_-]+$/', $class ) ) {
				continue;
			}

			$query = sprintf(
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]",
				$class
			);

			$nodes = $xpath->query( $query );

			if ( ! $nodes instanceof \DOMNodeList ) {
				continue;
			}

			foreach ( iterator_to_array( $nodes ) as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
					++$removed;
				}
			}
		}

		return $removed;
	}

	/**
	 * Flattens `<dl>` into paragraphs: the term in bold, each definition as its
	 * own paragraph.
	 *
	 * The converter has no definition-list support and `strip_tags` is on, so an
	 * untouched `<dl>` came out as its terms and definitions concatenated with no
	 * separator at all ("TermDefinition").
	 */
	private function flatten_definition_lists( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'dl' ) ) as $list ) {
			if ( ! $list->parentNode ) {
				continue;
			}

			$fragment = $dom->createDocumentFragment();

			foreach ( iterator_to_array( $list->childNodes ) as $child ) {
				if ( ! $child instanceof \DOMElement ) {
					continue;
				}

				$name = strtolower( $child->nodeName );

				if ( 'dt' !== $name && 'dd' !== $name ) {
					continue;
				}

				$paragraph = $dom->createElement( 'p' );
				$target    = $paragraph;

				if ( 'dt' === $name ) {
					$target = $dom->createElement( 'strong' );
					$paragraph->appendChild( $target );
				}

				foreach ( iterator_to_array( $child->childNodes ) as $inner ) {
					$target->appendChild( $inner );
				}

				$fragment->appendChild( $paragraph );
			}

			if ( ! $fragment->hasChildNodes() ) {
				$list->parentNode->removeChild( $list );
				continue;
			}

			$list->parentNode->replaceChild( $fragment, $list );
		}
	}

	/**
	 * Replaces <figure> with <p>, which the library treats as standalone blocks,
	 * ensuring blank-line separation around images and captions.
	 *
	 * Figures holding a block-level element are left alone: a `<table>` (the
	 * core table block wraps one in a figure) or a `<pre>` inside a `<p>` is
	 * invalid nesting, and the paragraph brought no benefit there anyway.
	 */
	private function unwrap_figures( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'figure' ) ) as $figure ) {
			if ( ! $figure->parentNode || $this->contains_block_element( $figure ) ) {
				continue;
			}

			$paragraph = $dom->createElement( 'p' );
			foreach ( iterator_to_array( $figure->childNodes ) as $child ) {
				$paragraph->appendChild( $child );
			}

			$figure->parentNode->replaceChild( $paragraph, $figure );
		}
	}

	/**
	 * Whether the element contains a descendant that cannot live inside a `<p>`.
	 */
	private function contains_block_element( \DOMElement $element ): bool {
		foreach ( $element->getElementsByTagName( '*' ) as $descendant ) {
			if ( in_array( strtolower( $descendant->nodeName ), self::BLOCK_TAGS, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Normalizes code blocks: removes syntax-highlighting <span> elements while
	 * preserving text and setting the `language-*` class on <code>, so the library
	 * produces a fenced code block. Covers Code Block Pro and other highlighters.
	 */
	private function normalize_code_blocks( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'pre' ) ) as $pre ) {
			$language  = $this->detect_code_language( $pre );
			$code_text = $this->code_text( $pre );

			while ( $pre->firstChild ) {
				$pre->removeChild( $pre->firstChild );
			}

			$code = $dom->createElement( 'code' );
			if ( '' !== $language ) {
				$code->setAttribute( 'class', 'language-' . $language );
			}
			$code->appendChild( $dom->createTextNode( $code_text ) );
			$pre->appendChild( $code );
		}
	}

	/**
	 * Text content of a <pre>, restoring the line breaks when the markup carries
	 * none of its own.
	 *
	 * Highlighters that wrap every line in its own element and rely on CSS for
	 * the breaks (Shiki — and therefore Code Block Pro — emit
	 * `<span class="line">…</span><span class="line">…</span>`) have no newline
	 * anywhere in their text, so reading textContent flat collapsed the whole
	 * block onto a single line. Highlighters that do keep literal newlines
	 * (Prism, highlight.js) take the first branch and are untouched.
	 */
	private function code_text( \DOMElement $pre ): string {
		$text = $pre->textContent;

		if ( false !== strpos( $text, "\n" ) ) {
			return $text;
		}

		$lines = $this->line_elements( $pre );

		if ( count( $lines ) < 2 ) {
			return $text;
		}

		$out = array();
		foreach ( $lines as $line ) {
			$out[] = $line->textContent;
		}

		return implode( "\n", $out );
	}

	/**
	 * Per-line wrapper elements of a <pre>, or an empty array when its children
	 * are not a clean one-element-per-line structure.
	 *
	 * Conservative on purpose: anything unexpected (a stray text node, an element
	 * that does not look like a line wrapper) returns nothing, so code_text()
	 * keeps the previous flat behaviour rather than guessing at line boundaries.
	 *
	 * @return \DOMElement[]
	 */
	private function line_elements( \DOMElement $pre ): array {
		$container = $pre;

		foreach ( $pre->childNodes as $child ) {
			if ( $child instanceof \DOMElement && 'code' === strtolower( $child->nodeName ) ) {
				$container = $child;
				break;
			}
		}

		$lines = array();

		foreach ( $container->childNodes as $child ) {
			if ( ! $child instanceof \DOMElement ) {
				if ( '' !== trim( $child->textContent ) ) {
					return array(); // Mixed content: not a line-per-element block.
				}
				continue;
			}

			$is_line = self::has_line_class( $child )
				|| in_array( strtolower( $child->nodeName ), array( 'div', 'p' ), true );

			if ( ! $is_line ) {
				return array();
			}

			$lines[] = $child;
		}

		return $lines;
	}

	/**
	 * Whether an element carries a class that marks it as one rendered line.
	 *
	 * Matched per class token, with `line` as a whole hyphen/underscore-delimited
	 * word: `line`, `code-line`, `token-line`, `line-number` all qualify, while
	 * `inline-token`, `underline`, `baseline` and `outline` do not. A plain
	 * substring test accepted all of those, and since this decides where
	 * code_text() inserts newlines, an `inline-*` class on adjacent spans split
	 * one source line into several — silently rewriting the code.
	 */
	private static function has_line_class( \DOMElement $element ): bool {
		$classes = preg_split( '/\s+/', trim( $element->getAttribute( 'class' ) ), -1, PREG_SPLIT_NO_EMPTY );

		foreach ( (array) $classes as $class ) {
			if ( 1 === preg_match( '/(?:^|[-_])line(?:[-_]|$)/i', $class ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Detects the code language from classes (`language-*`, `lang-*`, `brush:`)
	 * and data attributes (`data-language`, `data-lang`) on <pre> or descendants.
	 */
	private function detect_code_language( \DOMElement $pre ): string {
		$elements = array_merge( array( $pre ), iterator_to_array( $pre->getElementsByTagName( '*' ) ) );

		foreach ( $elements as $el ) {
			$class = $el->getAttribute( 'class' );
			if ( $class && preg_match( '/(?:language|lang|brush:?)[-\s:]([a-z0-9#+]+)/i', $class, $m ) ) {
				return strtolower( $m[1] );
			}

			foreach ( array( 'data-language', 'data-lang' ) as $attr ) {
				if ( $el->hasAttribute( $attr ) ) {
					$value = trim( $el->getAttribute( $attr ) );
					if ( '' !== $value ) {
						return strtolower( $value );
					}
				}
			}
		}

		return '';
	}

	/**
	 * Converts link href and image src values to absolute URLs, resolving
	 * relative values against the post permalink ($base).
	 */
	private function absolutize_urls( \DOMDocument $dom, string $base ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'a' ) ) as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( $href ) {
				$a->setAttribute( 'href', $this->absolutize( $href, $base ) );
			}
		}

		foreach ( iterator_to_array( $dom->getElementsByTagName( 'img' ) ) as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( $src ) {
				$img->setAttribute( 'src', $this->absolutize( $src, $base ) );
			}
		}
	}

	/**
	 * Makes a URL absolute by resolving it against $base (the source permalink):
	 * - any absolute reference (a scheme is present) / protocol-relative /
	 *   fragment-only → unchanged;
	 * - query-only (?x) → against the base path itself (RFC 3986 §5.3);
	 * - root-relative (/x) → against the origin (scheme://host);
	 * - document-relative (x, ../x) → against the permalink directory.
	 */
	private function absolutize( string $url, string $base ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return $url;
		}

		// Fragment-only reference.
		if ( '#' === $url[0] ) {
			return $url;
		}

		// Protocol-relative.
		if ( 0 === strpos( $url, '//' ) ) {
			return $url;
		}

		// Any absolute reference. RFC 3986 §3.1 defines a scheme as
		// ALPHA *( ALPHA / DIGIT / "+" / "-" / "." ) followed by ":", and scheme
		// names are case-insensitive. Matching the grammar rather than a list of
		// known prefixes covers http(s), mailto:, tel:, data: and everything else
		// real content links to — ftp:, sms:, whatsapp:, callto:, webcal: — none
		// of which may be resolved as a path.
		if ( 1 === preg_match( '#^[a-z][a-z0-9+.\-]*:#i', $url ) ) {
			return $url;
		}

		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) || empty( $parts['scheme'] ) ) {
			// Unparseable base: fall back to the site origin.
			return home_url( '/' . ltrim( $url, '/' ) );
		}

		$port      = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$origin    = $parts['scheme'] . '://' . $parts['host'] . $port;
		$base_path = isset( $parts['path'] ) && '' !== $parts['path'] ? $parts['path'] : '/';

		// Query-only reference: the base path is kept as it is, not treated as a
		// directory (RFC 3986 §5.3). Resolving "?page=2" against "/blog/my-post"
		// as a directory would otherwise land on "/blog/?page=2".
		if ( '?' === $url[0] ) {
			return $origin . $base_path . $url;
		}

		// Root-relative: resolve against the origin.
		if ( '/' === $url[0] ) {
			return $origin . $this->resolve_dot_segments( $url );
		}

		// Document-relative: resolve against the permalink directory.
		$dir = ( '/' === substr( $base_path, -1 ) )
			? $base_path
			: (string) preg_replace( '#/[^/]*$#', '/', $base_path );

		return $origin . $this->resolve_dot_segments( $dir . $url );
	}

	/**
	 * Normalizes "." and ".." path segments while preserving the leading slash.
	 */
	private function resolve_dot_segments( string $path ): string {
		$out = array();

		foreach ( explode( '/', $path ) as $segment ) {
			if ( '.' === $segment ) {
				continue;
			}
			if ( '..' === $segment ) {
				if ( ! empty( $out ) && '' !== end( $out ) ) {
					array_pop( $out );
				}
				continue;
			}
			$out[] = $segment;
		}

		$result = implode( '/', $out );

		return '' === $result || '/' !== $result[0] ? '/' . ltrim( $result, '/' ) : $result;
	}
}
