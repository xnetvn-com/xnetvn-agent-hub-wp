# PCI DSS Data Security Standard Compliance (PCI DSS Compliance)

> **Report and guidance on Payment Card Industry Data Security Standard (PCI DSS v4.0) compliance applicable to xNetVN Agent Hub WP environments.**

---

## 1. Scope

- **xNetVN Agent Hub WP** is an MCP server plugin for integrating AI Agents into WordPress.
- The plugin **does not directly process, store or transmit cardholder data (Primary Account Number - PAN or Sensitive Authentication Data - SAD)**.
- However, in e-commerce environments (WooCommerce / WordPress e-Commerce), the MCP server runs on the same server as the commerce application. Therefore, compliance with PCI DSS v4.0 principles is mandatory to avoid increasing risk to the Cardholder Data Environment (CDE).

---

## 2. Measures Addressing PCI DSS v4.0 Requirements

### Requirement 2: Apply Secure Default Configurations (Secure Configurations)
- By default, the plugin completely disables the `admin` risk tier.
- By default, reject unencrypted HTTP connections and Origins not included in the trusted list.

### Requirement 3: Protect Stored Account Data
- Users' API secrets are never stored in cleartext.
- Use the one-way cryptographic hashing algorithm `HMAC-SHA256` with an independent Pepper key.
- The output pipeline (`OutputPipeline`) removes all sensitive information before sending responses to AI Agents.

### Requirement 6: Develop & Maintain Secure Systems and Software
- Source code undergoes static analysis at the strictest level (PHPStan Level 8 without a baseline).
- There are no security vulnerabilities in the OWASP Top 10 categories (protection against SQL Injection, XSS, CSRF).
- Every database query uses parameterized Prepared Statements.

### Requirement 8: Identification and Authentication
- Each MCP request must carry a Bearer Token issued exclusively to an individual user.
- Support key lifetime limits (up to 365 days) and key rotation without service interruption.
- Require users to reauthenticate with their account password when issuing keys with destructive data permissions (`destructive`).

### Requirement 10: Log and Monitor Access to Resources
- The audit log table records all MCP access activity: UTC time, user ID, key ID, action and client IP address.
- Audit log integrity is protected, with controlled periodic cleanup.
