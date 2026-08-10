#!/usr/bin/env bash

# Regression test for bin/release-tag.sh. A large changelog makes the historical
# `git show | awk ... exit` pipeline fail reliably with SIGPIPE under pipefail.

set -euo pipefail

SCRIPT_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)
RELEASE_SCRIPT="$SCRIPT_DIR/release-tag.sh"
TEST_ROOT=$(mktemp -d)
REMOTE="$TEST_ROOT/origin.git"
WORKTREE="$TEST_ROOT/worktree"

cleanup() {
	rm -rf "$TEST_ROOT"
}
trap cleanup EXIT

git init --bare --initial-branch=main "$REMOTE" >/dev/null
git init --quiet --initial-branch=main "$WORKTREE"
cd "$WORKTREE"

git config user.name 'Release test'
git config user.email 'release-test@example.com'
git remote add origin "$REMOTE"

mkdir -p system-markdown-alternate
printf "<?php\ndefine( 'SYSMDA_VERSION', '0.17.1' );\n" \
	> system-markdown-alternate/system-markdown-alternate.php

{
	printf '# Changelog\n\n## 0.17.1\n\n- First release.\n\n## 0.17.2\n\n'
	awk 'BEGIN { for (i = 0; i < 50000; i++) print "- Later release filler." }'
} > CHANGELOG.md

git add CHANGELOG.md system-markdown-alternate/system-markdown-alternate.php
git commit --quiet -m 'Release 0.17.1'
git push --quiet origin main

if ! OUTPUT=$(bash "$RELEASE_SCRIPT" --dry-run 2>&1); then
	printf '%s\n' "$OUTPUT" >&2
	exit 1
fi

case "$OUTPUT" in
	*'would tag v0.17.1'*) ;;
	*)
		printf 'Expected the dry run to find v0.17.1, got:\n%s\n' "$OUTPUT" >&2
		exit 1
		;;
esac

# Existing remote tags must also be skipped without a pipefail/SIGPIPE error.
git tag -a v0.17.1 -m v0.17.1
git push --quiet origin v0.17.1

if ! OUTPUT=$(bash "$RELEASE_SCRIPT" 2>&1); then
	printf '%s\n' "$OUTPUT" >&2
	exit 1
fi

case "$OUTPUT" in
	*'Nothing to do: all release tags are already on GitHub.'*) ;;
	*)
		printf 'Expected an idempotent no-op, got:\n%s\n' "$OUTPUT" >&2
		exit 1
		;;
esac

printf 'release-tag.sh regression test passed.\n'
