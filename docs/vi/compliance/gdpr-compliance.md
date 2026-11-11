# Tuân Thủ Quy Định Bảo Vệ Dữ Liệu GDPR (GDPR Compliance)

> **Báo cáo và hướng dẫn tuân thủ Quy chế Bảo vệ Dữ liệu Chung của Liên minh Châu Âu (EU GDPR) cho xNetVN Agent Hub WP.**

---

## 1. Khung Tuân Thủ GDPR Trong Dự Án

Quy chế GDPR đặt ra các yêu cầu khắt khe về việc thu thập, xử lý và lưu trữ dữ liệu cá nhân (Personal Data / PII). **xNetVN Agent Hub WP** được thiết kế để hỗ trợ các tổ chức triển khai đáp ứng 100% các nguyên tắc cốt lõi theo Điều 5 và các quyền của chủ thể dữ liệu theo Chương III của GDPR.

---

## 2. Đáp Ứng Các Nguyên Tắc Xử Lý Dữ Liệu (Điều 5 GDPR)

1. **Hợp pháp, công bằng và minh bạch (Lawfulness, Fairness, Transparency):**
   - Mọi hoạt động của AI Agent đều được liên kết định danh với một tài khoản người dùng WordPress hợp pháp (`user_id`).
   - Nhật ký kiểm toán minh bạch ghi nhận thời gian, công cụ và kết quả của từng hành động.
2. **Giới hạn mục đích (Purpose Limitation):**
   - Khóa API và nhật ký kiểm toán chỉ được sử dụng duy nhất cho mục đích xác thực, phân quyền và đảm bảo an toàn vận hành hệ thống máy chủ MCP.
3. **Giảm thiểu dữ liệu (Data Minimization):**
   - Không lưu trữ giá trị tham số yêu cầu (ví dụ: văn bản prompt, nội dung bí mật).
   - Chỉ lưu trữ tên tham số cấp cao nhất (`arg_names`).
   - Khóa API chỉ lưu trữ dưới dạng băm một chiều `HMAC-SHA256`.
4. **Giới hạn lưu trữ (Storage Limitation):**
   - Quy định thời hạn lưu trữ nhật ký kiểm toán tối đa (mặc định 90 ngày) và tự động dọn dẹp qua cron job.
5. **Tính toàn vẹn và bảo mật (Integrity and Confidentiality):**
   - Áp dụng các thuật toán mật mã mạnh mẽ, bắt buộc TLS/HTTPS và bảo vệ cơ sở dữ liệu chống truy cập trái phép.

---

## 3. Thực Thi Các Quyền Của Chủ Thể Dữ Liệu (Chương III GDPR)

Dự án tích hợp sâu vào giao diện Quản lý Quyền riêng tư của WordPress Core:

### 3.1 Quyền Truy Xuất Dữ Liệu (Right of Access - Điều 15)
- Thông qua lớp `XNetVN\AgentHubWP\Audit\PrivacyExporter`:
- Đăng ký bộ trích xuất dữ liệu cá nhân qua hook `wp_privacy_personal_data_exporters`.
- Khi người dùng yêu cầu xuất dữ liệu:
  - Hệ thống xuất thông tin tất cả các khóa API thuộc quyền sở hữu của người dùng (tên khóa, ngày tạo, ngày hết hạn, quyền hạn, lần dùng cuối).
  - Xuất toàn bộ các sự kiện trong nhật ký kiểm toán liên quan đến người dùng.
  - Tuyệt đối không để lộ mật mã Pepper, hash bí mật hay token.

### 3.2 Quyền Xóa Dữ Liệu / Quyền Được Quên (Right to Erasure - Điều 17)
- Thông qua lớp `XNetVN\AgentHubWP\Audit\PrivacyEraser`:
- Đăng ký bộ xóa dữ liệu qua hook `wp_privacy_personal_data_erasers`.
- Khi yêu cầu xóa dữ liệu được xác nhận:
  - Xóa toàn bộ các khóa API của người dùng đó khỏi cơ sở dữ liệu.
  - Xóa thông tin tùy chọn nhận email thông báo của người dùng.
  - Ẩn danh hóa các bản ghi kiểm toán bằng cách gán `user_id = 0`, đảm bảo xóa sạch mối liên hệ cá nhân trong khi giữ lại số liệu thống kê kỹ thuật tổng quát.
  - Nếu người dùng từng đóng vai trò thu hồi khóa của người khác, trường tham chiếu `revoked_by` sẽ được xóa bỏ an toàn mà không làm ảnh hưởng đến khóa của chủ sở hữu khác.
