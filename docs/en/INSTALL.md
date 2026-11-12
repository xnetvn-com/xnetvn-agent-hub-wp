# System Installation & Configuration Guide

> **Official installation documentation for xNetVN Agent Hub WP (`xnetvn-agent-hub-wp`).**

---

## 1. Prerequisites

### 1.1 Software Requirements
- **PHP:** Minimum **PHP 8.2** (PHP 8.3 or later recommended).
  - Required PHP extensions: `mysqli`, `json`, `hash`, `pcre`, `openssl`, `mbstring`.
  - The initial runtime environment checker (`Bootstrap`) supports PHP 7.2 syntax and later to provide clear error messages when hosting does not meet PHP 8.2 requirements.
- **WordPress:** Minimum **WordPress 6.9** (compatible with and tested on WordPress 7.1.2).
- **Database:**
  - MySQL 8.0 or later, or MariaDB 10.6 or later.
  - The **InnoDB** storage engine is required for atomic row-level locking transactions (for API key rotation).
- **Web Server:**
  - Nginx, Apache or LiteSpeed with **HTTPS/TLS** support.
  - MCP requires a secure HTTPS connection (except in development when a testing constant is defined).
- **Plugin dependency:**
  - The **`mcp-adapter`** plugin (WordPress Official MCP Adapter) is required to use MCP. You can activate xNetVN Agent Hub first; it shows an admin notice and keeps MCP disabled until a supported adapter is active.

---

## 2. Installation Methods

### Method 1: Install from a Release Package (.zip)

1. Download `xnetvn-agent-hub-wp-1.0.0.zip` from the official Releases page.
2. Log in to WordPress administration (`wp-admin`).
3. Open **Plugins → Add New Plugin → Upload Plugin**.
4. Select the downloaded `.zip` file and click **Install Now**.
5. Click **Activate Plugin**.

### Method 2: Install through Git & Composer (For Developers)

1. Open the plugins directory on the server or development environment:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   ```
2. Clone the project repository:
   ```bash
   git clone https://github.com/xnetvn-com/xnetvn-agent-hub-wp.git xnetvn-agent-hub-wp
   cd xnetvn-agent-hub-wp
   ```
3. Install runtime dependencies through Composer:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
4. Activate the plugin through WP-CLI:
   ```bash
   wp plugin activate xnetvn-agent-hub-wp
   ```

### Method 3: Deploy with Docker / WP-Env

The project supports isolated testing environments through `docker compose` and `@wordpress/env`:
```bash
# Start the testing environment
npm run env:start

# Or use the Remote Test Lab script
python3 -I -B "$HOME/.agents/skills/remote-test-lab/scripts/rtl.py" up
```

---

## 3. Database Initialization & Migration

When the plugin is activated, `MigrationProvider` automatically checks the schema version and initializes 2 separate data tables:
1. `wp_xnetvn_agent_hub_wp_keys`: Manages API keys, HMAC hashes, expiry and permission scopes.
2. `wp_xnetvn_agent_hub_wp_audit`: Stores MCP request audit logs.
3. Generates a secret Pepper (32 random bytes) and stores it in `xnetvn_agent_hub_wp_pepper` without autoload (`autoload = 'no'`).

On WordPress Multisite, the schema is automatically created separately for each site when it activates the plugin or receives its first request.

---

## 4. Post-Installation Configuration

1. **Check HTTPS:** Ensure the SSL/TLS certificate works properly on the website domain.
2. **Configure Reverse Proxy / Load Balancer (if applicable):**
   - If the website is behind Cloudflare, AWS ALB or an Nginx Reverse Proxy, configure trusted proxy IP ranges (`trusted_proxy_cidrs`) and proxy headers (`trusted_proxy_headers`) under **Agent Hub → Settings** so the plugin correctly identifies HTTPS and the client's real IP address.
3. **Configure Origin:**
   - By default, the plugin allows non-browser clients (that send no `Origin` header).
   - For Web Browser or Web-based AI Tools connections, add the origin URL to the trusted list in settings.

---

## 5. Uninstallation

When deleting the plugin through the WordPress interface or `wp plugin delete`:
- By default, `uninstall.php` removes all `*_xnetvn_agent_hub_wp_keys`, `*_xnetvn_agent_hub_wp_audit` database tables, Pepper and related options.
- To retain data when uninstalling (for upgrading or later reinstallation), turn off **"Delete plugin data when uninstalled"** in Settings before deleting the plugin. That switch is on by default.
