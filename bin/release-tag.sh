#!/usr/bin/env bash
#
# Creates and pushes the missing annotated release tags (vX.Y.Z) on origin,
# deriving each tag's notes from that version's entries in CHANGELOG.md (the full
# history; readme.txt only carries the most recent releases, see AGENTS.md).
# Run from the Mac after merging a release PR — agents cannot push tags from
# the Claude Code web environment (the git proxy rejects tag pushes).
#
# Usage:
#   bash bin/release-tag.sh            # create + push whatever is missing
#   bash bin/release-tag.sh --dry-run  # only show what would be done
#
# Idempotent: versions already tagged on origin are skipped; with nothing to do
# it says so and exits. Only versions >= 0.17.1 are considered (older releases
# are intentionally untagged). Everything is read from origin/main, so the
# local checkout/branch state does not matter.

set -euo pipefail

DRY_RUN=0
if [ "${1:-}" = "--dry-run" ]; then
	DRY_RUN=1
fi

cd "$(git rev-parse --show-toplevel)"

PLUGIN_FILE='system-markdown-alternate/system-markdown-alternate.php'
# The FULL history, deliberately not readme.txt: that file is capped at the three
# most recent releases (wordpress.org truncates a Changelog section over 5000
# characters), so parsing it would find no version older than the last few — and
# no `0.17.1` to anchor the gate below.
CHANGELOG='CHANGELOG.md'
MIN_VERSION='0.17.1'

echo '==> Fetching origin…'
git fetch origin main --prune --quiet

# Tags already on origin (one network call, authoritative).
REMOTE_TAGS=$(git ls-remote --tags origin | awk -F'refs/tags/' '{ print $2 }' | sed 's/\^{}$//' | sort -u)

ALL_VERSIONS=$(git show "origin/main:$CHANGELOG" \
	| sed -n 's/^## \([0-9][0-9.]*\)$/\1/p' \
	| sort -V)

# Both guards exist because the failure they catch is silent: an empty version
# list makes the loop below a no-op, and the script then reports "nothing to do"
# and exits 0 — a green workflow run that tagged nothing. That is exactly what
# happened when the changelog moved out of readme.txt, so fail loudly instead.
if [ -z "$ALL_VERSIONS" ]; then
	echo "!!  No '## X.Y.Z' version headings found in $CHANGELOG — refusing to continue." >&2
	exit 1
fi

if ! printf '%s\n' "$ALL_VERSIONS" | grep -qx "$MIN_VERSION"; then
	echo "!!  $MIN_VERSION is not in $CHANGELOG, so the version gate cannot be applied." >&2
	echo "!!  Releases before it are intentionally untagged; fix the gate before tagging." >&2
	exit 1
fi

# Oldest first, from MIN_VERSION onward (earlier releases are intentionally untagged).
VERSIONS=$(printf '%s\n' "$ALL_VERSIONS" | awk -v min="$MIN_VERSION" '$0 == min { seen = 1 } seen')

CREATED=0
for VERSION in $VERSIONS; do
	TAG="v$VERSION"

	if printf '%s\n' "$REMOTE_TAGS" | grep -qx "$TAG"; then
		continue # already on origin
	fi

	# Oldest commit on main touching this exact version string = the commit
	# that bumped SYSMDA_VERSION to it (the squashed release commit).
	COMMIT=$(git log origin/main --reverse --format=%H -S "'SYSMDA_VERSION', '$VERSION'" -- "$PLUGIN_FILE" | head -n 1)
	if [ -z "$COMMIT" ]; then
		echo "!!  $TAG: no commit found bumping SYSMDA_VERSION to $VERSION — skipped."
		continue
	fi

	# Changelog entries of this version = tag notes ("Notes" on the GitHub
	# Tags page). git tag strips leading/trailing blank lines itself.
	NOTES=$(git show "origin/main:$CHANGELOG" | awk -v v="$VERSION" '
		$0 == "## " v { grab = 1; next }
		grab && /^## / { exit }
		grab { print }
	')

	if [ "$DRY_RUN" = 1 ]; then
		echo "--  would tag $TAG on $(git log -1 --format='%h — %s' "$COMMIT")"
		continue
	fi

	# A stale local tag (e.g. created by hand without notes) is replaced.
	if git rev-parse -q --verify "refs/tags/$TAG" > /dev/null; then
		git tag -d "$TAG" > /dev/null
	fi

	git tag -a "$TAG" "$COMMIT" -m "$TAG

$NOTES"
	git push origin "$TAG"
	echo "==> $TAG created on $(git log -1 --format='%h — %s' "$COMMIT") and pushed."
	CREATED=1
done

if [ "$CREATED" = 0 ] && [ "$DRY_RUN" = 0 ]; then
	echo 'Nothing to do: all release tags are already on GitHub.'
fi
