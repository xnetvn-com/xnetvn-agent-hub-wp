#!/usr/bin/env bash
set -euo pipefail
cd /work
version="${1:-6.9}"
if [[ $# -gt 0 ]]; then shift; fi
case "$version" in
  6.9|7.1.2) ;;
  *) printf 'Unsupported integration version.\n' >&2; exit 2;;
esac
export WP_TESTS_DIR="/opt/wp-tests/$version"
export WP_TESTS_CONFIG_FILE_PATH="$WP_TESTS_DIR/wp-tests-config.php"
exec vendor/bin/phpunit -c phpunit.integration.xml.dist "$@"
