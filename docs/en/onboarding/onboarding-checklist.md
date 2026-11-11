# New Member Onboarding Checklist

> **A step-by-step path to help developers and new members join and start contributing effectively to xNetVN Agent Hub WP.**

---

## 📅 Week 1: Environment Setup & Project Introduction

### Day 1: Read the Core Documentation & Get Acquainted
- [ ] Read [README.md](../README.md) to understand the project's mission and goals.
- [ ] Read [ARCHITECTURE.md](../ARCHITECTURE.md) to understand the immutable security boundaries.
- [ ] Read [CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md) and [CONTRIBUTING.md](../CONTRIBUTING.md).
- [ ] Read [standards/coding-standards.md](../standards/coding-standards.md) to understand the coding standards.

### Days 2 - 3: Set Up the Local Development Environment
- [ ] Install PHP 8.2+ and Composer 2.x on your workstation.
- [ ] Install Node.js 20+ and npm.
- [ ] Clone the project repository: `git clone https://github.com/xnetvn-com/xnetvn-agent-hub-wp.git`.
- [ ] Run `composer install` and `npm install`.
- [ ] Start the local WordPress environment through wp-env or Docker Compose: `npm run env:start`.
- [ ] Run the initial quality checks: `composer check` (ensure 100% green).

### Days 4 - 5: Understand the Processing Flow & Run Test Cases
- [ ] Run the Unit Tests: `composer test:unit`.
- [ ] Open `src/Abilities/Packs/System/SystemPack.php` and learn how the 5 System Abilities are defined.
- [ ] Create a test API key in the administration screen and use cURL/Postman to try calling the route `/wp-json/xnetvn-agent-hub-wp/mcp`.

---

## 📅 Week 2: Complete the First Task (First PR)

- [ ] Take a Good First Issue or a test case assignment from your Mentor.
- [ ] Create a feature branch following the convention: `git checkout -b feature/...`.
- [ ] Apply TDD: Write tests first, then write code to solve the problem.
- [ ] Run checks: `composer check`.
- [ ] Create a Pull Request using [templates/pr_template.md](../templates/pr_template.md).
- [ ] Address code review feedback from Core Maintainers and finalize the PR for merging.
