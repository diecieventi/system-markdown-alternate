#!/usr/bin/env bash
#
# Rigenera i file di traduzione del plugin usando la toolchain standard (wp-cli +
# gettext). Flusso canonico wordpress.org:
#
#   1. wp i18n make-pot   → estrae i msgid dal codice (.pot, con riferimenti
#                           #: file:riga e commenti #. translators).
#   2. msgmerge           → aggiorna ogni .po di lingua mantenendo le traduzioni
#                           esistenti e allineandole al nuovo .pot.
#   3. msgfmt             → compila il .mo (fallback per WP 6.0–6.4).
#   4. wp i18n make-php   → genera il .l10n.php (preferito da WP 6.5+).
#
# La fonte di verità delle traduzioni è il file .po (modificabile a mano o con
# Poedit). NON modificare a mano .pot/.mo/.l10n.php: sono rigenerati.
#
# Dipendenze: wp-cli (`wp`) e gettext (`msgfmt`, `msgmerge`). L'ambiente di
# sviluppo remoto è effimero: se mancano, installale così (Ubuntu, come root):
#
#   apt-get install -y gettext
#   curl -sSL -o /usr/local/bin/wp \
#     https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
#   chmod +x /usr/local/bin/wp
#
# Uso:  bash bin/make-i18n.sh
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="system-markdown-alternate"
PLUGIN_DIR="${ROOT_DIR}/${PLUGIN_SLUG}"
LANG_DIR="${PLUGIN_DIR}/languages"
POT="${LANG_DIR}/${PLUGIN_SLUG}.pot"

# wp-cli gira come root solo con --allow-root; innocuo se non root.
WP_FLAGS="--allow-root"

for bin in wp msgfmt msgmerge; do
	if ! command -v "$bin" >/dev/null 2>&1; then
		echo "ERRORE: '$bin' non trovato. Vedi le istruzioni di installazione in cima a questo script." >&2
		exit 1
	fi
done

mkdir -p "${LANG_DIR}"

echo "==> 1/4 make-pot (estrazione stringhe dal codice)…"
wp ${WP_FLAGS} i18n make-pot "${PLUGIN_DIR}" "${POT}" \
	--exclude=vendor,tests,languages \
	--domain="${PLUGIN_SLUG}"

echo "==> 2/4 msgmerge (aggiorna i .po di lingua)…"
shopt -s nullglob
for po in "${LANG_DIR}/${PLUGIN_SLUG}"-*.po; do
	echo "    - $(basename "${po}")"
	msgmerge --update --backup=none --no-fuzzy-matching "${po}" "${POT}"
done

echo "==> 3/4 msgfmt (compila i .mo)…"
for po in "${LANG_DIR}/${PLUGIN_SLUG}"-*.po; do
	msgfmt "${po}" -o "${po%.po}.mo"
done

echo "==> 4/4 make-php (genera i .l10n.php per WP 6.5+)…"
wp ${WP_FLAGS} i18n make-php "${LANG_DIR}"

echo "==> Fatto. File in ${LANG_DIR}"
