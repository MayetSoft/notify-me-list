#!/usr/bin/env bash
# Builds the release ZIP from the committed files (what is in git HEAD, minus
# the export-ignore paths of .gitattributes: no tests, no tooling, no data).
#
#   tools/build-release.sh            -> dist/notify-me-list-<version>.zip (+ .sha256)
#
# The version comes from NM_VERSION in notifyme/bootstrap.php.
set -euo pipefail
cd "$(dirname "$0")/.."

version=$(sed -n "s/^define('NM_VERSION', '\([^']*\)');.*/\1/p" notifyme/bootstrap.php)
if [ -z "$version" ]; then
  echo "Cannot read NM_VERSION from notifyme/bootstrap.php" >&2
  exit 1
fi
if [ -n "$(git status --porcelain -- notifyme www docs README.md LICENSE CHANGELOG.md)" ]; then
  echo "Warning: uncommitted changes are NOT included (the ZIP is built from HEAD)." >&2
fi

name="notify-me-list-${version}"
mkdir -p dist
rm -f "dist/${name}.zip" "dist/${name}.zip.sha256"
git archive --format=zip --prefix="${name}/" -o "dist/${name}.zip" HEAD

# Sanity checks: the two folders are there, nothing private slipped in.
listing=$(unzip -Z1 "dist/${name}.zip")
for required in "${name}/notifyme/bootstrap.php" "${name}/www/install.php" "${name}/README.md" "${name}/notifyme/data/.htaccess"; do
  grep -qxF "$required" <<<"$listing" || { echo "Missing from the ZIP: $required" >&2; exit 1; }
done
if grep -E '(\.sqlite$|/secret\.php$|installed\.lock$|config\.local\.php$|notifyme-path\.php$|/tests/|/tools/|/\.github/)' <<<"$listing"; then
  echo "Unexpected files in the ZIP (see above)" >&2
  exit 1
fi

(cd dist && sha256sum "${name}.zip" > "${name}.zip.sha256")
echo "dist/${name}.zip ($(du -h "dist/${name}.zip" | cut -f1)), $(wc -l <<<"$listing") entries"
