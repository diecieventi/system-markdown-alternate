<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Pulizia ricorsiva dei blocchi Gutenberg prima del rendering.
 *
 * I blocchi riutilizzabili / synced pattern (`core/block`) vengono espansi nel
 * contenuto del post `wp_block` referenziato e ripuliti con le stesse regole:
 * senza espansione, render_block() renderizzerebbe il contenuto referenziato
 * senza passare da esclusioni di blocchi e shortcode.
 */
class BlockCleaner {

	/** Classi CSS che marcano un blocco da escludere. */
	const EXCLUDED_CLASSES = array( 'no-md', 'md-exclude', 'exclude-from-markdown' );

	/** @var ShortcodeCleaner */
	private $shortcodes;

	/** @var string[] Nomi blocco esclusi, risolti una volta per clean(). */
	private $excluded_names = array();

	/** @var string[] Classi escluse, risolte una volta per clean(). */
	private $excluded_classes = array();

	/** @var array<int,bool> Ref già espansi nella catena corrente (guardia anti-ricorsione). */
	private $expanding_refs = array();

	public function __construct( ShortcodeCleaner $shortcodes ) {
		$this->shortcodes = $shortcodes;
	}

	/**
	 * @param array $blocks Output di parse_blocks().
	 * @return array Blocchi ripuliti (con innerBlocks/innerContent riallineati).
	 */
	public function clean( array $blocks ): array {
		// Risolviamo le liste filtrabili una sola volta: is_excluded() viene
		// chiamato per ogni blocco (anche annidato) e i filtri non vanno rilanciati N volte.
		$this->excluded_names   = $this->excluded_block_names();
		/** Filtro: classi CSS i cui blocchi vengono esclusi dall'output Markdown. */
		$this->excluded_classes = (array) apply_filters( 'sma_markdown_excluded_classes', self::EXCLUDED_CLASSES );
		$this->expanding_refs   = array();

		return $this->clean_list( $blocks );
	}

	/**
	 * Pulisce una lista di blocchi appiattendo le eventuali espansioni.
	 *
	 * @return array
	 */
	private function clean_list( array $blocks ): array {
		$out = array();

		foreach ( $blocks as $block ) {
			foreach ( $this->clean_block( $block ) as $cleaned ) {
				$out[] = $cleaned;
			}
		}

		return $out;
	}

	/**
	 * Pulisce un singolo blocco. Restituisce 0..n blocchi: lista vuota se va
	 * rimosso, più di uno se un `core/block` viene espanso nel suo contenuto.
	 *
	 * Quando rimuove un innerBlock riallinea innerContent (i placeholder null devono
	 * restare in corrispondenza 1:1 con gli innerBlocks per un render_block() corretto).
	 *
	 * @return array Lista di blocchi ripuliti.
	 */
	private function clean_block( array $block ): array {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : null;

		// I "freeform" (blockName null) sono HTML libero tra i blocchi: si conservano.
		if ( null !== $name && $this->is_excluded( $block ) ) {
			return array();
		}

		if ( 'core/block' === $name ) {
			return $this->expand_reusable( $block );
		}

		if ( ! empty( $block['innerBlocks'] ) ) {
			$new_inner_blocks  = array();
			$new_inner_content = array();
			$index             = 0;
			$inner_content     = isset( $block['innerContent'] ) ? $block['innerContent'] : array();

			foreach ( $inner_content as $chunk ) {
				if ( is_string( $chunk ) ) {
					$new_inner_content[] = $chunk;
					continue;
				}

				// null = segnaposto per il prossimo innerBlock.
				$inner = isset( $block['innerBlocks'][ $index ] ) ? $block['innerBlocks'][ $index ] : null;
				++$index;

				if ( null === $inner ) {
					continue;
				}

				// Un'espansione dentro un wrapper produce N blocchi → N segnaposto.
				foreach ( $this->clean_block( $inner ) as $cleaned_inner ) {
					$new_inner_blocks[]  = $cleaned_inner;
					$new_inner_content[] = null;
				}
			}

			$block['innerBlocks']  = $new_inner_blocks;
			$block['innerContent'] = $new_inner_content;
		}

		return array( $block );
	}

	/**
	 * Espande un `core/block` nel contenuto del post `wp_block` referenziato,
	 * applicando strip degli shortcode esclusi e pulizia ricorsiva.
	 *
	 * @return array Blocchi del pattern ripuliti (vuoto se non espandibile).
	 */
	private function expand_reusable( array $block ): array {
		$ref = isset( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;

		if ( $ref <= 0 || isset( $this->expanding_refs[ $ref ] ) ) {
			return array(); // Ref mancante o ciclo di riferimenti: si scarta.
		}

		$reusable = get_post( $ref );

		if ( ! $reusable instanceof \WP_Post
			|| 'wp_block' !== $reusable->post_type
			|| 'publish' !== $reusable->post_status ) {
			return array();
		}

		$content = $this->shortcodes->strip( (string) $reusable->post_content );

		$this->expanding_refs[ $ref ] = true;
		$expanded                     = $this->clean_list( parse_blocks( $content ) );
		unset( $this->expanding_refs[ $ref ] );

		return $expanded;
	}

	/**
	 * Un blocco è escluso se ha un blockName nella lista oppure una classe esclusa negli attrs.
	 */
	private function is_excluded( array $block ): bool {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : '';

		if ( in_array( $name, $this->excluded_names, true ) ) {
			return true;
		}

		$class_attr = isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';

		if ( '' !== $class_attr ) {
			$classes = preg_split( '/\s+/', $class_attr );
			foreach ( $this->excluded_classes as $excluded ) {
				if ( in_array( $excluded, $classes, true ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Lista (filtrabile) dei blockName da escludere.
	 *
	 * @return string[]
	 */
	private function excluded_block_names(): array {
		$defaults = array(
			'gravityforms/form',
			'contact-form-7/contact-form-selector',
			'wpforms/form-selector',
			'mailerlite/form',
			'luckywp/toc', // LuckyWP TOC: navigazione, non contenuto.
		);

		/** Filtro: nomi dei blocchi da escludere dal Markdown. */
		return (array) apply_filters( 'sma_markdown_excluded_block_names', $defaults );
	}
}
