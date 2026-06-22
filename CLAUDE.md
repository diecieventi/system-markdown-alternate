# CLAUDE.md — System Markdown Alternate

Guida operativa per sviluppare e mantenere questo plugin WordPress: stato attuale,
decisioni, struttura, convenzioni e workflow. Lo stato funzionale è documentato qui,
nel `README.md` e nel changelog di `readme.txt`.

## Cos'è

Plugin WordPress custom che espone una **versione Markdown pulita** dei contenuti
(leggibile da LLM, agenti, tool di scraping tecnico). Ogni contenuto pubblicato
dei tipi abilitati è accessibile aggiungendo `.md` al permalink:

```
https://example.com/mio-articolo/      → HTML
https://example.com/mio-articolo.md    → Markdown (front matter + contenuto)
```

**Non** è un plugin SEO generico: è una feature tecnica. Priorità: funzionare bene
sul blog, restare semplice da verificare, produrre Markdown pulito, non creare
rischi SEO, restare estendibile via filtri.

## Stato attuale (v0.12.x)

Lo scope v1 è realizzato e ampiamente superato. Implementato:

- **Endpoint `.md`** per i post type abilitati (post/page/CPT pubblici), pubblicati,
  pubblici, non protetti da password; **content negotiation** (`Accept: text/markdown`
  o `?format=markdown`).
- **Link `rel="alternate"`** nel `<head>` dei singolari supportati.
- **Header HTTP**: `Content-Type: text/markdown; charset=utf-8`,
  `X-Robots-Tag: noindex, follow`, `Link: <permalink>; rel="canonical"`.
- **Conversione pulita**: `render_block()` sui blocchi ripuliti (no related/CTA),
  esclusione blocchi/shortcode/classi, code block fenced, **URL assoluti risolti
  contro il permalink sorgente** (document-relative, `../`, root-relative).
- **`/llms.txt`** (cachato, esclude i contenuti protetti) con toggle on/off.
- **Cache Redis-aware** (`Cache` helper): object cache persistente se presente,
  altrimenti transient. Invalidazione via salt globale + `post_modified_gmt` +
  `SMA_VERSION`; bump del salt al salvataggio opzioni; pulizia su `save_post`/
  `deleted_post` (salta revisioni/autosave).
- **Pannello admin** (pagina unica, Settings API): sezioni Generale / Output
  Markdown / llms.txt / Integrazioni / Avanzate; CSS caricato solo nella pagina.
- **ACF**: sottotitolo (testo) + TL;DR (WYSIWYG, passa dalla pipeline DOM) come
  preambolo tra H1 e corpo; nomi campo configurabili dal pannello.
- **Shortcode** `[sma_md_url]` (+ `id="123"`).
- **Dynamic Tag GenerateBlocks** `{{sma_md_url}}`: si auto-registra se GB 2.x è
  attivo (nessun toggle).
- `uninstall.php` (rimuove opzioni `sma_*` + transient).

## Aperti / da fare (verso wordpress.org)

- **i18n**: stringhe del pannello hardcoded (IT/EN miste) → `__()`/`esc_html__()`
  con text domain `system-markdown-alternate`.
- **`Contributors:`** reale in `readme.txt` (ora segnaposto `diecieventi`).
- Eventuale **auto-yield** opt-in di `/llms.txt` (per ora solo avviso, niente
  disattivazione automatica).
- Idea futura: contenuti `/llms.txt` più ricchi (spec Cloudflare / LLM signals).

## Decisioni di prodotto (durevoli)

- `sma_markdown_supported_post_types` default **vuoto** → plugin **inattivo**
  finché non si seleziona almeno un tipo dal pannello. `attachment` sempre escluso.
  **CPT supportati** (si mostrano/validano tutti i tipi pubblici).
- Sezioni **ACF** e **GenerateBlocks** nel pannello: mostrate solo se il rispettivo
  plugin è attivo. Le opzioni ACF sono `register_setting` **solo se ACF è attivo**,
  così salvare con ACF spento non azzera i nomi campo (la Settings API scrive tutte
  le opzioni registrate del gruppo).
- **Dynamic Tag** GenerateBlocks: auto-registrato quando GB 2.x è presente. Per post
  non servibili il callback restituisce '' → l'opzione "required to render" di GB
  nasconde l'elemento (niente link rotti).
- **Rilevamento conflitti `/llms.txt`**: solo segnali **locali e stabili** (plugin
  SEO attivi via costante/classe + file fisico nella root). Niente lettura di opzioni
  interne di terzi, niente check HTTP loopback (rimosso: inaffidabile dietro WAF).
  È solo un avviso informativo, decide l'utente.
- **Description** front matter: Rank Math (`rank_math_description`) → scartata solo
  se contiene un placeholder `%variabile%` non risolto → fallback excerpt → testo
  troncato (~200 char). Front matter include `featured_image` (+ `featured_image_alt`).

## Identità, versioning, workflow

- **Author** del plugin = **"Diecieventi Digital Marketing"**. La ragione sociale
  **"System for PC" non deve MAI comparire** in artefatti (codice, commit, readme).
  `system4pc` nell'URL/handle GitHub è OK.
- Non inserire l'**ID del modello** in commit, readme, codice o altri artefatti.
- **Versionamento semver `0.x.y`**: minor per nuove feature, patch per fix. A ogni
  release: bump in `system-markdown-alternate.php` (header `Version:` **e**
  `SMA_VERSION`), aggiorna `Stable tag` + changelog in `readme.txt`, `bash bin/build.sh`,
  commit, push.
- **Git**: si lavora **SEMPRE e SOLO su `main`** (unico sviluppatore; niente feature
  branch, niente PR salvo richiesta esplicita). Commit atomici, `git push -u origin main`.
  L'utente sincronizza il Mac manualmente: nessun automatismo locale.

## Stack di produzione / ambiente di test

- **Blog di produzione** (`webdietrolequinte.it`): GeneratePress/GenerateBlocks 2.x,
  ACF, Code Block Pro, Lightbox for Gallery & Image Block, LuckyWP TOC, WP Search
  with Algolia, Rank Math. Dietro **Cloudflare + RunCloud 8G WAF**: il WAF blocca gli
  User-Agent non-browser (`curl` viene bloccato come "bad bot"; i browser passano).
  Tenerne conto nei test via HTTP.
- **Sito di test** via **InstaWP MCP** (`mcp__Instawp_HVF__*`): GeneratePress/
  GenerateBlocks 2.2.1, ACF, WooCommerce; WP 7.0 / PHP 8.4; **nessun object cache
  persistente** (Cache usa il fallback transient). La copia di SMA installata può
  essere **vecchia**: non aggiornarla in automatico.
- **Limite noto:** non si riesce a installare lo zip completo via MCP (troppo grande,
  nessun URL pubblico). Per testare: usare `mcp__Instawp_HVF__execute_php` (logica a
  livello WP) o test PHP locali. Tool utili: `plugin_operations`, `create_content`/
  `create_term`, `discover_blocks`, `site_logs`.

### Impatti dello stack sui default

- **Code Block Pro**: NON convertire l'HTML di syntax highlighting. Si fa strip degli
  `<span>` preservando la classe `language-*` e si lascia che il converter produca il
  fenced block (approccio generico, copre qualsiasi highlighter).
- **LuckyWP TOC**: navigazione → escluso (shortcode `lwptoc`, blocco `luckywp/toc`).
- **Lightbox for Gallery & Image Block**: solo wrapper sulle immagini; nessuna
  gestione speciale, basta preservare `alt`.
- **GenerateBlocks**: MAI esclusi in automatico (contengono contenuto reale).
- **ACF**: implementato (sottotitolo/TL;DR via preambolo). I filtri
  `sma_markdown_source_content` / `sma_acf_field_keys` restano i punti di estensione.
- **WP Search with Algolia**: irrilevante per l'output.

## Struttura repository

```
.
├── CLAUDE.md                     ← questo file
├── README.md                     ← panoramica repo (GitHub)
├── .gitignore
├── bin/build.sh                  ← genera DIST/system-markdown-alternate.zip
├── DIST/                         ← zip distribuibile (versionato)
└── system-markdown-alternate/    ← IL PLUGIN
    ├── system-markdown-alternate.php   ← header + bootstrap (autoloader Composer)
    ├── readme.txt                      ← formato wordpress.org + changelog
    ├── uninstall.php                   ← cleanup opzioni + transient
    ├── composer.json / composer.lock   ← league/html-to-markdown + PSR-4
    ├── vendor/                         ← NON versionato, solo nello zip
    ├── assets/admin-settings.css       ← stile pannello (caricato solo lì)
    └── src/
        ├── Plugin.php              ← bootstrap, registra hook e dipendenze
        ├── MarkdownController.php  ← intercetta .md + content negotiation, validazione, header, cache, output, alternate link, invalidazione
        ├── ContentRenderer.php     ← sorgente → HTML pulito (shortcode/blocchi/DOM/URL assoluti); render_fragment()
        ├── BlockCleaner.php        ← parse/pulizia blocchi Gutenberg
        ├── ShortcodeCleaner.php    ← rimozione shortcode esclusi
        ├── MetadataBuilder.php     ← front matter YAML; markdown_url() (static)
        ├── MarkdownConverter.php   ← HTML → Markdown (league/html-to-markdown)
        ├── AcfIntegration.php      ← sottotitolo + TL;DR (preambolo)
        ├── LlmsTxtController.php   ← endpoint /llms.txt (cachato)
        ├── AdminSettings.php       ← pannello impostazioni (Settings API)
        ├── ConflictDetector.php    ← rilevamento conflitti /llms.txt (solo locale)
        ├── Shortcodes.php          ← [sma_md_url]
        ├── DynamicTags.php         ← {{sma_md_url}} (GenerateBlocks 2.x)
        └── Cache.php               ← helper cache (object cache o transient)
```

- **Namespace PHP:** `SystemMarkdownAlternate` (PSR-4 → `src/`).
- **Prefisso costanti/hook/option:** `sma_` / `SMA_`.

## Convenzioni di codice

- PHP `>= 7.4`, WP `>= 6.0`. Niente dipendenze runtime oltre a `league/html-to-markdown`.
- Classi piccole e a singola responsabilità.
- `defined('ABSPATH') || exit;` in cima a ogni file PHP.
- Escaping rigoroso dell'output (specie il **front matter YAML**: quotare stringhe,
  escape di `"` e `\`).
- Tutti i filtri vanno **documentati con docblock**.
- Dopo modifiche: `php -l` sui file toccati; per la logica pura, test PHP locali.

## Filtri (contratto pubblico)

```php
apply_filters( 'sma_markdown_supported_post_types', array() );             // [] = plugin inattivo finché non si seleziona un tipo
apply_filters( 'sma_markdown_robots_header', 'noindex, follow', $post );   // '' = non inviare header
apply_filters( 'sma_markdown_canonical_url', get_permalink( $post ), $post ); // '' = non inviare Link rel=canonical
apply_filters( 'sma_markdown_cache_ttl', DAY_IN_SECONDS, $post );          // 0 = cache disabilitata
apply_filters( 'sma_markdown_source_content', $post->post_content, $post );
apply_filters( 'sma_markdown_rendered_html', $html, $post );
apply_filters( 'sma_markdown_preamble', '', $post );                       // blocco tra # Titolo e corpo (sottotitolo/TL;DR)
apply_filters( 'sma_markdown_output', $markdown, $post );
apply_filters( 'sma_markdown_excluded_block_names', $block_names );
apply_filters( 'sma_markdown_excluded_shortcodes', $shortcodes );
apply_filters( 'sma_markdown_excluded_classes', $css_classes );
apply_filters( 'sma_acf_field_keys', array(), $post );                     // campi ACF accodati al sorgente
apply_filters( 'sma_acf_subtitle_key', '', $post );                       // campo ACF sottotitolo ('' = off)
apply_filters( 'sma_acf_tldr_key', '', $post );                          // campo ACF TL;DR ('' = off)
apply_filters( 'sma_llms_txt_max_posts', 500, $post_type );              // max post per tipo in /llms.txt
```

Default esclusioni:
- Block names: `gravityforms/form`, `contact-form-7/contact-form-selector`,
  `wpforms/form-selector`, `mailerlite/form`, `luckywp/toc`.
- Shortcode: `contact-form-7`, `gravityform`, `wpforms`, `mailerlite_form`, `lwptoc`.
- Classi CSS: `no-md`, `md-exclude`, `exclude-from-markdown`.

## Note tecniche

1. **Risoluzione `.md`**: in `template_redirect` (priorità 0) si legge `REQUEST_URI`,
   si rileva il suffisso `.md`, si gestiscono query string e trailing slash
   (`/slug.md/` → 301 → `/slug.md`), si ricostruisce il permalink e si usa
   `url_to_postid()`. Approccio senza rewrite rules → niente `flush_rewrite_rules`.
2. **Content negotiation**: oltre al suffisso `.md`, si serve il Markdown del post
   già risolto da WP se la richiesta ha `Accept: text/markdown` o `?format=markdown`.
3. **Esclusione classi**: oltre ad `attrs.className`, passaggio su `DOMDocument`
   sull'HTML renderizzato per togliere elementi annidati con le classi escluse.
4. **Rendering**: `render_block()` sui blocchi ripuliti (non `the_content` completo),
   per non reintrodurre related/CTA iniettati.
5. **URL assoluti**: risolti contro il permalink del post (non `home_url('/')`).
6. **Cache**: chiave `sma_md_{post_id}`, valore con hash di validità
   (`post_modified_gmt|SMA_VERSION|salt`); `/llms.txt` cachato in `sma_llms_txt`.
   Tutto via `Cache` helper (object cache persistente o transient).

## Spunti dal plugin di riferimento (ProgressPlanner/markdown-alternate)

Plugin GPL di Joost de Valk. Stessa libreria, stesso PSR-4. Config converter adottata:

```php
new HtmlConverter([
    'header_style'    => 'atx',          // # Heading
    'strip_tags'      => true,
    'remove_nodes'    => 'script style iframe',
    'hard_break'      => false,
    'list_item_style' => '-',
]);
```

- **Fallback conversione**: se `convert()` lancia un'eccezione → estrazione testo
  semplice invece di rompere la risposta.
- **escape_yaml**: decodifica entità + escape di `\` e `"`.

## Build & deploy

```bash
bash bin/build.sh        # → DIST/system-markdown-alternate.zip (con vendor/ bundlato)
```

Lo zip include le dipendenze Composer di produzione, quindi è installabile senza
Composer sul server. Ambiente di build locale: PHP 8.4, Composer e `zip` (no wp-cli).

## Test (acceptance)

Articoli di test:
1. Articolo semplice (heading, paragrafi, lista, link) → `.md` ok, header corretti, front matter, link alternate.
2. Articolo con immagini + codice (Code Block Pro) + blockquote → conversione corretta.
3. Articolo con sezione `md-exclude` → assente nel `.md`.
4. Articolo con shortcode form (`[contact-form-7 ...]`) e TOC (`[lwptoc]`) → assenti nel `.md`.
5. Contenuti non ammessi (pagina/CPT non abilitato, bozza, post protetto da password) → **404**.

Verificare sempre: `Content-Type: text/markdown; charset=utf-8`,
`X-Robots-Tag: noindex, follow`; nessun contenuto privato/bozza/non-abilitato esposto.
Nota: i test HTTP dalla riga di comando in produzione possono essere bloccati dal WAF
(usare un User-Agent da browser).
