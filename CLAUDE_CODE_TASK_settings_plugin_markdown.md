# TASK PER CLAUDE CODE — Refactor pagina settings plugin WordPress Markdown

Devi riorganizzare la pagina settings backend del plugin WordPress che genera versioni `.md` dei contenuti e gestisce `/llms.txt`.

Obiettivo: migliorare UI e struttura della pagina impostazioni, senza cambiare il comportamento core del plugin.

Non implementare traduzioni/i18n ora. I testi possono restare in italiano semplice. Le traduzioni verranno fatte dopo.

## Regole fondamentali

- Non rompere le opzioni già salvate.
- Non rinominare option key esistenti se non strettamente necessario.
- Non modificare la logica di generazione Markdown.
- Non modificare endpoint `.md`, endpoint `/llms.txt`, shortcode o dynamic tag GenerateBlocks, salvo bug evidente.
- Non introdurre React, Vue, build process o librerie esterne.
- Usare PHP admin WordPress classico.
- Mantenere Settings API / Options API se già usate.
- Se aggiungi CSS admin, caricalo solo nella pagina settings del plugin.
- Evita refactor architetturale aggressivo: prima rendi la UI più ordinata e sicura.

## Prima cosa da fare

Analizza il codice attuale e individua:

- file principale del plugin;
- classi o funzioni admin/settings;
- nome della option salvata;
- struttura array delle opzioni;
- callback di sanitizzazione;
- callback di rendering dei campi;
- shortcode `[sma_md_url]`;
- dynamic tag `{{sma_md_url}}`;
- gestione endpoint `/llms.txt`;
- gestione cache;
- gestione header `X-Robots-Tag`;
- gestione post type supportati;
- eventuali controlli per ACF e GenerateBlocks.

Poi procedi mantenendo la struttura esistente dove possibile.

## Nuova UI richiesta

Trasforma la pagina settings in una pagina con tab WordPress classici.

Tab richiesti:

1. Generale
2. Output Markdown
3. llms.txt
4. Integrazioni
5. Avanzate

Usa una whitelist per validare il tab attivo:

- `general`
- `markdown`
- `llms`
- `integrations`
- `advanced`

Il tab attivo deve arrivare da `$_GET['tab']`, ma va sanitizzato con `sanitize_key()` e validato. Se non valido, fallback su `general`.

## Tab 1 — Generale

Qui devono stare le impostazioni principali del plugin.

Campi:

- Supported post types
- Cache TTL

Layout desiderato:

Generale

Tipi di contenuto abilitati:
- Articolo (`post`)
- Pagina (`page`)

Cache:
- Cache TTL in secondi
- Default: `86400` secondi, cioè 24 ore
- `0` = cache disabilitata

Nota importante: se nel database è già salvato un valore diverso, non sovrascriverlo automaticamente. Però verifica che il default del plugin sia davvero `86400`.

## Tab 2 — Output Markdown

Qui devono stare tutte le impostazioni che decidono cosa entra o non entra nel file `.md`.

Campi:

- Excluded shortcodes
- Excluded block names
- Excluded CSS classes
- Campo ACF sottotitolo
- Campo ACF TL;DR

Le tre textarea delle esclusioni non devono essere enormi. Rendile più compatte.

Per le esclusioni, il testo di aiuto deve essere coerente:

“Uno per riga. Lascia vuoto per usare i default interni.”

Se mostri i default, mostrali uno per riga, non separati da virgole.

Esempio:

Default:
contact-form-7
gravityform
wpforms
mailerlite_form
lwptoc

Per ACF:

- campo sottotitolo: nome campo ACF usato per il sottotitolo, inserito dopo il titolo H1;
- campo TL;DR: nome campo ACF usato per il TL;DR, inserito come sezione dedicata nel Markdown.

Se ACF non è attivo, non causare fatal error. Mostra solo un avviso tipo:

“ACF non rilevato: queste impostazioni resteranno inattive finché ACF non sarà attivo.”

Non bloccare il salvataggio.

## Tab 3 — llms.txt

Qui deve stare la gestione di `/llms.txt`.

Campi:

- checkbox abilita endpoint `/llms.txt`

Mostra anche uno status semplice:

- `/llms.txt` abilitato nelle impostazioni: sì/no
- URL endpoint: `home_url('/llms.txt')`

Testo consigliato:

“Il file /llms.txt espone risorse selezionate del sito in un formato leggibile da LLM e agenti AI.”

Aggiungi anche:

“Attualmente vengono elencati i contenuti Markdown abilitati.”

Non implementare ora integrazioni Rank Math, Yoast, AIOSEO o SEOPress.

Non fare HTTP request verso il sito stesso, salvo esista già una funzione nel plugin.

Puoi predisporre il markup per futuri warning, ma senza implementare il controllo ora.

## Tab 4 — Integrazioni

Questa tab è soprattutto informativa.

Deve contenere:

- Shortcode
- GenerateBlocks dynamic tag
- eventuale stato ACF, se utile

Shortcode da mostrare:

- `[sma_md_url]`
  Restituisce l’URL `.md` del post corrente.

- `[sma_md_url id="123"]`
  Restituisce l’URL `.md` di un post specifico.

GenerateBlocks:

Se GenerateBlocks è rilevato:

“GenerateBlocks rilevato. Il dynamic tag è disponibile automaticamente.”

Mostra:

`{{sma_md_url}}`

Spiega:

“Inserisci `{{sma_md_url}}` nei campi GenerateBlocks/GeneratePress che accettano dynamic tag, ad esempio l’URL di un bottone.”

Se GenerateBlocks non è rilevato:

“GenerateBlocks non rilevato. Il dynamic tag non è disponibile.”

Non creare nuove impostazioni per GenerateBlocks.

## Tab 5 — Avanzate

Qui deve stare:

- X-Robots-Tag

Layout:

X-Robots-Tag:
- campo testo
- default: `noindex, follow`
- campo vuoto = non inviare header

Testo di aiuto:

“Default: noindex, follow. Lascia vuoto per non inviare l’header.”

## Punto critico: salvataggio tab-aware

Ogni tab mostra solo alcuni campi. Salvare un tab NON deve cancellare le impostazioni degli altri tab.

Se il plugin usa una sola option array, la sanitize callback deve fare merge con i valori già salvati.

Aggiungi nel form un hidden field con il tab attivo:

`<input type="hidden" name="sma_active_settings_tab" value="general">`

Ovviamente il valore deve essere dinamico e corrispondere al tab corrente.

Nella sanitize callback:

1. recupera opzioni attuali;
2. applica default;
3. crea `$output` partendo dai valori attuali;
4. sanitizza solo le chiavi del tab salvato;
5. per i checkbox, considera assente = 0 solo se appartengono al tab attivo.

Esempio logico:

- se salvo il tab `general`, posso aggiornare `cache_ttl` e `supported_post_types`;
- non devo toccare `enable_llms`;
- non devo toccare `x_robots_tag`;
- non devo toccare i campi ACF.

- se salvo il tab `llms`, posso aggiornare `enable_llms`;
- non devo toccare `supported_post_types`.

Questo è fondamentale perché i checkbox non selezionati non vengono inviati da WordPress.

## Sanitizzazione richiesta

Cache TTL:
- usa `absint()`;
- accetta `0`.

Supported post types:
- usa whitelist;
- per ora supporta solo `post` e `page`, salvo il codice attuale preveda altro;
- non salvare valori fuori whitelist.

X-Robots-Tag:
- usa `sanitize_text_field()`;
- non validare troppo rigidamente perché possono esserci valori tipo `noindex, follow`, `noindex, nofollow`, `noarchive`.

Textarea esclusioni:
- verifica formato storage attuale;
- se il plugin usa array, salva array pulito;
- se il plugin usa stringa multilinea, salva stringa multilinea pulita;
- non cambiare formato storage se il resto del plugin si aspetta quello attuale.

Normalizzazione consigliata:
- split per riga;
- trim;
- rimuovi righe vuote;
- `sanitize_text_field()` su ogni elemento;
- rimuovi duplicati.

ACF field names:
- preferisci `sanitize_key()`;
- se il codice attuale permette caratteri più liberi, usa `sanitize_text_field()`.

## CSS admin

Se serve, aggiungi CSS leggero.

Obiettivi:

- textarea meno enormi;
- box informativi più leggibili;
- sezioni più separate;
- stile compatibile con WordPress admin.

Esempio classi:

- `.sma-settings-page`
- `.sma-settings-section`
- `.sma-card`
- `.sma-status`
- `.sma-help`

Carica il CSS solo nella pagina settings del plugin, controllando `$hook_suffix`.

Non caricare CSS globalmente in tutto l’admin.

## Struttura codice suggerita

Adattati al codice attuale.

Se ha senso, usa una struttura simile:

- `includes/class-admin.php`
- `includes/class-settings.php`
- `admin/views/settings-page.php`
- `admin/views/tabs/general.php`
- `admin/views/tabs/markdown.php`
- `admin/views/tabs/llms.php`
- `admin/views/tabs/integrations.php`
- `admin/views/tabs/advanced.php`
- `assets/admin-settings.css`

Se il plugin è piccolo, non è obbligatorio creare tutti questi file.

Però evita una callback unica gigantesca con tutto l’HTML dentro.

## Funzioni utili da creare o adattare

- `get_settings_tabs()`
- `get_active_tab()`
- `render_tabs()`
- `render_settings_page()`
- `render_general_tab()`
- `render_markdown_tab()`
- `render_llms_tab()`
- `render_integrations_tab()`
- `render_advanced_tab()`

## Cose da NON fare ora

Non implementare:

- traduzioni complete;
- text domain completo;
- integrazione Rank Math;
- integrazione Yoast;
- integrazione AIOSEO;
- integrazione SEOPress;
- HTTP check reale su `/llms.txt`;
- wizard iniziale;
- React;
- REST API admin;
- redesign grafico pesante;
- nuove feature core;
- cambio formato option non necessario;
- refactor completo della generazione Markdown.

Questa fase è solo refactor UI/settings.

## Acceptance criteria

Il lavoro è completato quando:

1. La pagina settings ha tab funzionanti.
2. Ogni tab mostra solo le sezioni previste.
3. Salvare un tab non cancella le opzioni degli altri tab.
4. Cache TTL mantiene default `86400`, salvo valore già salvato.
5. `/llms.txt` resta abilitabile/disabilitabile come prima.
6. Supported post types continuano a funzionare.
7. X-Robots-Tag continua a essere applicato come prima.
8. Shortcode `[sma_md_url]` continua a funzionare.
9. Dynamic tag GenerateBlocks `{{sma_md_url}}` continua a funzionare.
10. Nessun fatal error se ACF non è attivo.
11. Nessun fatal error se GenerateBlocks non è attivo.
12. Asset admin caricati solo nella pagina del plugin.
13. Nessun cambio distruttivo alla struttura delle option esistenti.

## Test manuali richiesti

Test 1 — Salvataggio tab Generale:
- cambia Cache TTL;
- salva;
- verifica che Cache TTL venga salvato;
- verifica che `/llms.txt`, campi ACF e X-Robots-Tag non vengano cancellati.

Test 2 — Salvataggio tab llms.txt:
- disattiva `/llms.txt`;
- salva;
- verifica che supported post types e cache TTL restino invariati.

Test 3 — Supported post types:
- modifica i checkbox;
- salva;
- verifica che i post type supportati siano quelli corretti.

Test 4 — Exclusions:
- inserisci esclusioni una per riga;
- salva;
- verifica che righe vuote e spazi inutili vengano rimossi;
- verifica che il generatore Markdown continui a leggere correttamente le esclusioni.

Test 5 — ACF non attivo:
- disattiva ACF;
- apri settings;
- nessun fatal error;
- mostrare stato o avviso coerente.

Test 6 — GenerateBlocks non attivo:
- disattiva GenerateBlocks;
- apri tab Integrazioni;
- nessun fatal error;
- mostrare stato coerente.

Test 7 — Endpoint:
- apri un URL `.md` di un post abilitato;
- verifica che funzioni come prima;
- apri `/llms.txt` se abilitato;
- verifica che funzioni come prima.

## Ordine di lavoro consigliato

1. Crea tab UI senza cambiare logica.
2. Sposta il rendering delle sezioni nei tab.
3. Rendi la sanitizzazione tab-aware.
4. Aggiungi CSS admin leggero.
5. Esegui i test manuali.
6. Solo dopo fai eventuale cleanup minore.

Priorità assoluta: mantenere il comportamento esistente e rendere la pagina settings più chiara.
