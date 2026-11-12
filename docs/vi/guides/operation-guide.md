# Hướng Dẫn Vận Hành Hệ Thống (Operation Guide)

> **Cẩm nang dành cho Quản trị viên và Kỹ sư Vận hành (SRE / DevOps) giám sát và quản lý xNetVN Agent Hub WP.**

---

## 1. Công Việc Định Kỳ Của Quản Trị Viên

### 1.1 Quản Lý & Giám Sát Khóa API
- Định kỳ hàng tuần, truy cập **Agent Hub → API Keys**:
  - Rà soát danh sách các khóa API đang hoạt động (`active`).
  - Thu hồi các khóa không còn sử dụng hoặc thuộc về nhân sự đã luân chuyển công tác.
  - Kiểm tra cột **Lần cuối sử dụng (Last Used)** để phát hiện các khóa bất thường.
  - Nhắc nhở người dùng thực hiện xoay vòng khóa (Rotate) đối với các khóa sắp hết hạn (hệ thống tự động gửi email thông báo trước 7 ngày).

### 1.2 Giám Sát Nhật Ký Kiểm Toán (Audit Trail)
- Truy cập **Agent Hub → Nhật ký (Audit Logs)**:
  - Lọc theo kết quả `denied` để phát hiện các nỗ lực xâm nhập hoặc token sai.
  - Kiểm tra tần suất yêu cầu từ các IP lạ.
  - Đảm bảo tiến trình dọn dẹp nhật ký (`Retention`) hoạt động đều đặn để dung lượng bảng không vượt quá ngưỡng cho phép.

---

## 2. Quản Trị Bằng Dòng Lệnh (WP-CLI Commands)

Hệ thống hỗ trợ các tác vụ quản trị nhanh qua WP-CLI trên máy chủ:

```bash
# Kiểm tra trạng thái hoạt động của plugin
wp plugin status xnetvn-agent-hub-wp

# Liệt kê danh sách các khóa API của một user cụ thể
wp eval 'var_dump( (new \XNetVN\AgentHubWP\Persistence\WpdbKeyRepository($GLOBALS["wpdb"], new \XNetVN\AgentHubWP\Support\SystemClock()))->list_for_user(1) );'

# Kích hoạt chạy thủ công tiến trình dọn dẹp kiểm toán
wp cron event run xnetvn_agent_hub_wp_purge_audit

# Xóa cache đối tượng WordPress sau khi thay đổi cấu hình
wp cache flush
```

---

## 3. Quản Trị Đổi Mới Pepper Khi Xảy Ra Sự Cố Rò Rỉ

Nếu phát hiện cơ sở dữ liệu bị lộ lọt và cần vô hiệu hóa khẩn cấp toàn bộ các khóa API trên hệ sinh thái:
1. Truy cập vào máy chủ hoặc cơ sở dữ liệu MySQL.
2. Xóa hoặc ghi đè option Pepper:
   ```bash
   wp option delete xnetvn_agent_hub_wp_pepper
   ```
3. Khởi động lại hoặc kích hoạt lại plugin. Plugin sẽ tự động sinh một mã Pepper ngẫu nhiên 32-byte hoàn toàn mới.
4. **Kết quả:** Toàn bộ các khóa API hiện tại sẽ lập tức trở nên vô hiệu (khi đối chiếu HMAC sẽ không khớp), bảo vệ an toàn cho website ngay lập tức.
5. Thông báo cho người dùng tạo khóa API mới để tiếp tục sử dụng.
