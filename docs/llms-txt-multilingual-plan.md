# Piano: `/llms.txt` multilingua (fase 1 — solo modalità directory)

## Context

Oggi `LlmsTxtController` elenca i contenuti con una singola `get_posts()`
(`src/LlmsTxtController.php:166`) senza alcun override di lingua. Con WPML o
Polylang attivi quella query viene filtrata per la lingua del contesto della
richiesta, e `/llms.txt` (che non ha prefisso di lingua) gira nella lingua di
default: **l'indice copre una sola lingua**, non tutto il sito.

Obiettivo: quando è attivo un plugin multilingua (Polylang **o** WPML) **in
modalità directory** (`sito.it/it/…`, `sito.it/en/…`) **con più di una lingua**,
servire:

```
/llms.txt        → indice della lingua di default + sezione "## Translations" con i link
/it/llms.txt     → contenuti italiani
/en/llms.txt     → contenuti inglesi
/de/llms.txt     → contenuti tedeschi
```

**Gate stretto**: se non c'è un plugin multilingua, o la modalità URL non è
directory, o c'è una sola lingua → **comportamento identico a oggi** (`/llms.txt`
singolo). Coerente con la convenzione del plugin "feature off = output invariato"
(come `enriched`/`lastmod`).

Esito atteso: nessuna regressione sui siti monolingua; sui siti multilingua in
directory mode, un indice per-lingua + un root che resta utile da solo.

## Scope boundaries (decisi con l'utente)

- **Solo modalità directory.** Sottodominio/dominio separato: NON si servono
  `/{lang}/llms.txt` — ogni host ha già il suo `/llms.txt` nella propria lingua
  via il contesto `home_url()` esistente. Solo da documentare, nessun codice.
- **Root = indice lingua default + riferimenti alle traduzioni**, non un puro
  switchboard: `/llms.txt` mantiene contenuto reale (no regressione per i crawler
  che leggono solo il root) e aggiunge una sezione `## Translations`.
- Copertura: **Polylang + WPML** tramite un adapter.

## Componenti

### 1. Nuova classe `MultilingualAdapter` (`src/MultilingualAdapter.php`)

Astrazione su Polylang/WPML, stessa filosofia di `ConflictDetector` (solo segnali
locali stabili — costanti/classi/opzioni del plugin, nessuna chiamata di rete).
Metodi:

- `is_active(): bool`
  - Polylang: `function_exists('pll_languages_list')` o `defined('POLYLANG_VERSION')`.
  - WPML: `defined('ICL_SITEPRESS_VERSION')` o `class_exists('SitePress')`.
- `is_directory_mode(): bool`
  - Polylang: `get_option('polylang')['force_lang'] === 1` (1 = directory;
    2 = sottodominio, 3 = dominio, 0 = nessuna modifica URL). In alternativa
    `is_a(PLL()->links_model, 'PLL_Links_Directory')`.
  - WPML: `(int) apply_filters('wpml_setting', 0, 'language_negotiation_type') === 1`
    (1 = directory; 2 = dominio/sottodominio, 3 = parametro).
- `languages(): string[]` — slug lingua (es. `['it','en','de']`).
  - Polylang: `pll_languages_list()`.
  - WPML: `array_keys((array) apply_filters('wpml_active_languages', null))`.
- `default_language(): string` — Polylang `pll_default_language()`;
  WPML `apply_filters('wpml_default_language', null)`.
- `language_name(string $lang): string` — nome nativo per la sezione Translations
  (Polylang `pll_languages_list(['fields'=>'name'])` mappato per slug; WPML
  `icl_get_languages('skip_missing=0')[$lang]['native_name']`).
- `llms_txt_url(string $lang): string` — URL assoluto del file per-lingua.
  Costruito dall'home URL della lingua per rispettare l'opzione "nascondi
  prefisso lingua default": Polylang `rtrim(pll_home_url($lang),'/').'/llms.txt'`;
  WPML equivalente via `apply_filters('wpml_permalink', home_url('/'), $lang)`.
- `build_for_language(string $lang, callable $fn)` — esegue `$fn` (che fa le
  `get_posts` e costruisce l'indice) nel contesto lingua corretto:
  - **WPML**: `do_action('wpml_switch_language', $lang)` → `$fn()` →
    `do_action('wpml_switch_language', $restore)` (switch/restore).
  - **Polylang**: non serve switch globale — Polylang onora il query var `lang`
    per singola query. L'adapter espone anche `post_query_args(string $lang): array`
    che ritorna `['lang' => $lang]` (Polylang) o `[]` (WPML), da mergiare negli
    argomenti di `get_posts`. `build_for_language` per Polylang è quindi un
    passthrough che esegue `$fn()`.

> Nota: `MetadataBuilder::markdown_url()` (`src/MetadataBuilder.php:61`) usa
> `get_permalink()`, che restituisce già il permalink tradotto per il post
> corretto → i link `.md` di ogni indice per-lingua sono automaticamente giusti.
> Nessuna modifica lì.

### 2. Routing in `LlmsTxtController::maybe_render_llms_txt()` (`src/LlmsTxtController.php:43`)

- Nuovo **helper statico puro** (unità testabile senza WP):
  `resolve_llms_target(string $path, string $home_path, array $languages): array`
  → `['mode' => 'index', 'lang' => null]` (root),
    `['mode' => 'language', 'lang' => 'it']`,
    o `['mode' => 'none']` (non è un endpoint llms.txt).
  Gestisce: prefisso `$home_path` (install in sottocartella), suffisso
  `/llms.txt`, segmento intermedio che deve combaciare con uno slug lingua noto,
  trailing slash.
- Il gate multilingua costruisce `$languages` solo se
  `adapter->is_active() && adapter->is_directory_mode() && count(languages) > 1`;
  altrimenti `$languages = []` → il resolver riconosce solo il root `/llms.txt`
  (comportamento odierno).
- Redirect trailing-slash già presente: estenderlo per `/{lang}/llms.txt/`.

### 3. Build output — refactor `build()` (`src/LlmsTxtController.php:114`)

- Estrarre l'attuale loop per post-type in `build_index(?string $lang): string`:
  - `$lang === null` → identico a oggi.
  - `$lang` valorizzato → esegue le `get_posts` dentro
    `adapter->build_for_language($lang, …)` + merge di `post_query_args($lang)`
    negli argomenti (riga `src/LlmsTxtController.php:166`). Tutto il resto
    (`enriched`, `lastmod`, `Key content`, `Optional`, `footer`) resta invariato,
    gira solo nel contesto lingua.
- Root con multilingua attivo: `build_index(default_language())` +
  append di una sezione:
  ```
  ## Translations

  - [Italiano](https://sito.it/it/llms.txt)
  - [English](https://sito.it/en/llms.txt)
  - [Deutsch](https://sito.it/de/llms.txt)
  ```
  ("Translations" è un heading libero, in inglese come `Optional`/`updated:` —
  stessa convenzione di non-traduzione già in uso).

### 4. Cache per-lingua

- Helper `cache_key(?string $lang)`: root/default → `sysmda_llms_txt` (invariato,
  preserva la cache esistente); lingua → `sysmda_llms_txt_{lang}`.
- `render()` (`src/LlmsTxtController.php:76`) legge/scrive la chiave risolta.
  Il version-hash (`SYSMDA_VERSION|cache_salt`) resta e continua a invalidare
  tutte le varianti al salvataggio impostazioni (salt bump).
- **Invalidazione su save_post**: `MarkdownController::invalidate_cache()`
  (`src/MarkdownController.php:118-119`) oggi cancella solo `CACHE_KEY`. Aggiungere
  un metodo statico `LlmsTxtController::flush_cache()` che cancella la chiave base
  **e** ogni `sysmda_llms_txt_{lang}` (quando l'adapter è attivo, itera
  `languages()`), e chiamarlo da `invalidate_cache()`. Non sappiamo a basso costo
  quale lingua è cambiata → cancelliamo tutte le varianti.

### 5. Registrazione / gating (`src/Plugin.php:42`)

- Iniettare l'adapter: `new LlmsTxtController($metadata, new MultilingualAdapter())`.
- Tutto il comportamento nuovo dietro il gate del punto 2; senza gate la classe
  si comporta esattamente come oggi.

### 6. Nota informativa nel pannello admin (opzionale, leggera)

In `AdminSettings.php`, nell'aside della sezione llms.txt (riusa il pattern
esistente dello status/conflict aside):
- multilingua attivo + directory + >1 lingua → "Multilingual detected: serving
  `/llms.txt` per language (`/it/llms.txt`, …)".
- multilingua attivo ma NON directory → nota che l'indice per-lingua è servito
  solo in modalità directory (in sottodominio/dominio ogni host ha già il suo
  `/llms.txt`).

## File toccati

- **Nuovo**: `system-markdown-alternate/src/MultilingualAdapter.php`
- `system-markdown-alternate/src/LlmsTxtController.php` — routing, resolver
  statico, `build_index(?lang)`, sezione Translations, cache per-lingua,
  `flush_cache()`.
- `system-markdown-alternate/src/Plugin.php` — iniezione adapter.
- `system-markdown-alternate/src/MarkdownController.php` — invalidazione via
  `LlmsTxtController::flush_cache()`.
- `system-markdown-alternate/src/AdminSettings.php` — nota informativa (opzionale).
- `system-markdown-alternate/tests/run-tests.php` — test del resolver + stub.
- `AGENTS.md` / `README.md` — aggiornare il bullet llms.txt e "Product decisions"
  (scope directory-only + gate stretto). Se si aggiunge un filtro pubblico
  (es. `sysmda_llms_txt_languages` per override lista, o
  `sysmda_llms_txt_multilingual` per forzare on/off), documentarlo in "Filters".
- `system-markdown-alternate/uninstall.php` — verificare che la pulizia transient
  `sysmda_*` copra già le chiavi `sysmda_llms_txt_{lang}` (probabile wildcard);
  in caso, nessuna modifica.

## Limitazioni note (da documentare, non bloccanti)

- `sysmda_llms_txt_key_content` è config globale (ID/URL), non per-lingua: gli
  stessi elementi comparirebbero in ogni lingua salvo che l'utente li filtri.
- Solo directory mode in questa fase (per scelta).

## Verifica

**Locale** (obbligatoria prima del commit):
- `php system-markdown-alternate/tests/run-tests.php` — i nuovi test di
  `resolve_llms_target()` passano (root, lingua valida, lingua sconosciuta,
  trailing slash, install in sottocartella, path non-llms).
- `php -l` sui file modificati.

**Staging** (manuale, richiesta — lo stack ufficiale NON ha WPML/Polylang):
serve un sito Polylang **e** uno WPML in **modalità directory**, ≥2 lingue.
Usare uno User-Agent browser (il WAF può bloccare curl).
- `GET /llms.txt` → indice lingua default + sezione `## Translations` con i link.
- `GET /it/llms.txt`, `/en/llms.txt` → contenuti della lingua giusta, link `.md`
  ai permalink tradotti corretti.
- `GET /xx/llms.txt` (lingua inesistente) → fall-through (404/WP normale).
- Passare il plugin a modalità sottodominio → i path per-lingua non rispondono,
  `/llms.txt` invariato.
- Sito monolingua → output identico a oggi.
- Modifica di un post → `flush_cache()` azzera tutte le varianti (il fetch
  successivo riflette la modifica).

## Ordine di esecuzione suggerito

1. `MultilingualAdapter` + `resolve_llms_target()` (statico) + relativi test.
2. Refactor `build_index(?lang)` + routing + sezione Translations.
3. Cache per-lingua + `flush_cache()` + hook invalidazione.
4. Nota admin (opzionale) + docs.
5. `php -l` + test locali; poi validazione staging.
