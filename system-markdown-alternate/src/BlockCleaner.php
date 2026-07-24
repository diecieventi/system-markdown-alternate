<?php
/**
 * @package Diecieventi\SystemMarkdownAlternate
 */

namespace Diecieventi\SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Recursively cleans Gutenberg blocks before rendering.
 *
 * Reusable blocks / synced patterns (`core/block`) are expanded into the
 * referenced `wp_block` post content and cleaned with the same rules. Without
 * expansion, render_block() would render referenced content without applying
 * block and shortcode exclusions.
 */
class BlockCleaner {

	/** CSS classes that mark a block for exclusion. */
	const EXCLUDED_CLASSES = array( 'no-md', 'md-exclude', 'exclude-from-markdown' );

	/** @var ShortcodeCleaner */
	private $shortcodes;

	/** @var string[] Excluded block names, resolved once per clean() call. */
	private $excluded_names = array();

	/** @var string[] Excluded classes, resolved once per clean() call. */
	private $excluded_classes = array();

	/** @var array<int,bool> References already expanded in the current chain (recursion guard). */
	private $expanding_refs = array();

	public function __construct( ShortcodeCleaner $shortcodes ) {
		$this->shortcodes = $shortcodes;
	}

	/**
	 * @param array $blocks Output from parse_blocks().
	 * @return array Clean blocks (with realigned innerBlocks/innerContent).
	 */
	public function clean( array $blocks ): array {
		// Resolve filterable lists once: is_excluded() runs for every block,
		// including nested blocks, and filters should not run repeatedly.
		$this->excluded_names = $this->excluded_block_names();
		/** Filters CSS classes whose blocks are excluded from Markdown output. */
		$this->excluded_classes = (array) apply_filters( 'sysmda_markdown_excluded_classes', self::EXCLUDED_CLASSES );
		$this->expanding_refs   = array();

		return $this->clean_list( $blocks );
	}

	/**
	 * Cleans a list of blocks and flattens any expansions.
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
	 * Cleans one block. Returns 0..n blocks: an empty list when removed, or more
	 * than one when a `core/block` is expanded into its content.
	 *
	 * When removing an innerBlock, realigns innerContent (null placeholders must
	 * remain 1:1 with innerBlocks for render_block() to work correctly).
	 *
	 * @return array List of clean blocks.
	 */
	private function clean_block( array $block ): array {
		$name = isset( $block['blockName'] ) ? $block['blockName'] : null;

		// Freeform blocks (null blockName) are raw HTML between blocks: preserve them.
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

				// null is a placeholder for the next innerBlock.
				$inner = isset( $block['innerBlocks'][ $index ] ) ? $block['innerBlocks'][ $index ] : null;
				++$index;

				if ( null === $inner ) {
					continue;
				}

				// An expansion inside a wrapper produces N blocks → N placeholders.
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
	 * Expands a `core/block` into the referenced `wp_block` post content, strips
	 * excluded shortcodes, and cleans it recursively.
	 *
	 * @return array Clean pattern blocks (empty when expansion is not possible).
	 */
	private function expand_reusable( array $block ): array {
		$ref = isset( $block['attrs']['ref'] ) ? (int) $block['attrs']['ref'] : 0;

		if ( $ref <= 0 || isset( $this->expanding_refs[ $ref ] ) ) {
			return array(); // Missing reference or reference cycle: discard it.
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
	 * A block is excluded when its blockName is listed or attrs contain an excluded class.
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
	 * Filterable list of blockName values to exclude.
	 *
	 * @return string[]
	 */
	private function excluded_block_names(): array {
		$defaults = array(
			'gravityforms/form',
			'contact-form-7/contact-form-selector',
			'wpforms/form-selector',
			'mailerlite/form',
			'luckywp/toc', // LuckyWP TOC: navigation, not content.
		);

		/** Filters block names excluded from Markdown. */
		return (array) apply_filters( 'sysmda_markdown_excluded_block_names', $defaults );
	}
}
