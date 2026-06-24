# HANDOFF — System Markdown Alternate

> Documento di passaggio. Stato al commit `32c2202`, plugin **v0.12.1**, branch `main`.
> Ricostruito dall'intera conversazione; nessun dato inventato.
> Nota: questo file **non** viene auto-caricato in una nuova sessione (solo `CLAUDE.md` lo è).

## Obiettivo

Plugin WordPress che espone una **versione Markdown pulita** dei contenuti (per LLM/agenti)
aggiungendo `.md` al permalink (`/articolo/` → HTML, `/articolo.md` → Markdown con front
matter YAML + corpo). Non è un plugin SEO. In questa sessione si è portato il plugin dalla
v0.2.0 alla v0.12.1: nuove feature (llms.txt, ACF, shortcode, dynamic tag), performance,
correttezza, UX del pannello, igiene del repo e documentazione.

## Fatto

- **Endpoint `.md`** per i post type abilitati (post/page/CPT pubblici), pubblicati,
  pubblici, non protetti; **content negotiation** (`Accept: text/markdown` / `?format=markdown`).
- **Link `rel="alternate"`** nel `<head>` dei singolari supportati (con guard su lista vuota).
- **Header**: `Content-Type: text/markdown; charset=utf-8`, `X-Robots-Tag: noindex, follow`,
  `Link: <permalink>; rel="canonical"`.
- **Conversione pulita**: `render_block()` sui blocchi ripuliti, esclusione
  blocchi/shortcode/classi, code block fenced, **URL relativi risolti contro il permalink
  sorgente** (document-relative, `../`, root-relative).
- **`/llms.txt`** cachato, esclude i post protetti da password, toggle on/off.
- **Cache Redis-aware** (`Cache` helper): object cache persistente se presente, altrimenti
  transient. Invalidazione via salt globale + `post_modified_gmt` + `SMA_VERSION`; bump del
  salt al salvataggio opzioni; pulizia su `save_post`/`deleted_post` (salta revisioni/autosave).
- **Pannello admin** (pagina unica, Settings API): sezioni Generale / Output Markdown /
  llms.txt / Integrazioni / Avanzate; CSS caricato solo nella pagina; default esclusioni
  mostrati uno-per-riga; normalizzazione esclusioni al salvataggio; whitelist post types;
  status llms.txt (abilitato + URL).
- **ACF**: sottotitolo (testo) + TL;DR (WYSIWYG, passa dalla pipeline DOM) come preambolo
  tra H1 e corpo; nomi campo configurabili; opzioni registrate solo se ACF attivo.
- **Shortcode** `[sma_md_url]` (+ `id="123"`).
- **Dynamic Tag GenerateBlocks** `{{sma_md_url}}`: auto-registrato se GB 2.x è attivo.
- **Rilevamento conflitti `/llms.txt`**: solo segnali locali (plugin SEO attivi + file fisico).
- `uninstall.php` (rimuove opzioni `sma_*` + transient).
- **Repo/doc**: `readme.txt` (wordpress.org + changelog), `README.md` (GitHub), `CLAUDE.md`
  aggiornato allo stato attuale; rimossi `piano.md` e il task MD temporaneo; branch residuo
  `claude/intelligent-bell-aew3mi` eliminato (rimane solo `main`).

## Da fare

Verso la pubblicazione su wordpress.org:

- **i18n**: stringhe del pannello hardcoded (IT/EN miste) → `__()`/`esc_html__()` con text
  domain `system-markdown-alternate`. (Lavoro più grosso ancora aperto.)
- **`Contributors:`** in `readme.txt` impostato su `system4pc` (account wordpress.org di
  pubblicazione). Fatto.
- Eventuale **auto-yield opt-in** di `/llms.txt` (per ora solo avviso; vedi sotto).
- Idea futura: contenuti `/llms.txt` più ricchi (spec Cloudflare / LLM signals).
- **Test end-to-end** del codice v0.9.0+ su un sito reale (vedi limiti in "Stato attuale").

## Approcci falliti (con motivo)

- **Check HTTP loopback su `/llms.txt`** (bottone "Controlla ora", v0.10.0–0.12.0):
  **rimosso in v0.12.1**. Inaffidabile dietro WAF/CDN (vedi errore esatto sotto), produceva
  messaggi fuorvianti ("HTTP 404 / blocco WAF"), scarso valore. Errore di formulazione mio:
  alla v0.11.0 avevo impacchettato "tieni il bottone" dentro un'opzione che riguardava altro,
  così è rimasto più del dovuto.
- **Rilevamento conflitti leggendo gli interni dei plugin SEO** (Rank Math `rank_math_modules`,
  opzioni Yoast, v0.10.x): **rimosso in v0.11.0**. Fragile e ad alta manutenzione: se il plugin
  cambia i propri interni, il check "mente in silenzio". Sostituito da rilevamento per presenza.
- **Auto-yield automatico di `/llms.txt`** (il nostro endpoint che si spegne da solo):
  **scartato**. Richiederebbe sapere con certezza che un altro lo gestisce → di nuovo la
  lettura fragile degli interni di terzi. Preferito avviso + decisione utente.
- **Settings a tab multi-form** (proposta nel task MD): **non adottata**. L'utente ha scelto
  pagina unica; con storage per-opzione la Settings API nativa basta, evitando hidden-field +
  merge manuale (che avrebbe rischiato di azzerare le opzioni di altri tab).
- **Toggle on/off del Dynamic Tag** (v0.6.0): **rimosso in v0.8.0**. Disattivandolo restavano
  `{{sma_md_url}}` letterali nell'HTML; l'auto-registrazione risolve l'edge case (post non
  servibile → '' → "required to render" nasconde l'elemento).
- **Shortcode `[sma_md_link]`** (v0.5.0): **rimosso in v0.6.0**, l'utente voleva un solo
  shortcode (solo URL).
- **Installare lo zip completo sul sito di test via InstaWP MCP**: **fallito**. Zip ~95KB
  (base64 ~127KB) troppo grande come argomento del tool, nessun URL pubblico. Aggirato con
  test a livello query/logica via `execute_php` e test PHP locali.
- **`WebFetch` della doc GeneratePress** sui dynamic tag: **403 Forbidden**. Aggirato leggendo
  il sorgente di GenerateBlocks 2.2.1 installato sul sito di test.
- *(Inizio sessione, già corretti)*: la PR aveva mergiato la v0.1.0 invece della v0.2.0
  (risolto con cherry-pick); un gate `is_admin()` su `AdminSettings::boot()` impediva ai filtri
  di girare sul front-end → llms.txt vuoto e `.md` non servibile (risolto rimuovendo il gate).

## Decisioni chiave e razionale

- **`sma_markdown_supported_post_types` default vuoto → plugin inattivo** finché non si
  seleziona un tipo. *Razionale*: nessuna esposizione a sorpresa; l'utente attiva esplicitamente.
  `attachment` sempre escluso. CPT supportati (si mostrano tutti i tipi pubblici).
- **Sezioni ACF/GenerateBlocks mostrate solo se il plugin è attivo**; opzioni ACF registrate
  solo se ACF attivo. *Razionale*: UI pulita + evita che `options.php` azzeri i nomi campo ACF
  quando ACF è spento (la Settings API scrive tutte le opzioni registrate del gruppo).
- **Dynamic Tag auto-registrato (niente toggle)**. *Razionale*: tenerlo sempre registrato
  evita i `{{sma_md_url}}` letterali; coerente col pattern "l'integrazione appare se il plugin
  c'è" (come ACF).
- **Conflitti `/llms.txt` solo con segnali locali** (presenza plugin + file fisico).
  *Razionale*: zero manutenzione e zero falsi silenzi; niente dipendenza dagli interni altrui
  né dalla rete (WAF).
- **Pagina settings unica + Settings API nativa** (no tab multi-form). *Razionale*: semplicità,
  performance, nessun merge custom; lo storage è una option per impostazione.
- **Cache via `Cache` helper Redis-aware**. *Razionale*: usa l'object cache persistente se c'è
  (più veloce, niente DB), altrimenti transient.
- **Description Rank Math scartata solo con placeholder `%variabile%`** (regex), non con
  qualsiasi `%`. *Razionale*: non buttare descrizioni valide tipo "Sconto 20%".
- **Identità/workflow**: Author = "Diecieventi Digital Marketing"; "System for PC" mai negli
  artefatti (handle GitHub `system4pc` OK); no model-id negli artefatti; semver `0.x.y`; si
  lavora solo su `main`, sync Mac manuale.

## Stato attuale

### Cosa funziona (verificato)

- **Endpoint `.md` in produzione**: con UA browser `curl -I` su
  `https://webdietrolequinte.it/gallerie-dinamiche-cpt-happyfiles-acf.md` →
  `HTTP/2 200`, `content-type: text/markdown; charset=utf-8`, `x-robots-tag: noindex, follow`,
  `link: <…/>; rel="canonical"`. (screenshot utente)
- **ACF sottotitolo + TL;DR**: confermato dall'utente via screenshot del `.md` (sottotitolo in
  corsivo dopo l'H1, sezione `**TL;DR**` con separatori `---`).
- **`/llms.txt` esclusione password**: verificato con query reale su WP 7.0 — la query nuova
  (`has_password => false`) esclude il post id 85 (`sma-test-5-password`) che la vecchia includeva.
- **Dynamic Tag `{{sma_md_url}}`**: verificato via simulazione su GenerateBlocks 2.2.1
  (registrazione + `replace_tags` → URL `.md`; post protetto → '' → elemento rimosso).
- **Resolver URL relativi e regex Rank Math**: test PHP locali, tutti passati.
- **Logica avviso conflitti + `sanitize_lines` + whitelist post types**: test PHP locali, passati.

### Cosa è rotto

- **Nessuna funzionalità nota come rotta.**
- **Problema ambientale (NON bug del plugin)**: in produzione `curl` senza UA browser è
  bloccato dal RunCloud 8G WAF. Errore esatto dal log:
  `[8G] 37.176.34.158 - "block_bad_bot_rule_3" - - [22/Jun/2026:11:30:09 +0000] "HEAD /gallerie-dinamiche-cpt-happyfiles-acf.md HTTP/2.0" "-" "curl/8.7.1"`
  → risposta `HTTP/2 302, content-type: text/html`. Con UA browser risponde 200 corretto.
- **Limite di verifica**: lo zip v0.9.0+ non è stato installato sul sito di test (troppo grande
  per MCP), quindi **non c'è un test E2E del codice nuovo su sito reale**: le verifiche sono a
  livello query/logica/simulazione + lint `php -l`.

## File toccati

(dal merge della v0.1.0 a HEAD — `git diff --name-status 5ebee39 HEAD`)

**Nuovi (A):**
- `README.md`
- `system-markdown-alternate/readme.txt`
- `system-markdown-alternate/uninstall.php`
- `system-markdown-alternate/assets/admin-settings.css`
- `system-markdown-alternate/src/AcfIntegration.php`
- `system-markdown-alternate/src/AdminSettings.php`
- `system-markdown-alternate/src/Cache.php`
- `system-markdown-alternate/src/ConflictDetector.php`
- `system-markdown-alternate/src/DynamicTags.php`
- `system-markdown-alternate/src/LlmsTxtController.php`
- `system-markdown-alternate/src/Shortcodes.php`

**Modificati (M):**
- `CLAUDE.md`
- `DIST/system-markdown-alternate.zip` (rigenerato a ogni release)
- `system-markdown-alternate/system-markdown-alternate.php` (versione, Author)
- `system-markdown-alternate/src/Plugin.php`
- `system-markdown-alternate/src/MarkdownController.php`
- `system-markdown-alternate/src/ContentRenderer.php`
- `system-markdown-alternate/src/BlockCleaner.php`
- `system-markdown-alternate/src/MetadataBuilder.php`

**Eliminati (D):**
- `piano.md` (piano v1 superato)
- `CLAUDE_CODE_TASK_settings_plugin_markdown.md` (creato e poi eliminato nella stessa sessione)

**Non toccati** (esistenti dalla v1): `src/MarkdownConverter.php`, `src/ShortcodeCleaner.php`,
`composer.json`, `composer.lock`, `bin/build.sh`.

## Prossimo step concreto

Non c'è un task di codice in sospeso assegnato. Il prossimo passo naturale verso wordpress.org è:

1. **i18n** del pannello: avvolgere le stringhe di `src/AdminSettings.php` (e gli avvisi di
   `ConflictDetector`/sezioni) in `esc_html__()` / `__()` con text domain
   `system-markdown-alternate`, caricare il text domain, generare il `.pot`. È il lavoro più
   grosso rimasto.
2. Fatto: `Contributors:` in `readme.txt` impostato su `system4pc` (account wordpress.org
   di pubblicazione).

Prima di iniziare la i18n, decidere se l'UI di default resta in italiano (con traduzione EN) o
viceversa — scelta che condiziona tutte le stringhe.
