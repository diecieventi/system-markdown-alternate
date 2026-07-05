# Assets per la scheda wordpress.org

Immagini della **pagina del plugin** su wordpress.org. **Non** fanno parte del
plugin: vivono nella cartella `/assets` dell'SVN di WP.org, separata da `/trunk`
e `/tags`, quindi **non** vengono incluse nello zip distribuibile.

| File | Uso |
|------|-----|
| `icon-128x128.png` / `icon-256x256.png` | Icona (griglia plugin, risultati di ricerca) |
| `banner-772x250.png` / `banner-1544x500.png` | Banner in cima alla scheda (1x / retina) |

Mancano gli **screenshot** (`screenshot-1.png`, …): vanno catturati dalla UI reale
del plugin (pannello impostazioni, esempio di output `.md`) e descritti nella
sezione `== Screenshots ==` di `readme.txt`.

## Come finiscono su wordpress.org

- **Manuale**: copiare i file in `svn/assets/` e `svn commit`.
- **Automatico**: l'action `10up/action-wordpress-plugin-asset-update` legge
  proprio questa cartella `.wordpress-org/` e la sincronizza con `svn/assets`.

## Rigenerazione

Icona e banner sono generati programmaticamente (Pillow), palette allineata al
pannello admin (ink `#1d2327`, blu WP `#2271b1`). Sono un punto di partenza
pulito, sostituibili con una grafica curata quando vorrai.
