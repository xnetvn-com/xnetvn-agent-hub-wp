# Logging & Auditing Standards (Logging Standards)

> **Standards for audit logging, sensitive information management and data privacy protection in xNetVN Agent Hub WP.**

---

## 1. Log Data Safety Principles (Zero-Leakage Policy)

- **Strictly Prohibit Logging Secrets:**
  - Never record raw secret token strings (`xnv_..._secret`), HMAC hashes, Pepper keys, user passwords or application passwords in logs or audit tables.
- **No Request Argument Values (No Argument Values):**
  - Audit logs record only **Top-level argument names** in `arg_names` (e.g. `["post_id", "status"]`).
  - Never record argument values (e.g. post content, emails, post titles) to prevent personal data (PII) or sensitive customer data leakage.
- **IP Masking:**
  - To comply with GDPR, the system supports user IP address masking:
    - IPv4: Mask the last bit (e.g. `192.168.1.123` -> `192.168.1.0/24`).
    - IPv6: Mask the host portion (e.g. `2001:db8:abcd:0012::1` -> `2001:db8:abcd::/48`).

---

## 2. Audit Record Structure (`wp_xnetvn_agent_hub_wp_audit`)

Every audit record created must include all of the following standard structured fields:

| Field Name | Data Type | Meaning |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Auto-incrementing record primary key. |
| `request_id` | `VARCHAR(36)` | UUID v4 uniquely identifying each HTTP MCP request, supporting end-to-end Distributed Tracing. |
| `user_id` | `BIGINT UNSIGNED` | ID of the WordPress user who owns the key performing the action. |
| `key_id` | `VARCHAR(12)` | The API key's public 12-character identifier (or `null` if authentication fails before key parsing). |
| `ability_name`| `VARCHAR(128)` | Identifier of the called Ability (e.g. `xnetvn/get-site-info`). |
| `tier` | `VARCHAR(20)` | Ability risk tier (`read`, `write`, `destructive`, `admin`). |
| `outcome` | `VARCHAR(20)` | Execution outcome (`success`, `denied`, `error`). |
| `ip_address` | `VARCHAR(45)` | Client IP address (validated against trusted proxies and masked if configured). |
| `user_agent` | `VARCHAR(255)` | Client User-Agent string (truncated to at most 255 characters to prevent DoS). |
| `arg_names` | `TEXT` | JSON list containing the names of supplied top-level arguments. |
| `execution_time_ms` | `INT UNSIGNED` | Execution time in milliseconds for performance monitoring. |
| `created_at` | `DATETIME UTC` | Timestamp in international standard UTC time. |

---

## 3. Buffered Asynchronous Writes

- To avoid degrading AI Agent response latency, audit events are temporarily held in the PHP process's internal buffer (`RequestScope`).
- A bulk database table write (`INSERT`) is executed only once at the end of the WordPress lifecycle (`shutdown` hook).
- If the database fails or becomes overloaded, the system lightly logs to the PHP error file (`error_log`) without crashing the response process for the client.

---

## 4. Retention & Disposal Policy (Retention Policy)

- By default, audit logs are retained for **90 days**.
- A daily cron job (`xnetvn_agent_hub_wp_purge_audit`) deletes records older than the configured number of days in bounded batches, at most 500 records per batch, to avoid extended database table locks.
