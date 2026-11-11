#!/usr/bin/env bash
# Reuse the owned HTTP lifecycle; cleanup also removes fixture-owned taxonomy keys.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }
site="${1:-wp69}"
case "$site" in
  wp69) site_alias="$RTL_ALIAS_WP69";;
  wp71) site_alias="$RTL_ALIAS_WP71";;
  *) printf 'Select wp69 or wp71.\n' >&2; exit 2;;
esac
export XNETVN_MATRIX_SITE="$site"
[[ ! -e /work/.local/http-matrix/state.json ]] || { printf 'Reconcile the existing HTTP fixture first.\n' >&2; exit 2; }
cleanup_taxonomy_fixture() {
  taxonomy_status=$?
  trap - EXIT
  bash /work/infra/remote/run-wp.sh "$site" --url="https://$site_alias" eval-file /work/tests/E2E/http-fixture.php cleanup --use-include || exit 2
  exit "$taxonomy_status"
}
trap cleanup_taxonomy_fixture EXIT
bash /work/infra/remote/run-wp.sh "$site" --url="https://$site_alias" eval-file /work/tests/E2E/http-fixture.php setup --use-include
bash /work/infra/remote/run-wp.sh "$site" eval-file /work/tests/E2E/taxonomy-fixture.php setup --use-include
php /work/tests/E2E/taxonomy-matrix.php
