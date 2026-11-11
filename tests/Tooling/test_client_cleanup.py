"""Service-free failure injection for the remote client's private-file lifecycle."""
from pathlib import Path
import subprocess
import tempfile
import unittest

RUNNER = Path(__file__).parents[2] / "infra/remote/run-real-clients.sh"


class ClientCleanupTest(unittest.TestCase):
    def run_fixture(self, remove_fails=False, client_fails=False):
        """Map only fixed lab paths into an isolated disposable directory."""
        with tempfile.TemporaryDirectory() as temporary:
            base = Path(temporary)
            work = base / "work"
            sites = base / "sites"
            commands = base / "commands"
            commands.mkdir()
            (work / "infra/remote").mkdir(parents=True)
            trust = work / ".local/mcp-clients-trust"
            trust.mkdir(parents=True)
            cert = trust / "lab-public-cert.pem"
            cert.write_text("Public certificate fixture; no network use")
            cert.chmod(0o600)
            cli = work / "infra/remote/run-wp.sh"
            cli.write_text("#!/bin/bash\nexit 0\n")
            fake_python = commands / "python3"
            fake_python.write_text("#!/bin/bash\nif [[ \"$*\" == *real-clients.py* ]]; then\nmkdir -p '" + str(work / ".local/mcp-clients-run") + "'\nprintf fixture > '" + str(work / ".local/mcp-clients-run/client.json") + "'\nexit " + ("1" if client_fails else "0") + "\nfi\nexec /usr/bin/python3 \"$@\"\n")
            fake_python.chmod(0o700)
            if remove_fails:
                fake_rm = commands / "rm"
                fake_rm.write_text("#!/bin/bash\nexit 1\n")
                fake_rm.chmod(0o700)
            runner = base / "runner.sh"
            runner.write_text(RUNNER.read_text().replace("/work/", str(work) + "/").replace("/sites/", str(sites) + "/"))
            result = subprocess.run(["bash", str(runner)], env={"PATH": str(commands) + ":/usr/bin:/bin", "XNETVN_RTL": "1", "RTL_ALIAS_WP69": "example.test"}, capture_output=True, text=True, timeout=10)
            return result.returncode, (work / ".local/mcp-clients-run").exists()

    def test_success_requires_private_files_absent(self):
        self.assertEqual(self.run_fixture(), (0, False))

    def test_failed_removal_cannot_report_success(self):
        self.assertEqual(self.run_fixture(remove_fails=True), (2, True))

    def test_client_failure_remains_failure_after_cleanup(self):
        self.assertEqual(self.run_fixture(client_fails=True), (1, False))


if __name__ == "__main__":
    unittest.main()
