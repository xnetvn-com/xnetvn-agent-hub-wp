#!/usr/bin/env bash
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }
test ! -e /work/.local/mcp-clients-run
test ! -L /work/.local/mcp-clients-run
test ! -e /work/.local/http-matrix/state.json
test ! -e /sites/wp69/wp-content/mu-plugins/xnetvn-http-matrix.php
# Missing trusted input must fail before creating any credential fixture.
python3 -I -B - <<'CHECK'
from pathlib import Path
path = Path("/work/.local/mcp-clients-trust/lab-public-cert.pem")
for component in [*reversed(path.parents), path]:
    if component.is_symlink():
        raise SystemExit("Trusted certificate path contains a symlink")
if not path.is_file() or path.stat().st_mode & 0o777 != 0o600:
    raise SystemExit("Provision the trusted public lab certificate first")
CHECK
cleanup_clients() {
  client_status=$?
  trap - EXIT
  set +e
  bash /work/infra/remote/run-wp.sh wp69 --url="https://$RTL_ALIAS_WP69" eval-file /work/tests/E2E/http-fixture.php cleanup --use-include
  cleanup_status=$?
  [[ "$cleanup_status" -eq 0 ]] || { printf 'Client fixture cleanup failed; reconcile private state.\n' >&2; exit 2; }
  if [[ -d /work/.local/mcp-clients-run && ! -L /work/.local/mcp-clients-run ]]; then
    # Only this fresh fixed private directory is owned by the current lifecycle.
    rm -r -- /work/.local/mcp-clients-run || { printf 'Private client cleanup failed.\n' >&2; exit 2; }
  fi
  if [[ -e /work/.local/mcp-clients-run || -L /work/.local/mcp-clients-run ]]; then
    printf 'Private client files remain; reconcile before retry.\n' >&2; exit 2
  fi
  exit "$client_status"
}
trap cleanup_clients EXIT
bash /work/infra/remote/run-wp.sh wp69 --url="https://$RTL_ALIAS_WP69" eval-file /work/tests/E2E/http-fixture.php setup --use-include
bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/client-config.php --use-include
python3 -I -B /work/tests/E2E/real-clients.py
