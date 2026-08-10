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

			$html = $this->expand_shortcodes( $html );
		} else {
			// Classic content: skip the_content to avoid injected related content/CTAs.
			$html = wpautop( $this->expand_shortcodes( $content ) );
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
		$html = wpautop( $this->expand_shortcodes( $html ) );

		return $this->process_dom( $html, (string) get_permalink( $post ) );
	}

	/**
	 * Expands shortcodes, leaving the inside of `<pre>` and `<code>` untouched.
	 *
	 * Both source branches go through here, and each had one half of the
	 * problem:
	 *
	 * - **Blocks were never expanded at all.** `render_block()` does not expand
	 *   shortcodes; on the front end that job belongs to `the_content`, which
	 *   this pipeline skips by design (see render()). So a shortcode typed into
	 *   a paragraph, a Custom HTML block or the core Shortcode block reached the
	 *   converter as literal text and was published as an escaped `\[tag\]`.
	 * - **Classic content expanded too much.** It has always called
	 *   `do_shortcode()`, which is a plain regex over the whole string with no
	 *   notion of markup: a code sample *showing* `[gallery]` was expanded like
	 *   the real thing, silently rewriting the sample into whatever the
	 *   shortcode renders.
	 *
	 * Masking the code regions before the expansion fixes the second for both
	 * branches at once. Only the inside of a code region is hidden, so a
	 * shortcode wrapping one still runs. WordPress's own escape (`[[tag]]`)
	 * stays the way to keep literal brackets outside code.
	 *
	 * If the masking pass fails (a PCRE limit on pathological input), the
	 * shortcodes are expanded unprotected rather than skipped: that is the
	 * behaviour classic content has always had, and leaving them unexpanded
	 * would publish the raw tag in its place.
	 */
	private function expand_shortcodes( string $html ): string {
		// No bracket, no shortcode: do_shortcode() short-circuits on the same
		// test, so this only skips the masking work.
		if ( false === strpos( $html, '[' ) ) {
			return $html;
		}

		$stash = array();
		$token = $this->stash_token( $html );

		$masked = preg_replace_callback(
			'#<(pre|code)\b[^>]*>.*?</\1\s*>#is',
			static function ( $matches ) use ( &$stash, $token ) {
				$key = $token . count( $stash ) . '-->';

				$stash[ $key ] = $matches[0];

				return $key;
			},
			$html
		);

		if ( null === $masked ) {
			return do_shortcode( $html );
		}

		return strtr( do_shortcode( $masked ), $stash );
	}

	/**
	 * Prefix for the placeholders that stand in for code regions, guaranteed not
	 * to occur in the content it is used on.
	 *
	 * Shaped like an HTML comment so that a placeholder which somehow survived
	 * restoration would be invisible rather than printed as stray text.
	 */
	private function stash_token( string $html ): string {
		do {
			$token = '<!--sysmda-code-' . md5( uniqid( '', true ) ) . '-';
		} while ( false !== strpos( $html, $token ) );

		return $token;
	}

	/**
	 * Removes the regions marked for exclusion from a fragment, running none of
	 * the rest of the pipeline.
	 *
	 * Exists for the `description` front-matter fallback, which derives its text
	 * from the post content directly rather than from the rendered body — so
	 * without this a section the body promises never to publish came straight
	 * back out in the front matter. Excluded shortcodes are already gone by the
	 * time this runs; the two exclusion rules left are applied here, and BOTH of
	 * them are needed:
	 *
	 * - **Block-level** (`BlockCleaner`), for block content. The first version
	 *   of this method skipped it, on the reasoning that a block excluded by
	 *   name is dynamic and contributes no text to the source anyway. That is
	 *   true of the names the plugin ships and false in general: "Excluded
	 *   blocks" is a settings-page field, so a site can exclude a *static*
	 *   block — a pullquote, a quote — whose text sits right there in the saved
	 *   markup. The same gap swallowed blocks excluded through
	 *   `attrs.className` when the saved inner HTML does not repeat the class
	 *   attribute. The body dropped them and the description published them.
	 * - **Element-level** (the DOM pass below), for a class on markup *inside* a
	 *   block, and for classic content, which has no blocks to walk.
	 *
	 * The block pass runs whenever the source has blocks, with no cheap
	 * pre-filter, and that is deliberate: any substring guard would have to be
	 * evaluated against this post's markup, and a synced pattern keeps its
	 * content in another post entirely — so a guard would go blind exactly where
	 * `BlockCleaner` follows the reference. Expanding those patterns also makes
	 * the description follow the body, which renders them. The cost is one
	 * `parse_blocks()` per fallback description, `/llms.txt` entries included;
	 * it is paid only when a post has neither an SEO description nor an excerpt.
	 *
	 * The DOM pass returns its input untouched when nothing matched, so content
	 * carrying no excluded class is never round-tripped through the DOM.
	 */
	public function strip_excluded_content( string $html ): string {
		if ( has_blocks( $html ) ) {
			$html = serialize_blocks( $this->blocks->clean( parse_blocks( $html ) ) );
		}

		$classes = $this->excluded_classes();

		if ( ! $this->mentions_class( $html, $classes ) ) {
			return $html;
		}

		$dom = $this->load_fragment( $html );

		if ( null === $dom ) {
			return $html;
		}

		if ( 0 === $this->remove_excluded_nodes( $dom, $classes ) ) {
			return $html;
		}

		return $this->serialize_fragment( $dom );
	}

	/**
	 * Whether any excluded class name occurs anywhere in the string.
	 *
	 * A cheap substring test whose only job is to decide whether the DOM pass is
	 * worth running. A false positive costs one parse and nothing else: the
	 * class attribute itself is matched properly in remove_excluded_nodes().
	 *
	 * @param array $classes Excluded class names, already resolved.
	 */
	private function mentions_class( string $html, array $classes ): bool {
		foreach ( $classes as $class ) {
			if ( is_string( $class ) && '' !== $class && false !== strpos( $html, $class ) ) {
				return true;
			}
		}

		return false;
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

		$dom = $this->load_fragment( $html );

		if ( null === $dom ) {
			return $html;
		}

		$removed = $this->remove_excluded_nodes( $dom, $this->excluded_classes() );
		$this->flatten_definition_lists( $dom );
		$this->flatten_disclosures( $dom );
		$this->promote_figcaptions( $dom );
		$this->unwrap_figures( $dom );
		$this->normalize_code_blocks( $dom );
		$this->absolutize_urls( $dom, $base );

		$out = $this->serialize_fragment( $dom );

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
	 * Parses an HTML fragment into a document wrapped in ROOT_TAG.
	 *
	 * @return \DOMDocument|null Null when the wrapper did not survive the parse.
	 */
	private function load_fragment( string $html ): ?\DOMDocument {
		$previous = libxml_use_internal_errors( true );

		$dom     = new \DOMDocument( '1.0', 'UTF-8' );
		$wrapped = '<?xml encoding="UTF-8"?><' . self::ROOT_TAG . '>' . $html . '</' . self::ROOT_TAG . '>';
		$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		return $dom->getElementsByTagName( self::ROOT_TAG )->item( 0 ) instanceof \DOMElement ? $dom : null;
	}

	/**
	 * Serializes the children of the ROOT_TAG wrapper back to HTML.
	 */
	private function serialize_fragment( \DOMDocument $dom ): string {
		$root = $dom->getElementsByTagName( self::ROOT_TAG )->item( 0 );

		if ( ! $root instanceof \DOMElement ) {
			return '';
		}

		$out = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * Filterable list of CSS classes whose content is excluded from Markdown.
	 *
	 * Resolved by the caller and passed down, so a pass that needs the list
	 * before deciding whether to parse at all does not run the filter twice.
	 *
	 * @return array
	 */
	private function excluded_classes(): array {
		/** Filters CSS classes whose elements are removed from Markdown output. */
		return (array) apply_filters( 'sysmda_markdown_excluded_classes', self::EXCLUDED_CLASSES );
	}

	/**
	 * Removes DOM elements carrying an excluded class (including nested elements).
	 *
	 * @param array $excluded_classes Excluded class names, already resolved.
	 * @return int Number of removed elements.
	 */
	private function remove_excluded_nodes( \DOMDocument $dom, array $excluded_classes ): int {
		$xpath = new \DOMXPath( $dom );

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
	 * Flattens `<details>`/`<summary>` into a bold lead-in paragraph followed by
	 * the disclosure body.
	 *
	 * The converter knows neither tag, and `strip_tags` is on, so `core/details`
	 * came out as its summary and body concatenated with nothing between them
	 * ("MoreHidden body"). The `<dt>` treatment in flatten_definition_lists() is
	 * the precedent: a label that introduces what follows becomes a bold
	 * paragraph, and the content it hid becomes ordinary content — a Markdown
	 * document has no collapsed state to represent, and hiding it from an agent
	 * would defeat the point of the representation.
	 */
	private function flatten_disclosures( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'summary' ) ) as $summary ) {
			if ( ! $summary->parentNode ) {
				continue;
			}

			$paragraph = $dom->createElement( 'p' );
			$strong    = $dom->createElement( 'strong' );
			$paragraph->appendChild( $strong );

			foreach ( iterator_to_array( $summary->childNodes ) as $child ) {
				$strong->appendChild( $child );
			}

			if ( $strong->hasChildNodes() ) {
				$summary->parentNode->replaceChild( $paragraph, $summary );
				continue;
			}

			$summary->parentNode->removeChild( $summary );
		}

		foreach ( iterator_to_array( $dom->getElementsByTagName( 'details' ) ) as $details ) {
			if ( ! $details->parentNode ) {
				continue;
			}

			$fragment = $dom->createDocumentFragment();

			foreach ( iterator_to_array( $details->childNodes ) as $child ) {
				$fragment->appendChild( $child );
			}

			if ( ! $fragment->hasChildNodes() ) {
				$details->parentNode->removeChild( $details );
				continue;
			}

			$details->parentNode->replaceChild( $fragment, $details );
		}
	}

	/**
	 * Moves a `<figcaption>` out of its `<figure>` and turns it into the
	 * paragraph that follows it.
	 *
	 * `<figcaption>` is another tag the converter does not know, so with
	 * `strip_tags` on its text was emitted flush against whatever it captioned:
	 * a captioned image came out as `![Alt](url)My caption`, on one line, with
	 * the caption indistinguishable from the alt text. Promoting it to a sibling
	 * paragraph works for every captioned construct at once — images, tables and
	 * embeds all use the same element — and leaves the figure holding only the
	 * media, which is what unwrap_figures() below expects.
	 *
	 * Only direct children are promoted: a caption belonging to a nested figure
	 * stays with that figure and is handled when its own turn comes.
	 */
	private function promote_figcaptions( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'figure' ) ) as $figure ) {
			if ( ! $figure->parentNode ) {
				continue;
			}

			$anchor = $figure;

			foreach ( iterator_to_array( $figure->childNodes ) as $caption ) {
				if ( ! $caption instanceof \DOMElement || 'figcaption' !== strtolower( $caption->nodeName ) ) {
					continue;
				}

				$paragraph = $dom->createElement( 'p' );

				foreach ( iterator_to_array( $caption->childNodes ) as $child ) {
					$paragraph->appendChild( $child );
				}

				$figure->removeChild( $caption );

				if ( ! $paragraph->hasChildNodes() ) {
					continue;
				}

				$figure->parentNode->insertBefore( $paragraph, $anchor->nextSibling );
				$anchor = $paragraph;
			}
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
