# Piano: fix description sporcata da script/style (front matter .md + /llms.txt)

> File di piano temporaneo, da cancellare una volta applicato il fix e riportato
> l'esito nel changelog di `readme.txt`. Non fa parte della documentazione
> permanente del progetto.

## Bug osservato (due sintomi, stessa causa)

1. **`/llms.txt`** (screenshot utente, 2026-07-11): le voci **Cookie Policy** e
   **Privacy Policy** mostrano come description il codice JS dell'embed
   Iubenda invece di un testo leggibile:

   ```
   - [Cookie Policy](https://webdietrolequinte.it/cookie-policy.md): Cookie Policy
     (function (w,d) {var loader = function () {var s = d.createElement("script"),
     tag = d.getElementsByTagName("script")[0]; s.src="https://cdn.iubenda.com/iubenda.js";…
   ```

2. **Front matter della pagina `.md`** (screenshot utente, 2026-07-11,
   `cookie-policy.md`): stesso identico problema nel campo `description:` del
   front matter YAML:

   ```yaml
   ---
   title: "Cookie Policy"
   url: "https://webdietrolequinte.it/cookie-policy/"
   markdown_url: "https://webdietrolequinte.it/cookie-policy.md"
   date_published: "2026-07-07T19:41:24+02:00"
   date_modified: "2026-07-07T19:41:40+02:00"
   author: "Fabio Balossi"
   description: "Cookie Policy (function (w,d) {var loader = function () {var s = d.createElement(\"script\"), tag = d.getElementsByTagName(\"script\")[0]; s.src=\"https://cdn.iubenda.com/iubenda.js\";…"
   ---

   # Cookie Policy

   [Cookie Policy](https://www.iubenda.com/privacy-policy/75148177/cookie-policy "Cookie Policy")
   ```

   Notare che il **corpo** del `.md` (sotto il front matter) è pulito: lo
   script Iubenda non compare, resta solo il link. Il problema è isolato al
   campo `description`.

## Causa (unica, in un solo metodo)

Entrambi i sintomi vengono dalla stessa funzione:
`MetadataBuilder::description()` (`system-markdown-alternate/src/MetadataBuilder.php`,
righe ~153-184), usata sia da:
- `build_front_matter()` (riga 44, campo `description:` del front matter `.md`), sia da
- `LlmsTxtController::item_line()` (per le voci arricchite di `/llms.txt`).

Catena di fallback: Rank Math description → excerpt → contenuto troncato. Per
le pagine Cookie/Privacy Policy non c'è probabilmente né Rank Math description
né excerpt, quindi si cade sul fallback che legge `post_content` grezzo
(riga 170).

Lo strip attuale nel fallback (righe 171-176):
```php
$raw = $this->shortcodes->strip( $raw );
$raw = strip_shortcodes( $raw );
$raw = preg_replace( '/<!--.*?-->/s', ' ', $raw );
$raw = preg_replace( '/<[^>]+>/', ' ', $raw );     // toglie solo i TAG <script>/<style>
$text = trim( preg_replace( '/\s+/', ' ', wp_strip_all_tags( $raw ) ) );
```

rimuove i tag `<script>`/`<style>` ma **non il loro contenuto testuale**. Se
il post_content contiene un blocco HTML custom con lo snippet Iubenda (o
qualunque script/tracking embed), il JS interno resta come testo puro e viene
troncato a 200 caratteri → esattamente il risultato negli screenshot.

### Perché il BODY del .md invece è pulito (verifica pipeline fatta)

Il corpo della pagina passa per un percorso diverso e più robusto:
`MarkdownConverter.php` (riga ~33) configura la libreria
`league/html-to-markdown` con:
```php
'remove_nodes' => 'script style iframe',
```
Questa opzione della libreria rimuove il **nodo intero** (tag + contenuto
interno) durante la conversione HTML→Markdown — non un semplice strip dei
tag via regex. Per questo il body risulta pulito mentre la description, che
non passa da questa libreria ma da un semplice `preg_replace` in
`MetadataBuilder::description()`, resta sporca.

**Conclusione della verifica richiesta**: la pipeline del corpo gestisce già
correttamente script/style/iframe (rimozione del nodo completo via libreria).
Il fallback description no: è l'unico punto debole, e un solo fix lo risolve
per entrambi i sintomi (front matter + llms.txt), perché condividono lo stesso
metodo.

## Fix proposto

In `MetadataBuilder::description()`, prima dello strip generico dei tag
(riga ~174), aggiungere una rimozione esplicita del contenuto di
`<script>`/`<style>` (non solo dei tag), speculare a quanto la libreria fa
già per il body:

```php
$raw = preg_replace( '/<(script|style)\b[^>]*>.*?<\/\1>/is', ' ', $raw );
```

da inserire subito dopo la rimozione dei commenti (riga 173) e prima dello
strip dei tag generici (riga 174), così il testo interno non sopravvive alla
successiva rimozione dei soli tag.

## Passi di implementazione

1. Applicare la modifica in
   `system-markdown-alternate/src/MetadataBuilder.php` (metodo `description()`).
2. `php -l system-markdown-alternate/src/MetadataBuilder.php`.
3. Verificare/aggiungere un test in
   `system-markdown-alternate/tests/run-tests.php` che copra il caso:
   `post_content` con un blocco `<script>...</script>` (es. simile a Iubenda)
   → `description()` non deve contenere il codice JS. Aggiungere anche un
   caso con `<style>...</style>` per simmetria.
4. `php system-markdown-alternate/tests/run-tests.php` (pure-logic, no WP).
5. Verifica manuale (se possibile via ambiente WP/staging):
   - rigenerare il `.md` di `cookie-policy` e `privacy-policy` (invalidare
     cache) e controllare che il campo `description:` del front matter sia
     pulito (o vuoto, se non resta altro testo utile);
   - rigenerare `/llms.txt` e controllare che le stesse voci mostrino una
     description pulita nell'indice arricchito.
   - Se dopo lo strip di script/style il testo risultante è vuoto o troppo
     povero (es. la pagina è *solo* uno script embed, come nel caso Iubenda),
     valutare se sia meglio omettere del tutto la description invece di
     mostrare un frammento povero o vuoto — vedi "Nota collaterale" sotto.
6. Aggiornare `readme.txt` (changelog) con il fix; valutare se serve un bump
   di versione **patch** (bugfix, non nuova feature) secondo le regole semver
   del progetto (`AGENTS.md` → "Identity, versioning, workflow").
7. `bash bin/build.sh` se si procede al bump/release.
8. Commit (no PR, solo `main`, come da regola del progetto) — solo se
   l'utente conferma il fix dopo revisione.
9. Cancellare questo file di piano dopo l'implementazione.

## Nota collaterale

Vale la pena controllare anche se altre pagine "di servizio" (Cookie Policy,
Privacy Policy, Termini, ecc. — spesso senza excerpt/Rank Math description
impostati) generano output simili con altri embed/tracking script, per capire
se il fix risolve il problema in generale o se serve un controllo più ampio
sul fallback (es. escludere queste pagine da `/llms.txt` e dalla description
del front matter, o forzare un excerpt manuale come raccomandazione lato
utente). Nel caso specifico di Cookie/Privacy Policy, il contenuto reale della
pagina è *solo* l'embed Iubenda: dopo lo strip di script/style potrebbe restare
pochissimo testo utile (es. solo "Cookie Policy") — da valutare se è
accettabile o se conviene un fallback ulteriore (es. tagline del sito o
stringa vuota) quando il testo residuo è troppo corto per essere una
description sensata.
