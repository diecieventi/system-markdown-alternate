# Documento tecnico — Revisione e aggiornamento del sistema Markdown dinamico

## Obiettivo

Verificare e aggiornare il plugin WordPress esistente che espone una versione Markdown di articoli, pagine o altri post type tramite endpoint dinamico.

Il plugin è già basato sulla generazione dinamica del Markdown. Questa architettura deve essere mantenuta.

Non deve essere introdotta la creazione permanente di file `.md` nel filesystem, nella cartella `uploads` o in altre directory pubbliche.

La revisione deve concentrarsi su:

- correttezza dell'endpoint dinamico;
- cache persistente del Markdown per singolo contenuto;
- invalidazione affidabile della cache;
- header HTTP corretti;
- supporto a cache browser, reverse proxy e CDN;
- sicurezza e rispetto della visibilità dei contenuti;
- compatibilità con WordPress, permalink, traduzioni e plugin di cache;
- manutenibilità del codice.

---

# Architettura attesa

L'architettura finale deve rispettare questo schema:

```text
Endpoint Markdown dinamico
+ cache persistente per singolo post
+ invalidazione della cache su modifica del contenuto
+ header ETag e Last-Modified
+ cache HTTP e CDN opzionale
+ nessun file Markdown permanente in uploads
```

Flusso atteso:

```text
Richiesta della versione Markdown
↓
Risoluzione del post richiesto
↓
Controllo accessibilità e visibilità
↓
Calcolo delle informazioni di validazione HTTP
↓
Verifica di ETag / Last-Modified
↓
Risposta 304 se il client possiede già la versione aggiornata
↓
Ricerca del Markdown nella cache persistente
↓
Se presente: restituzione del contenuto in cache
↓
Se assente: generazione del Markdown
↓
Salvataggio nella cache persistente
↓
Output con Content-Type text/markdown
```

---

# Decisione architetturale vincolante

## Mantenere la generazione dinamica

Il Markdown deve continuare a essere servito tramite WordPress e generato dinamicamente quando necessario.

Sono accettabili endpoint come:

```text
https://example.com/articolo/?format=markdown
```

oppure:

```text
https://example.com/articolo.md
```

La forma dell'URL può restare quella già implementata, purché:

- sia stabile;
- non generi conflitti con permalink o file reali;
- restituisca sempre il contenuto corretto;
- supporti adeguatamente redirect canonici e trailing slash;
- non produca loop di redirect;
- non interferisca con REST API, feed, sitemap o anteprime.

## Scartare i file fisici permanenti

Non implementare:

```text
/wp-content/uploads/markdown/post-slug.md
```

Non salvare file `.md` permanenti nel filesystem.

Non creare processi di sincronizzazione tra:

- contenuto WordPress;
- file Markdown;
- slug;
- permalink;
- lingue;
- revisioni;
- stato del post.

Motivazioni:

- rischio di file obsoleti;
- rischio di esposizione di contenuti privati o protetti;
- complessità di sincronizzazione;
- problemi con offload media, S3, Bunny Storage o CDN;
- duplicazione dei contenuti nei backup;
- gestione più fragile di eliminazioni e variazioni di permalink;
- necessità di protezioni aggiuntive a livello web server.

---

# Priorità principale: cache persistente per post

## Obiettivo della cache

La conversione in Markdown non deve essere eseguita a ogni richiesta.

Il sistema deve:

1. generare il Markdown alla prima richiesta;
2. salvarlo in cache;
3. riutilizzarlo finché il contenuto non cambia;
4. invalidarlo quando il contenuto o i dati rilevanti vengono modificati.

La cache deve essere specifica per:

- ID del post;
- lingua, se il sito è multilingua;
- variante dell'output, se esistono formati diversi;
- versione del formato o della pipeline di conversione;
- eventuali opzioni del plugin che modificano l'output.

Esempio concettuale di chiave:

```text
markdown_alternate:{post_id}:{locale}:{format_version}:{content_hash}
```

Non usare esclusivamente lo slug come chiave, perché può cambiare e non è garantito che sia univoco in tutti i contesti.

---

# Strategia di cache consigliata

## Livello 1 — Object cache persistente

Usare preferibilmente la WordPress Object Cache API:

```php
wp_cache_get()
wp_cache_set()
wp_cache_delete()
```

La cache deve usare un gruppo dedicato, per esempio:

```php
markdown_alternate
```

Esempio concettuale:

```php
$cache_key = 'post_' . $post_id . '_' . $locale . '_' . $format_version;

$markdown = wp_cache_get(
    $cache_key,
    'markdown_alternate'
);
```

Questo consente di sfruttare Redis, Memcached o altre object cache persistenti quando disponibili.

## Attenzione importante

La WordPress Object Cache non è necessariamente persistente.

Senza Redis o Memcached, il valore potrebbe durare solo per la singola richiesta.

Il plugin deve quindi verificare se l'architettura attuale garantisce realmente persistenza.

Non assumere che `wp_cache_set()` da solo equivalga sempre a cache persistente.

---

# Fallback persistente

Se non è disponibile una object cache persistente, prevedere un fallback affidabile.

Possibili opzioni:

## Opzione consigliata: post meta

Salvare il Markdown generato in un post meta privato, per esempio:

```text
_markdown_alternate_cache
_markdown_alternate_cache_hash
_markdown_alternate_cache_version
```

Vantaggi:

- persistenza garantita;
- invalidazione semplice;
- assenza di file fisici;
- compatibilità con hosting standard;
- accesso diretto per post;
- facile controllo della versione.

Il post meta deve essere considerato cache e non fonte primaria del contenuto.

Non deve essere esposto nella REST API salvo scelta esplicita.

## Alternativa: transient

I transient possono essere usati, ma non sono la prima scelta per questa architettura.

La validità della cache dipende dalla modifica del contenuto, non da una scadenza temporale arbitraria.

Se vengono usati transient:

- evitare TTL troppo brevi;
- invalidare esplicitamente il transient;
- non affidarsi solo alla scadenza;
- considerare il comportamento con object cache persistente.

---

# Strategia di cache a più livelli

La soluzione preferibile è:

```text
Object cache persistente, se disponibile
↓
Fallback su post meta
↓
Generazione del Markdown
```

Flusso suggerito:

```text
1. Cerca in object cache.
2. Se non presente, cerca nel post meta.
3. Se presente nel post meta, ripopola l'object cache.
4. Se non presente, genera il Markdown.
5. Salva nel post meta.
6. Salva nell'object cache.
7. Restituisci l'output.
```

Questo approccio garantisce:

- velocità quando Redis è disponibile;
- persistenza anche quando Redis viene svuotato;
- compatibilità con hosting senza object cache;
- rigenerazione solo quando realmente necessaria.

---

# Invalidazione della cache

## Hook principale

Controllare l'uso di:

```php
save_post
```

oppure, preferibilmente quando utile:

```php
save_post_{$post_type}
```

L'invalidazione non deve essere eseguita indiscriminatamente per ogni salvataggio interno di WordPress.

Escludere almeno:

```php
wp_is_post_revision( $post_id )
wp_is_post_autosave( $post_id )
```

Valutare anche:

- post type non supportati;
- aggiornamenti che non modificano dati rilevanti;
- salvataggi automatici;
- import massivi;
- revisioni;
- cambi di stato;
- eliminazione e ripristino;
- traduzioni;
- modifiche ai metadati inclusi nell'output.

## Eventi che devono invalidare la cache

La cache deve essere invalidata quando cambia uno degli elementi usati per generare il Markdown.

Controllare almeno:

- titolo;
- excerpt;
- contenuto;
- stato del post;
- slug, se incluso nell'output;
- data di modifica;
- tassonomie incluse;
- immagine in evidenza, se inclusa;
- custom field o campi ACF inclusi;
- autore, se incluso;
- opzioni globali del plugin;
- template o versione della pipeline Markdown.

## Hook aggiuntivi da valutare

A seconda dell'implementazione:

```php
deleted_post
trashed_post
untrashed_post
transition_post_status
set_object_terms
added_post_meta
updated_post_meta
deleted_post_meta
acf/save_post
```

Non aggiungere hook ridondanti senza verificare il flusso reale.

L'obiettivo è invalidare la cache quando necessario, non rigenerarla continuamente.

## Aggiornamento dei metadati

Se il Markdown include campi custom o ACF, `save_post` potrebbe non essere sufficiente in tutti i casi o potrebbe essere eseguito prima del salvataggio definitivo dei campi.

Controllare l'ordine degli hook.

Con ACF, verificare se sia opportuno usare:

```php
acf/save_post
```

con priorità adeguata.

L'invalidazione deve avvenire dopo che i dati rilevanti sono stati salvati.

---

# Evitare rigenerazione anticipata non necessaria

La strategia predefinita deve essere lazy:

```text
invalidazione su modifica
+
rigenerazione alla prima richiesta successiva
```

Non rigenerare obbligatoriamente il Markdown durante ogni `save_post`, salvo necessità dimostrata.

Motivazioni:

- salvataggio più veloce nel backend;
- minore carico durante importazioni;
- minore rischio di timeout;
- nessun lavoro inutile per contenuti mai richiesti in Markdown.

La rigenerazione anticipata può essere prevista come opzione separata, non come comportamento obbligatorio.

---

# Prevenzione della cache stampede

Se molte richieste raggiungono contemporaneamente un contenuto non presente in cache, più processi potrebbero generare lo stesso Markdown.

Valutare un lock temporaneo.

Esempio concettuale:

```text
markdown_alternate_lock:{post_id}:{locale}
```

Il lock deve:

- avere durata breve;
- essere rimosso dopo la generazione;
- scadere automaticamente in caso di errore;
- non bloccare definitivamente la risposta;
- non produrre deadlock.

Per siti normali questa protezione può essere opzionale, ma deve essere valutata se il plugin è destinato a bot, crawler o grandi volumi.

---

# Header HTTP

## Content-Type

La risposta Markdown deve includere:

```http
Content-Type: text/markdown; charset=UTF-8
```

Non usare:

```http
text/plain
application/octet-stream
```

come comportamento predefinito.

## Content-Disposition

Per visualizzazione inline:

```http
Content-Disposition: inline
```

Per download richiesto esplicitamente:

```http
Content-Disposition: attachment; filename="slug-articolo.md"
```

Il nome del file deve essere sanitizzato.

Usare funzioni WordPress appropriate, per esempio:

```php
sanitize_file_name()
```

Non usare direttamente input provenienti dalla query string.

---

# ETag

Implementare o verificare un ETag stabile.

L'ETag deve cambiare quando cambia l'output Markdown.

Può essere calcolato da:

- hash del Markdown;
- hash dei dati sorgente;
- hash della versione cache;
- combinazione tra ID, lingua, timestamp e versione del formato.

Esempio:

```php
$etag = '"' . hash( 'sha256', $markdown ) . '"';
```

Oppure, per evitare di generare il Markdown prima del controllo, usare un hash basato sulle dipendenze dell'output:

```text
post_modified_gmt
+ versione formato
+ lingua
+ opzioni rilevanti
+ hash dei meta inclusi
```

Inviare:

```http
ETag: "valore-etag"
```

Gestire correttamente:

```http
If-None-Match
```

Se l'ETag coincide, restituire:

```http
304 Not Modified
```

senza body.

---

# Last-Modified

Inviare:

```http
Last-Modified: data GMT conforme a RFC 7231
```

Usare una data coerente con l'ultima modifica effettiva dell'output.

Non limitarsi necessariamente a `post_modified_gmt` se il Markdown dipende da:

- tassonomie;
- custom field;
- opzioni globali;
- dati esterni;
- autore;
- allegati.

Se questi dati possono modificare l'output senza aggiornare `post_modified_gmt`, deve essere mantenuto un timestamp di cache/versione più affidabile.

Gestire:

```http
If-Modified-Since
```

Restituire `304 Not Modified` quando appropriato.

## Priorità tra validatori

Quando sono presenti entrambi:

```http
If-None-Match
If-Modified-Since
```

dare priorità a `If-None-Match`.

---

# Cache-Control

Per contenuti pubblici, valutare:

```http
Cache-Control: public, max-age=3600, stale-while-revalidate=86400
```

I valori devono essere configurabili tramite filtro o impostazione.

Non impostare cache pubblica per:

- contenuti privati;
- anteprime;
- contenuti protetti da password;
- risposte dipendenti dall'utente;
- contenuti riservati tramite membership;
- richieste autenticate che producono output differente.

Per contenuti non pubblici usare:

```http
Cache-Control: private, no-store
```

oppure rifiutare direttamente la richiesta Markdown.

---

# Supporto CDN e reverse proxy

Il plugin deve rendere possibile la cache CDN senza dipenderne.

La cache CDN è opzionale e non deve essere necessaria per il corretto funzionamento.

Verificare:

- compatibilità con Cloudflare;
- compatibilità con Bunny CDN;
- compatibilità con cache Nginx o Varnish;
- compatibilità con page cache WordPress;
- distinzione corretta tra HTML e Markdown;
- presenza di una cache key che includa query string o formato;
- assenza di collisioni tra pagina HTML e risposta Markdown.

Se l'endpoint usa:

```text
?format=markdown
```

controllare che il CDN o la page cache includano la query string nella cache key.

In caso contrario, esiste il rischio che:

- la pagina HTML venga servita come Markdown;
- il Markdown venga servito al posto della pagina HTML.

Se viene usata content negotiation tramite:

```http
Accept: text/markdown
```

inviare:

```http
Vary: Accept
```

Senza `Vary: Accept`, una cache intermedia potrebbe confondere la risposta HTML con quella Markdown.

Se il formato dipende anche da altri header, aggiungerli a `Vary` solo quando realmente necessario.

---

# Endpoint dinamico

## Controlli da eseguire

Verificare che l'endpoint:

- risolva il post corretto;
- funzioni con permalink semplici e pretty permalink;
- funzioni su home, pagine, articoli e CPT abilitati;
- gestisca correttamente archivi e tassonomie;
- non esponga revisioni;
- non esponga allegati non supportati;
- non esponga post non pubblicati;
- restituisca `404` per risorse inesistenti;
- restituisca `403` o `404` per risorse non accessibili, secondo la policy scelta;
- non interferisca con feed, sitemap, REST API e canonical;
- non attivi il template HTML;
- termini correttamente l'esecuzione dopo l'output;
- non invii output precedente agli header;
- gestisca correttamente compressione e buffering.

## URL `.md`

Se viene supportato un endpoint come:

```text
/articolo.md
```

verificare:

- rewrite rule specifica e non troppo permissiva;
- query var registrata;
- flush delle rewrite rules solo in attivazione o disattivazione;
- nessun `flush_rewrite_rules()` a ogni richiesta;
- compatibilità con sottodirectory WordPress;
- compatibilità con multisite;
- gestione corretta di slug contenenti punti;
- assenza di conflitto con file reali.

---

# Content negotiation

Se il plugin supporta:

```http
Accept: text/markdown
```

verificare che:

- non intercetti richieste che accettano genericamente `*/*`;
- non sostituisca HTML quando il client preferisce `text/html`;
- consideri eventuali quality value `q=`;
- restituisca Markdown solo quando richiesto esplicitamente;
- invii `Vary: Accept`;
- non modifichi il comportamento di browser e crawler normali.

La presenza di:

```http
Accept: */*
```

non deve essere considerata da sola una richiesta esplicita di Markdown.

---

# Sicurezza e controllo accessi

Prima di generare o servire la cache, verificare lo stato del contenuto.

Requisiti minimi:

```php
$post instanceof WP_Post
$post->post_status === 'publish'
```

oppure una policy equivalente e più completa.

Controllare inoltre:

- password protection;
- post privati;
- bozze;
- revisioni;
- anteprime;
- contenuti programmati;
- membership;
- restrizioni basate su ruolo;
- visibilità personalizzata;
- post type esclusi;
- contenuti eliminati o nel cestino.

## Regola importante

Non servire dalla cache un contenuto che non sarebbe più accessibile.

I controlli di accesso devono avvenire prima della restituzione del Markdown memorizzato.

Non assumere che la presenza della cache implichi che il contenuto sia ancora pubblico.

## Input utente

Sanitizzare e validare:

- ID;
- slug;
- formato;
- opzione download;
- nome del file;
- lingua;
- parametri di query.

Non usare input utente direttamente in:

- nomi file;
- chiavi non normalizzate;
- header HTTP;
- percorsi;
- query SQL;
- chiamate a funzioni dinamiche.

## Header injection

Evitare che titolo, slug o parametri possano introdurre:

```text
\r
\n
```

negli header, soprattutto in `Content-Disposition`.

---

# Contenuto sorgente

La conversione dovrebbe partire dal contenuto editoriale controllato, non dall'intero HTML della pagina renderizzata.

Fonte principale consigliata:

```php
$post->post_content
```

Aggiungere esplicitamente solo i dati necessari:

- titolo;
- excerpt;
- autore;
- data;
- tassonomie;
- campi custom pubblici;
- URL canonico;
- immagine in evidenza;
- metadati selezionati.

Non includere automaticamente:

- menu;
- header;
- footer;
- sidebar;
- cookie banner;
- moduli;
- widget;
- related post;
- elementi di tracking;
- contenuti amministrativi;
- dati privati;
- nonce;
- shortcode tecnici.

---

# Shortcode e blocchi dinamici

Valutare attentamente l'uso di:

```php
apply_filters( 'the_content', $post->post_content )
```

Questa chiamata può eseguire:

- shortcode;
- blocchi dinamici;
- query;
- hook di plugin;
- embed;
- moduli;
- componenti costosi;
- output non adatto al Markdown.

La pipeline deve essere intenzionale.

Possibili strategie:

1. conversione del contenuto blocchi con whitelist;
2. rendering controllato di blocchi supportati;
3. rimozione preventiva di shortcode non ammessi;
4. filtri per personalizzare il contenuto sorgente;
5. esclusione di componenti interattivi o sensibili.

Non usare l'intero output del frontend come sorgente predefinita.

---

# Filtri ed estensibilità

Prevedere o verificare filtri simili a:

```php
apply_filters(
    'markdown_alternate_source_content',
    $content,
    $post
);
```

```php
apply_filters(
    'markdown_alternate_output',
    $markdown,
    $post
);
```

```php
apply_filters(
    'markdown_alternate_cache_key',
    $cache_key,
    $post,
    $context
);
```

```php
apply_filters(
    'markdown_alternate_cache_control',
    $cache_control,
    $post
);
```

```php
apply_filters(
    'markdown_alternate_supported_post_types',
    $post_types
);
```

```php
do_action(
    'markdown_alternate_cache_invalidated',
    $post_id
);
```

I nomi definitivi devono seguire il prefisso reale del plugin.

Non introdurre nomi generici che possano entrare in conflitto con altri plugin.

---

# Versionamento della cache

Aggiungere una versione della pipeline Markdown.

Esempio:

```php
const CACHE_VERSION = '1';
```

La versione deve essere inclusa nella chiave o nei metadati della cache.

Quando cambia:

- il convertitore;
- il formato del front matter;
- la struttura dell'output;
- la gestione dei blocchi;
- la normalizzazione dei link;
- l'inclusione di metadati;

la cache precedente deve diventare automaticamente non valida.

Questo evita di dover cancellare manualmente tutti i record dopo un aggiornamento del plugin.

---

# Hash delle dipendenze

La cache non deve basarsi solo sulla data di modifica del post se l'output include altri dati.

Creare un hash delle dipendenze rilevanti.

Esempio concettuale:

```php
$source_hash = hash(
    'sha256',
    wp_json_encode(
        [
            'post_modified_gmt' => $post->post_modified_gmt,
            'title'             => $post->post_title,
            'excerpt'           => $post->post_excerpt,
            'content'           => $post->post_content,
            'terms'             => $included_terms,
            'meta'              => $included_meta,
            'locale'            => $locale,
            'cache_version'     => self::CACHE_VERSION,
        ]
    )
);
```

Non includere dati irrilevanti, altrimenti la cache verrebbe invalidata troppo spesso.

---

# Download per utenti

Il download deve usare lo stesso contenuto e la stessa cache dell'endpoint inline.

Non creare un secondo sistema di generazione.

Esempio:

```text
/articolo/?format=markdown&download=1
```

La differenza deve riguardare solo:

```http
Content-Disposition
```

Il contenuto Markdown, l'ETag e la logica di cache devono restare gli stessi.

Il link di download dovrebbe usare:

```html
target="_blank"
rel="noopener"
```

solo se l'interfaccia lo richiede.

Non è necessario aprire una nuova scheda per forzare il download.

---

# Error handling

Il plugin non deve mostrare:

- warning PHP;
- notice;
- stack trace;
- percorsi del filesystem;
- dettagli interni;
- contenuti parziali.

In caso di errore:

- usare status HTTP appropriato;
- registrare l'errore solo se il logging è abilitato;
- non salvare output incompleto nella cache;
- rimuovere eventuali lock;
- evitare cache poisoning.

Possibili status:

```text
400 Bad Request
403 Forbidden
404 Not Found
406 Not Acceptable
500 Internal Server Error
503 Service Unavailable
```

Usare `406` solo se coerente con il comportamento dell'endpoint.

---

# Compatibilità multilingua

Se il plugin deve funzionare con WPML, Polylang o sistemi simili:

- la cache deve distinguere la lingua;
- l'endpoint deve risolvere la traduzione corretta;
- ETag e Last-Modified devono essere specifici per lingua;
- il nome del file deve usare lo slug della traduzione;
- l'invalidazione di una lingua non deve necessariamente cancellare tutte le altre;
- le relazioni tra traduzioni devono essere considerate.

Non usare solo:

```text
post_id
```

se lo stesso contenuto può produrre output diverso in base al locale corrente.

---

# Compatibilità con plugin di cache

Verificare almeno i seguenti scenari:

- nessun plugin di cache;
- page cache;
- object cache Redis;
- Cloudflare;
- CDN;
- hosting con FastCGI cache;
- cache che ignora query string;
- cache che normalizza header `Accept`.

Prevedere una documentazione minima che specifichi:

- quale URL mettere in cache;
- se la query string deve far parte della cache key;
- se è necessario rispettare `Vary: Accept`;
- come escludere contenuti privati;
- come svuotare la cache del plugin;
- come invalidare la CDN.

---

# Purge CDN opzionale

L'invalidazione della CDN non deve essere obbligatoria.

Prevedere eventualmente un hook:

```php
do_action(
    'markdown_alternate_cdn_purge',
    $post_id,
    $markdown_url
);
```

Il plugin non deve integrare direttamente ogni provider nella logica principale.

Le integrazioni specifiche con Cloudflare, Bunny o altri servizi devono essere modulari.

Se non viene eseguito purge CDN, la durata massima del contenuto obsoleto deve essere limitata dagli header `Cache-Control`.

---

# Comandi e strumenti amministrativi

Valutare l'aggiunta di:

## Pulsante di svuotamento cache

Possibili azioni:

- svuota cache del singolo post;
- svuota tutta la cache Markdown;
- rigenera alla prossima richiesta;
- mostra stato della cache.

## WP-CLI

Comandi opzionali:

```bash
wp markdown-alternate cache clear
wp markdown-alternate cache clear --post_id=123
wp markdown-alternate cache warm
wp markdown-alternate cache status
```

Il warming della cache deve essere opzionale.

Non deve essere necessario per il funzionamento normale.

---

# Logging e debug

Aggiungere logging solo dietro opzione o costante di debug.

Possibili eventi:

- cache hit;
- cache miss;
- cache invalidata;
- generazione completata;
- errore di conversione;
- risposta 304;
- richiesta negata;
- lock acquisito o scaduto.

Non registrare il contenuto completo del post o del Markdown nei log.

Non registrare dati personali o riservati.

---

# Metriche utili

Se il plugin ha una modalità diagnostica, può mostrare:

- numero di hit;
- numero di miss;
- tempo medio di conversione;
- dimensione media del Markdown;
- data ultima generazione;
- hash corrente;
- versione cache;
- presenza di object cache persistente;
- storage usato;
- ultimo motivo di invalidazione.

Queste informazioni devono essere diagnostiche e non obbligatorie.

---

# Checklist di code review

Il coder deve verificare il codice esistente rispetto ai punti seguenti.

## Endpoint

- [ ] L'endpoint restituisce il post corretto.
- [ ] Non espone contenuti non pubblici.
- [ ] Non interferisce con HTML, feed, REST o sitemap.
- [ ] Non esegue flush delle rewrite rules a ogni richiesta.
- [ ] Restituisce status HTTP corretti.
- [ ] Supporta correttamente query string o URL `.md`.
- [ ] Termina l'esecuzione dopo l'output.

## Cache

- [ ] Il Markdown non viene rigenerato a ogni richiesta.
- [ ] La cache è specifica per post.
- [ ] La cache distingue lingua e versione.
- [ ] È presente un fallback persistente.
- [ ] Redis non è considerato obbligatorio.
- [ ] La cache viene invalidata su modifica.
- [ ] La cache viene invalidata su cambio di stato.
- [ ] La cache viene eliminata su cancellazione.
- [ ] Il contenuto obsoleto non viene servito dopo una modifica.
- [ ] Non esistono race condition evidenti.
- [ ] Non viene salvato output incompleto.

## Header

- [ ] `Content-Type` è `text/markdown; charset=UTF-8`.
- [ ] `Content-Disposition` è corretto.
- [ ] Il filename è sanitizzato.
- [ ] È presente `ETag`.
- [ ] È presente `Last-Modified`.
- [ ] Viene gestito `If-None-Match`.
- [ ] Viene gestito `If-Modified-Since`.
- [ ] La risposta `304` non contiene body.
- [ ] `Cache-Control` è coerente con la visibilità.
- [ ] `Vary: Accept` è presente se viene usato l'header `Accept`.

## Sicurezza

- [ ] I controlli di accesso avvengono prima del cache hit.
- [ ] I post privati non sono esposti.
- [ ] Le bozze non sono esposte.
- [ ] Le revisioni non sono esposte.
- [ ] I post protetti da password non sono esposti.
- [ ] Gli input sono sanitizzati.
- [ ] Non è possibile header injection.
- [ ] Non sono presenti path traversal.
- [ ] Non vengono creati file pubblici permanenti.
- [ ] I dati sensibili non entrano nell'output.

## Conversione

- [ ] La sorgente non è l'intera pagina HTML.
- [ ] Gli shortcode sono gestiti intenzionalmente.
- [ ] I blocchi dinamici sono gestiti intenzionalmente.
- [ ] I campi ACF inclusi sono espliciti.
- [ ] I metadati privati sono esclusi.
- [ ] Sono presenti filtri per estendere la pipeline.
- [ ] I link sono normalizzati.
- [ ] Il Markdown prodotto è valido e leggibile.

## Compatibilità

- [ ] Funziona senza Redis.
- [ ] Funziona con Redis.
- [ ] Funziona con page cache.
- [ ] Funziona con Cloudflare o CDN equivalenti.
- [ ] Non collide tra HTML e Markdown.
- [ ] Funziona con permalink standard.
- [ ] Funziona con permalink personalizzati.
- [ ] Funziona con CPT abilitati.
- [ ] Funziona con WPML o altre lingue, se previsto.
- [ ] Funziona in multisite, se dichiarato compatibile.

---

# Test funzionali richiesti

## Test 1 — Prima richiesta

1. Svuotare la cache.
2. Richiedere la versione Markdown.
3. Verificare che venga generata.
4. Verificare che venga salvata in cache.
5. Verificare gli header.
6. Verificare status `200`.

## Test 2 — Cache hit

1. Richiedere nuovamente lo stesso Markdown.
2. Verificare che la conversione non venga rieseguita.
3. Verificare che il body sia identico.
4. Verificare che ETag sia identico.

## Test 3 — Modifica del post

1. Modificare il contenuto.
2. Salvare.
3. Verificare che la cache venga invalidata.
4. Richiedere il Markdown.
5. Verificare che contenga il nuovo contenuto.
6. Verificare che ETag sia cambiato.
7. Verificare che Last-Modified sia aggiornato.

## Test 4 — Risposta 304 con ETag

1. Richiedere il Markdown.
2. Recuperare ETag.
3. Ripetere la richiesta con `If-None-Match`.
4. Verificare status `304`.
5. Verificare body vuoto.

## Test 5 — Risposta 304 con Last-Modified

1. Richiedere il Markdown.
2. Recuperare Last-Modified.
3. Ripetere la richiesta con `If-Modified-Since`.
4. Verificare status `304` quando appropriato.

## Test 6 — Post privato

1. Impostare un post come privato.
2. Richiedere l'endpoint Markdown.
3. Verificare che il contenuto non venga restituito.
4. Verificare che una vecchia cache pubblica non venga servita.

## Test 7 — Post protetto da password

1. Proteggere il post con password.
2. Richiedere la versione Markdown.
3. Verificare che il contenuto non venga esposto.

## Test 8 — Cancellazione

1. Generare la cache.
2. Spostare il post nel cestino.
3. Verificare che la cache sia invalidata.
4. Verificare che l'endpoint non restituisca il contenuto.

## Test 9 — Redis assente

1. Disabilitare object cache persistente.
2. Richiedere il Markdown.
3. Verificare che il fallback persistente funzioni.
4. Verificare che la conversione non venga ripetuta inutilmente.

## Test 10 — Redis presente

1. Abilitare Redis.
2. Richiedere il Markdown.
3. Verificare object cache hit.
4. Svuotare Redis.
5. Verificare ripopolamento dal fallback persistente.

## Test 11 — Query string e CDN

Se si usa:

```text
?format=markdown
```

verificare che HTML e Markdown abbiano cache key separate.

## Test 12 — Accept header

Se supportato:

1. Richiedere con `Accept: text/html`.
2. Verificare risposta HTML.
3. Richiedere con `Accept: text/markdown`.
4. Verificare risposta Markdown.
5. Verificare `Vary: Accept`.

## Test 13 — Download

1. Richiedere il download.
2. Verificare filename.
3. Verificare sanitizzazione.
4. Verificare che il contenuto coincida con la versione inline.
5. Verificare che venga usata la stessa cache.

## Test 14 — ACF e tassonomie

1. Modificare un campo incluso nel Markdown.
2. Verificare invalidazione.
3. Modificare un termine incluso.
4. Verificare invalidazione.

## Test 15 — Concorrenza

1. Inviare più richieste simultanee su cache vuota.
2. Verificare che il sistema non produca errori.
3. Verificare che non salvi dati corrotti.
4. Verificare l'eventuale lock.

---

# Test prestazionali

Misurare almeno:

- tempo di risposta senza cache;
- tempo di risposta con cache;
- tempo di conversione;
- memoria usata;
- dimensione dell'output;
- numero di query database;
- comportamento con 10, 50 e 100 richieste concorrenti;
- comportamento con Redis;
- comportamento senza Redis;
- risposta da CDN;
- percentuale di `304 Not Modified`.

Obiettivo:

- la conversione deve avvenire solo su cache miss;
- la risposta su cache hit deve essere sensibilmente più veloce;
- la risposta `304` deve evitare l'invio del body;
- il sistema non deve creare file permanenti;
- il salvataggio di un post non deve diventare sensibilmente più lento.

---

# Criteri di accettazione

L'aggiornamento è accettabile solo se:

1. il Markdown resta dinamico;
2. non vengono creati file `.md` permanenti;
3. il contenuto viene memorizzato in cache per singolo post;
4. la cache è persistente anche senza Redis, tramite fallback;
5. la cache viene invalidata in modo affidabile;
6. il sistema non espone contenuti non pubblici;
7. sono implementati ETag e Last-Modified;
8. le richieste condizionali restituiscono `304`;
9. gli header di cache sono coerenti;
10. HTML e Markdown non collidono nelle cache;
11. il download usa la stessa pipeline e la stessa cache;
12. la soluzione è compatibile con CDN senza dipenderne;
13. il codice è estendibile tramite hook e filtri;
14. la cache è versionata;
15. sono presenti test minimi automatici o una procedura di test ripetibile.

---

# Output richiesto al coder

Al termine della revisione, produrre:

## 1. Audit dell'implementazione attuale

Indicare:

- cosa è già corretto;
- cosa è incompleto;
- cosa è rischioso;
- cosa genera lavoro inutile;
- cosa può causare contenuti obsoleti;
- cosa può esporre contenuti riservati;
- cosa può creare collisioni con cache o CDN.

## 2. Piano delle modifiche

Dividere in:

```text
Critiche
Importanti
Opzionali
```

## 3. Implementazione

Applicare le modifiche senza cambiare inutilmente:

- API pubbliche;
- URL esistenti;
- nomi delle impostazioni;
- comportamento frontend;
- compatibilità retroattiva.

## 4. Test

Fornire:

- test eseguiti;
- risultati;
- casi non coperti;
- eventuali dipendenze dall'hosting.

## 5. Nota finale

Confermare esplicitamente:

```text
Il plugin non crea file Markdown permanenti nel filesystem.
Il Markdown viene servito dinamicamente.
La generazione viene evitata quando è disponibile una cache valida.
La cache viene invalidata quando cambiano i dati rilevanti.
ETag e Last-Modified vengono usati per le richieste condizionali.
La cache CDN resta opzionale.
```

---

# Vincoli finali

Non introdurre file `.md` permanenti.

Non usare `uploads` come cache.

Non dipendere obbligatoriamente da Redis.

Non rigenerare il Markdown a ogni richiesta.

Non servire la cache prima dei controlli di accesso.

Non usare esclusivamente TTL temporali per determinare la validità.

Non eseguire `flush_rewrite_rules()` durante le richieste normali.

Non creare una seconda pipeline separata per il download.

Non affidare alla CDN la correttezza dell'invalidazione applicativa.

La priorità deve restare:

```text
Performance
↓
Sicurezza
↓
Affidabilità
↓
Semplicità di manutenzione
↓
Estensibilità
```
