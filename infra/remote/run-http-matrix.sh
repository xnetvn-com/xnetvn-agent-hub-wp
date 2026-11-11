#!/usr/bin/env bash
# Real HTTP evidence with private fixture setup and guaranteed cleanup on normal process exits.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }
if [[ -e /work/.local/http-matrix/state.json || -L /work/.local/http-matrix/state.json || -e /sites/wp69/wp-content/mu-plugins/xnetvn-http-matrix.php || -L /sites/wp69/wp-content/mu-plugins/xnetvn-http-matrix.php ]]; then
  printf 'Reconcile the existing HTTP fixture before starting another run.\n' >&2
  exit 2
fi
cleanup_http_fixture() {
  matrix_status=$?
  trap - EXIT
  set +e
  bash /work/infra/remote/run-wp.sh wp69 --url="https://$RTL_ALIAS_WP69" eval-file /work/tests/E2E/http-fixture.php cleanup --use-include
  cleanup_status=$?
  if [[ "$cleanup_status" -ne 0 ]]; then
    printf 'HTTP fixture cleanup failed; reconcile private state before retrying.\n' >&2
    exit 2
  fi
  exit "$matrix_status"
}
trap cleanup_http_fixture EXIT
bash /work/infra/remote/run-wp.sh wp69 --url="https://$RTL_ALIAS_WP69" eval-file /work/tests/E2E/http-fixture.php setup --use-include
php /work/tests/E2E/http-matrix.php
