#!/usr/bin/env bash
#
# Builds the distributable "System Markdown Alternate" zip in DIST/.
# Installs production Composer dependencies and includes them in the zip, so
# Composer is not needed on the production server or test site.
#
# Usage: bash bin/build.sh
#
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PLUGIN_SLUG="system-markdown-alternate"
PLUGIN_DIR="${ROOT_DIR}/${PLUGIN_SLUG}"
DIST_DIR="${ROOT_DIR}/DIST"
ZIP_PATH="${DIST_DIR}/${PLUGIN_SLUG}.zip"

echo "==> Installing Composer dependencies (--no-dev)…"
composer install --no-dev --optimize-autoloader --working-dir="${PLUGIN_DIR}"

echo "==> Preparing DIST/…"
mkdir -p "${DIST_DIR}"
rm -f "${ZIP_PATH}"

echo "==> Creating zip…"
cd "${ROOT_DIR}"
zip -r -q "${ZIP_PATH}" "${PLUGIN_SLUG}" \
	-x "${PLUGIN_SLUG}/.git/*" \
	-x "${PLUGIN_SLUG}/tests/*" \
	-x "${PLUGIN_SLUG}/.gitignore" \
	-x "${PLUGIN_SLUG}/.distignore" \
	-x "${PLUGIN_SLUG}/composer.lock" \
	-x "*/tests/*" \
	-x "*/tests/" \
	-x "*/.git/*" \
	-x "*/.git/" \
	-x "*/.github/*" \
	-x "*/.github/" \
	-x "*/.DS_Store"

echo "==> Done: ${ZIP_PATH}"
