#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
wp i18n make-pot . languages/xnetvn-agent-hub-wp.pot --slug=xnetvn-agent-hub-wp --domain=xnetvn-agent-hub-wp --exclude=vendor,node_modules,tests,bin,.local,docs
