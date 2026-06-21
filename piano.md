# Piano implementazione plugin WordPress custom: Markdown Alternate per blog tecnico

## Obiettivo

Realizzare un plugin WordPress custom che esponga una versione Markdown degli articoli del blog.

La v1 deve essere volutamente limitata:

- solo articoli WordPress standard (`post`);
- solo articoli pubblicati;
- nessun supporto iniziale per pagine, CPT, WooCommerce, archivi o tassonomie;
- nessun pannello admin in v1;
- configurazione tramite costanti/filtro PHP;
- output Markdown pulito, leggibile da LLM, agenti, tool di scraping tecnico e sistemi di documentazione.

Il plugin non deve essere pensato come plugin SEO generico. Deve essere una feature tecnica per rendere gli articoli del blog più facilmente leggibili in Markdown.

---

## Nome plugin suggerito

`System Markdown Alternate`

Slug cartella:

`system-markdown-alternate`

File principale:

`system-markdown-alternate.php`

Namespace PHP:

`SystemMarkdownAlternate`

---

## Funzionalità principali v1

### 1. Endpoint Markdown per singoli articoli

Ogni articolo pubblicato deve essere accessibile anche da:

```text
/permalink-articolo.md
```

Esempio:

```text
https://example.com/mio-articolo-wordpress.md
```

Il plugin deve intercettare richieste che terminano con `.md`.

Deve risolvere l’URL originale rimuovendo `.md`, recuperare il post corrispondente e verificare che:

- esista;
- sia di tipo `post`;
- abbia stato `publish`;
- non sia protetto da password;
- sia pubblico.

Se una di queste condizioni fallisce, restituire 404.

Non implementare in v1:

- `/pagina.md`;
- `/prodotto.md`;
- `/categoria.md`;
- `/tag.md`;
- endpoint REST;
- feed Markdown;
- supporto CPT.

---

### 2. Link alternate nel `<head>` degli articoli

Su ogni singolo articolo HTML, il plugin deve aggiungere nel `<head>`:

```html
<link rel="alternate" type="text/markdown" href="https://example.com/permalink-articolo.md">
```

Solo per `is_singular('post')`.

Non aggiungere questo link su:

- pagine;
- CPT;
- archivi;
- homepage;
- categorie;
- tag;
- prodotti WooCommerce.

---

### 3. Output HTTP corretto

Quando viene richiesta la versione `.md`, il plugin deve inviare:

```http
Content-Type: text/markdown; charset=utf-8
X-Robots-Tag: noindex, follow
```

`X-Robots-Tag: noindex, follow` deve essere attivo di default.

Aggiungere un filtro per modificarlo:

```php
apply_filters( 'sma_markdown_robots_header', 'noindex, follow', $post );
```

Se il filtro restituisce stringa vuota, non inviare l’header.

---

### 4. Struttura Markdown dell’output

L’output deve avere questa struttura:

```markdown
---
title: "Titolo articolo"
url: "https://example.com/permalink-articolo/"
markdown_url: "https://example.com/permalink-articolo.md"
date_published: "2026-06-21T10:00:00+02:00"
date_modified: "2026-06-21T12:30:00+02:00"
author: "Nome Autore"
categories:
  - "WordPress"
  - "SEO tecnica"
tags:
  - "GeneratePress"
  - "ACF"
description: "Meta description o excerpt"
---

# Titolo articolo

Contenuto convertito in Markdown...
```

Note:

- usare date in formato ISO 8601;
- recuperare categorie e tag standard WordPress;
- `description` deve usare, in ordine:
  1. meta description Rank Math, se presente;
  2. excerpt del post, se presente;
  3. fallback: testo pulito del contenuto troncato;
- evitare dati personali reali hardcoded;
- non inserire informazioni autore estese in v1, solo display name pubblico.

---

### 5. Estrazione contenuto

Per ora il contenuto principale può partire da `post_content`.

Flusso suggerito:

1. recupera `$post->post_content`;
2. parse dei blocchi Gutenberg;
3. rimuovi blocchi esclusi;
4. renderizza i blocchi rimasti;
5. applica `the_content` solo se necessario;
6. pulisci HTML indesiderato;
7. converti HTML in Markdown.

Non implementare ancora una logica complessa per ACF o contenuti fuori da `post_content`, ma predisporre filtri per estenderla.

Filtri richiesti:

```php
apply_filters( 'sma_markdown_source_content', $post->post_content, $post );
apply_filters( 'sma_markdown_rendered_html', $html, $post );
apply_filters( 'sma_markdown_output', $markdown, $post );
```

Questi filtri serviranno più avanti per includere campi ACF, dati custom o sezioni specifiche.

---

### 6. Esclusione blocchi e sezioni inutili

Il plugin deve cercare di escludere contenuti non utili nella versione Markdown, per esempio:

- form newsletter;
- form contatto;
- box promozionali;
- related posts;
- CTA ripetitive;
- blocchi di layout non informativi;
- shortcode di form;
- sezioni esplicitamente marcate come da escludere.

Non rimuovere automaticamente tutti i blocchi GenerateBlocks, perché potrebbero contenere contenuto reale dell’articolo.

Implementare due livelli di esclusione.

#### A. Esclusione tramite classi CSS

Se un blocco ha una delle seguenti classi, deve essere escluso dal Markdown:

```text
no-md
md-exclude
exclude-from-markdown
```

Questo vale sia a livello Gutenberg `attrs.className`, sia su elementi HTML renderizzati.

Esempio nel blocco WordPress:

```text
Classe CSS aggiuntiva: md-exclude
```

#### B. Esclusione blocchi noti

Aggiungere filtro:

```php
apply_filters( 'sma_markdown_excluded_block_names', $block_names );
```

Lista default iniziale prudente:

```php
[
    'gravityforms/form',
    'contact-form-7/contact-form-selector',
    'wpforms/form-selector',
    'mailerlite/form',
]
```

Se un blocco ha uno di questi `blockName`, va rimosso prima del rendering.

Non escludere in automatico:

- `generateblocks/container`;
- `generateblocks/headline`;
- `generateblocks/button`;
- `core/group`;
- `core/columns`;
- `core/image`;
- `core/code`;
- `core/preformatted`.

---

### 7. Shortcode

Gestire gli shortcode in modo prudente.

In v1:

- se lo shortcode produce HTML utile, può essere renderizzato;
- se è uno shortcode di form/newsletter, deve essere rimosso.

Aggiungere filtro:

```php
apply_filters( 'sma_markdown_excluded_shortcodes', $shortcodes );
```

Default iniziale:

```php
[
    'contact-form-7',
    'gravityform',
    'wpforms',
    'mailerlite_form',
]
```

Rimuovere questi shortcode dal contenuto prima della conversione Markdown.

---

### 8. Conversione HTML → Markdown

Usare una libreria PHP affidabile via Composer, per esempio:

```bash
composer require league/html-to-markdown
```

Il plugin deve includere la cartella `vendor` nella build finale, così non serve Composer sul server di produzione.

Configurare la conversione per preservare bene:

- heading;
- paragrafi;
- link;
- liste;
- grassetti/corsivi;
- immagini con alt;
- code block;
- blockquote;
- tabelle, se possibile.

Se una tabella non viene convertita perfettamente in v1, accettabile, ma non deve rompere l’output.

---

## Struttura file proposta

```text
system-markdown-alternate/
├── system-markdown-alternate.php
├── composer.json
├── vendor/
└── src/
    ├── Plugin.php
    ├── MarkdownController.php
    ├── ContentRenderer.php
    ├── BlockCleaner.php
    ├── ShortcodeCleaner.php
    ├── MetadataBuilder.php
    └── MarkdownConverter.php
```

### Responsabilità classi

#### `Plugin.php`

- bootstrap plugin;
- registra hook WordPress;
- inizializza controller.

#### `MarkdownController.php`

- intercetta richieste `.md`;
- risolve URL originale;
- recupera post;
- valida tipo/stato/password;
- invia header;
- stampa Markdown;
- termina con `exit`.

#### `ContentRenderer.php`

- recupera contenuto sorgente;
- applica filtro `sma_markdown_source_content`;
- pulisce shortcode esclusi;
- parse/render blocchi;
- applica filtro `sma_markdown_rendered_html`;
- restituisce HTML pronto per conversione.

#### `BlockCleaner.php`

- parse ricorsivo dei blocchi Gutenberg;
- rimuove blocchi con classi escluse;
- rimuove blocchi presenti in `sma_markdown_excluded_block_names`;
- conserva contenuto utile.

#### `ShortcodeCleaner.php`

- rimuove shortcode esclusi;
- lista modificabile via filtro `sma_markdown_excluded_shortcodes`.

#### `MetadataBuilder.php`

- costruisce front matter Markdown;
- recupera titolo, URL, data, autore, categorie, tag, description.

#### `MarkdownConverter.php`

- usa `league/html-to-markdown`;
- converte HTML in Markdown;
- pulisce spazi e righe vuote eccessive;
- restituisce Markdown finale.

---

## Hook WordPress richiesti

Nel bootstrap:

```php
add_action( 'template_redirect', [ $controller, 'maybe_render_markdown' ], 0 );
add_action( 'wp_head', [ $controller, 'print_alternate_link' ] );
```

Possibile approccio per `.md`:

- leggere `$_SERVER['REQUEST_URI']`;
- verificare se il path termina con `.md`;
- rimuovere `.md`;
- ricostruire URL originale;
- usare `url_to_postid()` per recuperare il post ID;
- validare il post;
- renderizzare Markdown.

Attenzione a query string e trailing slash.

Esempi:

```text
/articolo.md
/articolo.md?utm_source=test
/blog/articolo.md
```

Devono risolversi correttamente.

---

## Caching

In v1 implementare caching semplice con transient.

Chiave cache:

```text
sma_md_{post_id}_{post_modified_gmt_hash}
```

Oppure equivalente.

Il Markdown deve rigenerarsi quando cambia `post_modified_gmt`.

Aggiungere filtro:

```php
apply_filters( 'sma_markdown_cache_ttl', DAY_IN_SECONDS, $post );
```

Se il filtro restituisce `0`, disabilitare cache.

Non implementare invalidazioni complesse in v1.

---

## Sicurezza e limiti

Il plugin non deve mostrare contenuti privati.

Restituire 404 per:

- post non pubblicati;
- post privati;
- post password protected;
- preview;
- bozze;
- revisioni;
- pagine;
- CPT;
- prodotti;
- allegati.

Sanitizzare output metadati per evitare front matter rotto.

Non esporre dati autore sensibili come email, ID utente, username login.

---

## Cose da NON implementare in v1

Non implementare:

- pannello impostazioni;
- supporto CPT;
- supporto pagine;
- supporto WooCommerce;
- supporto archivi;
- generazione `llms.txt`;
- sitemap Markdown;
- canonical header;
- endpoint REST;
- UI Gutenberg;
- supporto ACF avanzato;
- integrazione Rank Math oltre al recupero eventuale della meta description;
- indicizzazione dei `.md`.

---

## Rank Math description

Per la description provare a leggere il meta:

```php
get_post_meta( $post->ID, 'rank_math_description', true );
```

Verificare anche eventuale chiave corretta usata da Rank Math sul sito.

Fallback:

```php
get_the_excerpt( $post );
```

Ultimo fallback:

contenuto testuale pulito, massimo 160–200 caratteri.

---

## Test manuali richiesti

Creare o usare almeno 4 articoli di test:

### Test 1 — articolo semplice

Contenuto:

- titolo;
- paragrafi;
- H2/H3;
- lista;
- link.

Verificare:

- `/articolo.md` restituisce Markdown;
- header `Content-Type` corretto;
- header `X-Robots-Tag: noindex, follow`;
- front matter corretto;
- link alternate presente nell’HTML.

### Test 2 — articolo con immagini e codice

Contenuto:

- immagine con alt;
- blocco codice;
- blockquote.

Verificare:

- immagine convertita in Markdown;
- codice leggibile;
- blockquote preservato.

### Test 3 — articolo con blocco escluso

Aggiungere una sezione con classe:

```text
md-exclude
```

Verificare che non appaia nel `.md`.

### Test 4 — articolo con shortcode form

Aggiungere shortcode finto o reale tipo:

```text
[contact-form-7 id="123"]
```

Verificare che non appaia nel Markdown.

### Test 5 — contenuti non ammessi

Verificare che restituiscano 404:

- pagina `.md`;
- prodotto `.md`;
- bozza `.md`;
- articolo protetto da password `.md`.

---

## Acceptance criteria

La v1 è completa quando:

- ogni articolo pubblicato ha URL `.md` funzionante;
- solo i post standard sono serviti;
- il Markdown contiene front matter + titolo + contenuto;
- i blocchi marcati `md-exclude`, `no-md`, `exclude-from-markdown` vengono esclusi;
- gli shortcode di form configurati vengono rimossi;
- il link `rel="alternate"` appare solo sugli articoli;
- i `.md` inviano `X-Robots-Tag: noindex, follow`;
- nessun contenuto privato, bozza, pagina o CPT viene esposto;
- il plugin funziona senza pannello admin;
- il codice è organizzato in classi piccole e leggibili;
- i filtri previsti sono presenti e documentati con commenti PHP.

---

## Nota importante

Non trasformare questo plugin in un plugin generico da marketplace.

La priorità è:

1. funzionare bene sul mio blog;
2. essere semplice da controllare;
3. produrre Markdown pulito;
4. non creare rischi SEO inutili;
5. lasciare punti di estensione per ACF/contenuti custom in una v2.