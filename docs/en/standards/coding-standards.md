# Programming & Source Code Formatting Standards (Coding Standards)

> **Rules and source code formatting standards applicable to the entire xNetVN Agent Hub WP project.**

---

## 1. Core Principles
- **Code as though written by one person:** Consistent, clean, explicit and compliant with the highest software industry standards.
- **Dual-Language Protocol:**
  - **Source Code:** 100% professional English is required for variable names, function names, class names, constants, logic comments, Docblocks/PHPDoc and commit messages.
  - **Original documentation in `docs/vi/`:** 100% standard Vietnamese is required as the Single Source of Truth.
- **No dead code / commented-out code:** Never retain discarded, unused or commented-out source code in VCS.
- **No Magic Numbers / Magic Strings:** All constant values must be explicitly declared in Enums or class constants (`class constants`).

---

## 2. PHP Coding Standards

### 2.1 Compliance Standards
- **WordPress Coding Standards:** `WordPress-Core`, `WordPress-Extra`, `WordPress-Docs` (through `phpcs.xml.dist`).
- **PHPStan:** Static analysis at the highest **Level 8** without a baseline file (`phpstan.neon.dist`).
- **Strict declaration:** `declare(strict_types=1);` is required at the beginning of every PHP file in `src/` and `tests/`.
- **Version compatibility:**
  - Runtime code (`src/`): PHP 8.2 or later (using Typed Properties, Readonly Classes/Properties, Constructor Promotion, Match expressions).
  - Bootstrap code (`src/Bootstrap/`, `uninstall.php`, `xnetvn-agent-hub-wp.php`): Compatible with PHP 7.2 or later syntax to safely report errors on older environments.

### 2.2 Naming Conventions
- **Namespace:** `XNetVN\AgentHubWP\...` (PSR-4).
- **Classes, Interfaces, Traits, Enums:** `PascalCase` (e.g. `SlidingWindowLimiter`, `KeyRepository`, `RiskTier`).
- **Methods & Functions:** `snake_case` following WordPress API tradition or `camelCase` when implementing external interfaces; prefer consistent `snake_case` for internal methods in the WordPress context (e.g. `is_available()`, `register()`, `get_site_info()`).
- **Variables & Properties:** `snake_case` (e.g. `$plugin_file`, `$audit_repository`).
- **Constants:** `UPPER_SNAKE_CASE` (e.g. `XNETVN_AGENT_HUB_WP_VERSION`).
- **Boolean variables:** Start with `is_`, `has_`, `can_`, `should_` (e.g. `$is_active`, `$has_permission`).

### 2.3 Indentation & Line Length
- **Indentation:** Use 4 spaces or 1 standard tab according to the WordPress PHPCS configuration. Do not mix tabs and spaces in one file.
- **Line length:** Recommended below 100 characters, with a maximum of 120 characters.

### 2.4 Docblocks & Comments (PHPDoc)
- 100% of classes, interfaces, public methods and properties must have complete Docblocks.
- Explicitly declare `@param`, `@return`, `@throws` with detailed descriptions:
  ```php
  /**
   * Authenticate and parse a Bearer Token string.
   *
   * @param string $token Raw token string received from the Authorization header.
   * @return ParsedToken|null Returns a parsed token object or null for invalid syntax.
   * @throws InvalidArgumentException When the token format is unacceptable.
   */
  ```

---

## 3. JavaScript & CSS Standards

### 3.1 JavaScript (`assets/admin.js`)
- Use modern JavaScript ES6+, wrapped in an IIFE or Module to avoid global variable conflicts (`global scope pollution`).
- Interact with the DOM safely through `addEventListener`; do not use `innerHTML` with strings of unknown origin to eliminate DOM-based XSS vulnerabilities.
- Manage asynchronous interactions through `async/await` and the `fetch()` API combined with a `wp_rest` nonce.

### 3.2 CSS (`assets/admin.css`)
- Give every class selector an explicit prefix: `.xnetvn-agent-hub-...` to avoid overriding the core WordPress administration interface.
- Fully support Responsive mode (Mobile/Tablet/Desktop) and browser Dark Mode where applicable.
- Ensure color contrast meets WCAG 2.2 AA (at least 4.5:1 for normal text).
