# MCP Server Runbook

> **A detailed operations handbook for the xNetVN Agent Hub WP MCP server service.**

---

## 1. Core Service Information

- **Service Name:** xNetVN Agent Hub MCP Server (`xnetvn-agent-hub-wp`).
- **MCP Endpoint:** `POST /wp-json/xnetvn-agent-hub-wp/mcp`
- **Protocol:** MCP over Streamable HTTP / JSON-RPC 2.0.
- **Dependencies:** Nginx, PHP 8.2-FPM, MySQL 8.0/MariaDB, Redis Cache (optional), `mcp-adapter` plugin.
- **Operations Owner:** xNetVN System Administration / SRE team.

---

## 2. Standard Operating Procedures

### SOP-01: Check MCP Service Health
```bash
# Send an endpoint check request
curl -i -X POST https://your-domain.com/wp-json/xnetvn-agent-hub-wp/mcp \
  -H "Content-Type: application/json"

# Expected result:
# HTTP/1.1 401 Unauthorized (No Bearer token supplied, demonstrating that the route works and is protected)
```

### SOP-02: Check AI Agent Connectivity with a Valid Key
```bash
curl -i -X POST https://your-domain.com/wp-json/xnetvn-agent-hub-wp/mcp \
  -H "Authorization: Bearer <YOUR_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc": "2.0", "id": 1, "method": "tools/list", "params": {}}'

# Expected result:
# HTTP/1.1 200 OK
# Response listing the 5 SystemPack tools
```

### SOP-03: Handle Cache Congestion or Rate Limit Counter Errors
If Rate Limit counters in Redis or the Database appear stuck or inaccurate:
```bash
# Clear the WordPress cache
wp cache flush

# Check PHP-FPM logs
tail -n 100 /var/log/php8.2-fpm.log
```

---

## 3. Error Indicators Requiring Urgent Action

1. **A spike in 401 errors (> 100 req/minute from 1 IP):**
   - Action: The IP address may be scanning for tokens. Check the IP in audit logs and add it to the blocking configuration in Firewall/Fail2ban or Cloudflare WAF.
2. **500 errors on the MCP route:**
   - Action: Immediately check `wp-content/debug.log` for PHP Fatal errors or plugin syntax errors.
3. **Unusually high MySQL RAM usage:**
   - Action: Check the row count in `wp_xnetvn_agent_hub_wp_audit`. If excessive, trigger the cleanup cron command:
     ```bash
     wp cron event run xnetvn_agent_hub_wp_purge_audit
     ```
