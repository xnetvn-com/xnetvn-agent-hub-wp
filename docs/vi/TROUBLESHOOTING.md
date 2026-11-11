# Hướng Dẫn Xử Lý Sự Cố (Troubleshooting Guide)

> **Cẩm nang chẩn đoán, tra cứu mã lỗi và xử lý các sự cố thường gặp khi vận hành xNetVN Agent Hub WP.**

---

## 1. Các Mã Lỗi Thường Gặp & Cách Khắc Phục

### 1.1 Lỗi 401 Unauthorized (Xác thực không thành công)
- **Triệu chứng:** Client nhận phản hồi HTTP `401 Unauthorized` kèm thông báo chung `Invalid or missing credentials`.
- **Nguyên nhân tiềm ẩn:**
  1. Header `Authorization` bị thiếu hoặc không đúng định dạng `Bearer xnv_<key_id>_<secret>`.
  2. Web server (Nginx/Apache) tự ý lọc bỏ tiêu đề `Authorization` trước khi chuyển tiếp tới PHP-FPM.
  3. Khóa API đã hết hạn (`expires_at`), bị thu hồi (`revoked_at`), hoặc người dùng sở hữu khóa đã bị xóa.
  4. Khóa bí mật Pepper trên website bị thay đổi hoặc mất.
- **Biện pháp xử lý:**
  - Kiểm tra cấu hình Nginx/Apache xem đã cho phép chuyển tiếp `Authorization` header chưa (xem [DEPLOYMENT.md](DEPLOYMENT.md)).
  - Đăng nhập vào trang quản trị WordPress, kiểm tra trạng thái khóa tại mục **Hồ sơ** hoặc **Agent Hub → API Keys**.
  - Thử tạo một khóa API mới và kết nối lại.

### 1.2 Lỗi 403 Forbidden (Bị từ chối truy cập)
- **Triệu chứng:** Client nhận phản hồi HTTP `403 Forbidden`.
- **Nguyên nhân tiềm ẩn:**
  1. **Lỗi TLS/HTTPS:** Yêu cầu được gửi qua kết nối HTTP không an toàn.
  2. **Lỗi Origin:** Header `Origin` gửi từ trình duyệt không nằm trong danh sách trắng `allowed_origins` hoặc gửi `null`.
  3. **Lỗi Quyền hạn (Capability):** Tài khoản sở hữu khóa không có quyền WordPress tương ứng (ví dụ: tài khoản chỉ có quyền `subscriber` nhưng gọi công cụ tạo bài viết yêu cầu `edit_posts`).
  4. **Lỗi Cấp độ Rủi ro (Risk Tier):** Công cụ yêu cầu quyền `destructive` hoặc `admin` nhưng khóa API hoặc trần của site (`site_ceiling`) chỉ cho phép mức `read` hoặc `write`.
  5. **Lỗi Giới hạn IP:** Yêu cầu đến từ địa chỉ IP không nằm trong dải CIDR được chỉ định cho khóa API.
- **Biện pháp xử lý:**
  - Đảm bảo client gửi yêu cầu qua giao thức HTTPS.
  - Kiểm tra quyền hạn của tài khoản WordPress sở hữu khóa.
  - Kiểm tra cấu hình trần rủi ro của site trong **Agent Hub → Cài đặt**.

### 1.3 Lỗi 429 Too Many Requests (Vượt quá giới hạn tần suất)
- **Triệu chứng:** Client nhận phản hồi HTTP `429 Too Many Requests` kèm tiêu đề `Retry-After`.
- **Nguyên nhân:**
  - Khóa API gửi quá 120 yêu cầu trong vòng 60 giây.
  - Địa chỉ IP gửi quá 10 yêu cầu xác thực thất bại liên tiếp trong 60 giây.
- **Biện pháp xử lý:**
  - Cấu hình AI Agent giảm tần suất gửi yêu cầu hoặc áp dụng thuật toán Exponential Backoff theo tiêu đề `Retry-After`.
  - Nếu cần tăng hạn mức cho hệ thống doanh nghiệp, quản trị viên có thể điều chỉnh trong **Agent Hub → Cài đặt**.

### 1.4 Lỗi 503 Service Unavailable (Dịch vụ không khả dụng)
- **Triệu chứng:** Phản hồi HTTP `503 Service Unavailable`.
- **Nguyên nhân:**
  - Plugin phụ thuộc `mcp-adapter` chưa được kích hoạt hoặc bị vô hiệu hóa.
  - Cơ sở dữ liệu tạm thời bị khóa hoặc không kết nối được tới Redis Cache.
- **Biện pháp xử lý:**
  - Kiểm tra xem plugin `mcp-adapter` đã được kích hoạt trong trang Plugins chưa.
  - Kiểm tra trạng thái kết nối MySQL và Redis.

---

## 2. Quy Trình Chẩn Đoán Có Hệ Thống (Diagnostic Procedure)

Khi gặp sự cố kết nối giữa AI Agent và WordPress, hãy thực hiện kiểm tra theo các bước sau:

```
[Bước 1: Kiểm tra kết nối mạng & HTTPS]
   └─► curl -I -X POST https://your-site.com/wp-json/xnetvn-agent-hub-wp/mcp
   └─► Phải nhận về 401 hoặc 400 (chứng minh route tồn tại và web server chuyển tiếp đúng).
          │
          ▼
[Bước 2: Kiểm tra tiêu đề Authorization]
   └─► Đăng nhập Admin -> Agent Hub -> Tổng quan (Overview).
   └─► Chạy công cụ kiểm tra tự động xem web server có nhận được Authorization header không.
          │
          ▼
[Bước 3: Kiểm tra Nhật ký Kiểm toán (Audit Logs)]
   └─► Truy cập menu Agent Hub -> Nhật ký (Audit Logs).
   └─► Xem các yêu cầu gần nhất, kiểm tra cột "Kết quả" (Outcome) và lý do từ chối (Denied reason).
          │
          ▼
[Bước 4: Kiểm tra Nhật ký Lỗi WordPress (debug.log)]
   └─► Kiểm tra tệp wp-content/debug.log để phát hiện các ngoại lệ PHP (nếu có).
```

---

## 3. Cách Báo Cáo Lỗi Kỹ Thuật
Nếu xác định lỗi phát sinh từ mã nguồn của plugin:
1. Đọc hướng dẫn báo lỗi tại [templates/bug_report.md](templates/bug_report.md).
2. Thu thập thông tin: Phiên bản PHP, phiên bản WordPress, cấu hình Web Server, mã lỗi và stack trace (đã ẩn thông tin nhạy cảm).
3. Tạo issue trên kho lưu trữ GitHub của dự án hoặc liên hệ đội ngũ hỗ trợ tại [SUPPORT.md](SUPPORT.md).
