<?php
/**
 * Genera i file di traduzione del plugin in system-markdown-alternate/languages/:
 *   - system-markdown-alternate.pot        (template, tutti i msgid)
 *   - system-markdown-alternate-it_IT.po   (traduzione italiana, leggibile)
 *   - system-markdown-alternate-it_IT.mo   (traduzione compilata, caricata da WP)
 *
 * Perché uno script PHP e non wp-cli/xgettext/msgfmt: nell'ambiente di sviluppo
 * remoto quei tool NON sono disponibili. Lo script mantiene un'unica tabella di
 * coppie EN (sorgente) → IT (traduzione) e compila il .mo a mano (formato binario
 * gettext documentato e stabile).
 *
 * IMPORTANTE: i `msgid` qui sotto devono combaciare byte-per-byte con le stringhe
 * passate ai `__()`/`esc_html__()` nel codice. Aggiungendo/cambiando una stringa
 * del pannello, aggiorna questa tabella e rilancia:  php bin/make-i18n.php
 *
 * @package SystemMarkdownAlternate
 */

$domain  = 'system-markdown-alternate';
$version = '0.13.0';
$out_dir = $argv[1] ?? ( __DIR__ . '/../system-markdown-alternate/languages' );

if ( ! is_dir( $out_dir ) && ! mkdir( $out_dir, 0755, true ) && ! is_dir( $out_dir ) ) {
	fwrite( STDERR, "Impossibile creare $out_dir\n" );
	exit( 1 );
}

/*
 * id = stringa sorgente inglese (= msgid)
 * it = traduzione italiana (= testo storico del pannello). '' = identica all'inglese.
 */
$strings = array(
	array( 'id' => 'Markdown Alternate', 'it' => '' ),
	array( 'id' => 'General', 'it' => 'Generale' ),
	array( 'id' => 'Enabled content types', 'it' => 'Tipi di contenuto abilitati' ),
	array( 'id' => 'Cache TTL (seconds)', 'it' => 'Cache TTL (secondi)' ),
	array( 'id' => 'Markdown output', 'it' => 'Output Markdown' ),
	array( 'id' => 'Excluded shortcodes', 'it' => 'Shortcode esclusi' ),
	array( 'id' => 'Excluded blocks', 'it' => 'Blocchi esclusi' ),
	array( 'id' => 'Excluded CSS classes', 'it' => 'Classi CSS escluse' ),
	array( 'id' => 'ACF subtitle field', 'it' => 'Campo ACF sottotitolo' ),
	array( 'id' => 'ACF TL;DR field', 'it' => 'Campo ACF TL;DR' ),
	array( 'id' => 'ACF fields', 'it' => 'Campi ACF' ),
	array( 'id' => 'Enable /llms.txt', 'it' => 'Abilita /llms.txt' ),
	array( 'id' => 'Integrations', 'it' => 'Integrazioni' ),
	array( 'id' => 'Advanced', 'it' => 'Avanzate' ),
	array( 'id' => 'Main settings. Without at least one selected content type, the plugin stays inactive.', 'it' => 'Impostazioni principali. Senza almeno un tipo di contenuto selezionato, il plugin resta inattivo.' ),
	array( 'id' => 'Controls what goes into or stays out of the <code>.md</code> file. For exclusions: one entry per line, leave empty to use the built-in defaults.', 'it' => 'Decide cosa entra o resta fuori dal file <code>.md</code>. Per le esclusioni: una voce per riga, lascia vuoto per usare i default interni.' ),
	array( 'id' => 'Settings for advanced users.', 'it' => 'Impostazioni per utenti esperti.' ),
	array( 'id' => 'The <code>/llms.txt</code> file exposes selected site resources in a format readable by LLMs and AI agents. It currently lists the enabled Markdown content.', 'it' => 'Il file <code>/llms.txt</code> espone risorse selezionate del sito in un formato leggibile da LLM e agenti AI. Attualmente elenca i contenuti Markdown abilitati.' ),
	array( 'id' => 'Enabled in the settings:', 'it' => 'Abilitato nelle impostazioni:' ),
	array( 'id' => 'yes', 'it' => 'sì' ),
	array( 'id' => 'no', 'it' => '' ),
	array( 'id' => 'URL:', 'it' => '' ),
	array( 'id' => 'Informational section: how to use the <code>.md</code> URL in content and templates.', 'it' => 'Sezione informativa: come usare l\'URL del <code>.md</code> nei contenuti e nei template.' ),
	array( 'id' => 'Shortcode', 'it' => '' ),
	array( 'id' => '<code>[sma_md_url]</code> — <code>.md</code> URL of the current post.', 'it' => '<code>[sma_md_url]</code> — URL del <code>.md</code> del post corrente.' ),
	array( 'id' => '<code>[sma_md_url id="123"]</code> — <code>.md</code> URL of a specific post.', 'it' => '<code>[sma_md_url id="123"]</code> — URL del <code>.md</code> di un post specifico.' ),
	array( 'id' => 'Returns empty if the post does not expose a .md (type not enabled, draft, or password-protected).', 'it' => 'Restituisce vuoto se il post non espone un .md (tipo non abilitato, bozza o protetto da password).' ),
	array( 'id' => 'GenerateBlocks detected. The dynamic tag is available automatically.', 'it' => 'GenerateBlocks rilevato. Il dynamic tag è disponibile automaticamente.' ),
	array( 'id' => 'Insert <code>{{sma_md_url}}</code> in GenerateBlocks/GeneratePress fields that accept a dynamic tag, e.g. a button URL. If the post has no <code>.md</code>, the tag resolves to empty and the element is hidden (required to render).', 'it' => 'Inserisci <code>{{sma_md_url}}</code> nei campi GenerateBlocks/GeneratePress che accettano un dynamic tag, ad esempio l\'URL di un bottone. Se il post non ha un <code>.md</code>, il tag si risolve a vuoto e l\'elemento viene nascosto (required to render).' ),
	array( 'id' => 'GenerateBlocks not detected. The dynamic tag is not available.', 'it' => 'GenerateBlocks non rilevato. Il dynamic tag non è disponibile.' ),
	array( 'id' => 'ACF detected. The Subtitle and TL;DR fields are configured in the <strong>Markdown output</strong> section.', 'it' => 'ACF rilevato. I campi Sottotitolo e TL;DR si configurano nella sezione <strong>Output Markdown</strong>.' ),
	array( 'id' => 'ACF not detected. The Subtitle and TL;DR fields are not available.', 'it' => 'ACF non rilevato. I campi Sottotitolo e TL;DR non sono disponibili.' ),
	array( 'id' => 'A physical <code>llms.txt</code> file exists in the site root: the web server serves it <strong>before</strong> WordPress, so this endpoint (and any other plugin\'s) is ignored.', 'it' => 'Esiste un file fisico <code>llms.txt</code> nella root del sito: il web server lo serve <strong>prima</strong> di WordPress, quindi questo endpoint (e quello di altri plugin) viene ignorato.' ),
	array( 'id' => 'Active SEO plugins that <em>might</em> handle <code>/llms.txt</code>: <strong>%s</strong>. If one of them already generates it, keep only one handler active (disable this one below, or the llms.txt feature in the other plugin).', 'it' => 'Plugin SEO attivi che <em>potrebbero</em> gestire <code>/llms.txt</code>: <strong>%s</strong>. Se uno di loro lo genera già, tieni attivo un solo gestore (disattiva questo qui sotto, oppure la funzione llms.txt nell\'altro plugin).' ),
	array( 'id' => 'Possible /llms.txt conflict:', 'it' => 'Possibile conflitto su /llms.txt:' ),
	array( 'id' => 'Content types exposed as <code>.md</code> and in <code>/llms.txt</code>. No selection = plugin inactive.', 'it' => 'Tipi di contenuto esposti come <code>.md</code> e in <code>/llms.txt</code>. Nessuna selezione = plugin inattivo.' ),
	array( 'id' => 'seconds', 'it' => 'secondi' ),
	array( 'id' => '0 = cache disabled. Default: 86400 (24 hours).', 'it' => '0 = cache disabilitata. Default: 86400 (24 ore).' ),
	array( 'id' => 'One per line. Leave empty to use the built-in defaults.', 'it' => 'Uno per riga. Lascia vuoto per usare i default interni.' ),
	array( 'id' => 'Default:', 'it' => '' ),
	array( 'id' => 'ACF field name for the subtitle (type: text). Inserted in italics right after the H1 title.', 'it' => 'Nome del campo ACF per il sottotitolo (tipo: testo). Inserito in corsivo subito dopo il titolo H1.' ),
	array( 'id' => 'ACF field name for the TL;DR (type: WYSIWYG editor). Inserted as a <code>**TL;DR**</code> section with <code>---</code> separators.', 'it' => 'Nome del campo ACF per il TL;DR (tipo: editor WYSIWYG). Inserito come sezione <code>**TL;DR**</code> con separatori <code>---</code>.' ),
	array( 'id' => 'ACF not detected: the Subtitle and TL;DR fields will appear here when ACF is active. Any previously saved settings are preserved.', 'it' => 'ACF non rilevato: i campi Sottotitolo e TL;DR appariranno qui quando ACF sarà attivo. Le eventuali impostazioni già salvate restano conservate.' ),
	array( 'id' => 'Enable the <code>/llms.txt</code> endpoint', 'it' => 'Abilita l\'endpoint <code>/llms.txt</code>' ),
	array( 'id' => 'Disable if another plugin already handles <code>/llms.txt</code>.', 'it' => 'Disattiva se un altro plugin gestisce già <code>/llms.txt</code>.' ),
	array( 'id' => 'Default: <code>noindex, follow</code>. Leave empty to not send the header.', 'it' => 'Default: <code>noindex, follow</code>. Lascia vuoto per non inviare l\'header.' ),
	array( 'id' => 'System Markdown Alternate: missing dependencies. Run "composer install" in the plugin folder, or install the built zip (DIST folder).', 'it' => 'System Markdown Alternate: dipendenze mancanti. Esegui "composer install" nella cartella del plugin oppure installa lo zip buildato (cartella DIST).' ),
);

/** Escape di una stringa per il formato .po/.pot. */
function sma_po_escape( $s ) {
	$s = str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), $s );
	$s = str_replace( "\n", '\\n', $s );
	$s = str_replace( "\t", '\\t', $s );
	return $s;
}

/** Blocco header di un file .po/.pot. */
function sma_po_header( $version, $lang ) {
	$date = gmdate( 'Y-m-d H:iO' );
	$h    = "msgid \"\"\n";
	$h   .= "msgstr \"\"\n";
	$h   .= "\"Project-Id-Version: System Markdown Alternate $version\\n\"\n";
	$h   .= "\"Report-Msgid-Bugs-To: \\n\"\n";
	$h   .= "\"POT-Creation-Date: $date\\n\"\n";
	$h   .= "\"PO-Revision-Date: $date\\n\"\n";
	$h   .= "\"Last-Translator: Diecieventi Digital Marketing\\n\"\n";
	$h   .= "\"Language-Team: \\n\"\n";
	$h   .= "\"Language: $lang\\n\"\n";
	$h   .= "\"MIME-Version: 1.0\\n\"\n";
	$h   .= "\"Content-Type: text/plain; charset=UTF-8\\n\"\n";
	$h   .= "\"Content-Transfer-Encoding: 8bit\\n\"\n";
	$h   .= "\"Plural-Forms: nplurals=2; plural=(n != 1);\\n\"\n";
	$h   .= "\"X-Domain: system-markdown-alternate\\n\"\n";
	return $h;
}

// ── .pot ──────────────────────────────────────────────────────────────────────
$pot = sma_po_header( $version, '' );
foreach ( $strings as $s ) {
	$pot .= "\nmsgid \"" . sma_po_escape( $s['id'] ) . "\"\nmsgstr \"\"\n";
}
file_put_contents( "$out_dir/$domain.pot", $pot );

// ── it_IT .po ─────────────────────────────────────────────────────────────────
$po = sma_po_header( $version, 'it_IT' );
foreach ( $strings as $s ) {
	$po .= "\nmsgid \"" . sma_po_escape( $s['id'] ) . "\"\nmsgstr \"" . sma_po_escape( $s['it'] ) . "\"\n";
}
file_put_contents( "$out_dir/$domain-it_IT.po", $po );

// ── it_IT .mo (solo voci tradotte + header) ───────────────────────────────────
$mo_header  = "Project-Id-Version: System Markdown Alternate $version\n";
$mo_header .= "MIME-Version: 1.0\n";
$mo_header .= "Content-Type: text/plain; charset=UTF-8\n";
$mo_header .= "Content-Transfer-Encoding: 8bit\n";
$mo_header .= "Language: it_IT\n";
$mo_header .= "Plural-Forms: nplurals=2; plural=(n != 1);\n";
$mo_header .= "X-Domain: system-markdown-alternate\n";

$entries = array( '' => $mo_header );
foreach ( $strings as $s ) {
	if ( '' !== $s['it'] ) {
		$entries[ $s['id'] ] = $s['it'];
	}
}
uksort( $entries, 'strcmp' ); // spec MO: tabella originali ordinata.

$ids   = array_keys( $entries );
$trans = array_values( $entries );
$n     = count( $entries );

$o_table    = '';
$t_table    = '';
$ids_blob   = '';
$trans_blob = '';
$offset     = 28 + ( 16 * $n ); // header + tabella O (8n) + tabella T (8n).

foreach ( $ids as $id ) {
	$len        = strlen( $id );
	$o_table   .= pack( 'VV', $len, $offset );
	$ids_blob  .= $id . "\0";
	$offset    += $len + 1;
}
foreach ( $trans as $t ) {
	$len         = strlen( $t );
	$t_table    .= pack( 'VV', $len, $offset );
	$trans_blob .= $t . "\0";
	$offset     += $len + 1;
}

$mo  = pack( 'V', 0x950412de );  // magic number
$mo .= pack( 'V', 0 );           // revisione
$mo .= pack( 'V', $n );          // numero di stringhe
$mo .= pack( 'V', 28 );          // offset tabella originali
$mo .= pack( 'V', 28 + 8 * $n ); // offset tabella traduzioni
$mo .= pack( 'V', 0 );           // dimensione hash table (non usata)
$mo .= pack( 'V', 28 + 16 * $n );// offset hash table (non usata)
$mo .= $o_table . $t_table . $ids_blob . $trans_blob;

file_put_contents( "$out_dir/$domain-it_IT.mo", $mo );

printf( "OK: %d stringhe; .mo con %d entry (header incluso) in %s\n", count( $strings ), $n, $out_dir );
