"""Service-free boundaries shared by the owned real-client harness."""
from pathlib import Path


def require_plain_path(path):
    """Reject symlinks in every existing component before private writes."""
    path = Path(path)
    if not path.is_absolute() or ".." in path.parts:
        raise ValueError("Absolute owned path required")
    for component in [*reversed(path.parents), path]:
        if component.is_symlink():
            raise ValueError("Symlink in owned path")


def client_environment(source):
    """Allow only executable lookup; no inherited proxy, TLS or runtime injection."""
    return {"PATH": source.get("PATH", "/usr/local/bin:/usr/bin:/bin")}


def revocation_confirmed(code, evidence):
    """A CLI failure needs independently recorded server authentication denial."""
    return code != 0 and isinstance(evidence, dict) and type(evidence.get("status")) is int and evidence["status"] == 401 and evidence.get("revoked") is True and evidence.get("anonymous") is True
