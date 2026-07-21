# Piano: traduzioni in `/llms.txt` (sezione dedicata)

## Context

Oggi `LlmsTxtController` elenca i contenuti con una singola `get_posts()`
(`src/LlmsTxtController.php:166`) senza override di lingua. Con WPML o Polylang
attivi quella query è filtrata per la lingua della richiesta, e `/llms.txt` (che
non ha prefisso di lingua) gira nella lingua di default: l'indice mostra **solo
la lingua di default** e le traduzioni non compaiono da nessuna parte.

Obiettivo (scope ridotto, deciso con l'utente):

- **Un solo `/llms.txt`, esattamente com'è ora.** Nessun endpoint per-lingua
  (`/it/llms.txt`, `/en/llms.txt` — abbandonati), nessuna modifica al routing,
  nessuna gestione della modalità directory.
- Se sul sito ci sono altre lingue, **elencare le traduzioni in una sezione
  dedicata** (`## Translations`) in fondo al file.
- Le sezioni principali continuano a dipendere dai **CPT attivati nel pannello**
  (`PostSupport::supported_post_types()`): `/llms.txt` può quindi elencare solo
  articoli, o solo pagine, o altro, a seconda della configurazione. La sezione
  traduzioni rispetta gli stessi tipi.

Per ora basta questo: se ci sono traduzioni dei contenuti già elencati, listarle.

## Comportamento atteso

- **Sito monolingua** (o nessun plugin multilingua): output **identico a oggi**.
- **Sito multilingua** (Polylang o WPML, >1 lingua) con almeno una traduzione dei
  contenuti elencati: in coda al file compare:

  ```
  ## Translations

  ### English
  - [Post title (EN)](https://sito.it/en/post.md)
  - [Page title (EN)](https://sito.it/en/page.md)

  ### Deutsch
  - [Titel (DE)](https://sito.it/de/titel.md)
  ```

  Raggruppato per lingua (heading `###` col nome nativo), voci nello stesso
  formato del resto del file (`- [titolo](url.md)`). `## Translations` è un
  heading libero in inglese, come `Optional`/`updated:` (stessa convenzione di
  non-traduzione già in uso). Lingue senza traduzioni servibili → saltate;
  nessuna traduzione in nessuna lingua → **la sezione non viene emessa**.

## Componenti

### 1. Nuova classe `MultilingualAdapter` (`src/MultilingualAdapter.php`)

Astrazione minima su Polylang/WPML, stessa filosofia di `ConflictDetector` (solo
segnali locali stabili — costanti/classi/funzioni del plugin, nessuna chiamata di
rete). Metodi:

- `is_active(): bool`
  - Polylang: `function_exists('pll_get_post_translations')` o `defined('POLYLANG_VERSION')`.
  - WPML: `defined('ICL_SITEPRESS_VERSION')` o `class_exists('SitePress')`.
- `languages(): string[]` — slug di tutte le lingue attive (es. `['it','en','de']`).
  - Polylang: `pll_languages_list()`.
  - WPML: `array_keys((array) apply_filters('wpml_active_languages', null))`.
- `default_language(): string`
  - Polylang: `pll_default_language()`. WPML: `apply_filters('wpml_default_language', null)`.
- `language_name(string $lang): string` — nome nativo per l'heading `###`.
  - Polylang: mappa da `pll_languages_list(['fields' => 'name'])`.
  - WPML: `icl_get_languages('skip_missing=0')[$lang]['native_name']`.
- `translations(int $post_id, string $post_type): array<string,int>` — mappa
  `lang => post_id` delle traduzioni del post (inclusa la propria lingua; il
  chiamante esclude quella della voce principale).
  - Polylang: `pll_get_post_translations($post_id)`.
  - WPML: per ogni lingua, `apply_filters('wpml_object_id', $post_id, $post_type, false, $lang)`.

> Nota: nessun rilevamento della modalità URL. `MetadataBuilder::markdown_url()`
> (`src/MetadataBuilder.php:61`) usa `get_permalink()`, che restituisce già
> l'URL corretto del post tradotto in **qualsiasi** modalità (directory,
> sottodominio, dominio) → i link `.md` delle traduzioni sono automaticamente
> giusti, senza logica di routing.

### 2. Sezione traduzioni in `LlmsTxtController::build()` (`src/LlmsTxtController.php:114`)

- Il loop principale resta invariato. Durante il loop, **raccogliere gli ID (e il
  post_type) dei post già elencati**, per riusarli senza query aggiuntive.
- Dopo il loop principale (e dopo `Optional`/`footer`), se
  `adapter->is_active() && count(languages) > 1`:
  1. Per ogni post elencato, `adapter->translations($id, $post_type)`.
  2. Raggruppare per lingua, **escludendo la lingua di default** (già nel corpo
     principale) e i post non servibili (`PostSupport::is_servable()` — published,
     non password-protected: stessa regola dell'endpoint `.md`). Deduplicare per ID.
  3. Emettere `## Translations`, poi un `### <nome lingua>` per ogni lingua con
     almeno una traduzione, con le voci `item_line()` (riuso di
     `MetadataBuilder::markdown_url()` e `escape_link_text()`).
- Se la mappa risultante è vuota → non emettere nulla (gate finale).
- `enriched`/`lastmod` si applicano anche qui riusando `item_line($post, $enriched, $with_lastmod)`.

### 3. Cache e invalidazione — **nessuna modifica**

- File unico → chiave cache unica `sysmda_llms_txt` (invariata).
- Invalidazione `MarkdownController::invalidate_cache()` (`src/MarkdownController.php:118-119`)
  già cancella `LlmsTxtController::CACHE_KEY`: quando si salva/aggiorna una
  traduzione scatta `save_post` → cache azzerata. Nessun `flush_cache`, nessuna
  chiave per-lingua.

### 4. Registrazione / gating (`src/Plugin.php:42`)

- Iniettare l'adapter: `new LlmsTxtController($metadata, new MultilingualAdapter())`.
- La sezione compare solo con plugin multilingua attivo, >1 lingua e ≥1 traduzione
  servibile; altrimenti output identico a oggi.

### 5. Nota informativa nel pannello admin (opzionale, leggera)

In `AdminSettings.php`, aside della sezione llms.txt: quando l'adapter è attivo
con >1 lingua, "Multilingual detected: translations are listed in `/llms.txt`."

## File toccati

- **Nuovo**: `system-markdown-alternate/src/MultilingualAdapter.php`
- `system-markdown-alternate/src/LlmsTxtController.php` — raccolta ID elencati +
  sezione `## Translations`.
- `system-markdown-alternate/src/Plugin.php` — iniezione adapter.
- `system-markdown-alternate/src/AdminSettings.php` — nota informativa (opzionale).
- `AGENTS.md` / `README.md` — aggiornare il bullet llms.txt. Se si aggiunge un
  filtro pubblico (es. `sysmda_llms_txt_translations` per on/off, default on
  quando multilingua è presente), documentarlo in "Filters (public contract)".
- `system-markdown-alternate/tests/run-tests.php` — vedi sotto.

> Nessuna modifica a `MarkdownController.php` (cache invariata) né a
> `uninstall.php` (nessuna opzione/chiave nuova).

## Test

Gran parte della logica dipende dalle API runtime di Polylang/WPML e non è
copribile con l'attuale harness pure-logic. Testabile senza WP:

- il raggruppamento/dedup/esclusione-default della sezione traduzioni, se
  estratto in un helper statico puro tipo
  `group_translations(array $listed, array $translations_by_id, string $default): array`
  (mappa `lang => [post_id...]`). Aggiungere i relativi test in
  `tests/run-tests.php`.

Il resto va validato su staging.

## Limitazioni note (da documentare)

- La sezione elenca le traduzioni dei **soli contenuti già presenti** nell'indice
  principale (che dipende dai CPT attivi e dai limiti `sysmda_llms_txt_max_posts`).
  Non è un crawl indipendente di tutte le traduzioni del sito.
- `sysmda_llms_txt_key_content` resta config globale (non per-lingua).

## Verifica

**Locale** (prima del commit):
- `php system-markdown-alternate/tests/run-tests.php` — passano i test dell'helper
  di raggruppamento.
- `php -l` sui file modificati.

**Staging** (manuale — lo stack ufficiale NON ha WPML/Polylang): un sito Polylang
**e** uno WPML, ≥2 lingue. User-Agent browser (il WAF può bloccare curl).
- `GET /llms.txt` → corpo invariato (lingua default) + `## Translations` con
  sotto-sezioni per lingua e link `.md` ai permalink tradotti corretti.
- Post con traduzione mancante in una lingua → assente da quella sotto-sezione.
- Traduzione in bozza/password-protected → esclusa.
- Sito monolingua / plugin multilingua assente → output identico a oggi.
- Modifica/aggiunta di una traduzione → `save_post` azzera la cache, il fetch
  successivo la riflette.

## Ordine di esecuzione suggerito

1. `MultilingualAdapter` (detection + `languages`/`default_language`/`language_name`/`translations`).
2. `group_translations()` statico + test.
3. Sezione `## Translations` in `build()` + iniezione adapter in `Plugin.php`.
4. Nota admin (opzionale) + docs.
5. `php -l` + test locali; poi validazione staging.
