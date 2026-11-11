#!/usr/bin/env bash
set -euo pipefail
case "${XNETVN_TEST_MULTISITE:-0}" in
  0|1) ;;
  *) printf 'XNETVN_TEST_MULTISITE must be 0 or 1.\n' >&2; exit 2;;
esac
harness_env=(env "WP_MULTISITE=${XNETVN_TEST_MULTISITE:-0}")
if [[ "${CI:-}" == 'true' ]]; then
  exec npx --no-install wp-env run tests-cli --env-cwd=wp-content/plugins/xnetvn-agent-hub-wp -- "${harness_env[@]}" vendor/bin/phpunit -c phpunit.integration.xml.dist "$@"
fi
exec python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec toolbox -- "${harness_env[@]}" bash /work/infra/remote/run-integration.sh "$@"
