#!/usr/bin/env bash
set -euo pipefail
exec python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec toolbox -- bash /work/infra/remote/run-network.sh "$@"
