"""Service-free regression tests for private client evidence boundaries."""
import importlib.util
from pathlib import Path
import tempfile
import unittest

SPEC = importlib.util.spec_from_file_location("client_safety", Path(__file__).parents[1] / "E2E/client_safety.py")
safety = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(safety)


class ClientSafetyTest(unittest.TestCase):
    def test_unrelated_cli_failure_never_proves_revocation(self):
        self.assertFalse(safety.revocation_confirmed(1, {}))
        self.assertFalse(safety.revocation_confirmed(1, {"status": "401", "revoked": True, "anonymous": True}))
        self.assertFalse(safety.revocation_confirmed(1, {"status": 401, "revoked": True, "anonymous": False}))
        self.assertFalse(safety.revocation_confirmed(0, {"status": 401, "revoked": True, "anonymous": True}))
        self.assertFalse(safety.revocation_confirmed(1, {"status": 500, "revoked": True, "anonymous": True}))
        self.assertFalse(safety.revocation_confirmed(1, {"status": 401, "revoked": False, "anonymous": True}))
        self.assertTrue(safety.revocation_confirmed(1, {"status": 401, "revoked": True, "anonymous": True}))

    def test_environment_cannot_disable_tls_or_inject_runtime(self):
        env = safety.client_environment({"PATH": "/usr/bin", "NODE_TLS_REJECT_UNAUTHORIZED": "0", "NODE_OPTIONS": "--require attacker", "PYTHONPATH": "/tmp", "SSLKEYLOGFILE": "/tmp/keys", "HTTPS_PROXY": "attacker"})
        self.assertEqual(env, {"PATH": "/usr/bin"})

    def test_private_path_rejects_symlink_ancestor(self):
        with tempfile.TemporaryDirectory() as root:
            base = Path(root)
            (base / "real").mkdir()
            (base / "link").symlink_to(base / "real", target_is_directory=True)
            with self.assertRaises(ValueError):
                safety.require_plain_path(base / "link/new")
            safety.require_plain_path(base / "real/new")
            with self.assertRaises(ValueError):
                safety.require_plain_path(base / "real/../new")


if __name__ == "__main__":
    unittest.main()
