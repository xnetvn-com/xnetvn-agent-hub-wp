# Automated Testing Standards (Testing Standards)

> **Technical standards for writing Unit Tests, Integration Tests and E2E Tests for xNetVN Agent Hub WP.**

---

## 1. Core Testing Principles

1. **Test-Driven Development (TDD):**
   - **Step 1 (Red):** Write a test reflecting a new requirement or reproducing a reported failure; run it and confirm it fails.
   - **Step 2 (Green):** Write the minimum code necessary for the test to pass.
   - **Step 3 (Refactor):** Optimize the source code and clean up its structure while keeping tests green.
2. **Standard Test Structure (Given - When - Then / Arrange - Act - Assert):**
   - Every test case must clearly separate 3 parts:
     ```php
     public function test_should_deny_request_when_token_is_expired(): void {
         // Given: Prepare data and the expired key state
         $expired_key = $this->create_expired_key();
         
         // When: Perform authentication
         $result = $this->authenticator->authenticate($expired_key);
         
         // Then: Verify denial with the correct reason
         $this->assertTrue($result->is_denied());
         $this->assertSame(FailureReason::KeyExpired, $result->reason);
     }
     ```
3. **Every Test Must Be Fully Independent (Test Isolation):**
   - A test's result must not depend on execution order or state left by another test.
   - All WordPress database or environment variable changes must be completely cleaned up in `tearDown()`.

---

## 2. Unit Testing Standards (PHPUnit + Brain Monkey)

- **File location:** Place files in `tests/Unit/`, mirroring the `src/` directory structure (e.g. `src/Auth/KeyGenerator.php` corresponds to `tests/Unit/Auth/KeyGeneratorTest.php`).
- **Test method naming:** Start with `test_should_<ExpectedBehavior>_when_<StateUnderTest>`.
- **Use Mocks Responsibly:**
  - Mock contract Interfaces (`src/Contracts/`) using Mockery.
  - Use Brain Monkey to control global WordPress functions (`add_action`, `do_action`, `apply_filters`, `get_option`).
  - Do not mock pure Value Objects (e.g. `KeyId`, `Scope`, `RiskTier`).

---

## 3. Integration Testing Standards (WP-PHPUnit)

- **File location:** Place files in `tests/Integration/`.
- **Base class:** Extend `WP_UnitTestCase`.
- **Testing Against a Real Database:**
  - Verify actual interaction with WordPress MySQL tables.
  - Verify row-locking transactions (`FOR UPDATE`) and distributed locks `GET_LOCK()`.
  - Verify WordPress hooks execute in the correct order and with the correct arguments.

---

## 4. Code Coverage Requirements

- **Coverage Targets:**
  - At least **90%** Line & Branch Coverage for the `Domain`, `Auth`, `Policy` layers.
  - At least **80%** across the project for official releases.
- **Coverage Report Command:**
  ```bash
  # Collect detailed code coverage
  composer test:unit -- --coverage-html .local/reports/coverage
  ```
- **Do Not Pursue Numbers for Appearance:** The quality of Boundary Cases, Negative Testing with invalid/malicious data and security boundary checks matters more than percentages.
