#!/usr/bin/env python3
# Copyright (c) 2026 xNetVN Inc.
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
"""Convert a Composer CycloneDX SBOM to SPDX 2.3 tag-value format."""

from __future__ import annotations

import sys
from datetime import datetime, timezone
from pathlib import Path


def build_spdx_lines(document: dict, doc_name: str, timestamp: str) -> list[str]:
    """Build SPDX 2.3 tag-value lines for the given CycloneDX document."""
    lines = [
        "SPDXVersion: SPDX-2.3",
        "DataLicense: CC0-1.0",
        "SPDXID: SPDXRef-DOCUMENT",
        f"DocumentName: {doc_name}",
        (
            "DocumentNamespace: "
            f"https://github.com/xnetvn-com/xnetvn-agent-hub-wp/sbom/{doc_name}-{timestamp}"
        ),
        (
            "Creator: Tool: cyclonedx-php-composer "
            "(exported via GitHub Actions SBOM workflow)"
        ),
        f"Created: {timestamp}",
        "",
    ]

    for index, component in enumerate(document.get("components") or [], start=1):
        purl = component.get("purl", "")
        if not purl:
            continue
        lines.extend(
            [
                f"##### Package: {purl}",
                f"SPDXID: SPDXRef-Package-{index}",
                f"PackageName: {component.get('name', purl)}",
                f"PackageVersion: {component.get('version', '')}",
                "PackageDownloadLocation: NOASSERTION",
                "FilesAnalyzed: false",
                "PackageLicenseConcluded: NOASSERTION",
                "PackageLicenseDeclared: NOASSERTION",
                "PackageCopyrightText: NOASSERTION",
                f"ExternalRef: PACKAGE-MANAGER purl {purl}",
                "",
            ]
        )

    return lines


def main() -> int:
    """Convert dist/sbom/composer.cdx.json to dist/sbom/composer.spdx."""
    cdx_path = Path("dist/sbom/composer.cdx.json")
    if not cdx_path.is_file():
        sys.stderr.write("FAIL: dist/sbom/composer.cdx.json is missing.\n")
        return 1

    import json

    try:
        document = json.loads(cdx_path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        sys.stderr.write(f"FAIL: invalid JSON: {exc}\n")
        return 1

    doc_name = (document.get("metadata") or {}).get("component", {}).get(
        "name", "xnetvn-agent-hub-wp"
    )
    timestamp = datetime.now(timezone.utc).strftime("%Y-%m-%dT%H:%M:%SZ")

    spdx_lines = build_spdx_lines(document, doc_name, timestamp)
    out_path = Path("dist/sbom/composer.spdx")
    out_path.parent.mkdir(parents=True, exist_ok=True)
    out_path.write_text("\n".join(spdx_lines), encoding="utf-8")

    sys.stdout.write("PASS: SPDX 2.3 tag-value SBOM written.\n")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())