# Troubleshooting Guide

> **Handbook for diagnosis, error code lookup and resolution of common issues when operating xNetVN Agent Hub WP.**

---

## 1. Common Error Codes & Remedies

### 1.1 401 Unauthorized (Authentication failed)
- **Symptoms:** The client receives HTTP `401 Unauthorized` with the generic message `Invalid or missing credentials`.
- **Potential causes:**
  1. The `Authorization` header is missing or does not match `Bearer xnv_<key_id>_<secret>` format.
  2. The web server (Nginx/Apache) strips `Authorization` before forwarding to PHP-FPM.
  3. The API key has expired (`expires_at`), been revoked (`revoked_at`) or its owner has been deleted.
  4. The website's secret Pepper has been changed or lost.
- **Remedies:**
  - Check whether Nginx/Apache configuration allows forwarding the `Authorization` header (see [DEPLOYMENT.md](DEPLOYMENT.md)).
  - Log in to WordPress administration and check key status under **Profile** or **Agent Hub → API Keys**.
  - Try creating a new API key and reconnecting.

### 1.2 403 Forbidden (Access denied)
- **Symptoms:** The client receives HTTP `403 Forbidden`.
- **Potential causes:**
  1. **TLS/HTTPS error:** The request is sent over an insecure HTTP connection.
  2. **Origin error:** The browser's `Origin` header is not in the `allowed_origins` allowlist or is `null`.
  3. **Capability error:** The key owner lacks the corresponding WordPress capability (for example, an account with only `subscriber` permissions calls a post creation tool requiring `edit_posts`).
  4. **Risk Tier error:** The tool requires `destructive` or `admin`, but the API key or site ceiling (`site_ceiling`) allows only `read` or `write`.
  5. **IP restriction error:** The request comes from an IP address outside the CIDR range specified for the API key.
- **Remedies:**
  - Ensure the client sends requests over HTTPS.
  - Check the permissions of the WordPress account owning the key.
  - Check the site risk ceiling configuration under **Agent Hub → Settings**.

### 1.3 429 Too Many Requests (Rate limit exceeded)
- **Symptoms:** The client receives HTTP `429 Too Many Requests` with a `Retry-After` header.
- **Causes:**
  - The API key sends more than 120 requests within 60 seconds.
  - The IP address sends more than 10 consecutive failed authentication requests within 60 seconds.
- **Remedies:**
  - Configure the AI Agent to lower its request rate or apply Exponential Backoff based on the `Retry-After` header.
  - If higher quotas are needed for an enterprise system, an administrator can adjust them under **Agent Hub → Settings**.

### 1.4 503 Service Unavailable (Service unavailable)
- **Symptoms:** HTTP `503 Service Unavailable` response.
- **Causes:**
  - The `mcp-adapter` plugin dependency has not been activated or is disabled.
  - The database is temporarily locked or Redis Cache is unreachable.
- **Remedies:**
  - Check whether `mcp-adapter` is activated on the Plugins page.
  - Check MySQL and Redis connection status.

---

## 2. Systematic Diagnosis (Diagnostic Procedure)

When connection issues occur between an AI Agent and WordPress, check the following steps:

```
[Step 1: Check network connectivity & HTTPS]
   └─► curl -I -X POST https://your-site.com/wp-json/xnetvn-agent-hub-wp/mcp
   └─► Must receive 401 or 400 (proving the route exists and the web server forwards correctly).
          │
          ▼
[Step 2: Check the Authorization header]
   └─► Log in to Admin -> Agent Hub -> Overview.
   └─► Run the automated check to see whether the web server receives the Authorization header.
          │
          ▼
[Step 3: Check Audit Logs]
   └─► Open Agent Hub -> Audit Logs.
   └─► View recent requests, check the "Outcome" column and the Denied reason.
          │
          ▼
[Step 4: Check WordPress Error Logs (debug.log)]
   └─► Check wp-content/debug.log for PHP exceptions (if any).
```

---

## 3. Reporting Technical Bugs
If the error originates in the plugin source code:
1. Read the bug reporting guide at [templates/bug_report.md](templates/bug_report.md).
2. Collect information: PHP version, WordPress version, Web Server configuration, error code and stack trace (with sensitive information hidden).
3. Create an issue in the project's GitHub repository or contact the support team listed in [SUPPORT.md](SUPPORT.md).
