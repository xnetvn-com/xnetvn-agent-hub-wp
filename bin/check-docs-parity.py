#!/usr/bin/env python3
"""Verify English/Vietnamese documentation parity and relative links.

The project keeps two documentation trees, `docs/en` (canonical) and `docs/vi`
(translation). This checker enforces the parts that can be verified without
reading prose:

1. path parity in both directions: every English document has a Vietnamese
   counterpart and every Vietnamese document has an English original;
2. the same heading level sequence for every shared file;
3. every relative Markdown link resolves to an existing path.

Project tickets are not documentation and are not scanned: they live in the
Git-ignored store `.local/docs/en/issues/` (see `docs/en/standards/issue-storage.md`).

Usage:
    python3 -I -B bin/check-docs-parity.py [--root PATH]
    python3 -I -B bin/check-docs-parity.py --self-test
"""

from __future__ import annotations

import argparse
import re
import sys
import tempfile
from pathlib import Path
from urllib.parse import unquote

HEADING = re.compile(r"^(#{1,6})\s")
LINK = re.compile(r"\[[^\]]*\]\(([^)\s]+)\)")
INLINE_CODE = re.compile(r"`[^`\n]*`")
FENCE = "```"
SKIP_SCHEMES = ("http://", "https://", "mailto:", "tel:", "#", "<")
SKIP_DIRS = {".git", "node_modules", "vendor", ".worktrees"}


def markdown_files(tree: Path) -> dict[str, str]:
    """Return `relative path -> text` for every Markdown file in one tree."""
    files: dict[str, str] = {}
    for path in sorted(tree.rglob("*.md")):
        parts = set(path.relative_to(tree).parts)
        if parts & SKIP_DIRS:
            continue
        files[path.relative_to(tree).as_posix()] = path.read_text(
            encoding="utf-8", errors="replace"
        )
    return files


def heading_levels(text: str) -> list[int]:
    """Return the heading level sequence, ignoring fenced and inline code."""
    levels: list[int] = []
    in_fence = False
    for line in text.splitlines():
        if line.lstrip().startswith(FENCE):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        match = HEADING.match(line)
        if match:
            levels.append(len(match.group(1)))
    return levels


def link_targets(text: str) -> list[str]:
    """Return relative link targets, ignoring fenced and inline code spans."""
    targets: list[str] = []
    in_fence = False
    for line in text.splitlines():
        if line.lstrip().startswith(FENCE):
            in_fence = not in_fence
            continue
        if in_fence:
            continue
        for target in LINK.findall(INLINE_CODE.sub("", line)):
            if target.startswith(SKIP_SCHEMES):
                continue
            target = target.split("#", 1)[0]
            if target:
                targets.append(target)
    return targets


def check(en_tree: Path, vi_tree: Path) -> list[str]:
    """Return every parity or link failure between the two trees."""
    failures: list[str] = []
    en_files = markdown_files(en_tree)
    vi_files = markdown_files(vi_tree)

    en_docs = set(en_files)
    vi_docs = set(vi_files)
    failures += [
        f"docs/vi is missing a translation of {path}"
        for path in sorted(en_docs - vi_docs)
    ]
    failures += [
        f"docs/en has no counterpart for {path}" for path in sorted(vi_docs - en_docs)
    ]

    for path in sorted(en_docs & vi_docs):
        en_levels = heading_levels(en_files[path])
        vi_levels = heading_levels(vi_files[path])
        if en_levels != vi_levels:
            failures.append(
                f"{path}: heading structure differs "
                f"(en {en_levels} / vi {vi_levels})"
            )

    for tree, files in ((en_tree, en_files), (vi_tree, vi_files)):
        for relative, text in files.items():
            source = tree / relative
            for target in link_targets(text):
                resolved = (source.parent / unquote(target)).resolve()
                if not resolved.exists():
                    failures.append(
                        f"{tree.name}/{relative} -> {target} does not exist"
                    )
    return failures


def build_fixture(root: Path) -> tuple[Path, Path]:
    """Create a minimal passing documentation pair for the self-test."""
    en_tree = root / "en"
    vi_tree = root / "vi"
    for tree in (en_tree, vi_tree):
        (tree / "standards").mkdir(parents=True)
    body = "# Title\n\n## Section\n\nSee [policy](standards/policy.md).\n"
    (en_tree / "index.md").write_text(body, encoding="utf-8")
    (vi_tree / "index.md").write_text(body, encoding="utf-8")
    (en_tree / "standards" / "policy.md").write_text("# Policy\n", encoding="utf-8")
    (vi_tree / "standards" / "policy.md").write_text("# Policy\n", encoding="utf-8")
    return en_tree, vi_tree


def self_test() -> int:
    """Prove the checker accepts valid trees and rejects each defect class."""
    failures: list[str] = []

    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        en_tree, vi_tree = build_fixture(root)
        if check(en_tree, vi_tree):
            failures.append("self-test: a valid tree pair was rejected")

    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        en_tree, vi_tree = build_fixture(root)
        (vi_tree / "standards" / "policy.md").unlink()
        if not any("missing a translation" in item for item in check(en_tree, vi_tree)):
            failures.append("self-test: a missing translation was accepted")

    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        en_tree, vi_tree = build_fixture(root)
        (vi_tree / "extra.md").write_text("# Extra\n", encoding="utf-8")
        if not any("no counterpart" in item for item in check(en_tree, vi_tree)):
            failures.append("self-test: an untranslated document was accepted")

    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        en_tree, vi_tree = build_fixture(root)
        (en_tree / "index.md").write_text(
            "# Title\n\n## Section\n\nSee [gone](standards/gone.md).\n",
            encoding="utf-8",
        )
        (vi_tree / "index.md").write_text(
            "# Title\n\n## Section\n\nSee [gone](standards/gone.md).\n",
            encoding="utf-8",
        )
        if not any("does not exist" in item for item in check(en_tree, vi_tree)):
            failures.append("self-test: a broken link was accepted")

    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        en_tree, vi_tree = build_fixture(root)
        (vi_tree / "index.md").write_text(
            "# Title\n\n## Section\n\n### Deeper\n", encoding="utf-8"
        )
        if not any("heading structure differs" in item for item in check(en_tree, vi_tree)):
            failures.append("self-test: a heading mismatch was accepted")

    with tempfile.TemporaryDirectory() as directory:
        root = Path(directory)
        en_tree, vi_tree = build_fixture(root)
        example = (
            "# Title\n\n## Section\n\n"
            "Example: `[policy](standards/gone.md)` inside code.\n\n"
            "```text\n[also gone](standards/gone.md)\n```\n"
        )
        for tree in (en_tree, vi_tree):
            (tree / "index.md").write_text(example, encoding="utf-8")
        found = check(en_tree, vi_tree)
        if found:
            failures.append("self-test: code examples were treated as links: " + str(found))

    if failures:
        print("\n".join(failures), file=sys.stderr)
        return 1
    print("docs parity self-test: 6 cases passed.")
    return 0


def main() -> int:
    """Run the self-test or the real parity check."""
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--root",
        type=Path,
        default=Path(__file__).resolve().parent.parent,
        help="Project root; defaults to the directory above this script.",
    )
    parser.add_argument(
        "--self-test",
        action="store_true",
        help="Exercise the checker against temporary fixtures and exit.",
    )
    arguments = parser.parse_args()
    if arguments.self_test:
        return self_test()

    docs = arguments.root / "docs"
    en_tree, vi_tree = docs / "en", docs / "vi"
    for tree in (en_tree, vi_tree):
        if not tree.is_dir():
            print(f"Missing documentation tree: {tree}", file=sys.stderr)
            return 1

    failures = check(en_tree, vi_tree)
    if failures:
        print("\n".join(failures), file=sys.stderr)
        print(f"Documentation parity failed: {len(failures)} problem(s).", file=sys.stderr)
        return 1
    total = len(markdown_files(en_tree))
    print(f"EN/VI documentation parity verified: {total} English documents.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
