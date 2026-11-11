"""Run published MCP CLI applications; export only bounded boolean evidence."""
import json
import os
from pathlib import Path
import ssl
import socket
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).parent))
from client_safety import client_environment, require_plain_path, revocation_confirmed

if os.environ.get("XNETVN_RTL") != "1":
    raise SystemExit(2)

RUN = Path("/work/.local/mcp-clients-run")
TOOLS = Path("/work/.local/mcp-clients-tools-v2")
state = json.loads(Path("/work/.local/http-matrix/state.json").read_text())
host = "wp69" if os.environ.get("XNETVN_CLIENT_TLS_FIXTURE") == "1" else os.environ["RTL_ALIAS_WP69"]
env = client_environment(os.environ)
env.update({"MCP_INSPECTOR_SECRET_STORE": "memory", "MCP_STORAGE_DIR": str(RUN / "storage"), "MCP_CLIENT_CONFIG_PATH": str(RUN / "client.json"), "NO_COLOR": "1", "HOME": str(RUN), "XDG_CONFIG_HOME": str(RUN / "config"), "XDG_CACHE_HOME": str(RUN / "cache")})
phase = "certificate"


def invoke(client, method, name=None):
    """Only fixed application/config arguments; no credential-bearing argv or output."""
    if client == "inspector":
        argv = [str(TOOLS / "node/node_modules/.bin/mcp-inspector"), "--cli", "--config", str(RUN / "inspector.json"), "--server", "agent", "--method", method, "--format", "json", "--stored-auth-only"]
        if name:
            argv += ["--tool-name", name]
    else:
        argv = [str(TOOLS / "python/bin/fastmcp"), "list" if method == "tools/list" else "call", str(RUN / "fastmcp.json")]
        if name:
            argv += [name]
        argv += ["--json", "--timeout", "15", "--auth", "none"]
    result = subprocess.run(argv, env=env, capture_output=True, text=True, timeout=40)
    if len(result.stdout) > 1048576 or len(result.stderr) > 1048576:
        raise RuntimeError("Unbounded client result")
    body = None
    try:
        body = json.loads(result.stdout)
    except json.JSONDecodeError:
        pass
    return result.returncode, body, result.stderr + (result.stdout if body is None else "")


def logical(client, body):
    """Use each published application's verified JSON shape only."""
    record = body.get("result", {}) if client == "inspector" else body
    return record.get("structuredContent", record.get("structured_content"))


def main():
    global phase
    require_plain_path(RUN)
    require_plain_path(TOOLS)
    if RUN.is_symlink() or not RUN.is_dir() or RUN.stat().st_mode & 0o777 != 0o700:
        raise RuntimeError("Private client directory unavailable")
    for client in ["inspector", "fastmcp"]:
        config = RUN / (client + ".json")
        if config.is_symlink() or config.stat().st_mode & 0o777 != 0o600:
            raise RuntimeError("Private client config unavailable")
    # Provision this public certificate via the trusted lab/operator channel, never from the peer.
    certificate = Path("/work/.local/mcp-clients-trust/lab-public-cert.pem")
    require_plain_path(certificate)
    if not certificate.is_file() or certificate.stat().st_mode & 0o777 != 0o600:
        raise RuntimeError("Trusted lab certificate unavailable")
    context = ssl.create_default_context(cafile=str(certificate))
    with socket.create_connection((host, 443), timeout=10) as connection:
        with context.wrap_socket(connection, server_hostname=host):
            pass
    env["NODE_EXTRA_CA_CERTS"] = str(certificate)
    env["SSL_CERT_FILE"] = str(certificate)
    reports = {}
    for client in ["inspector", "fastmcp"]:
        phase = client + "_list"
        code, body, _ = invoke(client, "tools/list")
        if code != 0 or not isinstance(body, dict):
            raise RuntimeError("Client listing failed")
        tools = body.get("result", body).get("tools", [])
        names = [item["name"] for item in tools]
        prefix = "agent_" if names and all(name.startswith("agent_") for name in names) else ""
        canonical = [name.removeprefix(prefix) for name in names]
        if "xnetvn-fixture-read" not in canonical or "xnetvn-get-session-info" not in canonical or any(name in canonical for name in ["xnetvn-fixture-write", "xnetvn-fixture-bulk", "xnetvn-create-user"]):
            raise RuntimeError("Unexpected scoped tool list")
        phase = client + "_session"
        code, body, _ = invoke(client, "tools/call", prefix + "xnetvn-get-session-info")
        data = logical(client, body) if isinstance(body, dict) else None
        if code != 0 or not isinstance(data, dict) or data.get("user", {}).get("id") != state["users"]["owner"] or data.get("key", {}).get("tier_max") != "read":
            raise RuntimeError("Verified client actor is unavailable")
        phase = client + "_read"
        code, body, _ = invoke(client, "tools/call", prefix + "xnetvn-fixture-read")
        if code != 0 or logical(client, body) != {"ok": True}:
            raise RuntimeError("Permitted client execution failed")
        reports[client] = {"scoped_list": True, "key_owner": True, "permitted_read": True, "higher_tiers_hidden": True}
    phase = "revoke"
    result = subprocess.run(["bash", "/work/infra/remote/run-wp.sh", "wp69", "eval-file", "/work/tests/E2E/http-fixture.php", "revoke-read", "--use-include"], capture_output=True, text=True, timeout=20)
    if result.returncode != 0:
        raise RuntimeError("Owned fixture revocation failed")
    for client in ["inspector", "fastmcp"]:
        phase = client + "_revoked"
        config_file = RUN / (client + ".json")
        config = json.loads(config_file.read_text())
        headers = config["mcpServers"]["agent"]["headers"]
        headers["X-Xnetvn-Test-Control"] = state["control"]
        headers["X-Xnetvn-Test-Client"] = client
        config_file.write_text(json.dumps(config))
        code, _, _ = invoke(client, "tools/list")
        proof = subprocess.run(["bash", "/work/infra/remote/run-wp.sh", "wp69", "eval-file", "/work/tests/E2E/http-fixture.php", "client-denials", "--use-include"], capture_output=True, text=True, timeout=20)
        if proof.returncode != 0 or not revocation_confirmed(code, json.loads(proof.stdout).get(client)):
            raise RuntimeError("Client revocation denial unconfirmed")
        reports[client]["revocation_denied"] = True
    print(json.dumps({"clients": reports, "scope": "Published CLI apps; HTTP on owned lab, synthetic read fixture. No desktop GUI or production claim.", "tls_fixture": "owned-container verified TLS" if os.environ.get("XNETVN_CLIENT_TLS_FIXTURE") == "1" else "Hestia ingress"}))


try:
    main()
except Exception as error:
    # Neither traces, credential-bearing config nor raw client output is printed.
    print(json.dumps({"failed_phase": phase, "failure_type": type(error).__name__}))
    raise SystemExit(1)
