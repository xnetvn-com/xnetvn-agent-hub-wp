# System Operation Guide

> **A handbook for administrators and operations engineers (SRE / DevOps) monitoring and managing xNetVN Agent Hub WP.**

---

## 1. Periodic Administrator Tasks

### 1.1 API Key Management and Monitoring
- Every week, visit **Agent Hub → API Keys**:
  - Review the list of API keys that are currently `active`.
  - Revoke unused keys or keys belonging to personnel who have changed assignments.
  - Check the **Last Used** column to identify unusual keys.
  - Remind users to Rotate keys that are approaching expiry (the system automatically sends an email notification 7 days in advance).

### 1.2 Audit Trail Monitoring
- Visit **Agent Hub → Audit Logs**:
  - Filter by the `denied` outcome to identify intrusion attempts or incorrect tokens.
  - Check request frequency from unfamiliar IP addresses.
  - Ensure that the log cleanup process (`Retention`) runs regularly so that table size stays within the permitted threshold.

---

## 2. WP-CLI Administration Commands

The system supports quick administration tasks through WP-CLI on the server:

```bash
# Check plugin status
wp plugin status xnetvn-agent-hub-wp

# List API keys for a specific user
wp eval 'var_dump( (new \XNetVN\AgentHubWP\Persistence\WpdbKeyRepository($GLOBALS["wpdb"], new \XNetVN\AgentHubWP\Support\SystemClock()))->list_for_user(1) );'

# Manually run the audit cleanup process
wp cron event run xnetvn_agent_hub_wp_retention_purge

# Clear the WordPress object cache after configuration changes
wp cache flush
```

---

## 3. Replacing the Pepper After a Leak

If a database leak is detected and all API keys in the ecosystem need to be invalidated urgently:
1. Access the server or the MySQL database.
2. Delete or overwrite the Pepper option:
   ```bash
   wp option delete xnetvn_agent_hub_wp_pepper
   ```
3. Restart or reactivate the plugin. The plugin automatically generates a completely new random 32-byte Pepper.
4. **Result:** All existing API keys immediately become invalid (HMAC comparisons no longer match), protecting the website immediately.
5. Notify users to create new API keys to continue using the service.
