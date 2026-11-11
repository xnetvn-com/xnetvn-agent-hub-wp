"""Published FastMCP application: real content/media mutations with private key configs."""
import json
import os
from pathlib import Path
import subprocess
import sys

sys.path.insert(0, str(Path(__file__).parent))
from client_safety import client_environment, require_plain_path

if os.environ.get("XNETVN_RTL") != "1":
    raise SystemExit(2)
RUN = Path("/work/.local/mcp-clients-run")
TOOLS = Path("/work/.local/mcp-clients-tools-v2")
state = json.loads(Path("/work/.local/http-matrix/state.json").read_text())
phase = "private-config"
env = client_environment(os.environ)
env.update({"HOME": str(RUN), "XDG_CONFIG_HOME": str(RUN / "config"), "XDG_CACHE_HOME": str(RUN / "cache"), "SSL_CERT_FILE": "/work/.local/mcp-clients-trust/lab-public-cert.pem", "NO_COLOR": "1"})


def call(kind, name, arguments):
    """Credentials stay in private files; diagnostics never print client responses."""
    global phase
    phase = name
    result = subprocess.run([str(TOOLS / "python/bin/fastmcp"), "call", str(RUN / (kind + ".json")), "xnetvn-" + name, "--input-json", json.dumps(arguments), "--json", "--timeout", "15", "--auth", "none"], env=env, capture_output=True, text=True, timeout=40)
    if len(result.stdout) > 1048576 or len(result.stderr) > 1048576:
        raise RuntimeError("Unbounded client output")
    try:
        body = json.loads(result.stdout)
    except json.JSONDecodeError:
        body = {}
    data = body.get("structuredContent", body.get("structured_content"))
    denied = body.get("isError", body.get("is_error", False)) is True
    if name == "update-post" and arguments.get("title") == "Cross owner refusal fixture":
        fixed_error = json.dumps(body) if body else result.stderr
        if not (denied or result.returncode != 0) or "forbidden:" not in fixed_error:
            raise RuntimeError("Cross-owner denial unconfirmed")
        return None
    if result.returncode != 0 or denied or not isinstance(data, dict):
        raise RuntimeError("Structured client success unavailable")
    return data


try:
    require_plain_path(RUN)
    require_plain_path(TOOLS)
    if RUN.stat().st_mode & 0o777 != 0o700:
        raise RuntimeError("Private fixture permissions mismatch")
    template = json.loads((RUN / "fastmcp.json").read_text())
    for kind in ["content_editor", "content_author"]:
        config = json.loads(json.dumps(template))
        config["mcpServers"]["agent"]["headers"]["Authorization"] = "Bearer " + state["keys"][kind]["token"]
        path = RUN / (kind + ".json")
        if path.exists() or path.is_symlink():
            raise RuntimeError("Private fixture collision")
        path.write_text(json.dumps(config))
        path.chmod(0o600)
    other = call("content_editor", "create-post", {"title": "Real client editor fixture"})
    own = call("content_author", "create-post", {"title": "Real client author fixture", "content": "<p>Before</p>"})
    if type(own.get("id")) is not int or type(other.get("id")) is not int:
        raise RuntimeError("Object identity unavailable")
    updated = call("content_author", "update-post", {"id": own["id"], "title": "Real client updated fixture", "expected_modified": own["modified_gmt"]})
    if updated.get("title") != "Real client updated fixture":
        raise RuntimeError("Update unconfirmed")
    call("content_author", "update-post", {"id": other["id"], "title": "Cross owner refusal fixture"})
    reconciled = call("content_editor", "get-post", {"id": other["id"]})
    if reconciled.get("title") != "Real client editor fixture":
        raise RuntimeError("Denied cross-owner request changed content")
    media = call("content_author", "upload-media-from-data", {"filename": "client-pixel.png", "mime_type": "image/png", "data_base64": "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aF9sAAAAASUVORK5CYII=", "post": own["id"]})
    if type(media.get("id")) is not int or media.get("mime_type") != "image/png":
        raise RuntimeError("Media upload unconfirmed")
    featured = call("content_author", "set-featured-media", {"id": own["id"], "media_id": media["id"]})
    if featured.get("featured_media") != media["id"]:
        raise RuntimeError("Featured media unconfirmed")
    for kind, name, identity in [("content_author", "delete-media", media["id"]), ("content_author", "delete-post", own["id"]), ("content_editor", "delete-post", other["id"])]:
        if call(kind, name, {"id": identity}).get("deleted") is not True:
            raise RuntimeError("Deletion unconfirmed")
    report = {"client": "published FastMCP CLI", "create_update": True, "cross_owner_denied": True, "upload_featured_delete": True, "tls_verified": True, "scope": "Owned-container TLS fixture on the remote lab; no desktop or production claim."}
    Path("/work/.local/http-matrix/real-content-media-results.json").write_text(json.dumps(report, indent=2))
    print(json.dumps(report))
except Exception as error:
    print(json.dumps({"failed_phase": phase, "failure_type": type(error).__name__}))
    raise SystemExit(1)
