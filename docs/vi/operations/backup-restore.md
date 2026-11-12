# Quy Trình Sao Lưu & Phục Hồi Dữ Liệu (Backup & Restore Runbook)

> **Hướng dẫn sao lưu định kỳ, bảo vệ dữ liệu bí mật và phục hồi toàn vẹn hệ thống xNetVN Agent Hub WP.**

---

## 1. Thành Phần Dữ Liệu Cần Sao Lưu

Để có thể khôi phục 100% hoạt động của plugin xNetVN Agent Hub WP mà không làm mất khóa của người dùng:
1. **Bảng Cơ Sở Dữ Liệu:**
   - `{$wpdb->prefix}xnetvn_agent_hub_wp_keys`: Chứa thông tin khóa API và mã băm.
   - `{$wpdb->prefix}xnetvn_agent_hub_wp_audit`: Chứa nhật ký kiểm toán.
2. **Các Bản Ghi Tùy Chọn trong bảng `wp_options`:**
   - `xnetvn_agent_hub_wp_pepper`: **Tối quan trọng.** Nếu mất giá trị này, toàn bộ các khóa API hiện tại sẽ không thể xác thực được.
   - `xnetvn_agent_hub_wp_settings`: Cấu hình toàn site (trần rủi ro, dải IP proxy, origins).
   - `xnetvn_agent_hub_wp_db_version`: Phiên bản cấu trúc bảng cơ sở dữ liệu hiện tại.

---

## 2. Kịch Bản Sao Lưu Tự Động Bằng Dòng Lệnh (Automated Backup Script)

Tạo tệp script chạy định kỳ qua Cron hàng ngày trên máy chủ:

```bash
#!/usr/bin/env bash
set -euo pipefail

BACKUP_DIR="/var/backups/xnetvn-agent-hub/$(date +%Y-%m-%d)"
mkdir -p "$BACKUP_DIR"

DB_USER="wp_db_user"
DB_PASS="your_db_password"
DB_NAME="wordpress_db"
WP_PREFIX="wp_"

echo "Bắt đầu sao lưu các bảng xNetVN Agent Hub..."

# 1. Sao lưu các bảng dữ liệu chuyên biệt
mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  "${WP_PREFIX}xnetvn_agent_hub_wp_keys" \
  "${WP_PREFIX}xnetvn_agent_hub_wp_audit" \
  > "$BACKUP_DIR/tables_backup.sql"

# 2. Sao lưu các tùy chọn options quan trọng (bao gồm pepper)
mysqldump -u "$DB_USER" -p"$DB_PASS" "$DB_NAME" \
  "${WP_PREFIX}options" \
  --where="option_name LIKE 'xnetvn_agent_hub_wp_%'" \
  > "$BACKUP_DIR/options_backup.sql"

# 3. Nén tệp sao lưu và phân quyền an toàn
tar -czf "$BACKUP_DIR.tar.gz" -C "/var/backups/xnetvn-agent-hub" "$(date +%Y-%m-%d)"
rm -rf "$BACKUP_DIR"
chmod 600 "$BACKUP_DIR.tar.gz"

echo "Sao lưu hoàn thành: $BACKUP_DIR.tar.gz"
```

---

## 3. Quy Trình Phục Hồi Dữ Liệu (Restore Workflow)

Khi cần khôi phục lại dữ liệu sau sự cố hỏng hóc hoặc chuyển đổi máy chủ:

1. Giải nén tệp sao lưu:
   ```bash
   tar -xzf /var/backups/xnetvn-agent-hub/2026-10-06.tar.gz
   cd 2026-10-06
   ```
2. Nạp lại cấu trúc và dữ liệu vào cơ sở dữ liệu MySQL:
   ```bash
   mysql -u wp_db_user -p wordpress_db < tables_backup.sql
   mysql -u wp_db_user -p wordpress_db < options_backup.sql
   ```
3. Xóa cache đối tượng của WordPress để nhận diện lại options mới nạp:
   ```bash
   wp cache flush
   ```
4. Kiểm tra lại kết nối bằng lệnh kiểm tra mẫu SOP-02 trong [serviceA-runbook.md](runbooks/serviceA-runbook.md).
