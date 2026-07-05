# System Markdown Alternate

Plugin WordPress che espone una **versione Markdown pulita** degli articoli del
blog — leggibile da LLM, agenti AI e tool di scraping tecnico. Ogni contenuto
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
- **`406 Not Acceptable`** opzionale se il client non accetta né HTML né Markdown (filtro `sma_markdown_strict_406`, default attivo; i client reali non sono mai colpiti).
- **Link `rel="alternate"`** nell'`<head>` dei contenuti supportati.
- **Header HTTP** corretti: `Content-Type: text/markdown`, `X-Robots-Tag` (default `noindex, follow`), `Link: rel="canonical"` verso l'HTML.
- **Conversione pulita**: blocchi Gutenberg renderizzati singolarmente (niente related/CTA iniettati), esclusione di blocchi/shortcode/classi CSS, code block fenced, URL assoluti.
- **Endpoint `/llms.txt`** (opzionale): indice dei contenuti per LLM e agenti. Una **modalità arricchita** opzionale (spenta di default) aggiunge sintesi del sito, sezione "Contenuti chiave" curata, una description per ogni voce e una sezione `Optional` per i post meno recenti.
- **Cache transient** con invalidazione proattiva (modifica post, aggiornamento plugin, salvataggio impostazioni).
- **Pannello admin** per scegliere i tipi di contenuto esposti e regolare cache, esclusioni e header. Nessun tipo è esposto finché non lo selezioni.
- **Shortcode** `[sma_md_url]` per stampare l'URL del `.md`.
- **Integrazioni opzionali**, mostrate solo se il plugin relativo è attivo:
  - **Advanced Custom Fields**: sottotitolo e TL;DR (da campi ACF) come preambolo tra H1 e corpo.
  - **GenerateBlocks 2.x**: Dynamic Tag `{{sma_md_url}}` auto-attivo, usabile nei campi degli elementi (es. URL di un Button).

## Struttura del repository

```
.
├── README.md                     ← questo file (GitHub)
├── AGENTS.md                     ← guida operativa (agnostica; CLAUDE.md è un symlink)
├── LICENSE                       ← GPL-2.0
├── .github/workflows/ci.yml      ← CI: php -l + test su PHP 7.4/8.4
├── bin/build.sh                  ← genera DIST/system-markdown-alternate.zip
├── bin/make-i18n.sh              ← rigenera le traduzioni
├── DIST/                         ← zip distribuibile (versionato)
└── system-markdown-alternate/    ← il plugin
    ├── system-markdown-alternate.php
    ├── readme.txt                ← readme in formato WordPress.org
    ├── uninstall.php
    ├── composer.json
    ├── languages/                ← .pot + traduzione it_IT
    ├── tests/run-tests.php       ← test della logica pura (no WP/PHPUnit)
    └── src/                      ← classi PSR-4 (namespace SystemMarkdownAlternate)
```

## Build

```bash
bash bin/build.sh        # → DIST/system-markdown-alternate.zip (con vendor/ bundlato)
```

Lo zip include le dipendenze Composer di produzione (`league/html-to-markdown`),
quindi è installabile direttamente in WordPress senza Composer sul server.

Ambiente di build: PHP ≥ 7.4, Composer e `zip`.

## Requisiti

- WordPress ≥ 6.0
- PHP ≥ 7.4

## Licenza

GPL-2.0-or-later. Testo completo nel file [`LICENSE`](LICENSE).
