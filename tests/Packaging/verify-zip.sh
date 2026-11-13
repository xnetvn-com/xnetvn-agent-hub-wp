#!/usr/bin/env bash
# Structural release regression fixtures; no application or service is started.
set -euo pipefail
cd "$(dirname "$0")/../.."
if ! grep -Fxq '.gitleaksignore' .distignore; then
  printf 'Distribution ignore rules must exclude .gitleaksignore.\n' >&2
  exit 1
fi
if ! grep -Fxq 'skills' .distignore; then
  printf 'Distribution ignore rules must exclude skills.\n' >&2
  exit 1
fi
fixture=$(mktemp -d)
trap 'rm -rf -- "$fixture"' EXIT
mkdir -p "$fixture/xnetvn-agent-hub-wp/vendor" "$fixture/xnetvn-agent-hub-wp/src/Bootstrap" "$fixture/xnetvn-agent-hub-wp/assets" "$fixture/xnetvn-agent-hub-wp/languages"
for required in xnetvn-agent-hub-wp.php readme.txt LICENSE uninstall.php vendor/autoload.php src/Bootstrap/Loader.php assets/admin.js languages/xnetvn-agent-hub-wp.pot; do
  touch "$fixture/xnetvn-agent-hub-wp/$required"
done
(cd "$fixture" && zip -qr valid.zip xnetvn-agent-hub-wp)
bash bin/verify-zip.sh "$fixture/valid.zip" >/dev/null
cp "$fixture/valid.zip" "$fixture/missing-src.zip"
(cd "$fixture" && zip -q -d missing-src.zip 'xnetvn-agent-hub-wp/src/*' 'xnetvn-agent-hub-wp/src/')
if bash bin/verify-zip.sh "$fixture/missing-src.zip" >/dev/null 2>&1; then
  printf 'Release accepted a ZIP without src/.\n' >&2
  exit 1
fi
rm -f -- "$fixture/missing-src.zip"
for excluded in AGENTS.md CLAUDE.md opencode.json opencode.jsonc .agents/README.md .claude/skills/example/SKILL.md .github/copilot-instructions.md SUPPORT.md CHANGELOG.md README.md SECURITY.md scripts/sbom/convert_composer_to_spdx.py skills/xnetvn-agent-hub-wp/SKILL.md .env .env.rtl .dockerignore .distignore .gitleaks.toml .gitleaksignore compose.yaml compose.ui-network.yaml rtl.override.yml infra/remote/config.php; do
  mkdir -p "$(dirname "$fixture/xnetvn-agent-hub-wp/$excluded")"
  touch "$fixture/xnetvn-agent-hub-wp/$excluded"
  cp "$fixture/valid.zip" "$fixture/invalid.zip"
  (cd "$fixture" && zip -q invalid.zip "xnetvn-agent-hub-wp/$excluded")
  if bash bin/verify-zip.sh "$fixture/invalid.zip" >/dev/null 2>&1; then
    printf 'Release accepted an excluded file: %s\n' "$excluded" >&2
    exit 1
  fi
  rm -f -- "$fixture/xnetvn-agent-hub-wp/$excluded" "$fixture/invalid.zip"
done
printf 'Release exclusion fixtures passed.\n'
