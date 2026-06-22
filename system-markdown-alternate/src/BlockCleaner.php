<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Pulizia ricorsiva dei blocchi Gutenberg prima del rendering.
 */
class BlockCleaner {

	/** Classi CSS che marcano un blocco da escludere. */
	const EXCLUDED_CLASSES = array( 'no-md', 'md-exclude', 'exclude-from-markdown' );

	/**
	 * @param array $blocks Output di parse_blocks().
	 * @return array Blocchi ripuliti (con innerBlocks/innerContent riallineati).
	 */
	public function clean( array $blocks ): array {
		$out = array();

		foreach ( $blocks as $block ) {
			$cleaned = $this->clean_block( $block );
			if ( null !== $cleaned ) {
				$out[] = $cleaned;
			}
		}

		return $out;
	}

	/**
	 * Pulisce un singolo blocco. Restituisce null se va rimosso.
	 *
	 * Quando rimuove un innerBlock riallinea innerContent (i placeholder null devono
	 * restare in corrispondenza 1:1 con gli innerBlocks per un render_block() corretto).
	 *
	 * @return array|null
	 */
	private function clean_block( array $block ) {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : null;

		// I "freeform" (blockName null) sono HTML libero tra i blocchi: si conservano.
		if ( null !== $name && $this->is_excluded( $block ) ) {
			return null;
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

				$cleaned_inner = $this->clean_block( $inner );

				if ( null === $cleaned_inner ) {
					continue; // Rimuove sia il blocco sia il suo segnaposto.
				}

				$new_inner_blocks[]  = $cleaned_inner;
				$new_inner_content[] = null;
			}

			$block['innerBlocks']  = $new_inner_blocks;
			$block['innerContent'] = $new_inner_content;
		}

		return $block;
	}

	/**
	 * Un blocco è escluso se ha un blockName nella lista oppure una classe esclusa negli attrs.
	 */
	private function is_excluded( array $block ): bool {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : '';

		if ( in_array( $name, $this->excluded_block_names(), true ) ) {
			return true;
		}

		$class_attr = isset( $block['attrs']['className'] ) ? (string) $block['attrs']['className'] : '';

		if ( '' !== $class_attr ) {
			$classes = preg_split( '/\s+/', $class_attr );
			/** Filtro: classi CSS i cui blocchi vengono esclusi dall'output Markdown. */
			$excluded_classes = (array) apply_filters( 'sma_markdown_excluded_classes', self::EXCLUDED_CLASSES );
			foreach ( $excluded_classes as $excluded ) {
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
			'luckywp/toc', // LuckyWP TOC: navigazione, non contenuto (verificare nome esatto in produzione).
		);

		/** Filtro: nomi dei blocchi da escludere dal Markdown. */
		return (array) apply_filters( 'sma_markdown_excluded_block_names', $defaults );
	}
}
