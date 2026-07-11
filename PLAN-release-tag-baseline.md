# Piano: riallineamento release e nuovo baseline WordPress.org

> Piano operativo temporaneo. Non esegue né autorizza la cancellazione di tag,
> release o cronologia Git. Da eliminare dopo aver completato e verificato la
> release descritta qui sotto.

## Risposta alla domanda

Non conviene cancellare le vecchie release o i vecchi tag.

La cronologia esistente documenta l'evoluzione reale del plugin, consente di
confrontare regressioni e rende verificabili le modifiche già introdotte.
Cancellare release o tag non renderebbe il codice più pulito, non aiuterebbe la
validazione wordpress.org e trasformerebbe riferimenti pubblici esistenti in
collegamenti non validi.

Lo stato verificato è:

- tag Git: `v0.17.1`, `v0.18.0`, `v0.19.0`, `v0.20.0`;
- GitHub Release pubblicate: `v0.17.1`, `v0.18.0`, `v0.19.0`;
- `v0.20.0` è un tag, ma non una GitHub Release pubblicata;
- il deploy wordpress.org non è ancora attivo perché il plugin non è stato
  accettato e non sono configurate le credenziali SVN.

La soluzione ordinata è conservare tutto e creare, dopo le modifiche correnti,
una nuova release chiaramente identificata come **WordPress.org submission
baseline**. Non deve essere chiamata "initial release", perché non è la prima
release del progetto.

## Versione da usare

Usare `v0.20.1` se il ciclo corrente contiene soltanto:

- correzione della doppia notifica delle impostazioni;
- pulizia delle description contaminate da script/style;
- audit i18n per translate.wordpress.org;
- conversione di commenti e tooling in inglese;
- correzioni documentali, test e manutenzione senza nuove funzionalità.

Queste modifiche sono bugfix e manutenzione, quindi una patch release rispetta
la convenzione SemVer già stabilita dal progetto.

Usare invece `v0.21.0` soltanto se, prima della chiusura del ciclo, viene
implementata una nuova funzionalità pubblica, per esempio il contatore degli
accessi `.md`. Non aumentare la minor version solo per dare l'impressione di un
nuovo inizio e non passare a `v1.0.0` per ragioni puramente estetiche.

Il piano seguente assume che non vengano aggiunte nuove funzionalità e fissa
quindi il baseline a **`v0.20.1`**.

## Conservazione della cronologia

- Non eliminare né spostare `v0.17.1`, `v0.18.0`, `v0.19.0` o `v0.20.0`.
- Non cancellare le GitHub Release esistenti.
- Non riscrivere commit già pubblicati e non eseguire force-push.
- Non creare un nuovo repository e non comprimere la cronologia in un commit
  iniziale artificiale.
- Non pubblicare retroattivamente una GitHub Release `v0.20.0`: dopo le
  modifiche correnti sarebbe già superata e potrebbe diventare una sorgente di
  confusione.
- Lasciare `v0.19.0` come ultima release GitHub pubblicata fino alla chiusura del
  nuovo baseline.

Il tag `v0.20.0` punta a uno stato nel quale il codice della versione è già
presente, anche se include anche un successivo documento di piano. Non è
necessario correggerlo retroattivamente: la nuova release deve invece applicare
rigorosamente la regola "tag sul commit esatto di release".

## Preparazione del baseline `v0.20.1`

### 1. Chiudere le modifiche correnti

Completare prima tutti gli interventi approvati per questa patch:

- fix della doppia notifica `Settings saved`;
- fix delle description con contenuti `script`/`style`, se confermato;
- audit definitivo delle stringhe traducibili per wordpress.org;
- rimozione di traduzioni e loader locali;
- conversione in inglese di commenti, DocBlock e tooling;
- aggiornamento coordinato della documentazione inglese e italiana;
- eliminazione dei file di piano relativi a lavori completati.

Ogni intervento deve avere un commit atomico su `main`. Nessun branch e nessuna
pull request.

### 2. Eseguire il bump di release

In un commit finale dedicato alla release:

- aggiornare `Version:` da `0.20.0` a `0.20.1` nel file principale;
- aggiornare `SYSMDA_VERSION` allo stesso valore;
- aggiornare `Stable tag` a `0.20.1` in `readme.txt`;
- aggiungere una sezione changelog `0.20.1` concisa ma completa;
- verificare che README e guide descrivano lo stato effettivo;
- non includere nel commit file temporanei, screenshot locali o piani già
  completati.

Il messaggio del commit deve identificare chiaramente il baseline, per esempio:

```text
fix: finalize WordPress.org submission baseline (v0.20.1)
```

Non inserire il modello utilizzato nel messaggio di commit.

### 3. Validare il contenuto

Eseguire prima di creare il tag:

```bash
find system-markdown-alternate -path '*/vendor/*' -prune -o -name '*.php' -print \
  -exec php -l {} \;
php system-markdown-alternate/tests/run-tests.php
bash bin/build.sh
unzip -l DIST/system-markdown-alternate.zip
```

Verificare inoltre:

- Plugin Check senza errori bloccanti;
- nessun file di traduzione incluso;
- nessun caricamento manuale delle traduzioni;
- text domain uguale allo slug;
- versione `0.20.1` coerente in header, costante, stable tag e changelog;
- ZIP contenente `vendor/` ma non test, Composer metadata o file di sviluppo;
- working tree pulito dopo il build e commit finale;
- nessun riferimento al precedente nome aziendale.

### 4. Creare il tag corretto

Solo dopo che il commit di release è su `main` e tutti i controlli sono passati:

```bash
git tag -a v0.20.1 -m "v0.20.1 — WordPress.org submission baseline"
```

Il tag deve puntare esattamente al commit che contiene bump, changelog e ZIP
finale. Non aggiungere altri commit tra il commit di release e la creazione del
tag.

Eseguire un controllo esplicito:

```bash
git show --no-patch --decorate v0.20.1
git diff v0.20.1^ v0.20.1 -- \
  system-markdown-alternate/system-markdown-alternate.php \
  system-markdown-alternate/readme.txt
```

### 5. Pubblicazione GitHub e wordpress.org

Ordine previsto:

1. push di `main`;
2. push del solo nuovo tag `v0.20.1`;
3. verifica che GitHub mostri tag e commit corretti;
4. creazione di una GitHub Release **draft** associata a `v0.20.1`;
5. allegare `DIST/system-markdown-alternate.zip` alla draft e usare come titolo
   `v0.20.1 — WordPress.org submission baseline`;
6. non pubblicare la Release finché il plugin non è accettato su wordpress.org
   e le credenziali SVN non sono configurate;
7. dopo l'accettazione, configurare `SVN_USERNAME` e `SVN_PASSWORD`, verificare
   ancora il workflow e pubblicare la draft;
8. controllare il deploy SVN di trunk e tag `0.20.1`.

La Release pubblicata diventerà il nuovo riferimento principale del progetto;
le release precedenti resteranno disponibili come cronologia, senza interferire
con lo stable tag corrente.

## Contenuto della GitHub Release

La descrizione non deve presentare `0.20.1` come prima release assoluta. Deve
spiegare che è il primo baseline consolidato per la candidatura wordpress.org,
per esempio:

```markdown
## WordPress.org submission baseline

This release consolidates the plugin prefix and namespace changes, WordPress.org
internationalization requirements, settings-page fixes, Markdown description
cleanup, English internal documentation, and final packaging checks.

It is the recommended baseline for WordPress.org submission and new installs.
```

Includere anche le note di compatibilità necessarie per il cambio breaking dei
prefissi avvenuto in `0.20.0`, in particolare la necessità di salvare nuovamente
le impostazioni dopo l'aggiornamento da versioni precedenti.

## Criteri di accettazione

- Tutti i vecchi tag e le vecchie release restano intatti.
- Nessuna cronologia Git viene riscritta.
- Il baseline usa `v0.20.1`, salvo introduzione reale di una nuova funzionalità.
- `v0.20.1` punta al commit esatto che contiene versione, stable tag, changelog
  e artefatto finali.
- La GitHub Release resta draft finché il deploy wordpress.org non può essere
  eseguito correttamente.
- Dopo la pubblicazione, `v0.20.1` è la release Latest e il riferimento per nuove
  installazioni e candidatura wordpress.org.
- Il workflow SVN viene attivato soltanto dopo accettazione e configurazione dei
  secret.
- Non vengono creati branch o pull request; la destinazione finale resta `main`.

