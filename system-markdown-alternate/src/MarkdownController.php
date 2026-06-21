<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Intercetta le richieste *.md, valida il post e serve il Markdown.
 *
 * Responsabilità:
 * - rilevare REQUEST_URI che termina con `.md` (gestendo query string e trailing slash);
 * - ricostruire il permalink originale e recuperare il post via url_to_postid();
 * - validare: esiste, post_type `post`, status `publish`, non protetto da password, pubblico;
 *   in caso contrario → 404;
 * - inviare gli header HTTP:
 *     Content-Type: text/markdown; charset=utf-8
 *     X-Robots-Tag: noindex, follow   (filtrabile via `sma_markdown_robots_header`; vuoto = non inviato)
 * - servire il Markdown (con cache transient) e terminare con exit;
 * - stampare nel <head> il link alternate, SOLO su is_singular('post'):
 *     <link rel="alternate" type="text/markdown" href="...permalink.md">
 */
class MarkdownController {

	/**
	 * Hook: template_redirect (priorità 0).
	 *
	 * TODO (fase 2): implementare.
	 */
	public function maybe_render_markdown(): void {
	}

	/**
	 * Hook: wp_head.
	 *
	 * TODO (fase 2): implementare.
	 */
	public function print_alternate_link(): void {
	}
}
