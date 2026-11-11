# Operations Incident Response Runbook

> **Step-by-step actions for service disruptions, cyberattacks, or authentication errors in xNetVN Agent Hub WP.**

---

## 1. Scenario 1: Suspected Compromised API Key

### Indicators:
- MCP requests from unfamiliar IP addresses or unusual countries appear in the Audit table.
- Calls to tools that modify/delete data increase unusually.

### Immediate Response Steps:
1. **Emergency key revocation:**
   - Log in to WordPress administration: **Agent Hub → API Keys**.
   - Search for the suspected `key_id` and click **Revoke**.
   - Revocation takes effect immediately, without delay.
2. **Review audit logs:**
   - Filter the audit table by that `key_id` to list all actions the Agent has performed over the relevant period.
   - Check modified posts and data to restore them from Revisions or backups.
3. **Notify the account owner:**
   - Send a security alert to the key owner and request a WordPress account password change if necessary.

---

## 2. Scenario 2: Compromised Secret Pepper

### Indicators:
- The entire `wp_options` database or a backup has leaked externally.

### Immediate Response Steps:
1. Log in to the web server through SSH:
   ```bash
   cd /var/www/html
   wp option delete xnetvn_agent_hub_wp_pepper
   ```
2. Reload the website or make any 1 request so that the plugin automatically generates a new Pepper.
3. Once the Pepper changes, 100% of existing API keys no longer match their HMAC hashes and are immediately invalidated.
4. Publish an internal notice requiring all users to create new API keys.

---

## 3. Scenario 3: Denial-of-Service Attack (DDoS / Brute-force Attack)

### Indicators:
- Thousands of continuous requests to `/wp-json/xnetvn-agent-hub-wp/mcp` drive server CPU to 100%.

### Response Steps:
1. Enable "Under Attack Mode" on Cloudflare or the WAF.
2. Apply IP range blocking or rate limiting rules at the Nginx Web Server layer:
   ```nginx
   limit_req_zone $binary_remote_addr zone=mcp_limit:10m rate=10r/s;
   location ~ ^/wp-json/xnetvn-agent-hub-wp/mcp {
       limit_req zone=mcp_limit burst=20 nodelay;
       try_files $uri $uri/ /index.php?$args;
   }
   ```
3. Restart PHP-FPM: `sudo systemctl restart php8.2-fpm`.
