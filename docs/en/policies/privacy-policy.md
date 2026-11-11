# Privacy Protection Policy (Privacy Policy)

> **Policy for collecting, storing, processing and protecting personal data under GDPR and CCPA regulations in xNetVN Agent Hub WP.**

---

## 1. Data Minimization

The system follows the principle of collecting and processing only the minimum data necessary to ensure secure MCP server operation:
- **Stored Data:**
  - WordPress user identifier (`user_id`).
  - Public API key identifier (`key_id`), user-defined memorable name, creation time, expiry time and last-used time.
  - The API key's cryptographic `HMAC-SHA256` hash (raw keys are never stored).
  - Audit logs: IP address (masking supported), User-Agent, called tool name, outcome and top-level argument names.
- **Data Never Collected or Stored:**
  - Argument values are not stored (e.g. text content, prompts).
  - Passwords or raw API keys are not stored.

---

## 2. Data Subject Rights under GDPR

xNetVN Agent Hub WP integrates directly with WordPress core Privacy Management tools (`Tools → Export Personal Data` and `Tools → Erase Personal Data`):

### 2.1 Right of Access & Portability
- Through the `PrivacyExporter` class, when an Administrator requests a user's personal data export:
  - The system extracts the complete list of API keys owned by that user (key name, key ID, permissions, creation date, last use).
  - It extracts the entire history of audit events associated with that `user_id`.
  - The export uses the standard WordPress JSON/ZIP format without any sensitive secret or hash.

### 2.2 Right to Erasure / To be Forgotten
- Through the `PrivacyEraser` class, when an Administrator confirms a user's personal data erasure request:
  - The system permanently deletes all API keys owned by that account.
  - It deletes related key lifecycle notification preferences.
  - It anonymizes audit records: changes `user_id` to `0` to preserve the integrity of system statistics without linking them to a personal identity.
  - If this user previously revoked another user's key, the `revoked_by` field on the other user's key is cleared without deleting the remaining owner's key.

---

## 3. IP Masking & Anonymization
- The system supports automatic IP address masking:
  - For IPv4: Replace the last byte with 0 (e.g. `198.51.100.45` becomes `198.51.100.0/24`).
  - For IPv6: Replace the final 80 bits with 0 (e.g. `2001:db8:85a3::8a2e:370:7334` becomes `2001:db8:85a3::/48`).
- This helps protect users' geographic location identity while retaining basic network information for attack detection.
