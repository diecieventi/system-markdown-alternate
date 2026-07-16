# Plugin Markdown Content Negotiation — Diagnosi LiteSpeed, esiti test e decisioni architetturali

**Data:** 15 luglio 2026
**Contesto:** plugin WordPress che serve versioni `.md` di post/pagine tramite HTTP content negotiation (`Accept: text/markdown`) sullo stesso URL canonico, con `rel="alternate"` per l'auto-discovery. Esiste un'opzione "compatibilità LiteSpeed". Questo documento chiude la fase diagnostica sul comportamento divergente tra due server LiteSpeed e fissa gli invarianti di implementazione. **Le decisioni nella sezione 4 sono vincolanti; non "migliorarle" senza discuterne prima.**

---

## 1. Il problema di partenza

Comportamento divergente tra due siti di produzione, entrambi LiteSpeed + LSCWP:

- **albanacupuncture.com**: la content negotiation funziona anche con l'opzione compatibilità **disattivata**.
- **sistemha.com**: con compatibilità disattivata la negotiation **non funziona** (le richieste con `Accept: text/markdown` ricevono HTML).

Sospetto iniziale: differenza lato hosting. Confermato, ma in direzione opposta al previsto (v. sezione 3).

## 2. Test eseguiti e risultati

Tutti i test sono stati eseguiti con `curl` da macchina esterna, leggendo `x-litespeed-cache` (hit/miss), `content-type`, `vary`, `cache-control`.

### 2.1 albana — sequenza a cache fredda (purge → md → md → HTML)
- Richieste `Accept: text/markdown`: risposta `text/markdown`, `Vary: Accept`, `X-LiteSpeed-Cache-Control: no-cache`, `Cache-Control: no-cache, no-store, private`, `X-Robots-Tag: noindex, follow`, canonical verso l'URL HTML. Mai cachate (nessun header hit/miss, generazione PHP ogni volta).
- Richiesta browser successiva: HTML corretto, cache attiva (`public, max-age=604800`), `hit` alla ripetizione.
- Richiesta con `Accept: */*`: HTML. Corretto — `*/*` deve dare il default HTML, mai markdown.
- **Esito: nessun cache poisoning. Architettura "md sempre no-cache + HTML cachato" verificata.**

### 2.2 albana — test interleaving a cache calda (HTML hit → md → HTML)
- HTML `hit` → richiesta md riceve **markdown fresco da PHP** → HTML di nuovo `hit`.
- **Esito: su albana le richieste md raggiungono PHP anche quando esiste l'entry HTML in cache.**

### 2.3 sistemha — stesso URL, plugin NON installato
- Prima richiesta md (`miss`): risposta **HTML** — nessuna negotiation, ovvio, il plugin non c'è. L'entry viene cachata e servita a tutte le richieste successive indipendentemente dall'Accept. `vary: User-Agent` (separazione mobile/desktop LSCWP).
- **Esito: baseline cache sana. Ma conferma che la chiave di cache ignora l'header Accept.**

### 2.4 Diff `.htaccess` dei due siti
- Blocchi LSCACHE sostanzialmente identici e standard. **Nessuna regola su `HTTP_ACCEPT` o markdown su nessuno dei due.** Esclusa l'ipotesi "regola orfana della compatibilità".
- Differenze non pertinenti alla cache: albana ha Patchstack e un blocco `XCLOUD_AI_BOT_BLOCKER` (v. sezione 6). Su sistemha manca il marker LOGIN COOKIE di LSCWP (possibile disallineamento versioni LSCWP, da verificare).

### 2.5 Test standalone decisivo — `vary-test.php` fuori da WordPress
File PHP nella docroot (bypassa WP e LSCWP) che emette `Vary: Accept`, `X-LiteSpeed-Cache-Control: public, max-age=300`, e nel body un token random + l'Accept ricevuto da PHP. Sequenza: Accept-A ×2, Accept-B ×2.

**Nota di metodo:** un primo tentativo come mu-plugin dentro WordPress è risultato nullo perché **LSCWP sovrascrive gli header di cache emessi via `header()`** (la risposta usciva `no-cache` nonostante il codice dichiarasse `public, max-age=300`). Qualsiasi test futuro dentro WP deve usare l'API di LSCWP (`litespeed_control_set_cacheable`), non header raw.

Risultati:

| Server | A→A (verifica cache) | A poi B (verdetto) |
|---|---|---|
| **sistemha** | `hit`, body identico ✓ | **`hit` con body di A** (accept nel body: `text/x-test-aaa`) → **Vary IGNORATO** |
| **albana** | `hit`, body identico ✓ | **`miss` con body fresco di B**, poi `hit` sulla variante B → **Vary RISPETTATO, varianti cachate separatamente** |

## 3. Causa root — conclusione

**Il LiteSpeed di sistemha rappresenta il comportamento di default: il cache lookup usa solo l'URL come chiave e ignora l'header `Vary` standard** (LiteSpeed gestisce il vary solo tramite meccanismi proprietari: env `cache-vary`, vary cookie, regole rewrite tipo il marker WEBP di LSCWP). **Albana è l'eccezione**: quel server (flavor/versione/configurazione non determinabile dagli header) onora il `Vary: Accept` e cacha varianti separate per ogni valore di Accept.

Conseguenze:
- Il "funzionava senza compatibilità" su albana era una proprietà dell'hosting, non del plugin.
- Il "non funzionava" su sistemha è il caso normale: a cache calda l'entry HTML viene servita alla richiesta md prima che PHP parta.
- **Il rispetto del Vary è una proprietà per-host, non verificabile a priori, assente nel caso di default e soggetta a cambiare nel tempo. Il plugin non può appoggiarcisi mai.**

## 4. Decisioni architetturali (vincolanti)

1. **Risposte markdown sempre no-cache.** `X-LiteSpeed-Cache-Control: no-cache` + `Cache-Control: no-cache, no-store, must-revalidate, private`. Questo è **l'invariante di sicurezza portante** contro il cache poisoning, non un workaround temporaneo. Non cachare mai la variante md a livello HTTP confidando nel `Vary` — è dimostrato che sul LiteSpeed di default il poisoning sarebbe immediato.
2. **Modalità compatibilità LiteSpeed attiva di default** quando viene rilevato LiteSpeed (server header o costanti LSCWP). Sui server che rispettano il Vary è ridondante ma innocua; su tutti gli altri è l'unico meccanismo funzionante. Nessuna logica condizionale di rilevamento runtime per decidere se attivarla.
3. **Cache di conversione interna via transient** per post (`save_post` la invalida). La risposta HTTP resta no-cache, ma la conversione HTML→markdown non viene rifatta a ogni richiesta: bootstrap WP + lettura transient (su siti con Redis object cache il costo è trascurabile). Mitiga sia i crawler legittimi sia il cache-busting ostile via `Accept: text/markdown`.
4. **Purge della cache LiteSpeed all'attivazione e disattivazione del plugin** (via API LSCWP se presente, `litespeed_purge_all`). Motivazione: entry cachate prima dell'attivazione non contengono il Vary e producono comportamenti fantasma difficilissimi da diagnosticare — è plausibilmente parte di ciò che ha confuso la diagnosi iniziale.
5. **`Vary: Accept` sempre emesso, in modalità append** (`header('Vary: ...', false)` o lettura del valore corrente prima di scrivere). Mai sovrascrivere: i siti hanno già `Vary: User-Agent` per la cache mobile/desktop e sovrascriverlo la romperebbe. L'header resta corretto e utile per browser e CDN a valle (es. Cloudflare) anche dove LiteSpeed lo ignora.
6. **`Accept: */*` riceve HTML.** Solo una preferenza esplicita per `text/markdown` (con gestione corretta dei q-value: `text/markdown` deve avere q maggiore di `text/html` per vincere) serve markdown. Verificato empiricamente che il comportamento attuale è corretto; non regredire.
7. Header della variante md già corretti e da mantenere: `X-Robots-Tag: noindex, follow`, `Link: <url>; rel="canonical"` verso la versione HTML, `Content-Type: text/markdown; charset=utf-8`.

## 5. Sviluppi futuri (non vincolanti, in ordine di priorità)

- **Self-test diagnostico del comportamento Vary** (informativo, NON per auto-toggle della compatibilità — decisione già presa e motivata: un test all'attivazione fotografa il presente, il comportamento dell'hosting può cambiare, e il beneficio dell'auto-disattivazione è marginale). Design: endpoint di test marcato cachabile via API LSCWP con tag dedicato, body con token random + Accept ricevuto; 3 loopback `wp_remote_get` (A prime, A verifica hit, B verdetto); purge del tag a fine test; risultato in option con timestamp, esposto nella pagina impostazioni o in Site Health. Il test deve gestire tre esiti: Vary ignorato / Vary rispettato / **non determinabile** (loopback bloccato, proxy/CDN davanti, bot-protection che aggiunge Set-Cookie e impedisce il caching — tutti casi incontrati realmente). In caso di fallimento del loopback, mostrare i comandi curl equivalenti da eseguire manualmente.
- **Opzione "includi front page"** separata dal toggle del CPT `page` (oggi la home è esclusa come effetto collaterale). Bassa priorità: valore reale solo per fetch real-time degli assistenti; verificare la qualità della conversione (le home sono block-heavy). Gestire il caso front page = indice dei post (nessun post object: o si esclude o si genera una lista di link, logica diversa).
- **Rate limiting per IP sulle richieste md** solo se i log mostrano abuso reale. Non anticipare.
- **Escape hatch documentato, non implementato:** su LiteSpeed è possibile cachare varianti separate con `RewriteCond %{HTTP_ACCEPT} text/markdown` + `RewriteRule .* - [E=cache-vary:md]` (o la sintassi LSCWP `E=Cache-Control:vary=...`). Scartato come default: dipende da `.htaccess`, comportamento non uniforme tra Enterprise e OpenLiteSpeed, rischio di regole orfane. Tenere come opzione avanzata futura solo su richiesta.

## 6. Nota fuori scope plugin ma bloccante per il progetto su albana

L'`.htaccess` di albana contiene il blocco `XCLOUD_AI_BOT_BLOCKER` (lista "Ultimate AI Block List") che risponde **403 a tutti i principali crawler AI**: GPTBot, OAI-SearchBot, ClaudeBot, PerplexityBot, Google-Extended, Applebot, ecc. **Su quel sito nessun crawler AI vedrà mai la negotiation né il `rel="alternate"`**: i test curl funzionano solo perché lo UA di curl non è in lista. Contraddizione strategica da risolvere a livello sito (rimuovere/potare il blocker, o accettare che il plugin lì sia inerte). Il banco di prova reale del plugin è sistemha, che non blocca i bot.

## 7. Contesto esterno utile (verificato luglio 2026)

- I dati pubblici disponibili (misurazioni di Dries Buytaert, marzo 2026) indicano che **nessun crawler AI usa oggi la content negotiation**: le versioni markdown vengono scoperte solo tramite il link `rel="alternate"` verso URL `.md` dedicati. La negotiation è un investimento sulla direzione del mercato (Cloudflare "Markdown for Agents", Vercel, ecc.), non sul traffico attuale. Implicazione: **il `rel="alternate"` è l'asset che produce valore oggi** e va trattato come first-class, non come contorno della negotiation.
- Il volume reale di richieste markdown è quindi trascurabile: le scelte prestazionali (no-cache HTTP + transient) sono ampiamente adeguate.
