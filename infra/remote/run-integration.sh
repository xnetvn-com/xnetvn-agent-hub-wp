#!/usr/bin/env bash
set -euo pipefail
cd /work
version="${1:-6.9}"
if [[ $# -gt 0 ]]; then shift; fi
case "$version" in
  6.9)
    matrix_site='wp69'
    ;;
  7.1.2)
    matrix_site='wp71'
    ;;
  *)
    printf 'Unsupported integration version.\n' >&2
    exit 2
    ;;
esac
# The stability class maps the integration core pin to the matrix target and
# requires a bounded run id. These values are process environment only.
export XNETVN_MATRIX_SITE="$matrix_site"
export XNETVN_ROLE_SEO_RUN_ID="${XNETVN_ROLE_SEO_RUN_ID:-it-$(date -u +%y%m%d%H%M%S)-$$}"
export WP_TESTS_DIR="/opt/wp-tests/$version"
export WP_TESTS_CONFIG_FILE_PATH="$WP_TESTS_DIR/wp-tests-config.php"
exec vendor/bin/phpunit -c phpunit.integration.xml.dist "$@"
