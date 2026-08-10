#!/usr/bin/env bash
#
# Builds the distributable "System Markdown Alternate" zip in DIST/.
# Installs production Composer dependencies and includes them in the zip, so
# Composer is not needed on the production server or test site.
#
# DIST/ is a build output, not a committed artifact: the GitHub Release and the
# wordpress.org deploy both rebuild the package from the tag. Run this when you
# want an installable zip by hand — for a test site, or to inspect what ships.
#
# Usage: bash bin/build.sh
#
set -euo pipefail

# Checked up front, before anything is installed or written. rsync is not
# universally present (it is absent from some minimal container images), and it
# became a hard requirement only when the packaging moved to .distignore — so a
# missing one has to say what it is rather than surface as "command not found"
# halfway through a build.
for REQUIRED in composer rsync zip; do
	if ! command -v "${REQUIRED}" > /dev/null 2>&1; then
		echo "Error: '${REQUIRED}' is required to build the package but was not found." >&2
		exit 1
	fi
done

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

echo "==> Staging package…"
mkdir -p "${STAGE_DIR}"
rsync -a \
	--exclude-from="${PLUGIN_DIR}/.distignore" \
	"${PLUGIN_DIR}/" "${STAGE_DIR}/"

# Built in the staging directory and moved into place only once it is complete,
# so a build that fails partway leaves any previous zip untouched instead of
# deleting it and putting nothing back.
echo "==> Creating zip…"
cd "${STAGE_ROOT}"
zip -r -q "${STAGE_ROOT}/${PLUGIN_SLUG}.zip" "${PLUGIN_SLUG}"

mkdir -p "${DIST_DIR}"
mv -f "${STAGE_ROOT}/${PLUGIN_SLUG}.zip" "${ZIP_PATH}"

echo "==> Done: ${ZIP_PATH}"
