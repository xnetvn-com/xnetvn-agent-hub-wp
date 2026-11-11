# Quy Trình Phản Hồi Sự Cố Vận Hành (Incident Response Runbook)

> **Hướng dẫn hành động từng bước khi xảy ra sự cố gián đoạn dịch vụ, tấn công mạng hoặc lỗi xác thực trong xNetVN Agent Hub WP.**

---

## 1. Kịch Bản 1: Khóa API Bị Nghi Ngờ Bị Lộ Lọt (Compromised Key)

### Dấu hiệu nhận biết:
- Xuất hiện các yêu cầu MCP từ địa chỉ IP lạ hoặc quốc gia bất thường trong bảng Audit.
- Tần suất gọi các công cụ sửa đổi/xóa dữ liệu tăng vọt bất thường.

### Các bước xử lý ngay lập tức:
1. **Thu hồi khóa khẩn cấp:**
   - Đăng nhập vào trang quản trị WordPress: **Agent Hub → API Keys**.
   - Tìm kiếm theo `key_id` bị nghi ngờ và bấm nút **Thu hồi (Revoke)**.
   - Thao tác thu hồi có hiệu lực ngay lập tức, không có thời gian trễ.
2. **Kiểm tra nhật ký kiểm toán:**
   - Lọc bảng audit theo `key_id` đó để lập danh sách toàn bộ các hành động Agent đã thực hiện trong thời gian qua.
   - Kiểm tra các bài viết, dữ liệu đã bị sửa đổi để tiến hành khôi phục từ bản sửa đổi (Revisions) hoặc sao lưu.
3. **Thông báo chủ sở hữu tài khoản:**
   - Gửi cảnh báo bảo mật cho người dùng sở hữu khóa và yêu cầu đổi mật khẩu tài khoản WordPress nếu cần thiết.

---

## 2. Kịch Bản 2: Lộ Lọt Khóa Bí Mật Pepper (Compromised Pepper)

### Dấu hiệu nhận biết:
- Toàn bộ cơ sở dữ liệu `wp_options` hoặc bản backup bị rò rỉ ra ngoài.

### Các bước xử lý ngay lập tức:
1. Đăng nhập qua SSH vào máy chủ web:
   ```bash
   cd /var/www/html
   wp option delete xnetvn_agent_hub_wp_pepper
   ```
2. Tải lại trang web hoặc thực hiện 1 request bất kỳ để plugin tự động sinh mã Pepper mới.
3. Khi Pepper thay đổi, 100% các khóa API hiện tại sẽ không còn khớp mã băm HMAC nữa và bị vô hiệu hóa tức thì.
4. Đăng thông báo nội bộ yêu cầu tất cả người dùng tạo khóa API mới.

---

## 3. Kịch Bản 3: Tấn Công Từ Chối Dịch Vụ (DDoS / Brute-force Attack)

### Dấu hiệu nhận biết:
- Hàng ngàn yêu cầu liên tục gửi đến `/wp-json/xnetvn-agent-hub-wp/mcp` làm CPU máy chủ đạt 100%.

### Các bước xử lý:
1. Bật chế độ phòng thủ "Under Attack Mode" trên Cloudflare hoặc WAF.
2. Áp dụng quy tắc chặn dải IP hoặc giới hạn tần suất tại tầng Web Server Nginx:
   ```nginx
   limit_req_zone $binary_remote_addr zone=mcp_limit:10m rate=10r/s;
   location ~ ^/wp-json/xnetvn-agent-hub-wp/mcp {
       limit_req zone=mcp_limit burst=20 nodelay;
       try_files $uri $uri/ /index.php?$args;
   }
   ```
3. Khởi động lại dịch vụ PHP-FPM: `sudo systemctl restart php8.2-fpm`.
