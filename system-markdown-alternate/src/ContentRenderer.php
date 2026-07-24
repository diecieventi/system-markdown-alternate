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
		$wrapped = '<?xml encoding="UTF-8"?><div id="sysmda-root">' . $html . '</div>';
		$dom->loadHTML( $wrapped, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD );

		libxml_clear_errors();
		libxml_use_internal_errors( $previous );

		$root = $dom->getElementsByTagName( 'div' )->item( 0 );
		if ( ! $root instanceof \DOMElement ) {
			return $html;
		}

		$this->remove_excluded_nodes( $dom );
		$this->unwrap_figures( $dom );
		$this->normalize_code_blocks( $dom );
		$this->absolutize_urls( $dom, $base );

		$out = '';
		foreach ( iterator_to_array( $root->childNodes ) as $child ) {
			$out .= $dom->saveHTML( $child );
		}

		return $out;
	}

	/**
	 * Removes DOM elements carrying an excluded class (including nested elements).
	 */
	private function remove_excluded_nodes( \DOMDocument $dom ): void {
		$xpath = new \DOMXPath( $dom );

		/** Filters CSS classes whose elements are removed from Markdown output. */
		$excluded_classes = (array) apply_filters( 'sysmda_markdown_excluded_classes', self::EXCLUDED_CLASSES );

		foreach ( $excluded_classes as $class ) {
			$query = sprintf(
				"//*[contains(concat(' ', normalize-space(@class), ' '), ' %s ')]",
				$class
			);

			foreach ( iterator_to_array( $xpath->query( $query ) ) as $node ) {
				if ( $node->parentNode ) {
					$node->parentNode->removeChild( $node );
				}
			}
		}
	}

	/**
	 * Replaces <figure> with <p>, which the library treats as standalone blocks,
	 * ensuring blank-line separation around images and captions.
	 */
	private function unwrap_figures( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'figure' ) ) as $figure ) {
			if ( ! $figure->parentNode ) {
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
	 * Normalizes code blocks: removes syntax-highlighting <span> elements while
	 * preserving text and setting the `language-*` class on <code>, so the library
	 * produces a fenced code block. Covers Code Block Pro and other highlighters.
	 */
	private function normalize_code_blocks( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'pre' ) ) as $pre ) {
			$language  = $this->detect_code_language( $pre );
			$code_text = $pre->textContent;

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
	 * - absolute / protocol-relative / special schemes → unchanged;
	 * - root-relative (/x) → against the origin (scheme://host);
	 * - document-relative (x, ../x) → against the permalink directory.
	 */
	private function absolutize( string $url, string $base ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return $url;
		}

		// Already absolute or protocol-relative.
		if ( preg_match( '#^(https?:)?//#i', $url ) ) {
			return $url;
		}

		// Schemes/anchors that must remain unchanged. Scheme names are
		// case-insensitive (RFC 3986 §3.1) — as the absolute check above already
		// assumes for http/https — so `MAILTO:` must be preserved too, otherwise
		// it would be mistaken for a document-relative path and mangled.
		foreach ( array( 'data:', 'mailto:', 'tel:', '#' ) as $prefix ) {
			if ( 0 === stripos( $url, $prefix ) ) {
				return $url;
			}
		}

		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			// Unparseable base: fall back to the site origin.
			return home_url( '/' . ltrim( $url, '/' ) );
		}

		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$origin = $parts['scheme'] . '://' . $parts['host'] . $port;

		// Root-relative: resolve against the origin.
		if ( '/' === $url[0] ) {
			return $origin . $this->resolve_dot_segments( $url );
		}

		// Document-relative: resolve against the permalink directory.
		$base_path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$dir       = ( '/' === substr( $base_path, -1 ) )
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
