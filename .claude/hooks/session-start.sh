#!/bin/bash
#
# SessionStart hook per Claude Code on the web.
#
# Prepara gli strumenti i18n NON preinstallati nel container effimero, così che
# `bash bin/make-i18n.sh` (wp i18n make-pot → msgmerge → msgfmt → wp i18n make-php)
# funzioni senza setup manuale. PHP, Composer e curl sono già presenti.
#
# Sincrono e idempotente: se i tool ci sono già (stato del container cachato dopo
# la prima esecuzione) non fa nulla.
set -euo pipefail

# Solo in ambiente remoto (Claude Code on the web): in locale non tocca la macchina.
if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
	exit 0
fi

installed=()

# gettext → fornisce msgfmt / msgmerge / xgettext (compilazione .mo, allineamento .po).
if ! command -v msgfmt >/dev/null 2>&1; then
	apt-get update -qq >/dev/null 2>&1 || true
	DEBIAN_FRONTEND=noninteractive apt-get install -y -qq gettext >/dev/null 2>&1
	installed+=("gettext")
fi

# wp-cli → fornisce `wp` (i18n make-pot / make-php). Phar ufficiale.
if ! command -v wp >/dev/null 2>&1; then
	curl -fsSL -o /usr/local/bin/wp \
		https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar
	chmod +x /usr/local/bin/wp
	installed+=("wp-cli")
fi

if [ "${#installed[@]}" -gt 0 ]; then
	echo "session-start: i18n tooling installato (${installed[*]})."
else
	echo "session-start: i18n tooling già presente (gettext, wp-cli)."
fi
