#!/usr/bin/env bash
# Builds the two release zips into dist/ from the committed files:
#
#   yap-reporting-wordpress-<version>.zip   folder yap-reporting/   (WordPress uploads it as an update of the same plugin)
#   yap-reporting-webservice-<version>.zip  folder reporting-api/   (unzipped into the YAP folder)
#
# Each zip contains build.json (version, commit, build time) and checksums.sha256 (every file), and gets a
# <zip>.sha256 next to it. Files are taken with git archive, so nothing uncommitted or ignored can slip in;
# file times are the commit's, so a build of the same commit is byte-for-byte reproducible.
#
# Usage: bin/build.sh [--dev] [--allow-dirty]
#   --dev          mark build.json "development": true (CI builds of commits that are not a release tag)
#   --allow-dirty  build although the working tree has uncommitted changes (still from the last commit)
set -euo pipefail

ROOT=$(cd "$(dirname "$0")/.." && pwd)
DEV=0
DIRTY=0
for arg in "$@"; do
	case "$arg" in
		--dev) DEV=1 ;;
		--allow-dirty) DIRTY=1 ;;
		*) sed -n '2,15p' "$0" | sed 's/^# \{0,1\}//'; exit 2 ;;
	esac
done
cd "$ROOT"
fail() { echo "build: $*" >&2; exit 1; }

# One version everywhere.
V=$(sed -n 's/^ \* Version: *//p' wordpress/yap-reporting/yap-reporting.php)
[ -n "$V" ] || fail "no Version in the plugin header"
check() { [ "$2" = "$V" ] || fail "$1 says $2, the plugin header says $V"; }
check "YAPR_VERSION" "$(sed -n "s/^define( 'YAPR_VERSION', '\(.*\)' );/\1/p" wordpress/yap-reporting/yap-reporting.php)"
check "wordpress/yap-reporting/VERSION" "$(tr -d '[:space:]' < wordpress/yap-reporting/VERSION)"
check "readme.txt Stable tag" "$(sed -n 's/^Stable tag: *//p' wordpress/yap-reporting/readme.txt | tr -d '[:space:]')"
check "webservice VERSION" "$(tr -d '[:space:]' < webservice/reporting-api/VERSION)"
check "App::VERSION" "$(sed -n "s/.*public const VERSION = '\(.*\)';/\1/p" webservice/reporting-api/src/App.php)"
grep -q "^## $V" wordpress/yap-reporting/CHANGELOG.md || fail "wordpress/yap-reporting/CHANGELOG.md has no entry for $V"
grep -q "^## $V" webservice/reporting-api/CHANGELOG.md || fail "webservice/reporting-api/CHANGELOG.md has no entry for $V"

if [ -n "$(git status --porcelain)" ] && [ "$DIRTY" = 0 ]; then
	fail "uncommitted changes (commit them, or use --allow-dirty)"
fi
COMMIT=$(git rev-parse HEAD)
STAMP=$(git log -1 --format=%ct)
if [ "$DEV" = 0 ] && git rev-parse -q --verify "refs/tags/v$V" >/dev/null && [ "$(git rev-parse "v$V^{commit}")" != "$COMMIT" ]; then
	fail "v$V is already tagged on another commit; bump the version (or use --dev for a test build)"
fi

DIST="$ROOT/dist"
mkdir -p "$DIST"
WORK=$(mktemp -d)
trap 'rm -rf "$WORK"' EXIT

package() { # <source dir> <folder name in zip> <zip name> <exclude...>
	local src=$1 name=$2 zip=$3
	shift 3
	local stage="$WORK/$name"
	mkdir -p "$stage"
	git archive --format=tar "$COMMIT" "$src" | tar -x -C "$WORK/x"
	cp -a "$WORK/x/$src/." "$stage/"
	for ex in "$@"; do
		rm -rf "${stage:?}/$ex"
	done
	local files
	files=$(cd "$stage" && find . -type f | wc -l)
	printf '{\n\t"name": "%s",\n\t"version": "%s",\n\t"commit": "%s",\n\t"built": "%s",\n\t"files": %d%s\n}\n' \
		"$name" "$V" "$COMMIT" "$(date -u -d "@$STAMP" +%Y-%m-%dT%H:%M:%SZ)" "$((files + 2))" "$([ "$DEV" = 1 ] && printf ',\n\t"development": true')" > "$stage/build.json"
	(cd "$stage" && find . -type f ! -name checksums.sha256 | LC_ALL=C sort | sed 's#^\./##' | xargs -d '\n' sha256sum > checksums.sha256)
	find "$stage" -exec touch -h -d "@$STAMP" {} +
	rm -f "$DIST/$zip"
	(cd "$WORK" && find "$name" -print | LC_ALL=C sort | TZ=UTC zip -q -X "$DIST/$zip" -@)
	(cd "$DIST" && sha256sum "$zip" > "$zip.sha256")
	echo "  $zip  $(cut -c1-16 < "$DIST/$zip.sha256")…  $((files + 2)) files, $(du -h "$DIST/$zip" | cut -f1)"
}

mkdir -p "$WORK/x"
echo "Building $V from ${COMMIT:0:12}$([ "$DEV" = 1 ] && echo ' (development)')"
# The plugin's pure-PHP tests stay in the repository; the webservice keeps its tests (the setup page's self-test runs them).
package wordpress/yap-reporting yap-reporting "yap-reporting-wordpress-$V.zip" tests
package webservice/reporting-api reporting-api "yap-reporting-webservice-$V.zip" data/clients.json data/secret.key
