#!/usr/bin/env bash
# Cache must be explicitly enabled before this command; cleanup restores HTTP fixture state.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }
benchmark_dropin=/sites/wp69/wp-content/mu-plugins/xnetvn-benchmark.php
if [[ -e /work/.local/http-matrix/state.json || -L /work/.local/http-matrix/state.json || -e "$benchmark_dropin" || -L "$benchmark_dropin" || -e /sites/wp69/wp-content/mu-plugins/xnetvn-http-matrix.php || -L /sites/wp69/wp-content/mu-plugins/xnetvn-http-matrix.php ]]; then
  printf 'Reconcile existing benchmark state before starting another run.\n' >&2
  exit 2
fi
cleanup_benchmark() {
  benchmark_status=$?
  trap - EXIT
  set +e
  if [[ -e "$benchmark_dropin" ]]; then
    if ! cmp -s /work/tests/E2E/benchmark-mu-plugin.php "$benchmark_dropin"; then
      printf 'Benchmark drop-in ownership mismatch.\n' >&2
      exit 2
    fi
    rm -- "$benchmark_dropin"
  fi
  bash /work/infra/remote/run-wp.sh wp69 --url="https://$RTL_ALIAS_WP69" eval-file /work/tests/E2E/http-fixture.php cleanup --use-include
  cleanup_status=$?
  [[ "$cleanup_status" -eq 0 ]] || { printf 'Benchmark cleanup failed; reconcile private state.\n' >&2; exit 2; }
  exit "$benchmark_status"
}
trap cleanup_benchmark EXIT
bash /work/infra/remote/run-wp.sh wp69 --url="https://$RTL_ALIAS_WP69" eval-file /work/tests/E2E/http-fixture.php setup --use-include
bash /work/infra/remote/run-wp.sh wp69 eval '(new \XNetVN\AgentHubWP\Persistence\WpSiteSettings())->update(array("rate_limit_key_per_min"=>4096,"rate_limit_auth_fail_per_min_ip"=>4096));'
cp /work/tests/E2E/benchmark-mu-plugin.php "$benchmark_dropin"
php /work/tests/E2E/benchmark.php
