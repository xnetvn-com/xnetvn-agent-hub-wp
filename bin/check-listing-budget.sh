#!/usr/bin/env bash
#
# Verify the public readme stays within the local listing budget and keeps the
# sections WordPress.org expects. This is a local advisory gate, not an
# official WordPress.org rule.
#
# Copyright (c) 2026 xNetVN Inc.
# License: GPL-2.0-or-later

set -euo pipefail

if [ "${1:-}" = '--self-test' ]; then
  fixture=$(mktemp -d)
  trap 'rm -rf -- "$fixture"' EXIT
  mkdir -p "$fixture/valid"
  {
    printf '== Changelog ==\n\n= 1.0.0 =\n'
    printf '== Upgrade Notice ==\n\n= 1.0.0 =\nFirst release.\n'
    for number in $(seq 1 10); do
      printf '%d. Screenshot %d\n' "$number" "$number"
    done
  } > "$fixture/valid/readme.txt"
  if ! bash "$0" "$fixture/valid" >/dev/null; then
    printf 'Listing budget self-test rejected a valid readme.\n' >&2
    exit 1
  fi
  mkdir -p "$fixture/oversize" "$fixture/missing"
  cp "$fixture/valid/readme.txt" "$fixture/oversize/readme.txt"
  dd if=/dev/zero bs=10241 count=1 2>/dev/null | tr '\0' ' ' >> "$fixture/oversize/readme.txt"
  if bash "$0" "$fixture/oversize" >/dev/null 2>&1; then
    printf 'Listing budget self-test accepted an oversized readme.\n' >&2
    exit 1
  fi
  cp "$fixture/valid/readme.txt" "$fixture/missing/readme.txt"
  sed -i '$d' "$fixture/missing/readme.txt"
  if bash "$0" "$fixture/missing" >/dev/null 2>&1; then
    printf 'Listing budget self-test accepted a missing screenshot.\n' >&2
    exit 1
  fi
  printf 'Listing budget self-test passed.\n'
  exit 0
fi

root="$(cd "${1:-$(dirname "$0")/..}" && pwd)"
readme="$root/readme.txt"
failures=0

if [ ! -f "$readme" ]; then
  printf 'FAIL: readme.txt is missing.\n' >&2
  exit 1
fi

size=$(wc -c < "$readme")
if [ "$size" -le 10240 ]; then
  printf 'PASS: readme.txt is %s bytes (limit 10240).\n' "$size"
else
  printf 'FAIL: readme.txt is %s bytes (limit 10240).\n' "$size" >&2
  failures=$((failures + 1))
fi

for marker in '== Changelog ==' '== Upgrade Notice =='; do
  if grep -Fq -- "$marker" "$readme"; then
    printf 'PASS: readme.txt contains %s.\n' "$marker"
  else
    printf 'FAIL: readme.txt is missing %s.\n' "$marker" >&2
    failures=$((failures + 1))
  fi
done

screenshots=$(grep -Ec '^[0-9]+\. ' "$readme" || true)
if [ "$screenshots" -ge 10 ]; then
  printf 'PASS: readme.txt lists %s screenshots.\n' "$screenshots"
else
  printf 'FAIL: readme.txt lists %s screenshots (expected at least 10).\n' "$screenshots" >&2
  failures=$((failures + 1))
fi

if [ "$failures" -ne 0 ]; then
  exit 1
fi
printf 'Listing budget verified.\n'
