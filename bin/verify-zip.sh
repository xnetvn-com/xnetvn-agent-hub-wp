#!/usr/bin/env bash
set -euo pipefail
archive=${1:?Pass the release ZIP path.}
entries=$(unzip -Z1 "$archive")
for required in xnetvn-agent-hub-wp.php vendor/autoload.php readme.txt LICENSE uninstall.php src/Bootstrap/Loader.php assets/admin.js languages/xnetvn-agent-hub-wp.pot; do
  if ! grep -Fxq "xnetvn-agent-hub-wp/$required" <<< "$entries"; then
    echo "Missing required release file: $required" >&2
    exit 1
  fi
done
if grep -Eq '^xnetvn-agent-hub-wp/(AGENTS\.md$|CLAUDE\.md$|opencode\.jsonc?$|SUPPORT\.md$|CHANGELOG\.md$|README\.md$|SECURITY\.md$|tests/|scripts/|\.distignore$|\.[^/]+|docs/|skills/|bin/|infra/|node_modules/|compose[^/]*\.ya?ml$|rtl\.override\.yml$|phpunit|phpcs|phpstan|composer\.lock$|package(-lock)?\.json|vendor/(phpunit|squizlabs|brain|mockery|phpstan|wp-phpunit|yoast|sebastian|wp-coding-standards|phpcompatibility|slevomat|dealerdirect)/)' <<< "$entries"; then
  echo 'Release contains excluded development files.' >&2
  exit 1
fi
if grep -Ev '^xnetvn-agent-hub-wp/([^/]+/)*[^/]*$' <<< "$entries" || grep -Eq '(^|/)\.\.(/|$)' <<< "$entries"; then
  echo 'Release contains an invalid archive path.' >&2
  exit 1
fi
echo 'Release ZIP structure verified.'
