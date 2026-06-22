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
- **Content negotiation**: stesso Markdown servito con `Accept: text/markdown` o `?format=markdown`.
- **Link `rel="alternate"`** nell'`<head>` dei contenuti supportati.
- **Header HTTP** corretti: `Content-Type: text/markdown`, `X-Robots-Tag` (default `noindex, follow`), `Link: rel="canonical"` verso l'HTML.
- **Conversione pulita**: blocchi Gutenberg renderizzati singolarmente (niente related/CTA iniettati), esclusione di blocchi/shortcode/classi CSS, code block fenced, URL assoluti.
- **Endpoint `/llms.txt`** (opzionale): indice dei contenuti per LLM e agenti.
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
├── CLAUDE.md                     ← guida operativa per lo sviluppo
├── piano.md                      ← piano funzionale completo
├── bin/build.sh                  ← genera DIST/system-markdown-alternate.zip
├── DIST/                         ← zip distribuibile (versionato)
└── system-markdown-alternate/    ← il plugin
    ├── system-markdown-alternate.php
    ├── readme.txt                ← readme in formato WordPress.org
    ├── composer.json
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

GPL-2.0-or-later.
