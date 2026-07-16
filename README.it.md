# System Markdown Alternate

> 🇬🇧 English version: see the [main README](README.md). *(Questa è la traduzione
> italiana: la fonte di verità è il README inglese — se noti differenze, fa fede quello.)*

Plugin WordPress che espone una **versione Markdown pulita** dei contenuti —
leggibile da LLM, agenti AI e tool di scraping tecnico. Ogni contenuto
pubblicato dei tipi abilitati è accessibile aggiungendo `.md` al permalink.

```
https://example.com/mio-articolo/      → HTML
https://example.com/mio-articolo.md    → Markdown (front matter + contenuto)
```

Non è un plugin SEO generico: è una feature tecnica pensata per rendere i
contenuti consumabili da strumenti che preferiscono Markdown all'HTML renderizzato.

## Funzionalità

- **Endpoint `.md`** per ogni post pubblicato, pubblico e non protetto dei tipi abilitati.
- **Content negotiation** (RFC 9110): stesso Markdown servito con `Accept: text/markdown` o `?format=markdown`. L'header `Accept` è parsato con q-values: il Markdown si serve solo se preferito esplicitamente, così un client che preferisce l'HTML (q più alto) o che manda un wildcard (`*/*`) riceve l'HTML.
- **`Vary: Accept`** sugli URL negoziabili: cache e CDN non mischiano le rappresentazioni HTML e Markdown dello stesso indirizzo.
- **`406 Not Acceptable`** opzionale se il client non accetta né HTML né Markdown (filtro `sysmda_markdown_strict_406`, default attivo; i client reali non sono mai colpiti).
- **Link `rel="alternate"`** nell'`<head>` dei contenuti supportati.
- **Header HTTP** corretti: `Content-Type: text/markdown`, `X-Robots-Tag` (default `noindex, follow`), `Link: rel="canonical"` verso l'HTML.
- **Conversione pulita**: blocchi Gutenberg renderizzati singolarmente (niente related/CTA iniettati), esclusione di blocchi/shortcode/classi CSS, code block fenced, URL assoluti.
- **Endpoint `/llms.txt`** (opzionale): indice dei contenuti per LLM e agenti. Una **modalità arricchita** opzionale (spenta di default) aggiunge sintesi del sito, sezione "Contenuti chiave" curata, una description per ogni voce e una sezione `Optional` per i post meno recenti. Un ulteriore toggle opzionale aggiunge a ogni voce la **data di ultima modifica** (`updated: YYYY-MM-DD`), così i crawler individuano i contenuti cambiati senza rifare il fetch di ogni URL.
- **Compatibilità con la cache LiteSpeed**: le risposte Markdown negoziate sono marcate come non cacheabili per le page cache keyed-by-URL (`X-LiteSpeed-Cache-Control: no-cache`, `DONOTCACHEPAGE`), e un'impostazione opt-in aggiunge regole `.htaccess` (inerti fuori da LiteSpeed) che fanno bypassare la page cache LiteSpeed alle richieste che negoziano Markdown, sui server che ignorano `Vary: Accept`.
- **Cache transient** con invalidazione proattiva (modifica post, aggiornamento plugin, salvataggio impostazioni).
- **Contatore accessi `.md` opzionale** (spento di default): conta quante volte viene servito l'endpoint Markdown, diviso bot vs umano. Privacy by design: solo totali giornalieri aggregati — niente IP, niente stringhe user-agent, niente dati per visitatore, niente cookie, nessuna chiamata esterna.
- **Pannello admin** per scegliere i tipi di contenuto esposti e regolare cache, esclusioni e header. Nessun tipo è esposto finché non lo selezioni.
- **Shortcode** `[sysmda_md_url]` per stampare l'URL del `.md`.
- **Integrazioni opzionali**, mostrate solo se il plugin relativo è attivo:
  - **Advanced Custom Fields**: sottotitolo e TL;DR (da campi ACF) come preambolo tra H1 e corpo.
  - **GenerateBlocks 2.x**: Dynamic Tag `{{sysmda_md_url}}` auto-attivo, usabile nei campi degli elementi (es. URL di un Button).

## Utilizzo

Dopo aver attivato il plugin, apri **Impostazioni → System Markdown Alternate** e
abilita almeno un tipo di contenuto (finché non lo fai, non viene esposto nulla).
Da quel momento la versione Markdown di ogni post pubblicato di quel tipo è
raggiungibile in tre modi:

1. **Suffisso `.md`** — aggiungi `.md` al permalink:
   `https://example.com/mio-articolo.md`. Restituisce sempre Markdown,
   indipendentemente dall'header `Accept`.
2. **Content negotiation** — richiedi il permalink normale con l'header
   `Accept: text/markdown`. Il Markdown si serve solo quando è preferito rispetto
   all'HTML (i q-values sono rispettati); un browser che manda `text/html` o un
   wildcard riceve comunque l'HTML.
3. **Parametro query** — aggiungi `?format=markdown` al permalink, per i client
   che non possono inviare header custom (e per i post con permalink semplici,
   dove il suffisso `.md` non è applicabile).

L'indice opzionale dei contenuti per LLM e agenti è disponibile su
`https://example.com/llms.txt` (attivabile dalla stessa pagina impostazioni).

## Estendere con i filtri

Tutto ciò che controlla la pagina impostazioni — e altro ancora — è esposto come
filtri WordPress, quindi il plugin si può personalizzare da un tema o da un
plugin di sito. Un paio di esempi:

```php
// Aggiunge un footer personalizzato a ogni output Markdown.
add_filter( 'sysmda_markdown_output', function ( $markdown, $post ) {
    return $markdown . "\n---\nConvertito da " . get_permalink( $post ) . "\n";
}, 10, 2 );

// Esclude dalla conversione una classe CSS aggiuntiva.
add_filter( 'sysmda_markdown_excluded_classes', function ( $classes ) {
    $classes[] = 'mio-blocco-privato';
    return $classes;
} );
```

Il contratto pubblico completo (ogni filtro con il suo valore di default) è
documentato nella sezione ["Filters (public contract)"](AGENTS.md#filters-public-contract)
di `AGENTS.md`.

## Struttura del repository

```
.
├── README.md                     ← README principale (inglese)
├── README.it.md                  ← questo file
├── AGENTS.md                     ← guida operativa (agnostica; CLAUDE.md è un symlink)
├── AGENTS.it.md                  ← traduzione italiana della guida
├── LICENSE                       ← GPL-2.0
├── .github/workflows/ci.yml      ← CI: php -l + test su PHP 7.4/8.4
├── .wordpress-org/               ← assets scheda wordpress.org (icona, banner)
├── bin/build.sh                  ← genera DIST/system-markdown-alternate.zip
├── DIST/                         ← zip distribuibile (versionato)
└── system-markdown-alternate/    ← il plugin
    ├── system-markdown-alternate.php
    ├── readme.txt                ← readme in formato WordPress.org
    ├── uninstall.php
    ├── composer.json
    ├── tests/run-tests.php       ← test della logica pura (no WP/PHPUnit)
    └── src/                      ← classi PSR-4 (namespace Diecieventi\SystemMarkdownAlternate)
```

## Build

```bash
bash bin/build.sh        # → DIST/system-markdown-alternate.zip (con vendor/ bundlato)
```

Lo zip include le dipendenze Composer di produzione (`league/html-to-markdown`),
quindi è installabile direttamente in WordPress senza Composer sul server.

Ambiente di build: PHP ≥ 7.4, Composer e `zip`.

## Requisiti

- WordPress ≥ 6.1
- PHP ≥ 7.4

## Licenza

GPL-2.0-or-later. Testo completo nel file [`LICENSE`](LICENSE).
