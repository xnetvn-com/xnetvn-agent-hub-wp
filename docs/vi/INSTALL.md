# Hướng Dẫn Cài Đặt & Cấu Hình Hệ Thống

> **Tài liệu cài đặt chính thức cho xNetVN Agent Hub WP (`xnetvn-agent-hub-wp`).**

---

## 1. Yêu Cầu Tiên Quyết (Prerequisites)

### 1.1 Yêu Cầu Phần Mềm
- **PHP:** Phiên bản tối thiểu **PHP 8.2** (Khuyến nghị PHP 8.3 trở lên).
  - Yêu cầu các extension PHP bắt buộc: `mysqli`, `json`, `hash`, `pcre`, `openssl`, `mbstring`.
  - Bộ kiểm tra môi trường chạy ban đầu (`Bootstrap`) hỗ trợ từ cú pháp PHP 7.2 trở lên để đưa ra thông báo lỗi rõ ràng nếu môi trường hosting chưa đạt PHP 8.2.
- **WordPress:** Phiên bản tối thiểu **WordPress 6.9** (Tương thích và đã kiểm thử trên WordPress 7.1.2).
- **Cơ Sở Dữ Liệu:**
  - MySQL 8.0 trở lên hoặc MariaDB 10.6 trở lên.
  - Bộ máy lưu trữ **InnoDB** là bắt buộc để hỗ trợ giao dịch khóa mức hàng nguyên tử (Atomic row-level locking cho tác vụ xoay vòng khóa API).
- **Web Server:**
  - Nginx, Apache hoặc LiteSpeed với hỗ trợ **HTTPS/TLS**.
  - MCP yêu cầu kết nối an toàn HTTPS (ngoại trừ môi trường dev khi định nghĩa hằng số kiểm thử).
- **Plugin phụ thuộc:**
  - Plugin **`mcp-adapter`** (WordPress Official MCP Adapter) phải được cài đặt và kích hoạt trước hoặc đồng thời.

---

## 2. Các Phương Thức Cài Đặt

### Phương thức 1: Cài đặt từ Tệp Gói Phát Hành (.zip)

1. Tải tệp bản phát hành `xnetvn-agent-hub-wp-1.0.0.zip` từ trang Releases chính thức.
2. Đăng nhập vào trang quản trị WordPress (`wp-admin`).
3. Truy cập **Plugins → Add New Plugin → Upload Plugin**.
4. Chọn tệp `.zip` vừa tải về và nhấn **Install Now**.
5. Nhấn **Activate Plugin**.

### Phương thức 2: Cài đặt qua Git & Composer (Dành cho Lập trình viên)

1. Truy cập thư mục plugins trên máy chủ hoặc môi trường phát triển:
   ```bash
   cd /path/to/wordpress/wp-content/plugins/
   ```
2. Clone kho lưu trữ dự án:
   ```bash
   git clone https://github.com/xnetvn-com/xnetvn-agent-hub-wp.git xnetvn-agent-hub-wp
   cd xnetvn-agent-hub-wp
   ```
3. Cài đặt các gói phụ thuộc runtime qua Composer:
   ```bash
   composer install --no-dev --optimize-autoloader
   ```
4. Kích hoạt plugin qua WP-CLI:
   ```bash
   wp plugin activate xnetvn-agent-hub-wp
   ```

### Phương thức 3: Triển khai với Docker / WP-Env

Dự án hỗ trợ môi trường kiểm thử cô lập thông qua `docker compose` và `@wordpress/env`:
```bash
# Khởi động môi trường kiểm thử
npm run env:start

# Hoặc dùng script của Remote Test Lab
python3 -I -B "$HOME/.agents/skills/remote-test-lab/scripts/rtl.py" up
```

---

## 3. Quá Trình Khởi Tạo & Migration Cơ Sở Dữ Liệu

Khi plugin được kích hoạt, thành phần `MigrationProvider` sẽ tự động kiểm tra phiên bản schema và khởi tạo 2 bảng dữ liệu riêng biệt:
1. `wp_xnetvn_agent_hub_keys`: Quản lý danh sách khóa API, băm HMAC, thời hạn và phạm vi quyền.
2. `wp_xnetvn_agent_hub_audit`: Lưu trữ nhật ký kiểm toán yêu cầu MCP.
3. Sinh khóa bí mật Pepper (32 bytes ngẫu nhiên) và lưu tại option `xnetvn_agent_hub_wp_pepper` với cấu hình không autoload (`autoload = 'no'`).

Nếu triển khai trên WordPress Multisite, schema sẽ tự động được tạo riêng biệt cho từng site khi site đó kích hoạt plugin hoặc khi có yêu cầu đầu tiên đến site đó.

---

## 4. Cấu Hình Sau Cài Đặt

1. **Kiểm tra HTTPS:** Đảm bảo chứng chỉ SSL/TLS hoạt động bình thường trên tên miền website.
2. **Cấu hình Reverse Proxy / Load Balancer (Nếu có):**
   - Nếu website nằm sau Cloudflare, AWS ALB hoặc Nginx Reverse Proxy, hãy cấu hình các dải IP proxy tin cậy (`trusted_proxy_cidrs`) và tiêu đề proxy (`trusted_proxy_headers`) trong menu **Agent Hub → Cài đặt** để plugin nhận diện đúng giao thức HTTPS và địa chỉ IP thực tế của client.
3. **Cấu hình Nguồn Gốc (Origin):**
   - Mặc định, plugin cho phép các client phi trình duyệt (không gửi header `Origin`).
   - Nếu kết nối qua Web Browser hoặc Web-based AI Tools, hãy thêm URL nguồn gốc vào danh sách tin cậy tại cài đặt.

---

## 5. Gỡ Bỏ Plugin (Uninstallation)

Khi xóa plugin qua giao diện WordPress hoặc lệnh `wp plugin delete`:
- Mặc định, quy trình `uninstall.php` sẽ xóa toàn bộ các bảng cơ sở dữ liệu `*_xnetvn_agent_hub_keys`, `*_xnetvn_agent_hub_audit`, khóa Pepper và các options liên quan.
- Nếu muốn giữ lại dữ liệu khi gỡ bỏ (để nâng cấp hoặc cài đặt lại sau này), hãy bật tùy chọn **"Bảo lưu dữ liệu khi gỡ bỏ" (Preserve data on uninstall)** trong phần Cài đặt trước khi tiến hành xóa plugin.
