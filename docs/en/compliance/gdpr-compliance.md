# GDPR Data Protection Compliance (GDPR Compliance)

> **Report and guidance on compliance with the European Union General Data Protection Regulation (EU GDPR) for xNetVN Agent Hub WP.**

---

## 1. The Project's GDPR Compliance Framework

GDPR imposes strict requirements on the collection, processing and storage of personal data (Personal Data / PII). **xNetVN Agent Hub WP** is designed to help deploying organizations meet 100% of the core principles under Article 5 and data subject rights under Chapter III of GDPR.

---

## 2. Meeting Data Processing Principles (GDPR Article 5)

1. **Lawfulness, Fairness, Transparency:**
   - Every AI Agent activity is linked by identity to a legitimate WordPress user account (`user_id`).
   - Transparent audit logs record the time, tool and outcome of each action.
2. **Purpose Limitation:**
   - API keys and audit logs are used solely for authentication, authorization and ensuring secure MCP server operation.
3. **Data Minimization:**
   - Do not store request argument values (e.g. prompt text, confidential content).
   - Store only top-level argument names (`arg_names`).
   - API keys are stored only as one-way `HMAC-SHA256` hashes.
4. **Storage Limitation:**
   - Define a maximum audit log retention period (90 days by default) and automatically clean up through a cron job.
5. **Integrity and Confidentiality:**
   - Apply strong cryptographic algorithms, require TLS/HTTPS and protect the database against unauthorized access.

---

## 3. Implementing Data Subject Rights (GDPR Chapter III)

The project integrates deeply with the WordPress Core Privacy Management interface:

### 3.1 Right of Access - Article 15
- Through the `XNetVN\AgentHubWP\Audit\PrivacyExporter` class:
- Register a personal data exporter through the `wp_privacy_personal_data_exporters` hook.
- When a user requests a data export:
  - The system exports information about all API keys owned by the user (key name, creation date, expiry date, permissions, last use).
  - It exports all audit events related to the user.
  - Never expose the Pepper secret, secret hashes or tokens.

### 3.2 Right to Erasure / To be Forgotten - Article 17
- Through the `XNetVN\AgentHubWP\Audit\PrivacyEraser` class:
- Register a data eraser through the `wp_privacy_personal_data_erasers` hook.
- When the data erasure request is confirmed:
  - Delete all of that user's API keys from the database.
  - Delete the user's email notification preferences.
  - Anonymize audit records by assigning `user_id = 0`, ensuring personal links are completely removed while retaining general technical statistics.
  - If the user previously revoked another user's key, the `revoked_by` reference field is safely cleared without affecting the other owner's key.
