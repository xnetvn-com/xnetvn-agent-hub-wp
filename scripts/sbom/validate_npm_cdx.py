#!/usr/bin/env python3
# Copyright (c) 2026 xNetVN Inc.
#
# Licensed under the Apache License, Version 2.0 (the "License");
# you may not use this file except in compliance with the License.
# You may obtain a copy of the License at
#
#     http://www.apache.org/licenses/LICENSE-2.0
"""Validate a CycloneDX JSON SBOM produced from npm production dependencies."""

from __future__ import annotations

import json
import sys
from pathlib import Path


def declared_tools(metadata: dict) -> list[str]:
    """Return lowercase tool names from either CycloneDX 1.5+ or legacy shapes."""
    tools = metadata.get("tools")
    names: list[str] = []

    if isinstance(tools, dict):
        for component in tools.get("components") or []:
            if isinstance(component, dict):
                names.append(str(component.get("name") or "").lower())
    elif isinstance(tools, list):
        for tool in tools:
            if isinstance(tool, dict):
                names.append(str(tool.get("name") or "").lower())

    return names


def main() -> int:
    """Return 0 when the CycloneDX SBOM is valid; otherwise print reason and exit non-zero."""
    path = Path("dist/sbom/npm.cdx.json")
    if not path.is_file():
        sys.stderr.write("FAIL: dist/sbom/npm.cdx.json is missing.\n")
        return 1

    try:
        document = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as exc:
        sys.stderr.write(f"FAIL: invalid JSON: {exc}\n")
        return 1

    if document.get("bomFormat") != "CycloneDX":
        sys.stderr.write(
            f"FAIL: expected bomFormat 'CycloneDX', got {document.get('bomFormat')!r}\n"
        )
        return 1

    if document.get("specVersion") != "1.6":
        sys.stderr.write(
            f"FAIL: expected specVersion 1.6, got {document.get('specVersion')!r}\n"
        )
        return 1

    metadata = document.get("metadata")
    if not isinstance(metadata, dict) or not isinstance(
        metadata.get("component"), dict
    ):
        sys.stderr.write("FAIL: CycloneDX SBOM has no metadata.component.\n")
        return 1

    components = document.get("components")
    if not isinstance(components, list):
        sys.stderr.write("FAIL: CycloneDX SBOM has no components array.\n")
        return 1

    if not any("cyclonedx-npm" in name for name in declared_tools(metadata)):
        sys.stderr.write(
            "FAIL: CycloneDX SBOM does not declare the cyclonedx-npm tool.\n"
        )
        return 1

    sys.stdout.write(
        f"PASS: npm CycloneDX SBOM validated ({len(components)} components).\n"
    )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
