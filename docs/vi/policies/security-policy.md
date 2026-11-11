# Chính Sách Bảo Mật Thông Tin (Security Policy)

> **Chính sách bảo mật toàn diện, các ranh giới kiểm soát rủi ro và quy trình xử lý lỗ hổng bảo mật cho xNetVN Agent Hub WP.**

---

## 1. Mục Tiêu & Cam Kết An Toàn
- **xNetVN Agent Hub WP** được thiết kế với tư duy **Security by Design** (Bảo mật ngay từ khâu thiết kế) và **Zero Trust** (Không tin tưởng mặc định).
- Dự án cam kết tuân thủ nghiêm ngặt các hướng dẫn và tiêu chuẩn bảo mật quốc tế hàng đầu:
  - **OWASP API Security Top 10 (2023)**.
  - **NIST SP 800-53 / 800-171**.
  - **WordPress Core Security Best Practices**.

---

## 2. Các Ranh Giới Kiểm Soát An Toàn (Security Controls)

### 2.1 Permanent operational boundaries

The authoritative exclusions are [architecture/overview.md](../architecture/overview.md) and the code HardDeny list. No arbitrary execution, SQL, source/configuration edits, secrets, credential administration or arbitrary plugin/theme ZIP tools. Sanctioned attachment writes use core upload APIs only. Settings are documented solely in [ENVIRONMENT.md](../ENVIRONMENT.md).

### 2.2 Phòng Chống Tấn Công Dò Thời Gian (Timing Attack Prevention)
- Việc so sánh chữ ký băm HMAC của khóa API luôn sử dụng hàm `hash_equals()`.
- Khi người dùng gửi một `key_id` không tồn tại trong cơ sở dữ liệu, hệ thống vẫn thực thi một phép tính HMAC giả lập với độ trễ tương đương nhằm đảm bảo thời gian phản hồi không làm lộ thông tin sự tồn tại của khóa.

### 2.3 Bảo Vệ Nguồn Gốc & Chống Tấn Công Qua Trình Duyệt (Origin & CSRF Defense)
- Tuyến MCP từ chối nhận diện người dùng qua Cookie. Mọi yêu cầu bắt buộc phải có Bearer Token.
- Tiêu đề `Origin` được kiểm tra chặt chẽ:
  - Cho phép vắng mặt tiêu đề `Origin` đối với các client máy tính để bàn (như Claude Desktop, CLI scripts).
  - Từ chối ngay lập tức nếu `Origin: null` hoặc Origin không nằm trong danh sách miền được Quản trị viên cấu hình cho phép (`allowed_origins`).

### 2.4 Phòng Ngừa Prompt Injection & Xử Lý Dữ Liệu Không Tin Cậy
- Mọi nội dung lấy ra từ cơ sở dữ liệu WordPress (tiêu đề bài viết, nội dung bài viết, bình luận, tên tác giả) đều được đối xử là **Dữ liệu Không Tin Cậy (Untrusted Data)**.
- Mô tả của các công cụ (Tool Descriptions) được cố định bằng tiếng Anh chuẩn mực và không bao giờ chèn nội dung động do người dùng nhập vào để tránh việc AI Agent bị đánh lừa cấu trúc chỉ lệnh (Instruction Override).

---

## 3. Quy Trình Báo Cáo & Xử Lý Lỗ Hổng Bảo Mật (Vulnerability Disclosure)

- Nếu bạn phát hiện một lỗ hổng bảo mật tiềm ẩn trong dự án, **vui lòng không công khai lỗ hổng trên GitHub Issues hoặc mạng xã hội**.
- Hãy gửi báo cáo chi tiết đến địa chỉ email bảo mật chuyên trách:
  **`security@xnetvn.net`** hoặc sử dụng tính năng **GitHub Private Security Advisories**.
- **Cam kết thời gian xử lý:**
  - Xác nhận tiếp nhận báo cáo trong vòng **24 giờ**.
  - Đánh giá mức độ nghiêm trọng và cung cấp phản hồi kỹ thuật trong vòng **48 giờ**.
  - Phát hành bản vá bảo mật và ghi nhận đóng góp (Security Hall of Fame) trong vòng **7 ngày làm việc**.
