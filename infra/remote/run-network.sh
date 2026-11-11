#!/usr/bin/env bash
# Core-library network acceptance runs only in the guarded owned remote toolbox.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }
version="${1:-6.9}"
[[ $# -eq 0 ]] || shift
case "$version" in 6.9|7.1.2) ;; *) exit 2;; esac
export WP_TESTS_DIR="/opt/wp-tests/$version"
export WP_TESTS_CONFIG_FILE_PATH="$WP_TESTS_DIR/wp-tests-config.php"
cd /work
exec vendor/bin/phpunit -c phpunit.network.xml.dist "$@"
