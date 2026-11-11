#!/usr/bin/env bash
# Ten independent WordPress processes must retain every increment.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }
[[ ! -e /work/.local/cache-fixture/counter.json && ! -L /work/.local/cache-fixture/counter.json ]] || { printf 'Reconcile the existing counter fixture.\n' >&2; exit 2; }
cleanup_counter() {
  counter_status=$?
  trap - EXIT
  set +e
  for site in wp69 wp71; do
    bash /work/infra/remote/run-wp.sh "$site" eval-file /work/tests/E2E/cache-counter.php cleanup-site --use-include
    [[ "$?" -eq 0 ]] || { printf 'Counter cleanup failed; reconcile state.\n' >&2; exit 2; }
  done
  rm -- /work/.local/cache-fixture/counter.json
  [[ "$?" -eq 0 ]] || { printf 'Counter state cleanup failed.\n' >&2; exit 2; }
  exit "$counter_status"
}
trap cleanup_counter EXIT
bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/cache-counter.php setup --use-include
bash /work/infra/remote/run-wp.sh wp71 eval-file /work/tests/E2E/cache-counter.php probe-write --use-include
worker_pids=()
for worker in {1..10}; do
  bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/cache-counter.php worker --use-include &
  worker_pids+=("$!")
done
worker_failed=0
for worker_pid in "${worker_pids[@]}"; do
  wait "$worker_pid" || worker_failed=1
done
[[ "$worker_failed" -eq 0 ]] || { printf 'Counter worker failed.\n' >&2; exit 1; }
bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/cache-counter.php assert-total --use-include
bash /work/infra/remote/run-wp.sh wp71 eval-file /work/tests/E2E/cache-counter.php assert-isolated --use-include
