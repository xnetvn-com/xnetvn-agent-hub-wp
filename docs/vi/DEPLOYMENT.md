# Hướng Dẫn Triển Khai & Vận Hành (Deployment Guide)

> **Tài liệu hướng dẫn quy trình đóng gói, triển khai, cấu hình hạ tầng và phát hành xNetVN Agent Hub WP.**

---

## 1. Quy Trình Đóng Gói Sản Phẩm (Packaging Workflow)

Để tạo bản phát hành sẵn sàng triển khai trên môi trường Production, thực hiện các bước sau:

### 1.1 Kiểm Tra Chất Lượng Mã Nguồn
```bash
# Đảm bảo 100% kiểm tra thành công
composer check
```

### 1.2 Biên Dịch & Đóng Gói Bản ZIP
```bash
# Thực hiện kiểm tra đóng gói và xuất file ZIP chuẩn
composer build
```
Script `bin/build-zip.sh` sẽ thực hiện:
1. Tạo thư mục tạm và sao chép mã nguồn sạch.
2. Chạy `composer install --no-dev --optimize-autoloader` để chỉ đóng gói thư viện runtime.
3. Loại bỏ toàn bộ tệp phát triển: `.git`, `tests/`, `infra/`, `.github/`, cấu hình linter, v.v.
4. Đóng gói thành tệp `xnetvn-agent-hub-wp-v[VERSION].zip`.
5. Chạy `bin/verify-zip.sh` để kiểm tra tính toàn vẹn và dung lượng tệp.

---

## 2. Triển Khai Trên Máy Chủ Đơn Lẻ (Single Server Deployment)

### 2.1 Cấu Hình Nginx Reverse Proxy Chuẩn
Khi triển khai đằng sau Nginx, cần cấu hình để đảm bảo các tiêu đề xác thực và HTTPS được chuyển tiếp chính xác đến WordPress:

```nginx
server {
    listen 443 ssl http2;
    server_name your-wordpress-site.com;

    ssl_certificate /etc/ssl/certs/your-cert.pem;
    ssl_certificate_key /etc/ssl/private/your-key.pem;

    root /var/www/html;
    index index.php;

    # Chuyển tiếp tiêu đề xác thực Authorization Bearer
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

### 2.2 Cấu Hình Apache (.htaccess)
Nếu sử dụng Apache, đảm bảo module `mod_rewrite` và `mod_headers` đã được kích hoạt. Thêm đoạn cấu hình sau vào `.htaccess` nếu Authorization header bị Apache lược bỏ:

```apache
<IfModule mod_rewrite.c>
RewriteEngine On
RewriteCond %{HTTP:Authorization} ^(.*)
RewriteRule ^ - [E=HTTP_AUTHORIZATION:%1]
</IfModule>
```

---

## 3. Triển Khai Trên Cụm Đa Máy Chủ & Đám Mây (High Availability & Cloud)

### 3.1 Cơ Sở Dữ Liệu Phân Tán & Bảng Khóa (Database Clustering)
- **Cơ chế Khóa:** Plugin sử dụng hàm khóa có tên của MySQL (`GET_LOCK()`) thông qua lớp `WpOwnerLock` và giao dịch `SELECT ... FOR UPDATE` của InnoDB để ngăn chặn xung đột khi tạo hoặc xoay vòng khóa API.
- **Yêu cầu đối với Cụm DB (Primary-Replica):** Các thao tác ghi khóa và kiểm toán bắt buộc phải định tuyến trực tiếp đến nút **Primary (Master)** để đảm bảo tính nhất quán (Read-your-writes consistency). Không hỗ trợ cụm đa nút ghi chủ động (Multi-Master Active-Active / NDB) nếu không đảm bảo tính năng khóa phiên kết nối.

### 3.2 Bộ Nhớ Cache Phân Tán (Redis Persistent Object Cache)
- Hệ thống Giới Hạn Tần Suất (`SlidingWindowLimiter`) và bộ đếm yêu cầu hoạt động tối ưu nhất khi website sử dụng **Redis Object Cache**.
- Khi có Redis, các phép tăng bộ đếm (`INCR`) được thực thi nguyên tử (atomic), loại bỏ rủi ro tranh chấp tài nguyên (race condition) giữa nhiều tiến trình PHP-FPM workers.
- **Khuyến nghị cấu hình Redis:**
  - `maxmemory-policy: noeviction` (đối với cơ sở dữ liệu lưu trữ cache giới hạn tần suất).
  - Sử dụng tiền tố cache (`WP_CACHE_KEY_SALT`) duy nhất cho từng site.

### 3.3 Cấu Hình Reverse Proxy / CDN Tin Cậy (Cloudflare, AWS CloudFront)
Khi website đặt sau CDN hoặc Proxy trung gian:
1. Vào trang quản trị WordPress: **Agent Hub → Cài đặt**.
2. Thêm danh sách IP của CDN/Proxy vào mục **"Dải IP Proxy Tin Cậy" (Trusted Proxy CIDRs)**.
3. Chọn tiêu đề chứa IP gốc (ví dụ: `HTTP_CF_CONNECTING_IP` cho Cloudflare hoặc `HTTP_X_FORWARDED_FOR`).
4. Plugin sẽ chỉ chấp nhận xác thực HTTPS và IP thực khi yêu cầu đến từ dải IP proxy được cho phép.

---

## 4. Kế Hoạch Sao Lưu & Khôi Phục (Backup & Rollback)

### 4.1 Dữ Liệu Cần Sao Lưu
- Bảng `wp_xnetvn_agent_hub_wp_keys`: Chứa danh sách khóa API của người dùng.
- Bảng `wp_xnetvn_agent_hub_wp_audit`: Chứa nhật ký kiểm toán.
- Option `xnetvn_agent_hub_wp_pepper`: Chứa khóa Pepper bí mật của site. **Mất khóa Pepper này sẽ khiến toàn bộ các khóa API hiện có không thể giải mã/xác thực được!**
- Option `xnetvn_agent_hub_wp_settings`: Cấu hình toàn site.

### 4.2 Kế Hoạch Rollback
Nếu bản cập nhật phát sinh lỗi bất ngờ:
1. Đặt website vào chế độ bảo trì tạm thời: `wp maintenance-mode activate`.
2. Khôi phục thư mục plugin về phiên bản trước:
   ```bash
   rm -rf wp-content/plugins/xnetvn-agent-hub-wp
   cp -r /backup/xnetvn-agent-hub-wp-previous wp-content/plugins/xnetvn-agent-hub-wp
   ```
3. Xóa cache đối tượng: `wp cache flush`.
4. Hủy chế độ bảo trì: `wp maintenance-mode deactivate`.
