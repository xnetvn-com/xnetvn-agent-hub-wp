# Data Retention & Disposal Policy (Data Retention Policy)

> **Rules for retention periods, automated cleanup cycles and safe disposal of audit data and API keys in xNetVN Agent Hub WP.**

---

## 1. Purpose & Principles

- Ensure the system maintains sufficient audit evidence for information security investigations and compliance monitoring.
- Also comply with the principle of limiting personal data retention under the General Data Protection Regulation (GDPR Article 5.1(e)).
- Prevent database growth from degrading website performance over time.

---

## 2. Retention Schedule

| Data Type | Storage Location | Default Retention Period | Disposal Mechanism |
|---|---|---|---|
| **Active API Keys** | `*_xnetvn_agent_hub_keys` | Up to 365 days (or the user-defined `expires_at` date) | Automatically become invalid on that date; revoked immediately upon user request |
| **Revoked / Expired API Keys** | `*_xnetvn_agent_hub_keys` | 30 days after revocation/expiry | Automatically cleaned up by a daily Cron process |
| **Audit Logs** | `*_xnetvn_agent_hub_audit` | **90 days** (Configurable from 30 to 365 days in Settings) | Automatically deleted in small batches by daily Cron |
| **Rate Limit Counters** | `wp_options` / Redis Cache | 60 seconds (sliding window) | Automatically expire (TTL) or are deleted after more than 5 minutes |
| **Personal Data of Deleted Users** | `*_xnetvn_agent_hub_keys` & `audit` | Immediately upon a deletion command | Triggered through the WordPress Erase Personal Data workflow |

---

## 3. Automated Purge Workflow

1. **Schedule a Cron Job:**  
   The system registers the daily cron event `xnetvn_agent_hub_wp_retention_purge`.
2. **Delete in Bounded Batches:**  
   - The process deletes at most **500 records** in one `DELETE ... WHERE created_at < ... LIMIT 500` statement.
   - If more than 500 records require deletion, the system automatically schedules a follow-up run to process the backlog without locking database tables for extended periods or slowing website users' queries.
3. **Record Cleanup Results:**  
   The number of records successfully cleaned up is recorded in the system log for monitoring.

---

## 4. Handling Complete Plugin Removal (Uninstallation)

- When the plugin is removed through the Plugins administration menu or the `wp plugin delete` command:
  - **Default:** The `uninstall.php` process completely deletes all database tables (`wp_xnetvn_agent_hub_keys`, `wp_xnetvn_agent_hub_audit`), the Pepper key and all related options.
  - **Data Preservation:** If the Administrator enabled `preserve_on_uninstall` on the Settings page, all table data remains intact for subsequent reactivation.
