#!/usr/bin/env bash
# Published client test through verified TLS; credentials remain inside the owned lab.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || exit 2
[[ ! -e /work/.local/http-matrix/state.json && ! -e /work/.local/mcp-clients-run && ! -L /work/.local/mcp-clients-run ]] || { printf 'Reconcile existing client fixture first.\n' >&2; exit 2; }
test -f /work/.local/mcp-clients-trust/lab-public-cert.pem
cleanup_content_client() {
  client_status=$?
  trap - EXIT
  bash /work/infra/remote/run-wp.sh wp69 --url="https://$RTL_ALIAS_WP69" eval-file /work/tests/E2E/http-fixture.php cleanup --use-include || exit 2
  if [[ -d /work/.local/mcp-clients-run && ! -L /work/.local/mcp-clients-run ]]; then
    rm -r -- /work/.local/mcp-clients-run || exit 2
  fi
  exit "$client_status"
}
trap cleanup_content_client EXIT
bash /work/infra/remote/run-wp.sh wp69 --url="https://$RTL_ALIAS_WP69" eval-file /work/tests/E2E/http-fixture.php setup --use-include
bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/content-media-fixture.php setup --use-include
bash /work/infra/remote/run-wp.sh wp69 eval-file /work/tests/E2E/client-config.php --use-include
python3 -I -B /work/tests/E2E/content-media-real-client.py
