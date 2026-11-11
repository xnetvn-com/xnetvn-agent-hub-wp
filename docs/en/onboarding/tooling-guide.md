# Development Tooling Guide

> **Guide to command-line tools, automation scripts, and testing tools in xNetVN Agent Hub WP.**

---

## 1. Composer Command-Line Scripts

The commands defined in `composer.json` support the daily development workflow:

```bash
# 1. Check code formatting against WordPress standards (PHPCS)
composer lint

# 2. Automatically fix simple formatting errors (PHPCBF)
composer lint:fix

# 3. Check PHP 7.2 syntax compatibility for bootstrap files
composer lint:bootstrap

# 4. Perform static code quality analysis at the highest level (PHPStan Level 8)
composer analyse

# 5. Run the Unit Tests
composer test:unit

# 6. Run Integration Tests in an actual WordPress environment
composer test:integration

# 7. Verify the integrity of the release ZIP package
composer test:packaging

# 8. Run the complete quality check suite (required gate before committing)
composer check

# 9. Package the complete product release
composer build
```

---

## 2. Guide to Automation Scripts in `bin/`

- **`bin/build-zip.sh`:**  
  Script for packaging the official release ZIP. Automatically installs clean Composer dependencies, excludes development files, and validates the output package structure.
- **`bin/verify-zip.sh`:**  
  Checks the packaged ZIP for prohibited junk files, sensitive files, or leftover test files.
- **`bin/make-pot.sh`:**  
  Extracts all localization strings from PHP and JavaScript source into `languages/xnetvn-agent-hub-wp.pot`.
- **`bin/test-integration.sh`:**  
  Script that starts the automated integration testing environment with a WordPress database.

---

## 3. Browser & E2E Testing Tools (Playwright & Axe Core)

```bash
# Install Playwright browsers (if not already installed)
npx playwright install --with-deps

# Run E2E tests in Chromium
npm run test:e2e

# Run dependency contract checks
npm run test:tooling
```
