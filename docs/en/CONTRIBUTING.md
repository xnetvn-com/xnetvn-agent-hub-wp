# Project Contribution Guide (Contributing Guide)

> **Thank you for your interest in contributing to xNetVN Agent Hub WP! We welcome contributions from the global developer community.**

---

## 1. Code of Conduct
All contributors must follow the project's Code of Conduct at [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). We commit to building an inclusive, open, respectful and professional environment.

---

## 2. Git Workflow

1. **Fork the Repository:** Create a copy of the repository under your personal GitHub account.
2. **Create a Feature Branch:** Always create a new branch from `main` using the standard naming convention:
   - New feature: `feature/<ma-ticket>-<mo-ta-ngan>` (Example: `feature/M2-add-content-pack`)
   - Bug fix: `bugfix/<ma-ticket>-<mo-ta-ngan>` (Example: `bugfix/fix-token-parsing-length`)
   - Refactoring: `refactor/<ma-ticket>-<mo-ta-ngan>`
   - Documentation: `docs/<mo-ta-ngan>`
3. **Small, Incremental Changes:** Avoid enormous Pull Requests that bundle multiple unrelated features.
4. **Conventional Commits:**
   - Required English format: `<type>(<scope>): <subject>`
   - Examples:
     - `feat(auth): add ip cidr filtering to token validation`
     - `fix(ratelimit): prevent race condition in atomic counter`
     - `docs(vi): update installation guide and architecture specs`
     - `test(mcp): add negative test cases for invalid origin header`

---

## 3. Coding Standards

- **Source code language:** Standard English is mandatory throughout source code (class, method and variable names, code comments, docblocks).
- **WordPress & PHPStan compliance:**
  - Strictly apply WordPress Coding Standards (`WordPress-Extra`, `WordPress-Core`, `WordPress-Docs`).
  - Static analysis at **PHPStan Level 8 without a baseline**.
  - PHP 8.2 compatibility for runtime and PHP 7.2 for Bootstrap files.
- **Security rules:**
  - Always use Prepared Statements (`$wpdb->prepare`) for SQL queries.
  - Sanitize Input and Escape Output.
  - Never store or log sensitive information (tokens, passwords, hashes).

---

## 4. Mandatory Testing (Testing Standards)

- Every new feature or bug fix must include an automated test (Unit Test or Integration Test).
- Apply TDD: Write the test first, ensure it fails, then write the minimum source code needed to pass.
- Run the entire check suite before submitting a Pull Request:
  ```bash
  composer check
  ```

---

## 5. Pull Request Submission (PR Process)

1. Push commits to your branch on GitHub.
2. Create a Pull Request targeting the main repository's `main` branch.
3. Complete all information in the template at [templates/pr_template.md](templates/pr_template.md).
4. Ensure all automated checks (CI Pipeline) on GitHub Actions are green (Pass).
5. At least one Core Maintainer will review your source code and provide feedback.

---

## 6. Working Files and Plans

Working copies of plans, specifications and temporary reports are not published documentation; canonical specifications and reports live under `docs/`. Keep working copies in the Git-ignored `.local/docs/` tree: `plans/`, `specs/`, `reports/`, `superpowers/plans/` and `en/issues/`. They never ship in the release ZIP.

The four historical design plans formerly under `docs/superpowers/plans/` were moved out of the published tree. They remain readable at commit `9a709433d895255865b3ddccb5c2fc96eb4c4e8a`:

- `docs/superpowers/plans/2026-10-09-comments-pack.md`
- `docs/superpowers/plans/2026-10-09-taxonomy-pack.md`
- `docs/superpowers/plans/2026-10-10-users-pack.md`
- `docs/superpowers/plans/2026-11-04-wordpress-org-submission-readiness.md`

Use the [plans tree at that commit](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/tree/9a709433d895255865b3ddccb5c2fc96eb4c4e8a/docs/superpowers/plans) when you need the original context.
