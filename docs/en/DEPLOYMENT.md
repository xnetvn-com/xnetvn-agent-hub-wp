# Deployment & Operations Guide (Deployment Guide)

> **Guide to packaging, deployment, infrastructure configuration and releases of xNetVN Agent Hub WP.**

---

## 1. Product Packaging Workflow

To create a release ready for deployment to Production, follow these steps:

### 1.1 Source Code Quality Checks
```bash
# Ensure 100% of checks succeed
composer check
```

### 1.2 Build & Package the ZIP Release
```bash
# Run packaging checks and produce a standard ZIP file
composer build
```
The `bin/build-zip.sh` script will:
1. Create a temporary directory and copy clean source code.
2. Run `composer install --no-dev --optimize-autoloader` to package only runtime libraries.
3. Remove all development files: `.git`, `tests/`, `infra/`, `.github/`, linter configuration, etc.
4. Package the `xnetvn-agent-hub-wp-v[VERSION].zip` file.
5. Run `bin/verify-zip.sh` to check file integrity and size.

---

## 2. Single Server Deployment

### 2.1 Standard Nginx Reverse Proxy Configuration
When deploying behind Nginx, configure authentication and HTTPS headers to be forwarded correctly to WordPress:

```nginx
server {
    listen 443 ssl http2;
    server_name your-wordpress-site.com;

    ssl_certificate /etc/ssl/certs/your-cert.pem;
    ssl_certificate_key /etc/ssl/private/your-key.pem;

    root /var/www/html;
    index index.php;

    # Forward the Authorization Bearer authentication header
    proxy_set_header Authorization $http_authorization;
    proxy_pass_header Authorization;
    fastcgi_pass_header Authorization;

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
    }
}
```

### 2.2 Apache Configuration (.htaccess)
When using Apache, ensure `mod_rewrite` and `mod_headers` are enabled. Add the following configuration to `.htaccess` if Apache strips the Authorization header:

```apache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%1]
</IfModule>
```

---

## 3. Multi-Server Cluster & Cloud Deployment (High Availability & Cloud)

### 3.1 Distributed Databases & Key Tables (Database Clustering)
- **Locking mechanism:** The plugin uses MySQL named locks (`GET_LOCK()`) through `WpOwnerLock` and InnoDB `SELECT ... FOR UPDATE` transactions to prevent conflicts when creating or rotating API keys.
- **DB cluster requirements (Primary-Replica):** Key and audit writes must be routed directly to the **Primary (Master)** node to ensure Read-your-writes consistency. Multi-Master Active-Active / NDB clusters are unsupported unless connection-session locking is guaranteed.

### 3.2 Distributed Cache (Redis Persistent Object Cache)
- The rate limiting system (`SlidingWindowLimiter`) and request counters work optimally when the website uses **Redis Object Cache**.
- With Redis, counter increments (`INCR`) execute atomically, eliminating race conditions between multiple PHP-FPM workers.
- **Recommended Redis configuration:**
  - `maxmemory-policy: noeviction` (for the database storing rate-limit cache).
  - Use a unique cache prefix (`WP_CACHE_KEY_SALT`) for each site.

### 3.3 Trusted Reverse Proxy / CDN Configuration (Cloudflare, AWS CloudFront)
When a website is behind a CDN or intermediary Proxy:
1. Open the WordPress administration page: **Agent Hub → Settings**.
2. Add CDN/Proxy IP addresses to **"Trusted Proxy CIDRs"**.
3. Select the header containing the original IP (for example, `HTTP_CF_CONNECTING_IP` for Cloudflare or `HTTP_X_FORWARDED_FOR`).
4. The plugin accepts HTTPS authentication and real IP information only when requests come from an allowed proxy IP range.

---

## 4. Backup & Rollback Plan

### 4.1 Data to Back Up
- `wp_xnetvn_agent_hub_keys` table: Contains the users' API key list.
- `wp_xnetvn_agent_hub_audit` table: Contains audit logs.
- `xnetvn_agent_hub_wp_pepper` option: Contains the site's secret Pepper. **Losing this Pepper makes all existing API keys impossible to decrypt/authenticate!**
- `xnetvn_agent_hub_wp_settings` option: Site-wide configuration.

### 4.2 Rollback Plan
If an update causes unexpected errors:
1. Temporarily put the website in maintenance mode: `wp maintenance-mode activate`.
2. Restore the previous plugin directory version:
   ```bash
   rm -rf wp-content/plugins/xnetvn-agent-hub-wp
   cp -r /backup/xnetvn-agent-hub-wp-previous wp-content/plugins/xnetvn-agent-hub-wp
   ```
3. Flush the object cache: `wp cache flush`.
4. Disable maintenance mode: `wp maintenance-mode deactivate`.
