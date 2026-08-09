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
STAGE_ROOT="$(mktemp -d "${TMPDIR:-/tmp}/sysmda-build.XXXXXX")"
STAGE_DIR="${STAGE_ROOT}/${PLUGIN_SLUG}"

trap 'rm -rf "${STAGE_ROOT}"' EXIT

echo "==> Installing Composer dependencies (--no-dev)…"
composer install --no-dev --optimize-autoloader --working-dir="${PLUGIN_DIR}"

echo "==> Preparing DIST/…"
mkdir -p "${DIST_DIR}"
rm -f "${ZIP_PATH}"

echo "==> Staging package…"
mkdir -p "${STAGE_DIR}"
rsync -a \
	--exclude-from="${PLUGIN_DIR}/.distignore" \
	"${PLUGIN_DIR}/" "${STAGE_DIR}/"

echo "==> Creating zip…"
cd "${STAGE_ROOT}"
zip -r -q "${ZIP_PATH}" "${PLUGIN_SLUG}"

echo "==> Done: ${ZIP_PATH}"
