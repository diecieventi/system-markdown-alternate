#!/usr/bin/env bash
#
# Build dello zip distribuibile di "System Markdown Alternate" in DIST/.
# Installa le dipendenze Composer di produzione e le include nello zip,
# così non serve Composer sul server di produzione / sito di test.
#
# Uso:  bash bin/build.sh
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="system-markdown-alternate"
PLUGIN_DIR="${ROOT_DIR}/${PLUGIN_SLUG}"
DIST_DIR="${ROOT_DIR}/DIST"
ZIP_PATH="${DIST_DIR}/${PLUGIN_SLUG}.zip"

echo "==> Installazione dipendenze Composer (--no-dev)…"
composer install --no-dev --optimize-autoloader --working-dir="${PLUGIN_DIR}"

echo "==> Preparazione DIST/…"
mkdir -p "${DIST_DIR}"
rm -f "${ZIP_PATH}"

echo "==> Creazione zip…"
cd "${ROOT_DIR}"
zip -r -q "${ZIP_PATH}" "${PLUGIN_SLUG}" \
	-x "${PLUGIN_SLUG}/.git/*" \
	-x "${PLUGIN_SLUG}/tests/*" \
	-x "${PLUGIN_SLUG}/.gitignore" \
	-x "*/.DS_Store"

echo "==> Fatto: ${ZIP_PATH}"
