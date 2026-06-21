<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Produce l'HTML pulito pronto per la conversione in Markdown.
 *
 * Flusso:
 * 1. recupera la sorgente: apply_filters( 'sma_markdown_source_content', $post->post_content, $post );
 * 2. rimuove gli shortcode esclusi (ShortcodeCleaner);
 * 3. parse dei blocchi Gutenberg e pulizia (BlockCleaner);
 * 4. render dei blocchi rimasti (preferire render_block al posto del filtro the_content
 *    completo, per non reintrodurre CTA/related iniettati da altri plugin);
 * 5. passaggio DOM per rimuovere elementi con classi escluse (no-md / md-exclude /
 *    exclude-from-markdown) e per normalizzare immagini/link ad URL assoluti;
 * 6. gestione speciale di blocchi noti del blog:
 *      - Code Block Pro (kevinbatdorf/code-block-pro) → estrai codice+linguaggio e
 *        rendi come fenced code block, evitando l'HTML di syntax highlighting;
 *      - LuckyWP TOC → escluso (navigazione, non contenuto);
 * 7. apply_filters( 'sma_markdown_rendered_html', $html, $post );
 *
 * @param \WP_Post $post Post da renderizzare.
 * @return string HTML pronto per la conversione.
 *
 * TODO (fase 2): implementare.
 */
class ContentRenderer {

	public function render( \WP_Post $post ): string {
		return '';
	}
}
