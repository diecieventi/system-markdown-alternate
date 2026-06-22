<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Produce l'HTML pulito pronto per la conversione in Markdown.
 */
class ContentRenderer {

	/** Classi CSS che marcano contenuti da escludere dal Markdown. */
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
	 * @param \WP_Post $post Post da renderizzare.
	 * @return string HTML pronto per la conversione.
	 */
	public function render( \WP_Post $post ): string {
		/** Filtro: contenuto sorgente (punto di estensione per ACF/contenuti custom in v2). */
		$content = (string) apply_filters( 'sma_markdown_source_content', $post->post_content, $post );

		// 1. Rimuove gli shortcode esclusi dal sorgente grezzo (anche dentro i blocchi).
		$content = $this->shortcodes->strip( $content );

		// 2. Parse + pulizia blocchi, quindi render dei soli blocchi rimasti.
		if ( has_blocks( $content ) ) {
			$blocks = $this->blocks->clean( parse_blocks( $content ) );

			$html = '';
			foreach ( $blocks as $block ) {
				$html .= render_block( $block );
			}
		} else {
			// Contenuto classico: niente filtro the_content (evita related/CTA iniettati).
			$html = wpautop( do_shortcode( $content ) );
		}

		// 3-5. Passaggio DOM: normalizza code block, rimuove classi escluse, assolutizza URL.
		$html = $this->process_dom( $html, (string) get_permalink( $post ) );

		/** Filtro: HTML renderizzato e ripulito, prima della conversione. */
		return (string) apply_filters( 'sma_markdown_rendered_html', $html, $post );
	}

	/**
	 * Processa un frammento HTML (es. campo WYSIWYG ACF) con la stessa pipeline
	 * del corpo classico: rimozione shortcode esclusi, shortcode/wpautop, e
	 * passaggio DOM (esclusioni per classe, normalizzazione code, URL assoluti
	 * risolti rispetto al permalink del post).
	 */
	public function render_fragment( string $html, \WP_Post $post ): string {
		$html = $this->shortcodes->strip( $html );
		$html = wpautop( do_shortcode( $html ) );

		return $this->process_dom( $html, (string) get_permalink( $post ) );
	}

	/**
	 * Passaggio unico su DOM: code block, classi escluse, URL assoluti.
	 *
	 * @param string $base URL di base (permalink del post) per risolvere i relativi.
	 */
	private function process_dom( string $html, string $base ): string {
		if ( '' === trim( $html ) ) {
			return $html;
		}

		$previous = libxml_use_internal_errors( true );

		$dom     = new \DOMDocument( '1.0', 'UTF-8' );
		$wrapped = '<?xml encoding="UTF-8"?><div id="sma-root">' . $html . '</div>';
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
	 * Rimuove dal DOM gli elementi con una delle classi escluse (anche annidati).
	 */
	private function remove_excluded_nodes( \DOMDocument $dom ): void {
		$xpath = new \DOMXPath( $dom );

		/** Filtro: classi CSS i cui elementi vengono rimossi dall'output Markdown. */
		$excluded_classes = (array) apply_filters( 'sma_markdown_excluded_classes', self::EXCLUDED_CLASSES );

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
	 * Trasforma i <figure> in <p>: la libreria li tratta così come blocchi a sé,
	 * garantendo la separazione (riga vuota) attorno a immagini e didascalie.
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
	 * Normalizza i blocchi di codice: rimuove gli <span> di syntax highlighting
	 * preservando il testo e impostando la classe `language-*` sul <code>, così la
	 * libreria produce un fenced code block. Copre Code Block Pro e qualunque highlighter.
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
	 * Cerca il linguaggio del codice tra classi (`language-*`, `lang-*`, `brush:`) e
	 * attributi dati (`data-language`, `data-lang`) di <pre> e discendenti.
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
	 * Converte gli href dei link e i src delle immagini in URL assoluti,
	 * risolvendo i relativi rispetto al permalink del post ($base).
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
	 * Rende assoluto un URL risolvendolo rispetto a $base (il permalink sorgente):
	 * - assoluti / protocol-relative / schemi speciali → invariati;
	 * - root-relative (/x) → contro l'origine (scheme://host);
	 * - document-relative (x, ../x) → contro la directory del permalink.
	 */
	private function absolutize( string $url, string $base ): string {
		$url = trim( $url );

		if ( '' === $url ) {
			return $url;
		}

		// Già assoluto o protocol-relative.
		if ( preg_match( '#^(https?:)?//#i', $url ) ) {
			return $url;
		}

		// Schemi/áncore da non toccare.
		foreach ( array( 'data:', 'mailto:', 'tel:', '#' ) as $prefix ) {
			if ( 0 === strpos( $url, $prefix ) ) {
				return $url;
			}
		}

		$parts = wp_parse_url( $base );

		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			// Base non interpretabile: fallback all'origine del sito.
			return home_url( '/' . ltrim( $url, '/' ) );
		}

		$port   = isset( $parts['port'] ) ? ':' . $parts['port'] : '';
		$origin = $parts['scheme'] . '://' . $parts['host'] . $port;

		// Root-relative: contro l'origine.
		if ( '/' === $url[0] ) {
			return $origin . $this->resolve_dot_segments( $url );
		}

		// Document-relative: contro la directory del permalink.
		$base_path = isset( $parts['path'] ) ? $parts['path'] : '/';
		$dir       = ( '/' === substr( $base_path, -1 ) )
			? $base_path
			: (string) preg_replace( '#/[^/]*$#', '/', $base_path );

		return $origin . $this->resolve_dot_segments( $dir . $url );
	}

	/**
	 * Normalizza i segmenti "." e ".." di un path, preservando lo slash iniziale.
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
