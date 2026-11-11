#!/usr/bin/env bash
#
# Verify that the plugin header, the runtime version constant, the readme
# stable tag and the changelog agree. An optional first argument pins the
# version under validation (a release tag or a manual dispatch input).
#
# Copyright (c) 2026 xNetVN Inc.
# License: GPL-2.0-or-later

set -euo pipefail

root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$root"

expected="${1:-}"

header="$(grep -m1 -oE 'Version: [0-9]+\.[0-9]+\.[0-9]+' xnetvn-agent-hub-wp.php \
  | grep -oE '[0-9]+\.[0-9]+\.[0-9]+' || true)"
constant="$(sed -n "s/^define( 'XNETVN_AGENT_HUB_WP_VERSION', '\([0-9][0-9.]*\)' );.*/\1/p" \
  xnetvn-agent-hub-wp.php | head -1)"
stable="$(sed -n 's/^Stable tag: //p' readme.txt | head -1)"

failures=0

if [ -z "$header" ]; then
  echo "FAIL: the plugin header version is missing." >&2
  failures=$((failures + 1))
fi
if [ -z "$constant" ]; then
  echo "FAIL: XNETVN_AGENT_HUB_WP_VERSION is missing." >&2
  failures=$((failures + 1))
fi
if [ -z "$stable" ]; then
  echo "FAIL: the readme.txt stable tag is missing." >&2
  failures=$((failures + 1))
fi

if [ "$failures" -eq 0 ] && [ "$header" != "$constant" ]; then
  echo "FAIL: the plugin header ($header) and XNETVN_AGENT_HUB_WP_VERSION ($constant) differ." >&2
  failures=$((failures + 1))
fi
if [ "$failures" -eq 0 ] && [ "$header" != "$stable" ]; then
  echo "FAIL: the plugin header ($header) and readme.txt stable tag ($stable) differ." >&2
  failures=$((failures + 1))
fi
if [ -n "$expected" ] && [ "$failures" -eq 0 ] && [ "$header" != "$expected" ]; then
  echo "FAIL: the version under validation ($expected) and the plugin header ($header) differ." >&2
  failures=$((failures + 1))
fi
if [ "$failures" -eq 0 ] && ! grep -Fq "## [$header]" CHANGELOG.md; then
  echo "FAIL: CHANGELOG.md has no section for [$header]." >&2
  failures=$((failures + 1))
fi

if [ "$failures" -ne 0 ]; then
  exit 1
fi

if [ -n "$expected" ]; then
  echo "PASS: version $expected is consistent across the plugin header, the runtime constant, readme.txt and CHANGELOG.md."
else
  echo "PASS: version $header is consistent across the plugin header, the runtime constant, readme.txt and CHANGELOG.md."
fi
