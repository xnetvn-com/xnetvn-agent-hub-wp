# Chính Sách Bảo Vệ Quyền Riêng Tư (Privacy Policy)

> **Chính sách thu thập, lưu trữ, xử lý và bảo vệ dữ liệu cá nhân theo các quy định GDPR, CCPA trong xNetVN Agent Hub WP.**

---

## 1. Nguyên Tắc Giảm Thiểu Dữ Liệu (Data Minimization)

Hệ thống tuân thủ nguyên tắc chỉ thu thập và xử lý lượng dữ liệu tối thiểu cần thiết để đảm bảo tính năng vận hành an toàn của máy chủ MCP:
- **Dữ liệu được lưu trữ:**
  - Định danh người dùng WordPress (`user_id`).
  - Định danh công khai của khóa API (`key_id`), tên gợi nhớ do người dùng đặt, thời gian tạo, thời gian hết hạn và thời gian sử dụng gần nhất.
  - Chuỗi băm mật mã học `HMAC-SHA256` của khóa API (tuyệt đối không lưu khóa thô).
  - Nhật ký kiểm toán: Địa chỉ IP (hỗ trợ che dấu), User-Agent, tên công cụ được gọi, kết quả và tên các tham số cấp cao nhất.
- **Dữ liệu không bao giờ thu thập hoặc lưu trữ:**
  - Không lưu giá trị của các tham số được truyền vào (ví dụ: nội dung văn bản, câu lệnh prompt).
  - Không lưu mật khẩu hoặc khóa API thô.

---

## 2. Quyền Của Chủ Thể Dữ Liệu (Data Subject Rights theo GDPR)

xNetVN Agent Hub WP tích hợp trực tiếp vào công cụ Quản lý Quyền riêng tư cốt lõi của WordPress (`Tools → Export Personal Data` và `Tools → Erase Personal Data`):

### 2.1 Quyền Được Biết & Quyền Truy Xuất Dữ Liệu (Right of Access & Portability)
- Thông qua lớp `PrivacyExporter`, khi Quản trị viên thực hiện yêu cầu xuất dữ liệu cá nhân của một người dùng:
  - Hệ thống sẽ trích xuất toàn bộ danh sách các khóa API đang thuộc quyền sở hữu của người dùng đó (tên khóa, key ID, quyền hạn, ngày tạo, lần dùng cuối).
  - Trích xuất toàn bộ lịch sử các sự kiện trong nhật ký kiểm toán gắn liền với `user_id` đó.
  - File xuất ra định dạng JSON/ZIP tiêu chuẩn của WordPress mà không chứa bất kỳ secret hay hash nhạy cảm nào.

### 2.2 Quyền Được Quên (Right to Erasure / To be Forgotten)
- Thông qua lớp `PrivacyEraser`, khi Quản trị viên xác nhận yêu cầu xóa dữ liệu cá nhân của một người dùng:
  - Hệ thống sẽ xóa vĩnh viễn toàn bộ các khóa API thuộc quyền sở hữu của tài khoản đó.
  - Xóa bỏ các tùy chọn thông báo vòng đời khóa liên quan.
  - Ẩn danh hóa (anonymize) các bản ghi trong nhật ký kiểm toán: chuyển đổi `user_id` thành `0` để duy trì tính toàn vẹn số liệu thống kê hệ thống mà không còn liên kết đến danh tính cá nhân.
  - Nếu người dùng này từng thu hồi khóa của người dùng khác, trường `revoked_by` trên khóa của người khác sẽ được xóa mà không làm mất khóa của chủ sở hữu còn lại.

---

## 3. Che Dấu Địa Chỉ IP (IP Masking & Anonymization)
- Hệ thống hỗ trợ chế độ che dấu địa chỉ IP tự động:
  - Đối với IPv4: Thay thế byte cuối cùng bằng số 0 (ví dụ: `198.51.100.45` trở thành `198.51.100.0/24`).
  - Đối với IPv6: Thay thế 80 bit cuối cùng bằng số 0 (ví dụ: `2001:db8:85a3::8a2e:370:7334` trở thành `2001:db8:85a3::/48`).
- Giúp bảo vệ danh tính vị trí địa lý của người dùng trong khi vẫn lưu lại thông tin mạng cơ bản phục vụ việc phát hiện tấn công.
