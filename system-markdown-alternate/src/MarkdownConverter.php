<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

use League\HTMLToMarkdown\HtmlConverter;

defined( 'ABSPATH' ) || exit;

/**
 * Converte l'HTML pulito in Markdown usando league/html-to-markdown.
 *
 * Responsabilità:
 * - configurare HtmlConverter (heading ATX, preserva grassetti/corsivi, liste,
 *   link, immagini con alt, code block, blockquote; tabelle best-effort);
 * - rimuovere HTML residuo non convertibile senza rompere l'output;
 * - normalizzare spazi e righe vuote eccessive;
 * - apply_filters( 'sma_markdown_output', $markdown, $post ) a valle.
 *
 * TODO (fase 2): implementare.
 *
 * @param string $html HTML pronto per la conversione.
 * @return string Markdown del corpo (senza front matter).
 */
class MarkdownConverter {

	public function convert( string $html ): string {
		return '';
	}
}
