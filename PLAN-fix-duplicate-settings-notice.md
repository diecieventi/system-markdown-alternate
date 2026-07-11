# Piano: fix della doppia notifica “Settings saved”

> File di piano temporaneo, da eliminare dopo l'implementazione del fix e la
> registrazione dell'esito nel changelog di `readme.txt`.

## Problema osservato

Dopo ogni salvataggio della pagina **Settings → Markdown Alternate**, WordPress
mostra due notifiche identiche:

```text
Settings saved.
Settings saved.
```

Il salvataggio delle opzioni funziona; il difetto riguarda esclusivamente il
doppio rendering della conferma.

## Causa confermata

`AdminSettings::render_page()` richiama esplicitamente `settings_errors()` in:

```text
system-markdown-alternate/src/AdminSettings.php
```

La pagina è registrata tramite `add_options_page()` sotto il menu Settings e il
form usa la Settings API con destinazione `options.php`. In questo flusso
WordPress mostra già automaticamente gli avvisi della Settings API dopo il
redirect successivo al salvataggio. La chiamata aggiuntiva dentro `render_page()`
stampa una seconda volta lo stesso avviso.

Riferimento ufficiale:
<https://developer.wordpress.org/reference/functions/settings_errors/>

L'hook `admin_notices` presente nel file bootstrap non è coinvolto: mostra
soltanto l'errore relativo alle dipendenze Composer mancanti.

## Fix proposto

Rimuovere esclusivamente questa chiamata da `AdminSettings::render_page()`:

```php
<?php settings_errors(); ?>
```

Non aggiungere deduplicazione JavaScript o CSS e non modificare form, nonce,
redirect, registrazione delle opzioni o sanitizzazione. WordPress rimane l'unico
responsabile del rendering degli avvisi della Settings API.

## Passi di implementazione

1. Rimuovere la chiamata manuale a `settings_errors()` da
   `system-markdown-alternate/src/AdminSettings.php`.
2. Rimuovere la voce parcheggiata sulla doppia notifica da `AGENTS.md` e
   `AGENTS.it.md`, mantenendo allineate le due versioni.
3. Aggiungere il bugfix al changelog di
   `system-markdown-alternate/readme.txt`.
4. Se il fix viene pubblicato come release autonoma, incrementare la versione
   patch da `0.20.0` a `0.20.1` nel plugin header, in `SYSMDA_VERSION` e nello
   `Stable tag` del readme.
5. Eseguire i controlli automatici e la verifica manuale descritti sotto.
6. In caso di release, rigenerare
   `DIST/system-markdown-alternate.zip` con `bash bin/build.sh`.
7. Eliminare questo file di piano dopo l'implementazione.
8. Creare un commit atomico direttamente su `main`, senza branch o pull request.

## Verifica

### Controlli automatici

```bash
php -l system-markdown-alternate/src/AdminSettings.php
php system-markdown-alternate/tests/run-tests.php
```

Il test suite esistente è di pura logica e non avvia WordPress; per questo il
comportamento visivo della notice deve essere verificato anche in un ambiente
WordPress reale.

### Verifica manuale in WordPress

1. Aprire **Settings → Markdown Alternate**.
2. Modificare un'impostazione e premere **Save Changes**.
3. Verificare che compaia una sola notifica `Settings saved.`.
4. Ricaricare la pagina senza salvare e verificare che non compaiano notifiche
   residue.
5. Controllare che il valore modificato sia stato persistito.
6. Verificare che il redirect continui a contenere `settings-updated=true`.
7. Se una sanitizzazione registra un errore o un warning tramite Settings API,
   verificare che anche tale avviso compaia una sola volta.
8. Controllare che tab, pulsante Save Changes e layout della pagina restino
   invariati.

## Criteri di accettazione

- Ogni salvataggio riuscito produce una sola notifica `Settings saved.`.
- Le impostazioni continuano a essere salvate e sanificate normalmente.
- Gli altri avvisi amministrativi e gli errori della Settings API non vengono
  nascosti.
- Nessuna modifica a API pubbliche, opzioni, filtri, traduzioni o dati salvati.
- Tutti i test e il lint PHP terminano con successo.

