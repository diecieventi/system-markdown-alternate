#!/usr/bin/env bash
#
# Creates and pushes the missing annotated release tags (vX.Y.Z) on origin,
# deriving each tag's notes from that version's changelog entries in readme.txt.
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
README='system-markdown-alternate/readme.txt'

echo '==> Fetching origin…'
git fetch origin main --prune --quiet

# Tags already on origin (one network call, authoritative).
REMOTE_TAGS=$(git ls-remote --tags origin | awk -F'refs/tags/' '{ print $2 }' | sed 's/\^{}$//' | sort -u)

# Versions listed in the changelog, oldest first, from 0.17.1 onward.
VERSIONS=$(git show "origin/main:$README" \
	| sed -n 's/^= \([0-9][0-9.]*\) =$/\1/p' \
	| sort -V \
	| awk '$0 == "0.17.1" { seen = 1 } seen')

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
	NOTES=$(git show "origin/main:$README" | awk -v v="$VERSION" '
		$0 == "= " v " =" { grab = 1; next }
		grab && /^= [0-9]/ { exit }
		grab && /^== /     { exit }
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
