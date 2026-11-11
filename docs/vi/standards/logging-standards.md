# Tiêu Chuẩn Ghi Nhật Ký & Kiểm Toán (Logging Standards)

> **Quy chuẩn ghi nhật ký kiểm toán, quản lý thông tin nhạy cảm và bảo vệ quyền riêng tư dữ liệu trong xNetVN Agent Hub WP.**

---

## 1. Nguyên Tắc An Toàn Dữ Liệu Trong Nhật Ký (Zero-Leakage Policy)

- **Cấm Tuyệt Đối Ghi Secrets:**
  - Không bao giờ ghi vào log hoặc audit bảng các chuỗi token bí mật thô (`xnv_..._secret`), chuỗi băm HMAC, khóa Pepper, mật khẩu người dùng hoặc application passwords.
- **Không Ghi Giá Trị Tham Số Yêu Cầu (No Argument Values):**
  - Nhật ký kiểm toán chỉ ghi nhận **Tên của các tham số cấp cao nhất** (Top-level argument names) trong trường `arg_names` (ví dụ: `["post_id", "status"]`).
  - Tuyệt đối không ghi giá trị tham số (ví dụ: nội dung bài viết, email, tiêu đề bài viết) để phòng tránh rò rỉ dữ liệu cá nhân (PII) hoặc dữ liệu nhạy cảm của khách hàng.
- **Che Dấu Địa Chỉ IP (IP Masking):**
  - Để tuân thủ GDPR, hệ thống hỗ trợ cơ chế che dấu địa chỉ IP người dùng:
    - IPv4: Mask bit cuối (ví dụ: `192.168.1.123` -> `192.168.1.0/24`).
    - IPv6: Mask phần host (ví dụ: `2001:db8:abcd:0012::1` -> `2001:db8:abcd::/48`).

---

## 2. Cấu Trúc Bản Ghi Kiểm Toán (`wp_xnetvn_agent_hub_audit`)

Mỗi bản ghi kiểm toán được tạo ra phải chứa đầy đủ các trường thông tin có cấu trúc chuẩn sau:

| Tên Trường | Kiểu Dữ Liệu | Mô Tả Ý Nghĩa |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Khóa chính tự tăng của bản ghi. |
| `request_id` | `VARCHAR(36)` | UUID v4 định danh duy nhất cho mỗi yêu cầu HTTP MCP, hỗ trợ truy vết xuyên suốt (Distributed Tracing). |
| `user_id` | `BIGINT UNSIGNED` | ID người dùng WordPress sở hữu khóa thực hiện hành động. |
| `key_id` | `VARCHAR(12)` | Định danh công khai 12 ký tự của khóa API (hoặc `null` nếu xác thực thất bại trước khi phân tích được key). |
| `ability_name`| `VARCHAR(128)` | Tên định danh của Ability được gọi (ví dụ: `xnetvn/get-site-info`). |
| `tier` | `VARCHAR(20)` | Cấp độ rủi ro của Ability (`read`, `write`, `destructive`, `admin`). |
| `outcome` | `VARCHAR(20)` | Kết quả thực thi (`success`, `denied`, `error`). |
| `ip_address` | `VARCHAR(45)` | Địa chỉ IP của client (đã qua kiểm duyệt proxy tin cậy và che dấu nếu bật cấu hình). |
| `user_agent` | `VARCHAR(255)` | Chuỗi User-Agent của client (được cắt gọn tối đa 255 ký tự để chống DoS). |
| `arg_names` | `TEXT` | Danh sách JSON chứa tên các tham số cấp cao nhất được truyền vào. |
| `execution_time_ms` | `INT UNSIGNED` | Thời gian thực thi tính bằng mili-giây phục vụ giám sát hiệu năng. |
| `created_at` | `DATETIME UTC` | Dấu thời gian theo giờ chuẩn quốc tế UTC. |

---

## 3. Cơ Chế Ghi Bất Đồng Bộ (Buffered Asynchronous Writes)

- Để không làm suy giảm thời gian phản hồi (latency) của AI Agent, các sự kiện kiểm toán được lưu tạm thời trong bộ nhớ đệm nội bộ của tiến trình PHP (`RequestScope`).
- Thao tác ghi hàng loạt (`INSERT`) vào bảng cơ sở dữ liệu chỉ được thực thi một lần duy nhất tại sự kiện kết thúc vòng đời của WordPress (`shutdown` hook).
- Nếu cơ sở dữ liệu gặp lỗi hoặc quá tải, hệ thống ghi log nhẹ nhàng ra file lỗi PHP (`error_log`) mà không làm sập tiến trình phản hồi cho client.

---

## 4. Chính Sách Lưu Trữ & Hủy Bỏ (Retention Policy)

- Mặc định, nhật ký kiểm toán được lưu trữ trong vòng **90 ngày**.
- Một cron job định kỳ hàng ngày (`xnetvn_agent_hub_wp_retention_purge`) sẽ xóa các bản ghi cũ hơn số ngày quy định theo từng lô nhỏ (bounded batches, tối đa 500 bản ghi/lô) để tránh khóa bảng cơ sở dữ liệu lâu.
