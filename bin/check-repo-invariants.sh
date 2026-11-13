#!/usr/bin/env bash
#
# Verify the repository invariants that must hold on every revision: the
# license text, the license declarations, the changelog format, the security
# contact and the secret-scanning and translation files.
#
# Usage: bash bin/check-repo-invariants.sh [--root PATH]
#        bash bin/check-repo-invariants.sh --self-test
#        bash bin/check-repo-invariants.sh --help
# Output: one PASS or FAIL line per invariant; exit 0 when every invariant
# passes, 1 when any invariant fails and 2 on a usage error.
#
# Copyright (c) 2026 xNetVN Inc.
# License: GPL-2.0-or-later

set -euo pipefail

script_path="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
fixtures=""
fixtures_run=0
failures=0

usage() {
  cat <<'EOF'
Usage: bash bin/check-repo-invariants.sh [OPTION]
Print PASS or FAIL for each repository invariant.

  --root PATH  read the checked files under PATH instead of the repository root
  --self-test  run the fixture suite against literal copies and exit
  --help       print this usage and exit

Exit status: 0 when every invariant passes, 1 when any invariant fails and
2 on a usage error.
EOF
}

cleanup_fixtures() {
  if [ -n "$fixtures" ] && [ -d "$fixtures" ]; then
    rm -rf "$fixtures"
  fi
}

report_pass() {
  printf 'PASS: %s %s\n' "$1" "$2"
}

report_fail() {
  printf 'FAIL: %s %s\n' "$1" "$2" >&2
  failures=$((failures + 1))
}

check_contains() {
  local id="$1"
  local file="$2"
  local description="$3"
  shift 3
  local path="$root/$file"
  local phrase
  if [ ! -f "$path" ]; then
    report_fail "$id" "$description"
    return
  fi
  for phrase in "$@"; do
    if ! grep -Fq -- "$phrase" "$path"; then
      report_fail "$id" "$description"
      return
    fi
  done
  report_pass "$id" "$description"
}

check_exists() {
  local id="$1"
  local file="$2"
  local description="$3"
  if [ -f "$root/$file" ]; then
    report_pass "$id" "$description"
  else
    report_fail "$id" "$description"
  fi
}

run_checks() {
  root="$1"
  failures=0
  check_contains INV-001 LICENSE "LICENSE contains the GPLv2 text" \
    "GNU GENERAL PUBLIC LICENSE" "Version 2, June 1991"
  check_contains INV-002 composer.json "composer.json declares GPL-2.0-or-later" \
    '"license": "GPL-2.0-or-later"'
  check_contains INV-003 readme.txt "readme.txt declares GPLv2 or later" \
    "License: GPLv2 or later"
  check_contains INV-004 CHANGELOG.md "CHANGELOG.md declares the Keep a Changelog format" \
    "Keep a Changelog"
  check_contains INV-005 CHANGELOG.md "CHANGELOG.md has an Unreleased section" \
    "## [Unreleased]"
  check_contains INV-006 SECURITY.md "SECURITY.md names the private security contact" \
    "security@xnetvn.net"
  check_exists INV-007 .gitleaks.toml "the Gitleaks secret-scanning configuration exists"
  check_exists INV-008 languages/xnetvn-agent-hub-wp.pot "the translation template exists"
  if [ "$failures" -ne 0 ]; then
    return 1
  fi
  return 0
}

build_positive_root() {
  local target="$1"
  mkdir -p "$target/languages"
  cat > "$target/LICENSE" <<'EOF'
GNU GENERAL PUBLIC LICENSE
Version 2, June 1991
EOF
  cat > "$target/composer.json" <<'EOF'
{
  "name": "fixture/root",
  "license": "GPL-2.0-or-later"
}
EOF
  cat > "$target/readme.txt" <<'EOF'
=== Fixture ===
License: GPLv2 or later
EOF
  cat > "$target/CHANGELOG.md" <<'EOF'
# Changelog

Keep a Changelog

## [Unreleased]
EOF
  cat > "$target/SECURITY.md" <<'EOF'
# Security

Contact: security@xnetvn.net
EOF
  printf 'title = "fixture"\n' > "$target/.gitleaks.toml"
  printf 'msgid ""\n' > "$target/languages/xnetvn-agent-hub-wp.pot"
}

copy_root() {
  local source="$1"
  local target="$2"
  cp -R "$source" "$target"
}

fixture_pass_ids() {
  printf '%s\n' "$1" | sed -n 's/^PASS: \(INV-[0-9][0-9][0-9]\) .*/\1/p' | sort | tr '\n' ' '
}

fixture_fail_ids() {
  printf '%s\n' "$1" | sed -n 's/^FAIL: \(INV-[0-9][0-9][0-9]\) .*/\1/p' | sort | tr '\n' ' '
}

run_fixture() {
  local label="$1"
  local target="$2"
  local expected_fail="$3"
  local stdout_file="$fixtures/stdout"
  local stderr_file="$fixtures/stderr"
  local status=0
  local pass_ids
  local fail_ids
  local expected_pass_ids=""
  local expected_fail_ids=""
  local invariant
  local stdout_lines
  local stderr_lines

  fixtures_run=$((fixtures_run + 1))

  for invariant in INV-001 INV-002 INV-003 INV-004 INV-005 INV-006 INV-007 INV-008; do
    if [ "$invariant" != "$expected_fail" ]; then
      expected_pass_ids="$expected_pass_ids$invariant "
    fi
  done
  if [ -n "$expected_fail" ]; then
    expected_fail_ids="$expected_fail "
  fi

  : > "$stdout_file"
  : > "$stderr_file"
  if bash "$script_path" --root "$target" > "$stdout_file" 2> "$stderr_file"; then
    status=0
  else
    status=$?
  fi
  stdout_lines="$(wc -l < "$stdout_file")"
  stderr_lines="$(wc -l < "$stderr_file")"
  pass_ids="$(fixture_pass_ids "$(cat "$stdout_file")")"
  fail_ids="$(fixture_fail_ids "$(cat "$stderr_file")")"

  if [ -z "$expected_fail" ]; then
    if [ "$status" -ne 0 ]; then
      printf 'FAIL: self-test %s: exit %s, expected 0\n' "$label" "$status" >&2
      failures=$((failures + 1))
      return
    fi
    if [ "$stderr_lines" -ne 0 ]; then
      printf 'FAIL: self-test %s: unexpected error output\n' "$label" >&2
      failures=$((failures + 1))
      return
    fi
    if [ "$stdout_lines" -ne 8 ]; then
      printf 'FAIL: self-test %s: expected eight PASS lines, got %s\n' \
        "$label" "$stdout_lines" >&2
      failures=$((failures + 1))
      return
    fi
  else
    if [ "$status" -ne 1 ]; then
      printf 'FAIL: self-test %s: exit %s, expected 1\n' "$label" "$status" >&2
      failures=$((failures + 1))
      return
    fi
    if [ "$fail_ids" != "$expected_fail_ids" ]; then
      printf 'FAIL: self-test %s: expected one %s failure, got %s\n' \
        "$label" "$expected_fail" "${fail_ids:-none}" >&2
      failures=$((failures + 1))
      return
    fi
    if [ "$stdout_lines" -ne 7 ] || [ "$stderr_lines" -ne 1 ]; then
      printf 'FAIL: self-test %s: expected seven PASS lines and one FAIL line\n' \
        "$label" >&2
      failures=$((failures + 1))
      return
    fi
  fi

  if [ "$pass_ids" != "$expected_pass_ids" ]; then
    printf 'FAIL: self-test %s: expected the invariant set %s, got %s\n' \
      "$label" "$expected_pass_ids" "${pass_ids:-none}" >&2
    failures=$((failures + 1))
  fi
}

run_self_test() {
  local positive

  fixtures="$(mktemp -d)"
  trap cleanup_fixtures EXIT
  positive="$fixtures/positive"
  build_positive_root "$positive"

  run_fixture "positive root" "$positive" ""

  copy_root "$positive" "$fixtures/inv-001a"
  printf 'Version 2, June 1991\n' > "$fixtures/inv-001a/LICENSE"
  run_fixture "INV-001 without GNU GENERAL PUBLIC LICENSE" "$fixtures/inv-001a" "INV-001"

  copy_root "$positive" "$fixtures/inv-001b"
  printf 'GNU GENERAL PUBLIC LICENSE\n' > "$fixtures/inv-001b/LICENSE"
  run_fixture "INV-001 without Version 2, June 1991" "$fixtures/inv-001b" "INV-001"

  copy_root "$positive" "$fixtures/inv-002"
  printf '{"name": "fixture/root"}\n' > "$fixtures/inv-002/composer.json"
  run_fixture "INV-002 without the license key" "$fixtures/inv-002" "INV-002"

  copy_root "$positive" "$fixtures/inv-003"
  printf '=== Fixture ===\n' > "$fixtures/inv-003/readme.txt"
  run_fixture "INV-003 without the license header" "$fixtures/inv-003" "INV-003"

  copy_root "$positive" "$fixtures/inv-004"
  printf '# Changelog\n\n## [Unreleased]\n' > "$fixtures/inv-004/CHANGELOG.md"
  run_fixture "INV-004 without Keep a Changelog" "$fixtures/inv-004" "INV-004"

  copy_root "$positive" "$fixtures/inv-005"
  printf '# Changelog\n\nKeep a Changelog\n' > "$fixtures/inv-005/CHANGELOG.md"
  run_fixture "INV-005 without an Unreleased section" "$fixtures/inv-005" "INV-005"

  copy_root "$positive" "$fixtures/inv-006"
  printf '# Security\n\nReport privately.\n' > "$fixtures/inv-006/SECURITY.md"
  run_fixture "INV-006 without the contact address" "$fixtures/inv-006" "INV-006"

  copy_root "$positive" "$fixtures/inv-007"
  rm "$fixtures/inv-007/.gitleaks.toml"
  run_fixture "INV-007 without the Gitleaks configuration" "$fixtures/inv-007" "INV-007"

  copy_root "$positive" "$fixtures/inv-008"
  rm "$fixtures/inv-008/languages/xnetvn-agent-hub-wp.pot"
  run_fixture "INV-008 without the translation template" "$fixtures/inv-008" "INV-008"

  if [ "$failures" -ne 0 ]; then
    return 1
  fi
  printf 'PASS: repository invariant self-test passed (%d fixtures)\n' "$fixtures_run"
  return 0
}

self_test=false
while [ "$#" -gt 0 ]; do
  case "$1" in
    --root)
      if [ "$#" -lt 2 ] || [ -z "$2" ]; then
        usage >&2
        exit 2
      fi
      root="$2"
      shift 2
      ;;
    --self-test)
      self_test=true
      shift
      ;;
    --help)
      usage
      exit 0
      ;;
    *)
      usage >&2
      exit 2
      ;;
  esac
done

if [ "$self_test" = true ]; then
  if run_self_test; then
    exit 0
  fi
  exit 1
fi

if run_checks "$root"; then
  exit 0
fi
exit 1
