# CLAUDE.md — System Markdown Alternate

Guida operativa per sviluppare e mantenere questo plugin WordPress. Il piano
funzionale completo è in [`piano.md`](./piano.md); questo file riassume decisioni,
struttura, convenzioni e workflow di build/test.

## Cos'è

Plugin WordPress custom che espone una **versione Markdown pulita** degli articoli
del blog (leggibile da LLM, agenti, tool di scraping tecnico). Ogni `post`
pubblicato è accessibile aggiungendo `.md` al permalink:

```
https://example.com/mio-articolo/      → HTML
https://example.com/mio-articolo.md    → Markdown (front matter + contenuto)
```

**Non** è un plugin SEO generico: è una feature tecnica per il blog. Priorità:
funzionare bene sul blog, restare semplice da verificare, produrre Markdown
pulito, non creare rischi SEO, lasciare punti di estensione per ACF/contenuti
custom in v2.

## Scope v1

**Sì:** solo `post` pubblicati, pubblici, non protetti da password; endpoint `.md`;
link `rel="alternate"` nel `<head>` (solo `is_singular('post')`); header HTTP
corretti; front matter + contenuto; esclusione blocchi/shortcode; cache transient.

**No (rimandato a v2+):** pannello admin, pagine/CPT/WooCommerce/archivi,
endpoint REST, feed Markdown, `llms.txt`, sitemap Markdown, canonical, UI Gutenberg,
ACF avanzato, indicizzazione dei `.md`.

## Decisioni confermate (dall'utente)

- **SEO → Rank Math.** description da `get_post_meta($id, 'rank_math_description', true)`.
  Se contiene variabili `%...%` non risolte → fallback su excerpt → testo troncato (~160-200 char).
- **Stack del blog di produzione:** GeneratePress/GenerateBlocks, ACF, Code Block Pro,
  Lightbox for Gallery & Image Block, LuckyWP Table of Contents, WP Search with Algolia.
- **Sito di test:** sito WordPress collegato via **InstaWP MCP** (tool `mcp__Instawp_HVF__*`).
  Usato per installare lo zip, creare articoli di test, eseguire PHP e leggere i log.
- **Distribuzione:** lo zip va sempre prodotto in `DIST/` (oltre all'install via InstaWP).

### Impatti dello stack sui default

- **Code Block Pro** (`kevinbatdorf/code-block-pro`): NON convertire l'HTML di syntax
  highlighting. Rilevare il blocco, estrarre codice + linguaggio dagli attrs e produrre
  un fenced code block ```` ```lang ````. Verificare i nomi attributi sul sito di test.
- **LuckyWP TOC**: è navigazione, va **escluso**. Shortcode `lwptoc` nella lista esclusi;
  verificare anche l'eventuale nome blocco e aggiungerlo agli excluded block names.
- **Lightbox for Gallery & Image Block**: aggiunge solo wrapper/attributi alle immagini.
  Assicurarsi che l'immagine resti convertita con `alt`; nessuna gestione speciale.
- **GenerateBlocks**: MAI esclusi in automatico (possono contenere contenuto reale).
- **ACF**: nessuna implementazione in v1, ma i filtri `sma_markdown_source_content` /
  `sma_markdown_output` devono restare i punti di estensione per la v2.
- **WP Search with Algolia**: irrilevante per l'output.

## Struttura repository

```
.
├── CLAUDE.md                     ← questo file
├── piano.md                      ← piano funzionale completo
├── .gitignore
├── bin/
│   └── build.sh                  ← genera DIST/system-markdown-alternate.zip
├── DIST/                         ← zip distribuibile (versionato)
└── system-markdown-alternate/    ← IL PLUGIN
    ├── system-markdown-alternate.php   ← header + bootstrap (autoloader Composer)
    ├── composer.json                   ← league/html-to-markdown + PSR-4
    ├── composer.lock                   ← versionato (build riproducibili)
    ├── vendor/                         ← NON versionato, solo nello zip
    └── src/
        ├── Plugin.php              ← bootstrap, registra hook
        ├── MarkdownController.php  ← intercetta .md, valida, header, output, exit; link alternate
        ├── ContentRenderer.php     ← sorgente → HTML pulito (shortcode, blocchi, DOM, special blocks)
        ├── BlockCleaner.php        ← parse/pulizia blocchi Gutenberg
        ├── ShortcodeCleaner.php    ← rimozione shortcode esclusi
        ├── MetadataBuilder.php     ← front matter YAML
        └── MarkdownConverter.php   ← HTML → Markdown (league/html-to-markdown)
```

- **Namespace PHP:** `SystemMarkdownAlternate` (PSR-4 → `src/`).
- **Prefisso costanti/hook/option:** `sma_` / `SMA_`.

## Convenzioni di codice

- PHP `>= 7.4`, WP `>= 6.0`. Niente dipendenze runtime oltre a `league/html-to-markdown`.
- Classi piccole e a singola responsabilità (vedi struttura sopra).
- `defined('ABSPATH') || exit;` in cima a ogni file PHP.
- Escaping rigoroso dell'output (specie il **front matter YAML**: quotare stringhe,
  escape di `"` e `\`).
- Tutti i filtri previsti dal piano vanno implementati e **documentati con docblock**.

## Filtri (contratto pubblico)

```php
apply_filters( 'sma_markdown_robots_header', 'noindex, follow', $post ); // '' = non inviare header
apply_filters( 'sma_markdown_source_content', $post->post_content, $post );
apply_filters( 'sma_markdown_rendered_html', $html, $post );
apply_filters( 'sma_markdown_output', $markdown, $post );
apply_filters( 'sma_markdown_excluded_block_names', $block_names );
apply_filters( 'sma_markdown_excluded_shortcodes', $shortcodes );
apply_filters( 'sma_markdown_cache_ttl', DAY_IN_SECONDS, $post ); // 0 = cache disabilitata
```

Default esclusioni:
- Block names: `gravityforms/form`, `contact-form-7/contact-form-selector`,
  `wpforms/form-selector`, `mailerlite/form` (+ blocco LuckyWP TOC da verificare).
- Shortcode: `contact-form-7`, `gravityform`, `wpforms`, `mailerlite_form`, `lwptoc`.
- Classi CSS: `no-md`, `md-exclude`, `exclude-from-markdown`.

## Note tecniche / raffinamenti rispetto al piano

1. **Risoluzione `.md`**: in `template_redirect` (priorità 0) leggere `REQUEST_URI`,
   rilevare suffisso `.md`, gestire query string e trailing slash, ricostruire il
   permalink e usare `url_to_postid()`. Richiede permalink "puri" (assunto v1).
2. **Esclusione classi CSS**: oltre ad `attrs.className`, fare un passaggio su DOM
   (`DOMDocument`) sull'HTML renderizzato per togliere elementi annidati con le classi escluse.
3. **Rendering**: preferire `render_block()` sui blocchi ripuliti invece del filtro
   `the_content` completo, per non reintrodurre related/CTA iniettati da altri plugin.
4. **URL assoluti** per immagini e link nel Markdown.
5. **Cache**: chiave `sma_md_{post_id}_{hash(post_modified_gmt)}`; rigenera al cambio.

## Build & deploy

```bash
bash bin/build.sh        # → DIST/system-markdown-alternate.zip (con vendor/ bundlato)
```

Lo zip va poi installato sul sito di test. In questa sessione il sito di test è
raggiungibile via **InstaWP MCP**:
- `mcp__Instawp_HVF__plugin_operations` (install/activate dello zip)
- `mcp__Instawp_HVF__create_content` / `create_term` (articoli e tassonomie di test)
- `mcp__Instawp_HVF__execute_php` (ispezione runtime; richiede scope admin)
- `mcp__Instawp_HVF__discover_blocks` (nomi/attributi reali dei blocchi: Code Block Pro, LuckyWP TOC)
- `mcp__Instawp_HVF__site_logs` (debug fatali/notice)

Ambiente di build locale: PHP 8.4, Composer e `zip` disponibili (no `wp-cli`).

## Test (acceptance v1)

Articoli di test richiesti (vedi `piano.md` §Test manuali):
1. Articolo semplice (heading, paragrafi, lista, link) → `.md` ok, header corretti, front matter, link alternate.
2. Articolo con immagini + codice (Code Block Pro) + blockquote → conversione corretta.
3. Articolo con sezione `md-exclude` → assente nel `.md`.
4. Articolo con shortcode form (`[contact-form-7 ...]`) e TOC (`[lwptoc]`) → assenti nel `.md`.
5. Contenuti non ammessi (pagina, prodotto, bozza, post protetto da password) → **404**.

Verificare sempre: `Content-Type: text/markdown; charset=utf-8` e
`X-Robots-Tag: noindex, follow`; nessun contenuto privato/bozza/pagina/CPT esposto.

## Git

- Sviluppo sul branch **`claude/intelligent-bell-aew3mi`**.
- Commit chiari e atomici; push con `git push -u origin claude/intelligent-bell-aew3mi`.
- Non aprire PR salvo richiesta esplicita.
