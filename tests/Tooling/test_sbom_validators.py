"""Service-free regression tests for the CycloneDX SBOM validators."""
import contextlib
import importlib.util
import io
import json
import os
from pathlib import Path
import tempfile
import unittest

ROOT = Path(__file__).parents[2]
VALIDATORS = {
    "composer": ROOT / "scripts/sbom/validate_composer_cdx.py",
    "npm": ROOT / "scripts/sbom/validate_npm_cdx.py",
}


def load_validator(name):
    """Load a validator module by file path."""
    spec = importlib.util.spec_from_file_location(f"sbom_{name}", VALIDATORS[name])
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def run_validator(module, document, filename):
    """Run a validator in a temporary project directory."""
    with tempfile.TemporaryDirectory() as root:
        path = Path(root) / "dist/sbom" / filename
        path.parent.mkdir(parents=True)
        path.write_text(json.dumps(document), encoding="utf-8")
        previous = os.getcwd()
        os.chdir(root)
        try:
            stdout = io.StringIO()
            stderr = io.StringIO()
            with contextlib.redirect_stdout(stdout), contextlib.redirect_stderr(stderr):
                code = module.main()
        finally:
            os.chdir(previous)
        return code, stdout.getvalue(), stderr.getvalue()


def document(tools, spec_version="1.6", components=None):
    """Build a minimal CycloneDX document with a root component."""
    result = {
        "bomFormat": "CycloneDX",
        "specVersion": spec_version,
        "version": 1,
        "metadata": {"component": {"type": "application", "name": "root"}},
        "components": [] if components is None else components,
    }
    if tools is not None:
        result["metadata"]["tools"] = tools
    return result


class SbomValidatorTest(unittest.TestCase):
    def test_composer_accepts_empty_production_tree(self):
        code, stdout, stderr = run_validator(
            load_validator("composer"),
            document([{"name": "cyclonedx-php-composer"}]),
            "composer.cdx.json",
        )
        self.assertEqual(code, 0, stderr)
        self.assertIn("0 components", stdout)

    def test_composer_rejects_spec_version_outside_top_level(self):
        invalid = document([{"name": "cyclonedx-php-composer"}], spec_version=None)
        invalid.pop("specVersion")
        code, _, stderr = run_validator(
            load_validator("composer"), invalid, "composer.cdx.json"
        )
        self.assertEqual(code, 1)
        self.assertIn("specVersion", stderr)

    def test_npm_accepts_cyclonedx_16_object_tools(self):
        code, stdout, stderr = run_validator(
            load_validator("npm"),
            document({"components": [{"name": "cyclonedx-npm"}]}),
            "npm.cdx.json",
        )
        self.assertEqual(code, 0, stderr)
        self.assertIn("0 components", stdout)

    def test_npm_rejects_missing_generator(self):
        code, _, stderr = run_validator(
            load_validator("npm"),
            document({"components": [{"name": "other-tool"}]}),
            "npm.cdx.json",
        )
        self.assertEqual(code, 1)
        self.assertIn("cyclonedx-npm", stderr)


if __name__ == "__main__":
    unittest.main()
