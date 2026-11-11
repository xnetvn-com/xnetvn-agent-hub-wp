# Pull Request Template

> **Required information template for submitting a Pull Request (PR) to xNetVN Agent Hub WP.**

---

## 1. General Information

- **PR title (Conventional Commits):**  
  _(Example: `feat(content): implement list-post-types ability with strict schema`)_

- **Related issue resolved:**  
  Fixes #_____ / Closes #_____

- **Type of Change:**
  - [ ] `feat`: New feature
  - [ ] `fix`: Bug fix
  - [ ] `refactor`: Code refactoring (without changing external functionality)
  - [ ] `perf`: Performance improvement
  - [ ] `test`: Add or update tests
  - [ ] `docs`: Documentation update
  - [ ] `chore`: Update build configuration or dependencies

---

## 2. Change Description & Technical Solution

- **Problem to solve:**  
  _(Explain the root cause or business requirement)_

- **Implementation solution:**  
  _(Describe the classes, methods, or data structures added or modified in detail)_

- **System Impact & Backward Compatibility:**  
  - [ ] Does this break backward compatibility? (Breaking change)
  - [ ] Does this change the database table structure? (Schema migration)
  - [ ] Does this affect immutable security boundaries?

---

## 3. Verification Evidence

- **Checks completed successfully:**
  - [ ] `composer lint` (No WordPress PHPCS errors)
  - [ ] `composer lint:bootstrap` (PHP 7.2 compatibility)
  - [ ] `composer analyse` (PHPStan Level 8 without a baseline)
  - [ ] `composer test:unit` (100% of unit tests pass)
  - [ ] `composer test:integration` (100% of integration tests pass on actual WordPress)

- **Coverage Delta:**  
  _(Specify coverage before and after the change, ensuring >= 90% for the domain core)_

---

## 4. Security & Quality Checklist

- [ ] No sensitive information (tokens, secrets, private keys, passwords) is included in code or logs.
- [ ] All database queries use `$wpdb->prepare` with fully parameterized arguments.
- [ ] Output data passes through `OutputPipeline` and is validated against JSON Schema.
- [ ] Permissions are checked at the object level (Object-level authorization).
- [ ] Updated the corresponding documentation in `docs/en/` and `CHANGELOG.md`.
