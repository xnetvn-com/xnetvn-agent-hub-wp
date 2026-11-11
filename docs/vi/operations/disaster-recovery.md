# Kế Hoạch Khôi Phục Sau Thảm Họa (Disaster Recovery Plan)

> **Chiến lược ứng phó sự cố thảm họa, mục tiêu thời gian khôi phục (RTO/RPO) và quy trình chuyển đổi dự phòng cho xNetVN Agent Hub WP.**

---

## 1. Mục Tiêu Khôi Phục Sau Thảm Họa

- **Mục Tiêu Thời Gian Khôi Phục (Recovery Time Objective - RTO):**
  - Khôi phục hoạt động của dịch vụ máy chủ MCP trong vòng **dưới 30 phút**.
- **Mục Tiêu Điểm Khôi Phục Dữ Liệu (Recovery Point Objective - RPO):**
  - Mất mát dữ liệu khóa API: **RPO = 0** (không cho phép mất mát thông tin khóa còn hiệu lực).
  - Dữ liệu nhật ký kiểm toán: **RPO <= 1 giờ** (chấp nhận mất mát tối đa 1 giờ nhật ký kiểm toán trong trường hợp thảm họa vật lý trung tâm dữ liệu).

---

## 2. Kịch Bản & Quy Trình Chuyển Đổi Dự Phòng (Failover Procedures)

### 2.1 Thảm Họa Máy Chủ Web Bị Hỏng (Web Server Node Failure)
- **Tình huống:** Máy chủ Web Node chạy PHP-FPM bị sập phần cứng.
- **Quy trình:**
  1. Bộ cân bằng tải (Load Balancer) tự động loại bỏ node lỗi ra khỏi cụm thông qua Health Check Probe.
  2. Lưu lượng truy cập MCP tự động chuyển tiếp sang các Web Node còn lại trong cụm.
  3. Khởi tạo một Web Node mới từ hình ảnh mẫu (Golden AMI / Container Image) và đưa vào cụm.

### 2.2 Thảm Họa Cơ Sở Dữ Liệu Primary Bị Hỏng (Database Primary Failure)
- **Tình huống:** Máy chủ MySQL Primary gặp lỗi không thể phục hồi.
- **Quy trình:**
  1. Kích hoạt chuyển đổi dự phòng tự động (Failover): Thăng cấp một Read-Replica lên làm Primary Master mới.
  2. Cập nhật cấu hình kết nối trong `wp-config.php` hoặc DNS endpoint trỏ về Master mới.
  3. Đảm bảo cấu hình bảng InnoDB và quyền ghi dữ liệu hoạt động bình thường.
  4. Thực hiện lệnh kiểm tra tính toàn vẹn của bảng `*_keys` và `*_audit`.
  5. Xóa bộ nhớ cache toàn hệ thống: `wp cache flush`.

---

## 3. Diễn Tập Khôi Phục Định Kỳ (Disaster Recovery Drills)

- Đội ngũ kỹ thuật thực hiện diễn tập khôi phục sau thảm họa định kỳ **6 tháng một lần** trên môi trường Staging/Remote Test Lab:
  - Giả lập việc xóa cơ sở dữ liệu và khôi phục từ bản backup mới nhất.
  - Kiểm tra xem các AI Agents có thể tiếp tục kết nối thành công với các khóa API đã khôi phục hay không.
  - Đánh giá thời gian RTO và RPO thực tế so với mục tiêu đề ra.
