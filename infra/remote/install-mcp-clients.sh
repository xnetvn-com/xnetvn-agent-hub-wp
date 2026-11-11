#!/usr/bin/env bash
# Install pinned published clients only in the owned test project's private tooling directory.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }
umask 077
tools_dir=/work/.local/mcp-clients-tools-v2
# A fresh exclusive root avoids following package/config targets from older installs.
python3 -I -B - <<'CHECK'
from pathlib import Path
path = Path("/work/.local/mcp-clients-tools-v2")
for component in [*reversed(path.parents), path]:
    if component.is_symlink():
        raise SystemExit("Reconcile symlink in client tooling path")
path.mkdir(mode=0o700)
CHECK
python3 -m venv "$tools_dir/python"
curl --fail --location --silent --show-error --max-time 120 --output "$tools_dir/fastmcp_slim-4.0.11-py3-none-any.whl" https://files.pythonhosted.org/packages/47/69/0b4fbe30298c9c6cc123ba8ac63e78583b3567ac04f7d125f3c2309a6e9e/fastmcp_slim-4.0.11-py3-none-any.whl
printf '%s  %s\n' '524905197e318d934908594f16fedea559d44dd682bd763d40f3556233a2b1d9' "$tools_dir/fastmcp_slim-4.0.11-py3-none-any.whl" | sha256sum --check
timeout 300s "$tools_dir/python/bin/pip" install --disable-pip-version-check --report "$tools_dir/python-install.json" "$tools_dir/fastmcp_slim-4.0.11-py3-none-any.whl[client,server]"
timeout 300s npm install --prefix "$tools_dir/node" --ignore-scripts --no-audit --no-fund --save-exact @modelcontextprotocol/inspector@2.9.0
timeout 30s "$tools_dir/python/bin/fastmcp" version
node -e 'const p=require("/work/.local/mcp-clients-tools-v2/node/node_modules/@modelcontextprotocol/inspector/package.json"); if(p.version!=="2.9.0") throw new Error("Unexpected Inspector version"); console.log("Inspector "+p.version);'
