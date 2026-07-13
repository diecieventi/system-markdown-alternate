# AGENTS.it.md — System Markdown Alternate

Guida operativa **agnostica rispetto al tool** per sviluppare e mantenere questo
plugin WordPress: stato attuale, decisioni, struttura, convenzioni e workflow. Lo
stato funzionale è documentato qui, nel `README.md` e nel changelog di `readme.txt`.

> **Questa è la traduzione italiana di `AGENTS.md`**: la fonte di verità è il file
> inglese (è quello che leggono gli agenti via symlink `CLAUDE.md`). Chi modifica
> `AGENTS.md` aggiorna anche questo file **nello stesso commit**. Il plugin in sé
> è solo in inglese (vedi la nota i18n nelle "Note tecniche": le traduzioni
> arrivano da translate.wordpress.org). Se noti differenze, fa fede l'inglese.

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

## Comandi

```bash
# Test della logica pura (no WP/PHPUnit; la CI li esegue su PHP 7.4 e 8.4)
php system-markdown-alternate/tests/run-tests.php

# Lint di un file toccato
php -l system-markdown-alternate/src/<File>.php

# Installa le dipendenze Composer in locale (genera vendor/, necessario per far girare il plugin)
composer install --working-dir=system-markdown-alternate

# Builda lo zip distribuibile con vendor/ bundlato → DIST/system-markdown-alternate.zip
bash bin/build.sh
```

## Stato attuale (v0.21.x)

Lo scope v1 è realizzato e ampiamente superato. Implementato:

- **Endpoint `.md`** per i post type abilitati (post/page/CPT pubblici), pubblicati,
  pubblici, non protetti da password; **content negotiation** (`Accept: text/markdown`
  o `?format=markdown`). L'header `Accept` è **parsato con q-values** (`AcceptNegotiator`):
  il Markdown si serve solo se preferito esplicitamente (q ≥ HTML); un Accept wildcard
  o assente resta HTML. URL negoziabili → **`Vary: Accept`**; opzionale **`406`** se il
  client non accetta né HTML né Markdown (filtro `sysmda_markdown_strict_406`, default on).
- **Link `rel="alternate"`** nel `<head>` dei singolari supportati.
- **Header HTTP**: `Content-Type: text/markdown; charset=utf-8`,
  `X-Robots-Tag: noindex, follow`, `Link: <permalink>; rel="canonical"`,
  `Vary: Accept` (su URL negoziabili), **`ETag` + `Last-Modified`**.
- **Richieste condizionali**: la risposta `.md` gestisce `If-None-Match` /
  `If-Modified-Since` e risponde **`304 Not Modified`** (senza body) quando il client
  possiede già la versione corrente. Validatore = l'hash di validità della cache già
  esistente (`post_modified_gmt` + `SYSMDA_VERSION` + salt impostazioni), quindi un `304`
  implica sempre che il body in cache sarebbe identico; `If-None-Match` ha priorità su
  `If-Modified-Since` (RFC 9110). Funziona anche con la cache del body disattivata.
- **Conversione pulita**: `render_block()` sui blocchi ripuliti (no related/CTA),
  esclusione blocchi/shortcode/classi, code block fenced, **URL assoluti risolti
  contro il permalink sorgente** (document-relative, `../`, root-relative).
  I **synced pattern** (`core/block`) vengono espansi nel contenuto referenziato
  e ripuliti con le stesse regole (guardia sui cicli di riferimenti).
- **Permalink Plain** (`?p=123`): il suffisso `.md` non è applicabile, quindi
  `markdown_url()` ripiega su `?format=markdown` (servito dalla negotiation);
  avviso nel pannello. Eleggibilità dei post centralizzata in `PostSupport`.
- **`/llms.txt`** (cachato, esclude i contenuti protetti) con toggle on/off.
  **Modalità arricchita** opzionale (toggle `sysmda_llms_txt_enriched`, default off;
  off = output base identico): sintesi del sito, sezione "Key content" curata
  (ID/URL dal pannello), description per voce (catena Rank Math → excerpt →
  troncato), overflow oltre i più recenti in `## Optional` (keyword spec, non
  tradotta), filtro `sysmda_llms_txt_footer` come gancio per policy/LLM signals.
  **Date di ultima modifica** opzionali (toggle `sysmda_llms_txt_lastmod`, default
  off; off = output invariato): aggiunge `(updated: YYYY-MM-DD)` a ogni voce
  (base e arricchita, incluse Key content e Optional) — data ISO da
  `post_modified_gmt`, etichetta inglese `updated:` mai tradotta (stessa
  convenzione della keyword `Optional` della spec), collocata nelle note in
  testo libero dopo i `:` per restare compatibile con la spec llms.txt.
- **Compatibilità con la page cache LiteSpeed** (`LiteSpeedCompat`): alcuni
  server LiteSpeed usano come chiave di cache il solo URL e ignorano
  `Vary: Accept` (osservato live: una variante Markdown in cache servita ai
  client HTML e viceversa, mentre PHP negoziava correttamente). Due livelli:
  (1) le risposte Markdown negoziate e i `406` inviano sempre
  `X-LiteSpeed-Cache-Control: no-cache` + definiscono `DONOTCACHEPAGE` +
  lanciano l'action `litespeed_control_set_nocache` del plugin LSCache, così le
  cache keyed-by-URL non le memorizzano mai (gli URL `.md` restano cacheabili:
  sono una chiave a sé); (2) **regole `.htaccess`** opt-in (Avanzate → checkbox
  `sysmda_litespeed_htaccess`, default off) avvolte in `<IfModule LiteSpeed>`
  (inerti altrove): le richieste il cui `Accept` menziona `text/markdown`, o
  non ammette né HTML né un wildcard (il caso 406), ricevono
  `[E=Cache-Control:no-cache]` e bypassano la cache LiteSpeed, così PHP negozia
  sempre anche quando la variante HTML è già in cache. Il blocco viene
  sincronizzato (scritto/rimosso/riparato) a ogni caricamento della pagina
  impostazioni via `insert_with_markers`, lancia un purge-all LSCache quando
  cambia, mostra le regole da copiare a mano se `.htaccess` non è scrivibile,
  ed è rimosso alla disinstallazione.
- **Cache Redis-aware** (`Cache` helper): object cache persistente se presente,
  altrimenti transient. Invalidazione via salt globale + `post_modified_gmt` +
  `SYSMDA_VERSION`; bump del salt al salvataggio opzioni; pulizia su `save_post`/
  `deleted_post` (salta revisioni/autosave).
- **Pannello admin** (pagina unica, Settings API): Generale / Output Markdown /
  llms.txt / Integrazioni / Avanzate. UI restylizzata (solo presentazione): header
  di pagina + Save unico, **tab** native WP, **card** di sezione, layout a due
  colonne con aside di stato/conflitti `/llms.txt`, default interni in un
  `<details>`. `render_page()` itera le sezioni registrate nella Settings API
  (`$wp_settings_sections`) e avvolge ognuna in card+pannello-tab; **tutti i campi
  restano nell'unico form** (le tab mostrano/nascondono via JS), quindi
  salvataggio, sanitizzazione e nonce sono invariati. CSS scopato + piccolo
  enhancement vanilla-JS senza dipendenze (`assets/admin-settings.js`); usabile
  anche senza JS (tutti i pannelli visibili). Asset caricati solo nella pagina.
- **i18n**: stringhe del pannello in `__()`/`esc_html__()` (sorgente **inglese**),
  text domain `system-markdown-alternate` (= slug del plugin). **Nessuna
  traduzione bundlata e nessun loader manuale**: i language pack
  arrivano da translate.wordpress.org e WP li carica in automatico (≥ 4.6).
- **ACF**: sottotitolo (testo) + TL;DR (WYSIWYG, passa dalla pipeline DOM) come
  preambolo tra H1 e corpo; nomi campo configurabili dal pannello.
- **Shortcode** `[sysmda_md_url]` (+ `id="123"`).
- **Dynamic Tag GenerateBlocks** `{{sysmda_md_url}}`: si auto-registra se GB 2.x è
  attivo (nessun toggle).
- `uninstall.php` (rimuove opzioni `sysmda_*` + transient + il blocco
  `.htaccess` LiteSpeed).

## Aperti / da fare (verso wordpress.org)

- Una volta live su wordpress.org: tradurre le stringhe in italiano su
  translate.wordpress.org (chiedere il ruolo PTE se serve) così viene generato
  il language pack `it_IT` — nessun file di traduzione vive in questo repo.
- Idea futura: eventuali **LLM signals** formalizzati in `/llms.txt` quando la spec
  (Cloudflare & co.) si assesta — il gancio è già pronto (`sysmda_llms_txt_footer`).
- **Contatore accessi `.md`** (deciso; piano sotto — prossima minor
  pianificata): contare quante volte viene servito l'endpoint `.md`, diviso
  **bot vs umano**, e nient'altro. Privacy by design (vedi "Decisioni di
  prodotto"): solo contatori giornalieri aggregati → dato anonimo, fuori dal
  perimetro GDPR (niente consenso, niente banner) e dentro la linea guida
  wordpress.org "nessun tracking senza consenso". **Checkbox opt-in, default
  spento.** Limite accettato: una page cache/CDN che serve il `.md` senza
  arrivare a PHP fa sottocontare — è un indicatore, non analytics. Piano di
  implementazione (minor dedicata dopo la 0.20.0):
  1. Nuova `src/HitCounter.php` (responsabilità singola): `record( ?string
     $ua )` classifica la richiesta via `public static is_bot( ?string $ua ):
     bool` (UA vuoto ⇒ bot; lista token case-insensitive: bot, crawl, spider,
     curl, wget, python, java, http, headless, gpt, claude, perplexity, …;
     filtro documentato `sysmda_md_hits_bot_patterns`) e incrementa il bucket di
     oggi nell'opzione `sysmda_md_hits` (autoload off, forma `[ 'YYYY-MM-DD' =>
     [ 'bot' => n, 'human' => n ] ]`), potando i bucket più vecchi di 90
     giorni (filtro documentato `sysmda_md_hits_retention_days`). L'UA viene
     letto solo per classificare, mai salvato. Il read-modify-write può
     perdere un incremento sotto forte concorrenza: accettato (indicatore).
  2. `MarkdownController::serve_markdown()`: con `sysmda_md_hits_enabled`
     attivo, `record()` su ogni risposta servita — `200` **e** `304` (un
     accesso è un accesso) — sia per il suffisso `.md` sia per il permalink
     negoziato.
  3. `AdminSettings.php`: checkbox "Count `.md` requests" (sezione Advanced)
     + totali in sola lettura nella pagina impostazioni (oggi / ultimi 7 /
     ultimi 30 giorni, bot vs umano) con l'avvertenza page-cache nella
     descrizione.
  4. `uninstall.php`: aggiungere `sysmda_md_hits` + `sysmda_md_hits_enabled`
     all'elenco.
  5. Test per `is_bot()` e per la logica di pruning; `php -l`.
  6. Elenco filtri, docs + traduzioni, changelog `readme.txt`, bump di
     versione, build, commit, push.
- **`.wordpress-org/screenshot-*.jpg` sono superati**: mostrano il pannello
  admin pre-0.17.0 (prima del restyling tab/card). Da ricatturare aggiornando
  anche le didascalie `== Screenshots ==` in `readme.txt`, quando comodo (non
  serve un bump di versione: vivono nella cartella SVN `/assets`, indipendente
  da `/trunk`).

### Da controllare al prossimo giro (non urgente, parcheggiato qui)

- **Filtri non documentati nella documentazione utente**: il plugin espone
  un'ampia API di filtri (vedi "Filters (public contract)" più sotto) ma né il
  `readme.txt` (`== Frequently Asked Questions ==`) né `README.md`/`README.it.md`
  menzionano che esistono dei filtri. Decidere dove segnalarlo agli utenti finali
  (almeno un rimando all'elenco filtri) e correggere.
- **Valutare nuove integrazioni**: oltre ad ACF/GenerateBlocks, valutare cos'altro
  potrebbe meritare un'integrazione dedicata (candidati da definire).
- **Valutare come arricchire/gestire ulteriormente `/llms.txt`**: oltre alla
  modalità enriched attuale, valutare cos'altro vale la pena aggiungere
  (candidati da definire, vedi anche l'idea LLM signals qui sopra).
- ~~Possibile log di erogazione del `.md`~~ → valutato e promosso a
  **contatore accessi `.md`** pianificato (vedi "Aperti / da fare" qui sopra
  e la decisione count-only in "Decisioni di prodotto").

## Decisioni di prodotto (durevoli)

- `sysmda_markdown_supported_post_types` default **vuoto** → plugin **inattivo**
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
- **NIENTE auto-yield di `/llms.txt`** (deciso, non riproporre): il plugin non si
  disattiva MAI da solo, nemmeno come opzione. L'attivazione/disattivazione è sempre
  e solo una scelta manuale dell'utente dal pannello; se ci sono altri gestori attivi
  sotto, è responsabilità dell'utente. L'avviso di conflitto resta puramente informativo.
- **Description** front matter: Rank Math (`rank_math_description`) → scartata solo
  se contiene un placeholder `%variabile%` non risolto → fallback excerpt → testo
  troncato (~200 char). Front matter include `featured_image` (+ `featured_image_alt`).
- **NIENTE `Cache-Control` esplicito sulla risposta `.md`** (deciso, non
  riproporre): il plugin NON emette `Cache-Control`/`max-age`. Le richieste
  condizionali (`ETag`/`Last-Modified` → `304`) danno già una rivalidazione
  efficiente senza mai servire Markdown stantìo. Un `max-age` rischierebbe di
  entrare in conflitto con i plugin di page-cache/CDN e potrebbe continuare a
  servire una versione vecchia dopo una modifica; la policy di freshness spetta
  all'infrastruttura/CDN, non al plugin.
- **NIENTE sitemap XML per gli URL `.md`** (deciso, non riproporre): le risposte
  `.md` sono `noindex` per scelta, quindi elencarle in una sitemap manderebbe
  segnali contraddittori ai motori di ricerca (Search Console: "URL inviato ma
  contrassegnato noindex") — esattamente il rischio SEO che il plugin promette
  di non creare — e un secondo generatore di sitemap si sovrapporrebbe alle
  sitemap del plugin SEO (Rank Math & co.). La discovery per il pubblico reale
  (LLM/agenti) è già coperta dal link `rel="alternate"` e da `/llms.txt`. I
  segnali di freshness vanno dentro `/llms.txt` stesso (vedi la voce `lastmod`
  in "Aperti / da fare"): niente endpoint indice separato.
- **Il contatore accessi `.md` è count-only** (deciso): quando è attivo salva
  SOLO contatori giornalieri aggregati divisi bot/umano. MAI salvare indirizzi
  IP, stringhe user-agent grezze, timestamp più fini del giorno o qualsiasi
  identificativo per visitatore; lo user-agent viene letto dalla richiesta
  solo per classificare bot vs umano e subito scartato. Nessuna chiamata
  esterna, nessun cookie. Così il dato salvato resta anonimo (fuori dal
  perimetro GDPR, nessun consenso necessario) e dentro la linea guida
  wordpress.org "nessun tracking senza consenso".

## Identità, versioning, workflow

- **Author** del plugin = **"Diecieventi Digital Marketing"**. La ragione sociale
  legacy dell'autore **non deve MAI comparire** in artefatti (codice, commit, readme).
- **Casa GitHub**: account personale **`diecieventi`**
  (`github.com/diecieventi/system-markdown-alternate`); `Plugin URI` e
  `composer.json` puntano lì. `Author URI` → `webdietrolequinte.it` (dominio del sito,
  invariato).
- **wordpress.org**: `Contributors:` in `readme.txt` è impostato su **`system4pc`**
  (l'account esistente: lo username non è rinominabile, si può cambiare solo il Display
  Name). Resta l'opzione, se si preferisce, di pubblicare da un nuovo account
  `diecieventi` aggiornando il campo.
- Non inserire l'**ID del modello** in commit, readme, codice o altri artefatti.
- **Versionamento semver `0.x.y`**: minor per nuove feature, patch per fix. A ogni
  release: bump in `system-markdown-alternate.php` (header `Version:` **e**
  `SYSMDA_VERSION`), aggiorna `Stable tag` + changelog in `readme.txt`, `bash bin/build.sh`,
  commit, push.
- **Git — regola unica e inderogabile**: l'**unica destinazione del codice è `main`**.
  Unico sviluppatore, niente feature branch, **MAI** aprire PR (nemmeno su richiesta
  implicita), **MAI** lasciare il lavoro su un branch tecnico. Commit atomici. L'utente
  sincronizza il Mac manualmente con un solo `git pull origin main`: nessun altro passaggio,
  niente "push qui / merge là".

### Claude Code (web) — specifico

Nota valida solo per l'ambiente **Claude Code on the web** (gli altri agenti la
ignorano). Procedura fissa, **permesso permanente** dell'utente (non richiederlo
mai): l'harness obbliga a partire su un branch tecnico `claude/*`. Si committa lì
normalmente, poi **a fine lavoro si atterra solo su `main`**:

1. `git fetch origin main`
2. `git checkout main && git merge --ff-only origin/main` (allinea il main locale)
3. `git merge --ff-only <branch-tecnico>` per portare i commit su `main`
   (se il fast-forward non è possibile perché `main` è avanzato, fare `git rebase main`
   sul branch tecnico e ripetere il ff-merge — la storia resta lineare, **niente merge commit**)
4. `git push origin main`

Il branch tecnico è **solo lo staging imposto dall'ambiente**: non si pusha, non
genera PR, non va mergiato via interfaccia. Si ignora dopo il consolidamento.

## Compatibilità con plugin noti / ambiente di test

Sviluppato e testato contro uno stack basato su **GeneratePress/GenerateBlocks 2.x**,
**ACF** e **Rank Math**. Nei test via HTTP tenere presente che un **WAF/CDN** può
bloccare gli User-Agent non-browser (es. `curl` come "bad bot"): usare uno
User-Agent da browser.

**Ambiente di test**: sito di prova con GeneratePress/GenerateBlocks, ACF e
WooCommerce su WP recente / PHP 8.4, **senza object cache persistente** (Cache usa
il fallback transient). Lo zip completo non è installabile da remoto: la logica si
verifica con i **test PHP locali** (`tests/run-tests.php`) o eseguendo codice a
livello WP.

### Impatti sui default

- **Syntax highlighter** (es. Code Block Pro): NON convertire l'HTML di highlighting.
  Si fa strip degli `<span>` preservando la classe `language-*` e si lascia che il
  converter produca il fenced block (approccio generico, copre qualsiasi highlighter).
- **Table of Contents** (es. LuckyWP TOC): navigazione → esclusa (shortcode `lwptoc`,
  blocco `luckywp/toc`).
- **Lightbox su gallery/immagini**: solo wrapper sulle immagini; nessuna gestione
  speciale, basta preservare `alt`.
- **GenerateBlocks**: MAI esclusi in automatico (contengono contenuto reale).
- **ACF**: implementato (sottotitolo/TL;DR via preambolo). I filtri
  `sysmda_markdown_source_content` / `sysmda_acf_field_keys` restano i punti di estensione.
- **Motori di ricerca on-site** (es. Algolia): irrilevanti per l'output.
- **Page cache LiteSpeed**: comportamento variabile da server a server — alcune
  installazioni onorano `Vary: Accept`, altre usano il solo URL come chiave e
  mescolano le rappresentazioni. Gestito da `LiteSpeedCompat` (vedi "Stato
  attuale"): segnali no-cache sulle risposte negoziate sempre attivi, regole
  `.htaccess` di bypass opt-in dal pannello.

## Struttura repository

```
.
├── AGENTS.md                     ← guida agnostica (inglese, fonte di verità)
├── AGENTS.it.md                  ← questo file (traduzione italiana)
├── CLAUDE.md                     ← symlink → AGENTS.md
├── README.md                     ← panoramica repo (GitHub, inglese)
├── README.it.md                  ← traduzione italiana del README
├── LICENSE                       ← GPL-2.0 (testo completo)
├── .gitignore
├── .github/workflows/ci.yml      ← CI: php -l + test su PHP 7.4/8.4
├── .github/workflows/deploy-wordpress-org.yml  ← deploy SVN (pronto, non attivo: servono i secret SVN + una Release pubblicata)
├── .wordpress-org/               ← assets scheda wordpress.org (icona, banner)
├── bin/build.sh                  ← genera DIST/system-markdown-alternate.zip
├── DIST/                         ← zip distribuibile (versionato)
└── system-markdown-alternate/    ← IL PLUGIN
    ├── system-markdown-alternate.php   ← header + bootstrap (autoloader Composer)
    ├── readme.txt                      ← formato wordpress.org + changelog
    ├── uninstall.php                   ← cleanup opzioni + transient
    ├── .distignore                     ← esclusioni per il pacchetto WP.org (SVN)
    ├── composer.json / composer.lock   ← league/html-to-markdown + PSR-4
    ├── vendor/                         ← NON versionato, solo nello zip
    ├── assets/admin-settings.css       ← stile pannello (caricato solo lì)
    ├── assets/admin-settings.js         ← tab client-side (vanilla, progressive enhancement)
    ├── tests/run-tests.php             ← test della logica pura (php tests/run-tests.php, no WP/PHPUnit)
    └── src/
        ├── Plugin.php              ← bootstrap, registra hook e dipendenze
        ├── MarkdownController.php  ← intercetta .md + content negotiation (Vary/q-values/406), validazione, header, cache, output, alternate link, invalidazione
        ├── AcceptNegotiator.php    ← parser header Accept con q-values (no deps WP)
        ├── ContentRenderer.php     ← sorgente → HTML pulito (shortcode/blocchi/DOM/URL assoluti); render_fragment()
        ├── BlockCleaner.php        ← parse/pulizia blocchi Gutenberg (espande i synced pattern)
        ├── PostSupport.php         ← eleggibilità post (is_servable, tipi supportati)
        ├── ShortcodeCleaner.php    ← rimozione shortcode esclusi
        ├── MetadataBuilder.php     ← front matter YAML; markdown_url() (static)
        ├── MarkdownConverter.php   ← HTML → Markdown (league/html-to-markdown)
        ├── AcfIntegration.php      ← sottotitolo + TL;DR (preambolo)
        ├── LlmsTxtController.php   ← endpoint /llms.txt (cachato)
        ├── AdminSettings.php       ← pannello impostazioni (Settings API)
        ├── ConflictDetector.php    ← rilevamento conflitti /llms.txt (solo locale)
        ├── LiteSpeedCompat.php     ← compatibilità page cache LiteSpeed (segnali no-cache + regole .htaccess opzionali)
        ├── Shortcodes.php          ← [sysmda_md_url]
        ├── DynamicTags.php         ← {{sysmda_md_url}} (GenerateBlocks 2.x)
        └── Cache.php               ← helper cache (object cache o transient)
```

- **Namespace PHP:** `Diecieventi\SystemMarkdownAlternate` (PSR-4 → `src/`).
- **Prefisso costanti/hook/option:** `sysmda_` / `SYSMDA_` (≥ 4 caratteri e
  distintivo, come richiede la linea guida wordpress.org sui prefissi; usato
  anche col trattino per slug/handle: `sysmda-settings`, `sysmda-admin-settings`).

## Convenzioni di codice

- PHP `>= 7.4`, WP `>= 6.1`. Niente dipendenze runtime oltre a `league/html-to-markdown`.
- Classi piccole e a singola responsabilità.
- `defined('ABSPATH') || exit;` in cima a ogni file PHP.
- Escaping rigoroso dell'output (specie il **front matter YAML**: quotare stringhe,
  escape di `"` e `\`).
- Tutti i filtri vanno **documentati con docblock**.
- Dopo modifiche: `php -l` sui file toccati e `php system-markdown-alternate/tests/run-tests.php`
  (test della logica pura, senza WP; la CI li esegue su PHP 7.4 e 8.4).

## Filtri (contratto pubblico)

```php
apply_filters( 'sysmda_markdown_supported_post_types', array() );             // [] = plugin inattivo finché non si seleziona un tipo
apply_filters( 'sysmda_markdown_robots_header', 'noindex, follow', $post );   // '' = non inviare header
apply_filters( 'sysmda_markdown_strict_406', true );                          // false = niente 406, serve sempre l'HTML di default
apply_filters( 'sysmda_markdown_canonical_url', get_permalink( $post ), $post ); // '' = non inviare Link rel=canonical
apply_filters( 'sysmda_markdown_cache_ttl', DAY_IN_SECONDS, $post );          // 0 = cache disabilitata
apply_filters( 'sysmda_markdown_source_content', $post->post_content, $post );
apply_filters( 'sysmda_markdown_rendered_html', $html, $post );
apply_filters( 'sysmda_markdown_preamble', '', $post );                       // blocco tra # Titolo e corpo (sottotitolo/TL;DR)
apply_filters( 'sysmda_markdown_output', $markdown, $post );
apply_filters( 'sysmda_markdown_excluded_block_names', $block_names );
apply_filters( 'sysmda_markdown_excluded_shortcodes', $shortcodes );
apply_filters( 'sysmda_markdown_excluded_classes', $css_classes );
apply_filters( 'sysmda_acf_field_keys', array(), $post );                     // campi ACF accodati al sorgente
apply_filters( 'sysmda_acf_subtitle_key', '', $post );                       // campo ACF sottotitolo ('' = off)
apply_filters( 'sysmda_acf_tldr_key', '', $post );                          // campo ACF TL;DR ('' = off)
apply_filters( 'sysmda_llms_txt_max_posts', 500, $post_type );              // max post per tipo in /llms.txt
apply_filters( 'sysmda_llms_txt_cache_ttl', DAY_IN_SECONDS );               // TTL cache /llms.txt (0 = off)
apply_filters( 'sysmda_llms_txt_enriched', false );                         // true = output /llms.txt arricchito
apply_filters( 'sysmda_llms_txt_lastmod', false );                          // true = aggiunge (updated: YYYY-MM-DD) a ogni voce
apply_filters( 'sysmda_llms_txt_summary', '' );                             // sintesi del sito (solo arricchito)
apply_filters( 'sysmda_llms_txt_key_content', array() );                    // contenuti in evidenza: ID o URL (solo arricchito)
apply_filters( 'sysmda_llms_txt_main_posts', 25, $post_type );              // post per tipo nella sezione principale (solo arricchito)
apply_filters( 'sysmda_llms_txt_footer', '' );                              // blocco libero in coda (solo arricchito)
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
2. **Content negotiation**: oltre al suffisso `.md`, sul permalink canonico si decide
   la rappresentazione con `AcceptNegotiator` (RFC 9110). Markdown solo se preferito
   esplicitamente: `?format=markdown` oppure `text/markdown` con q ≥ a quello effettivo
   di `text/html` (match esatto > `text/*` > wildcard totale). Un Accept wildcard o
   assente → HTML (così `Accept: */*` di curl/librerie resta HTML). Ogni contenuto
   servibile dichiara **`Vary: Accept`** (sia rispondendo Markdown sia lasciando l'HTML
   a WP), così cache/CDN non mischiano le due rappresentazioni. Se l'Accept non accetta
   né HTML né Markdown si risponde **`406`** (filtro `sysmda_markdown_strict_406`, default
   on; i client reali mandano sempre `text/html` o un wildcard, mai colpiti). Il suffisso
   `.md` ignora invece l'Accept (l'URL è già la richiesta esplicita di Markdown).
3. **Esclusione classi**: oltre ad `attrs.className`, passaggio su `DOMDocument`
   sull'HTML renderizzato per togliere elementi annidati con le classi escluse.
4. **Rendering**: `render_block()` sui blocchi ripuliti (non `the_content` completo),
   per non reintrodurre related/CTA iniettati.
5. **URL assoluti**: risolti contro il permalink del post (non `home_url('/')`).
6. **Cache**: chiave `sysmda_md_{post_id}`, valore con hash di validità
   (`post_modified_gmt|SYSMDA_VERSION|salt`); `/llms.txt` cachato in `sysmda_llms_txt`.
   Tutto via `Cache` helper (object cache persistente o transient). Lo **stesso
   hash è l'`ETag` forte** della risposta `.md` (`ETag`/`Last-Modified` + `304`
   condizionale, `If-None-Match` prima di `If-Modified-Since`); deriva da
   `post_modified`, quindi le richieste condizionali funzionano anche con la cache
   del body spenta.
7. **i18n**: l'**inglese** è la lingua sorgente per stringhe runtime, commenti
   del codice, DocBlock, test, strumenti di build e messaggi dei workflow. Gli
   unici documenti intenzionalmente italiani nel repository sono `AGENTS.it.md`
   e `README.it.md`. Le stringhe con HTML inline (`<code>`, `<strong>`, …) escono
   via `wp_kses_post()`. Text domain `system-markdown-alternate` (= slug del
   plugin, richiesto da wordpress.org). **Nel plugin e nel repository non devono
   esserci cataloghi di traduzione né loader manuali**: WordPress carica
   automaticamente i language pack generati da translate.wordpress.org. Le
   traduzioni si gestiscono lì una volta che il plugin è live (vedi "Aperti / da
   fare"). Le installazioni dallo zip GitHub restano in inglese finché non è
   disponibile un language pack ufficiale.

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

### Pubblicazione su wordpress.org (SVN)

Su WP.org si **deploya**, non si sviluppa: il repo GitHub resta la casa dello
sviluppo, l'SVN è solo distribuzione. Ciò che va nell'SVN è **il contenuto della
cartella `system-markdown-alternate/`** (non la root del repo: niente `README.md`,
`AGENTS.md`, `bin/`, `DIST/`, `.github/`), con **`vendor/` bundlato** (dipendenza
di runtime). Le esclusioni interne alla cartella plugin sono in
`system-markdown-alternate/.distignore` (`tests/`, `composer.lock`). Il pacchetto
di produzione mantiene intenzionalmente `composer.json` insieme a `vendor/`,
come richiesto da WordPress.org Plugin Check per verificare le dipendenze.

- Flusso manuale: `bash bin/build.sh`, poi copiare il contenuto in `svn/trunk` e
  taggare in `svn/tags/x.y.z`.
- **Flusso automatico** (pronto, non ancora attivo): `.github/workflows/deploy-wordpress-org.yml`
  esegue `10up/action-wordpress-plugin-deploy`, con trigger sulla
  **pubblicazione di una Release GitHub** (non sul semplice push di un tag,
  per evitare un'esecuzione senza credenziali SVN). Siccome `BUILD_DIR` ignora
  `.distignore`, il workflow stagea prima una copia pulita di
  `system-markdown-alternate/` (stesse esclusioni del `.distignore`) e la passa
  all'action. `VERSION` deriva dal nome del tag (`v0.18.0` → `0.18.0`).
  **Attivazione, una volta accettati su wordpress.org**: aggiungere i secret di
  repository `SVN_USERNAME` / `SVN_PASSWORD`, poi pubblicare una Release GitHub
  sul tag della versione.
- **Tag Git**: annotati, `vX.Y.Z` sul commit che fa il bump di versione (es.
  `v0.18.0`); aggiunti retroattivamente da `v0.17.1` in poi. Non servono per lo
  sviluppo locale — solo per le release SVN e per fissare una versione precisa
  su GitHub.
  Banner/icona/screenshot vivono nella
  `/assets` dell'SVN (non nel plugin) e si aggiornano con
  `10up/action-wordpress-plugin-asset-update` dalla cartella `.wordpress-org/`
  del repo.

## Test (acceptance)

Articoli di test:
1. Articolo semplice (heading, paragrafi, lista, link) → `.md` ok, header corretti, front matter, link alternate.
2. Articolo con immagini + codice (con syntax highlighter) + blockquote → conversione corretta.
3. Articolo con sezione `md-exclude` → assente nel `.md`.
4. Articolo con shortcode form (`[contact-form-7 ...]`) e TOC (`[lwptoc]`) → assenti nel `.md`.
5. Contenuti non ammessi (pagina/CPT non abilitato, bozza, post protetto da password) → **404**.

Verificare sempre: `Content-Type: text/markdown; charset=utf-8`,
`X-Robots-Tag: noindex, follow`; nessun contenuto privato/bozza/non-abilitato esposto.
Nota: i test HTTP dalla riga di comando possono essere bloccati da un WAF/CDN
(usare un User-Agent da browser).
