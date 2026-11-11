# Contributing

Thank you for contributing to **xNetVN Agent Hub WP**. This project follows strict security and quality standards suitable for enterprise deployments and serves the global open-source community.

> **Read first:** the full project guidelines in [`docs/en/development.md`](docs/en/development.md), the threat model in [`docs/en/security/threat-model.md`](docs/en/security/threat-model.md) and the architecture overview in [`docs/en/architecture/overview.md`](docs/en/architecture/overview.md) before proposing work.

---

## Table of Contents

1. [Code of Conduct](#1-code-of-conduct)
2. [Quickstart](#2-quickstart)
3. [Git Workflow](#3-git-workflow)
4. [Coding Standards](#4-coding-standards)
5. [Testing Standards (TDD)](#5-testing-standards-tdd)
6. [Security Invariants](#6-security-invariants)
7. [Pull Request Process](#7-pull-request-process)
8. [Documentation Requirements](#8-documentation-requirements)
9. [Release & Versioning](#9-release--versioning)
10. [Reporting Vulnerabilities](#10-reporting-vulnerabilities)

---

## 1. Code of Conduct

All contributors must follow the project [Code of Conduct](CODE_OF_CONDUCT.md). We commit to building an inclusive, open, respectful and professional environment.

---

## 2. Quickstart

### 2.1 Prerequisites

| Tool | Required Version | Notes |
| --- | --- | --- |
| PHP | 8.2+ | 7.2 syntax required for `uninstall.php` and `src/Bootstrap/*.php` |
| Composer | 2.4+ | A project-local PHAR may be used without upgrading system tools |
| Node.js | 24 (per `.nvmrc`) | Use `nvm use` to align |
| npm | 10+ | Installed alongside Node.js |
| Docker | Latest stable | Required for `wp-env` integration runs |
| WP-CLI | Latest | Required by `bin/test-integration.sh` |
| Git | 2.34+ | Conventional Commits and signed commits recommended |

### 2.2 First-Time Setup (Local)

```bash
# 1. Clone the repository (use a personal fork for contributions).
git clone https://github.com/xnetvn-com/xnetvn-agent-hub-wp.git
cd xnetvn-agent-hub-wp

# 2. Install locked PHP dependencies.
composer install

# 3. Install locked Node dependencies.
npm ci

# 4. Verify PHP 8.2 toolchain.
php -v
composer --version
node -v
npm -v
docker --version
wp --version

# 5. Run the static and unit gates before touching code.
composer check
```

### 2.3 Disposable WordPress for Integration Tests

Services, databases, websites, browsers and E2E work run through **remote-test-lab** (shared owned lab at `192.168.2.215`). Local linters and service-free unit tests stay local. The approved remote toolbox uses WordPress 6.9 by default; `composer test:integration -- 7.1.2` selects the current-stable fixture.

```bash
npm run env:start              # Start disposable WordPress 6.9
composer test:integration      # Run the integration matrix
composer test:http             # Remote HTTP rejection matrix
npm run remote:plan            # Review the next remote plan
npm run remote:up              # Deploy the approved remote plan
npm run test:e2e               # Remote browser flows
```

### 2.4 Branch Strategy in One Diagram

```
main (production, protected)
 │
 ├── release/<version>     (release candidates, drafted by maintainers)
 ├── hotfix/<ticket>       (production-only emergency fixes)
 └── feature/<ticket>-<short-description>
     ├── bugfix/<ticket>-<short-description>
     ├── refactor/<ticket>-<short-description>
     ├── docs/<short-description>
     └── chore/<short-description>
```

Always base your branch on `main` and keep your branch rebased (no merge commits) before requesting review.

---

## 3. Git Workflow

1. **Fork the repository** to your personal GitHub account.
2. **Create a feature branch** from `main` using the standard naming convention:
   - `feature/<ticket-id>-<short-description>` (Example: `feature/M2-add-content-pack`)
   - `bugfix/<ticket-id>-<short-description>` (Example: `bugfix/fix-token-parsing-length`)
   - `refactor/<ticket-id>-<short-description>` (Example: `refactor/extract-rate-limit-policy`)
   - `docs/<short-description>` (Example: `docs/clarify-bearer-auth-flow`)
   - `chore/<short-description>` (Example: `chore/upgrade-phpstan-extension`)
3. **Keep changes small and reviewable.** A Pull Request must solve one problem only.
5. **Conventional Commits (English, mandatory):**
   - Format: `<type>(<scope>): <subject>`
   - Examples:
     - `feat(auth): add ip cidr filtering to token validation`
     - `fix(ratelimit): prevent race condition in atomic counter`
     - `docs(vi): update installation guide and architecture specs`
     - `test(mcp): add negative test cases for invalid origin header`
     - `chore(deps): pin ajv to 8.17.1 for wp-env compatibility`
6. **Preserve unrelated work.** Do not bundle formatting fixes with feature work.
7. **Commit and push only when explicitly authorized by the project owner.**

---

## 4. Coding Standards

- **Source language:** Standard English throughout source code (class, method and variable names, comments, docblocks, commit messages).
- **Tabs in PHP** (WordPress Coding Standards). YAML, JSON, Markdown, CSS and JavaScript use two spaces.
- **WordPress Coding Standards (`WordPress-Extra`, `WordPress-Core`, `WordPress-Docs`)** enforced via PHPCS.
- **PHPStan Level 8 with no baseline** (enforced by `composer analyse` in the `ci.yml` quality job).
- **Bootstrap files** (`xnetvn-agent-hub-wp.php`, `uninstall.php`, `src/Bootstrap/*.php`) must parse on PHP 7.2 before requirements are checked.
- **i18n:** Translate interface strings through the `xnetvn-agent-hub-wp` text domain.
- **Ability descriptions:** Keep fixed English for MCP clients; do not localize.
- **Docblocks:** Follow [WordPress PHP Documentation Standards](https://developer.wordpress.org/coding-standards/inline-documentation-standards/php/) — summary first line, full `@param`/`@return`/`@throws`, units/range/format, side-effects, examples, security notes.
- **Forbidden:** Dead code, commented-out code, magic numbers, hardcoded secrets, copy-pasted third-party implementation.

---

## 5. Testing Standards (TDD)

This project follows **Test-Driven Development** for every behavior change.

1. **Write a failing test first** that captures the missing or wrong behavior.
2. **Watch the test fail locally** to confirm it actually exercises the change.
3. **Write the minimum production code** to make the test pass.
4. **Refactor** with all tests still green.
5. **Run the full check suite** before requesting review:

```bash
composer check           # lint + lint:bootstrap + analyse + check:version + test:unit + check:docs
composer test:unit       # unit tests on PHP 8.2–8.5
composer test:integration
composer test:packaging
composer build           # release ZIP verification
```

Coverage expectations:

- Unit tests cover every new public method, branch and edge case.
- Integration tests cover any change that touches WP REST, MCP route, ability execution, audit, persistence or uninstall.
- Negative tests for security checks (missing header, wrong origin, expired key, replay, tampering, missing capability).

---

## 6. Security Invariants

The following invariants are enforced in code review and CI. They come from the project [`AGENTS.md`](AGENTS.md) and are non-negotiable.

The authoritative invariants are in [`AGENTS.md`](AGENTS.md). Use the complete current list there together with [architecture/overview.md](docs/en/architecture/overview.md); abbreviated descriptions elsewhere do not replace those requirements.

Security-sensitive changes require:

- Threat-model update in [`docs/en/security/threat-model.md`](docs/en/security/threat-model.md).
- Negative permission tests (capability missing, scope denied, owner mismatch).
- Independent review at the milestone security gate.

---

## 7. Pull Request Process

1. **Push** commits to your feature branch on GitHub.
2. **Open a Pull Request** against the upstream `main` branch using the repository [Pull Request template](.github/PULL_REQUEST_TEMPLATE.md).
3. **Fill every section** of the template, including:
   - Problem and behavior.
   - Validation steps (local + relevant integration tests).
   - Risks, limitations, migration, rollback.
   - Security invariants L-01 → L-20 that were reviewed.
4. **Wait for the required checks**: `ci-result`, `Complete source coverage and regression gate`, `workflow-lint`, `Secret detection (Gitleaks)`, `Static analysis (Semgrep)`, `Filesystem vulnerability scan (Trivy)` and `Composer and npm advisories`. Path-filtered `plugin-check` and `sbom` runs report when their inputs change; release, changelog, compliance, documentation deploy and performance gates run on tags, schedules or manual dispatch.
5. **Wait for code-owner review** (CODEOWNERS review is required). No self-merge.
6. **Address review feedback** in additional commits (do not force-push after review started).
7. **Once approved**, a maintainer will squash-merge. Delete your branch after merge.

Do not merge your own PR. Do not push directly to `main`.

---

## 8. Documentation Requirements

When you change behavior, update **all** the relevant documents in the same Pull Request.

| Change | Update |
| --- | --- |
| New or changed hook | `docs/en/architecture/overview.md`, `CHANGELOG.md` |
| New or changed REST/MCP route | `docs/en/architecture/overview.md`, threat model, ABility catalog |
| New or changed schema | `docs/en/architecture/ability-catalog.md`, integration tests, output pipeline tests |
| Security boundary change | Threat model, security tests, `SECURITY.md` if disclosure scope changed |
| Translation string change | `languages/xnetvn-agent-hub-wp.pot` regenerated via `bash bin/make-pot.sh` |
| Configuration change | `docs/en/INSTALL.md`, `docs/en/ENVIRONMENT.md` |

Vietnamese translations live under `docs/vi/`. English is the source of truth; Vietnamese mirrors the English documents.

---

## 9. Release & Versioning

- The project follows **Semantic Versioning 2.0.0** (see [the Semantic Versioning specification](https://semver.org/)).
- Releases are cut by maintainers from `release/<version>` branches.
- A release triggers `release.yml` which builds the plugin ZIP, opens a draft GitHub Release, and pins the artifact.
- Hotfix releases follow `hotfix/<ticket>` → fast-track → back-merge to `main`.
- Every release must update `CHANGELOG.md` with the `Added`, `Changed`, `Fixed`, `Deprecated`, `Removed`, `Security` sections (Keep a Changelog format).

---

## 10. Reporting Vulnerabilities

Please **do not** open public issues for security problems. Follow the private disclosure procedure in [SECURITY.md](SECURITY.md): email `security@xnetvn.net` with the reproduction steps and a private contact channel.

---

By contributing, you agree that your contributions are licensed under the project's [GPL-2.0-or-later](LICENSE) terms.

Read the [Code of Conduct](CODE_OF_CONDUCT.md). Report vulnerabilities privately through [SECURITY.md](SECURITY.md).
