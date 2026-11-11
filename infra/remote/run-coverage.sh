#!/usr/bin/env bash
# Fresh private artifacts only; never merge data from a previous source revision.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }
cd /work
test ! -e /sites/wp69/wp-content/object-cache.php
test ! -e /sites/wp71/wp-content/object-cache.php
php -r 'if (!extension_loaded("xdebug") || "3.5.3" !== phpversion("xdebug") || array() !== xdebug_info("mode")) { fwrite(STDERR, "Unsupported coverage driver configuration.\n"); exit(2); }'
umask 077
export XNETVN_COVERAGE_DIR
XNETVN_COVERAGE_DIR="$(mktemp -d /work/.local/coverage-XXXXXX)"
export XDEBUG_MODE=coverage
php -d memory_limit=768M vendor/bin/phpunit -c phpunit.xml.dist --coverage-php "$XNETVN_COVERAGE_DIR/unit.php" > "$XNETVN_COVERAGE_DIR/unit.log" 2>&1
for version in 6.9 7.1.2; do
  for mode in single multisite; do
    export WP_TESTS_DIR="/opt/wp-tests/$version"
    export WP_TESTS_CONFIG_FILE_PATH="$WP_TESTS_DIR/wp-tests-config.php"
    if [[ "$mode" == 'multisite' ]]; then export WP_MULTISITE=1; else export WP_MULTISITE=0; fi
    php -d memory_limit=768M vendor/bin/phpunit -c phpunit.integration.xml.dist --coverage-php "$XNETVN_COVERAGE_DIR/$mode-$version.php" > "$XNETVN_COVERAGE_DIR/$mode-$version.log" 2>&1
  done
done
XDEBUG_MODE=off php -d memory_limit=768M tests/Support/coverage-report.php
