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
		$html = $this->process_dom( $html );

		/** Filtro: HTML renderizzato e ripulito, prima della conversione. */
		return (string) apply_filters( 'sma_markdown_rendered_html', $html, $post );
	}

	/**
	 * Passaggio unico su DOM: code block, classi escluse, URL assoluti.
	 */
	private function process_dom( string $html ): string {
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
		$this->absolutize_urls( $dom );

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
	 * Converte gli href dei link e i src delle immagini in URL assoluti.
	 */
	private function absolutize_urls( \DOMDocument $dom ): void {
		foreach ( iterator_to_array( $dom->getElementsByTagName( 'a' ) ) as $a ) {
			$href = $a->getAttribute( 'href' );
			if ( $href ) {
				$a->setAttribute( 'href', $this->absolutize( $href ) );
			}
		}

		foreach ( iterator_to_array( $dom->getElementsByTagName( 'img' ) ) as $img ) {
			$src = $img->getAttribute( 'src' );
			if ( $src ) {
				$img->setAttribute( 'src', $this->absolutize( $src ) );
			}
		}
	}

	/**
	 * Risolve un URL relativo rispetto a home_url(). Lascia intatti URL assoluti e schemi speciali.
	 */
	private function absolutize( string $url ): string {
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

		return home_url( '/' . ltrim( $url, '/' ) );
	}
}
