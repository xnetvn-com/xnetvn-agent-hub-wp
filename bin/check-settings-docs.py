#!/usr/bin/env python3
"""Compare both documented settings tables with the actual PHP defaults."""

import json
from pathlib import Path
import re
import subprocess
import sys


def main() -> int:
    root = Path(__file__).resolve().parent.parent
    source = subprocess.run(
        [
            "php", "-r",
            'require "vendor/autoload.php"; echo json_encode('
            '\\XNetVN\\AgentHubWP\\Persistence\\WpSiteSettings::DEFAULTS, JSON_THROW_ON_ERROR);',
        ],
        cwd=root, check=True, capture_output=True, text=True,
    )
    defaults = json.loads(source.stdout)
    failures = []
    for language in ("en", "vi"):
        path = root / "docs" / language / "ENVIRONMENT.md"
        rows = re.findall(
            r"^\|\s*`([^`]+)`\s*\|\s*`[^`]+`\s*\|\s*`([^`]+)`\s*\|\s*$",
            path.read_text(), re.MULTILINE,
        )
        documented = {}
        for key, value in rows:
            if key in documented:
                failures.append(f"{language}: duplicate setting {key}")
            try:
                documented[key] = json.loads(value)
            except ValueError:
                failures.append(f"{language}: invalid default for {key}")
        if set(documented) != set(defaults):
            failures.append(f"{language}: setting keys differ from WpSiteSettings::DEFAULTS")
        for key in defaults.keys() & documented.keys():
            expected, actual = defaults[key], documented[key]
            if type(expected) is not type(actual) or expected != actual:
                failures.append(f"{language}: default differs for {key}")
    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 1
    print(f"EN/VI settings defaults verified: {len(defaults)} keys each.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
