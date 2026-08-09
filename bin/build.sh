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
	-x "${PLUGIN_SLUG}/phpcs.xml.dist" \
	-x "${PLUGIN_SLUG}/phpcs.xml" \
	-x "${PLUGIN_SLUG}/vendor/bin/*" \
	-x "${PLUGIN_SLUG}/vendor/bin/" \
	-x "${PLUGIN_SLUG}/vendor/league/html-to-markdown/bin/*" \
	-x "${PLUGIN_SLUG}/vendor/league/html-to-markdown/bin/" \
	-x "*/tests/*" \
	-x "*/tests/" \
	-x "*/.git/*" \
	-x "*/.git/" \
	-x "*/.github/*" \
	-x "*/.github/" \
	-x "*/.DS_Store"

# Provenance, so a reviewer can tell an intended release snapshot from a package
# that quietly fell behind. DIST/ holds a committed artifact, NOT a build of
# HEAD: post-release commits that change no version leave the zip correct and
# its readme.txt different from main, and without this there was no way to see
# which of the two situations you were looking at.
INFO_PATH="${DIST_DIR}/BUILD-INFO.txt"

PLUGIN_VERSION="$(sed -nE "s/.*'SYSMDA_VERSION',[[:space:]]*'([^']+)'.*/\1/p" "${PLUGIN_DIR}/${PLUGIN_SLUG}.php")"
PLUGIN_VERSION="${PLUGIN_VERSION%%$'\n'*}"

GIT_COMMIT="$(git -C "${ROOT_DIR}" rev-parse HEAD 2>/dev/null || echo 'unknown')"
GIT_DESCRIBE="$(git -C "${ROOT_DIR}" describe --tags --always --dirty 2>/dev/null || echo 'unknown')"

{
	echo "plugin_version: ${PLUGIN_VERSION}"
	echo "git_commit: ${GIT_COMMIT}"
	echo "git_describe: ${GIT_DESCRIBE}"
	echo "built_at: $(date -u '+%Y-%m-%dT%H:%M:%SZ')"
} > "${INFO_PATH}"

echo "==> Done: ${ZIP_PATH}"
echo "    ${INFO_PATH} (${PLUGIN_VERSION}, ${GIT_DESCRIBE})"
