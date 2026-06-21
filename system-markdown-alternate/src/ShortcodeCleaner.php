<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Rimuove gli shortcode non desiderati prima della conversione.
 *
 * Lista default (filtrabile via `sma_markdown_excluded_shortcodes`):
 *   contact-form-7, gravityform, wpforms, mailerlite_form,
 *   lwptoc   (LuckyWP Table of Contents → navigazione, non contenuto)
 *
 * TODO (fase 2): implementare.
 *
 * @param string $content Contenuto sorgente.
 * @return string Contenuto senza gli shortcode esclusi.
 */
class ShortcodeCleaner {

	public function strip( string $content ): string {
		return $content;
	}
}
