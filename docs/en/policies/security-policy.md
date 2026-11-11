# Information Security Policy (Security Policy)

> **Comprehensive security policy, risk control boundaries and security vulnerability handling procedures for xNetVN Agent Hub WP.**

---

## 1. Security Objectives & Commitments
- **xNetVN Agent Hub WP** is designed with **Security by Design** and **Zero Trust** principles.
- The project commits to strict compliance with leading international security guidelines and standards:
  - **OWASP API Security Top 10 (2023)**.
  - **NIST SP 800-53 / 800-171**.
  - **WordPress Core Security Best Practices**.

---

## 2. Security Controls

### 2.1 Permanent operational boundaries

The authoritative exclusions are [architecture/overview.md](../architecture/overview.md) and the code HardDeny list. No arbitrary execution, SQL, source/configuration edits, secrets, credential administration or arbitrary plugin/theme ZIP tools. Sanctioned attachment writes use core upload APIs only. Settings are documented solely in [ENVIRONMENT.md](../ENVIRONMENT.md).

### 2.2 Timing Attack Prevention
- API key HMAC hash comparison always uses `hash_equals()`.
- When a user submits a `key_id` that does not exist in the database, the system still performs a dummy HMAC computation with equivalent latency so that response timing does not reveal key existence.

### 2.3 Origin & CSRF Defense
- The MCP route rejects Cookie-based user identification. Every request must carry a Bearer Token.
- The `Origin` header is strictly checked:
  - An absent `Origin` header is allowed for desktop clients (such as Claude Desktop and CLI scripts).
  - Immediately reject `Origin: null` or an Origin outside the administrator-configured domain allowlist (`allowed_origins`).

### 2.4 Prompt Injection Prevention & Untrusted Data Handling
- All content retrieved from the WordPress database (post titles, post content, comments, author names) is treated as **Untrusted Data**.
- Tool Descriptions are fixed in standard English and never incorporate dynamic user input, preventing AI Agents from being deceived through Instruction Override.

---

## 3. Vulnerability Disclosure

- If you discover a potential security vulnerability in the project, **please do not disclose it publicly through GitHub Issues or social media**.
- Send a detailed report to the dedicated security email address:
  **`security@xnetvn.net`** or use **GitHub Private Security Advisories**.
- **Handling Time Commitments:**
  - Acknowledge receipt within **24 hours**.
  - Assess severity and provide technical feedback within **48 hours**.
  - Release a security patch and acknowledge contributions (Security Hall of Fame) within **7 business days**.
