# CI/CD Deployment Guide

> **A guide to the continuous integration and deployment pipeline with GitHub Actions for xNetVN Agent Hub WP.**

---

## 1. Pipeline Structure

The pipeline is split into independent workflows so a failure in one area does
not hide another. The source gate is a fan-out/fan-in graph; coverage, security,
dependency, SBOM, documentation, release, compliance and performance checks run
as separate workflows with their own triggers.

```text
pull request or main push
  ├──► source gate (ci.yml)
  ├──► coverage gate (coverage-check.yml)
  ├──► security and dependency gates
  ├──► workflow lint
  └──► path-filtered plugin check, SBOM and documentation deploy

v* tag ──► changelog validation + compliance check + draft release (SBOM + ZIP)
```

---

## 2. Workflow Layout

The authoritative workflow definitions are the YAML files under
`.github/workflows/`. This guide intentionally does not duplicate them, because
a copied example drifts from the hardened source.

| Workflow | Trigger | Purpose |
|---|---|---|
| `ci.yml` | pull request, push to `main`, manual | Source gate: coding standards, PHP 7.2 bootstrap parse, PHPStan, repository invariants, documentation parity, unit matrix, integration matrix, packaging and tooling contracts. `ci-result` is the stable required check for this workflow. |
| `coverage-check.yml` | pull request, push to `main`, manual | Native unit and integration coverage plus the numeric no-regression gate. A documentation-only pull request matching the explicit allowlist reports an explicit artifact-free success instead of collecting coverage. |
| `workflow-lint.yml` | pull request, push to `main`, manual | actionlint, zizmor and workflow contract checks; always reports the stable `workflow-lint` check. |
| `security-scan.yml` | pull request, push to `main`, weekly, manual | Gitleaks secret detection, Semgrep static analysis and Trivy filesystem advisories. |
| `dependency-audit.yml` | pull request, push to `main`, weekly, manual | Locked Composer and npm advisories. |
| `sbom.yml` | dependency changes, manual, release call | CycloneDX and SPDX SBOMs for Composer and npm. |
| `plugin-check.yml` | packaged-source changes, manual | WordPress Plugin Check against the built ZIP. |
| `docs-deploy.yml` | documentation changes on `main`, manual | Documentation bundle; Pages deployment only when `XNETVN_PAGES_ENABLED=true`. |
| `changelog.yml` | `v*` tag, manual | Version consistency and release-notes extraction. |
| `compliance-check.yml` | weekly, `v*` tag, manual | Repository invariants through `bin/check-repo-invariants.sh`, plus version consistency. |
| `release.yml` | `v*` tag | Verified ZIP and complete SBOM assets in a draft GitHub Release. |
| `performance-test.yml` | manual | Benchmark contract and packaging-duration budget. |
| `pr-labeler.yml` | pull request target | Path-based labels on the self-hosted runner; same-repository pull requests only, and never checks out pull-request code. |

The source gate uses a fan-out/fan-in graph: `quality` fails fast before the
unit and integration matrices, independent build and tooling jobs run in
parallel, and `ci-result` aggregates every result. Branch protection should
require only the stable contexts listed in `.github/settings.yml`, never a matrix
leg name.

Every executable job declares `timeout-minutes` and minimal permissions;
reusable-workflow calls inherit the timeouts of the jobs they invoke. Third-party
actions are pinned to full commit SHAs. A repository variable can select an
owner-managed runner; otherwise jobs use `ubuntu-24.04`. Jobs that hold a write
token or an OIDC identity always run on GitHub-hosted infrastructure.

---

## 3. Release Tagging Rules

- Follow Semantic Versioning strictly: `v0.1.0`, `v0.2.0`, `v1.0.0`.
- The tag must match the plugin header, the `readme.txt` stable tag and the
  `CHANGELOG.md` section; `changelog.yml` and `release.yml` enforce this, and
  `release.yml` refuses a wrong-format or mismatched tag before the draft job
  installs a dependency.
- A tag starts `release.yml`, which validates the package, generates complete
  Composer and npm SBOMs and creates a **draft** GitHub Release.
- Publishing the draft is a manual owner action after inspecting both the
  release run and the changelog run.
- Never overwrite, move or force-push a published tag. Each tag must identify an
  immutable build with its ZIP and release notes.
