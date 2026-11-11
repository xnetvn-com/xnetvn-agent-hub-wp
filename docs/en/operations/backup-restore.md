# Backup and Restore Runbook

> **A guide to periodic backups, protecting secret data, and restoring the integrity of xNetVN Agent Hub WP.**

---

## 1. Data Components to Back Up

To restore 100% of xNetVN Agent Hub WP functionality without losing users' keys:
1. **Database Tables:**
   - `{$wpdb->prefix}xnetvn_agent_hub_keys`: Contains API key information and hashes.
   - `{$wpdb->prefix}xnetvn_agent_hub_audit`: Contains audit logs.
2. **Option Records in the `wp_options` Table:**
   - `xnetvn_agent_hub_wp_pepper`: **Critical.** Losing this value prevents all existing API keys from authenticating.
   - `xnetvn_agent_hub_wp_settings`: Site-wide configuration (risk ceiling, proxy IP ranges, origins).
   - `xnetvn_agent_hub_wp_schema_version`: Current database table schema version.

---

## 2. Automated Command-Line Backup Script

Create a script to run daily through Cron on the server:

```bash
#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="/var/backups/xnetvn-agent-hub/$(date +%Y-%m-%d)"
mkdir -p "$BACKUP_DIR"

DB_USER="wp_db_user"
DB_PASS="your_db_password"
DB_NAME="wordpress_db"
WP_PREFIX="wp_"

echo "Starting xNetVN Agent Hub table backup..."

# 1. Back up dedicated data tables
mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  "${WP_PREFIX}xnetvn_agent_hub_keys" \
  "${WP_PREFIX}xnetvn_agent_hub_audit" \
  > "$BACKUP_DIR/tables_backup.sql"

# 2. Back up critical options (including the pepper)
mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  "${WP_PREFIX}options" \
  --where="option_name LIKE 'xnetvn_agent_hub_wp_%'" \
  > "$BACKUP_DIR/options_backup.sql"

# 3. Compress the backup and set safe permissions
tar -czf "$BACKUP_DIR.tar.gz" -C "/var/backups/xnetvn-agent-hub" "$(date +%Y-%m-%d)"
rm -rf "$BACKUP_DIR"
chmod 600 "$BACKUP_DIR.tar.gz"

echo "Backup complete: $BACKUP_DIR.tar.gz"
```

---

## 3. Restore Workflow

When restoring data after a failure or server migration:

1. Extract the backup file:
   ```bash
   tar -xzf /var/backups/xnetvn-agent-hub/2026-10-06.tar.gz
   cd 2026-10-06
   ```
2. Import the schema and data into the MySQL database:
   ```bash
   mysql -u wp_db_user -p wordpress_db < tables_backup.sql
   mysql -u wp_db_user -p wordpress_db < options_backup.sql
   ```
3. Clear the WordPress object cache to recognize the newly imported options:
   ```bash
   wp cache flush
   ```
4. Check connectivity again using the SOP-02 sample check in [serviceA-runbook.md](runbooks/serviceA-runbook.md).
