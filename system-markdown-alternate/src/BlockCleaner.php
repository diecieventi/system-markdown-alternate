<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Pulizia dei blocchi Gutenberg prima del rendering.
 *
 * Responsabilità:
 * - parse ricorsivo dei blocchi (parse_blocks + innerBlocks);
 * - rimuove i blocchi il cui blockName è in `sma_markdown_excluded_block_names`;
 * - rimuove i blocchi con attrs.className che contiene una classe esclusa
 *   (no-md, md-exclude, exclude-from-markdown);
 * - conserva il contenuto utile (NON rimuove in automatico generateblocks/*, core/group,
 *   core/columns, core/image, core/code, core/preformatted, ...).
 *
 * Default block name esclusi (filtrabili):
 *   gravityforms/form, contact-form-7/contact-form-selector,
 *   wpforms/form-selector, mailerlite/form
 *   (+ blocco LuckyWP TOC da verificare sul sito di test)
 *
 * Classi CSS escluse: no-md, md-exclude, exclude-from-markdown.
 *
 * TODO (fase 2): implementare.
 */
class BlockCleaner {

	/**
	 * @param array $blocks Output di parse_blocks().
	 * @return array Blocchi ripuliti.
	 */
	public function clean( array $blocks ): array {
		return $blocks;
	}
}
