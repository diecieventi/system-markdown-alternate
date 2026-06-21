<?php
/**
 * @package SystemMarkdownAlternate
 */

namespace SystemMarkdownAlternate;

defined( 'ABSPATH' ) || exit;

/**
 * Costruisce il front matter YAML del Markdown.
 *
 * Campi: title, url, markdown_url, date_published, date_modified (ISO 8601),
 * author (solo display name pubblico), categories[], tags[], description.
 *
 * description, in ordine:
 *   1. Rank Math → get_post_meta( $post->ID, 'rank_math_description', true )
 *      (se contiene variabili %...% non risolte, scarta e passa al fallback);
 *   2. get_the_excerpt( $post );
 *   3. testo pulito del contenuto, troncato a ~160-200 caratteri.
 *
 * IMPORTANTE: escaping YAML. Tutte le stringhe vanno quotate e con escape di
 * " e \ per non rompere il front matter (titoli con ":" o virgolette).
 * Non esporre email, user ID o login dell'autore.
 *
 * TODO (fase 2): implementare.
 *
 * @param \WP_Post $post Post di riferimento.
 * @return string Blocco front matter (--- ... ---) con newline finale.
 */
class MetadataBuilder {

	public function build_front_matter( \WP_Post $post ): string {
		return '';
	}
}
