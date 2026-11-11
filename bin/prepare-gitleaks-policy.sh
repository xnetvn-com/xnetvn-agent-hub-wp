#!/usr/bin/env bash
#
# Select the Gitleaks policy for a pull-request scan.
#
# The base revision owns the policy: its .gitleaks.toml is passed with
# --config and its .gitleaksignore replaces the pull-request copy. When the
# base revision or its policy is unavailable, the pull-request policy files are
# removed so the scanner falls back to its built-in policy instead of a policy
# the pull request controls.
#
# Usage: bash bin/prepare-gitleaks-policy.sh <base-sha>
# Output: config=<absolute path or empty> appended to $GITHUB_OUTPUT.
#
# Copyright (c) 2026 xNetVN Inc.
# License: GPL-2.0-or-later

set -euo pipefail

base="${1:-}"
output="${GITHUB_OUTPUT:-/dev/stdout}"
config=""

if [ -n "$base" ] && git cat-file -e "${base}^{commit}" 2>/dev/null; then
  if git show "${base}:.gitleaks.toml" > "${RUNNER_TEMP:?}/gitleaks-base.toml" 2>/dev/null; then
    config="${RUNNER_TEMP}/gitleaks-base.toml"
    # Replace the pull-request ignore file with the base revision's file, or
    # remove it when the base has none. Never let the pull request replace the
    # ignore policy that judges its own commits.
    rm -f .gitleaksignore
    if git show "${base}:.gitleaksignore" > .gitleaksignore 2>/dev/null; then
      :
    else
      rm -f .gitleaksignore
    fi
  else
    rm -f .gitleaks.toml .gitleaksignore
    echo "::warning title=Gitleaks policy::The base revision has no .gitleaks.toml; scanning with the built-in policy."
  fi
else
  rm -f .gitleaks.toml .gitleaksignore
  echo "::warning title=Gitleaks policy::The base revision is unavailable; scanning with the built-in policy."
fi

printf 'config=%s\n' "$config" >> "$output"
