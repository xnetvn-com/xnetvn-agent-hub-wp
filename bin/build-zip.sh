#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
plugin_slug=xnetvn-agent-hub-wp
version=$(php -r '$s = file_get_contents("xnetvn-agent-hub-wp.php"); if (!preg_match("/Version: ([0-9]+\\.[0-9]+\\.[0-9]+)/", $s, $m)) { exit(1); } echo $m[1];')
stage=$(mktemp -d)
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/$plugin_slug" dist
# Allowlist: only runtime paths may enter the release ZIP. .distignore stays the
# second net for anything nested inside an allowed path.
for path in "$plugin_slug.php" readme.txt LICENSE uninstall.php index.php composer.json composer.lock src languages templates assets; do
  [[ -e $path ]] || continue
  rsync -a --exclude-from=.distignore -- "$path" "$stage/$plugin_slug/"
done
if [[ -f .local/tools/composer.phar ]]; then
  composer_bin=(php "$PWD/.local/tools/composer.phar")
else
  composer_bin=(composer)
fi
"${composer_bin[@]}" install --working-dir="$stage/$plugin_slug" --no-dev --no-scripts --no-plugins --optimize-autoloader --prefer-dist --no-interaction
rm -f -- "$stage/$plugin_slug/composer.lock"
archive="$PWD/dist/$plugin_slug-$version.zip"
rm -f -- "$archive"
(cd "$stage" && zip -qr "$archive" "$plugin_slug")
bash bin/verify-zip.sh "$archive"
sha256sum "$archive"
