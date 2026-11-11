# Quy Trình Kiểm Toán Nội Bộ & Truy Vết (Audit Process)

> **Hướng dẫn thực hiện quy trình kiểm toán độc lập, rà soát nhật ký và truy vết trách nhiệm người dùng trong xNetVN Agent Hub WP.**

---

## 1. Mục Tiêu Kiểm Toán (Audit Objectives)

1. **Tính Không Thể Chối Bỏ (Non-repudiation):** Đảm bảo mọi hành động do AI Agent thực hiện trên website WordPress đều được truy vết chính xác về một khóa API và một tài khoản người dùng cụ thể.
2. **Minh Bạch & Toàn Vẹn (Transparency & Integrity):** Các bản ghi kiểm toán không thể bị chỉnh sửa hoặc giả mạo bởi người dùng thông thường.
3. **Phát Hiện Bất Thường Kịp Thời (Anomaly Detection):** Nhận diện các hành vi lạm dụng, quét lỗ hổng hoặc gọi công cụ ngoài phạm vi cho phép.

---

## 2. Các Bước Tiến Hành Kiểm Toán Định Kỳ (Quarterly Audit Workflow)

```
[1. Trích xuất dữ liệu kiểm toán] ──► [2. Phân tích lưu lượng & tỷ lệ lỗi] ──► [3. Rà soát danh sách khóa hoạt động] ──► [4. Lập báo cáo kiểm toán]
```

### Bước 1: Trích Xuất Dữ Liệu Kiểm Toán
- Quản trị viên truy cập màn hình **Agent Hub → Nhật ký (Audit Logs)**.
- Có thể lọc theo khoảng thời gian, theo người dùng hoặc xuất dữ liệu bảng `wp_xnetvn_agent_hub_audit` ra tệp CSV để phân tích nâng cao.

### Bước 2: Phân Tích Lưu Lượng & Tỷ Lệ Lỗi
- Kiểm tra các bản ghi có trạng thái `outcome = 'denied'`:
  - Phân tích nguyên nhân: Do hết hạn khóa, sai quyền hạn WordPress hay vượt quá trần rủi ro của site?
  - Kiểm tra xem có dấu hiệu tấn công thử mật khẩu/token từ một địa chỉ IP cụ thể không.

### Bước 3: Rà Soát Danh Sách Khóa Hoạt Động (Key Inventory Review)
- Rà soát toàn bộ các khóa API có trạng thái `active`:
  - Khóa nào đã quá 90 ngày chưa được sử dụng? (Khuyến nghị thu hồi).
  - Khóa nào có cấp độ `destructive` đang được cấp phát cho người dùng không còn thuộc nhóm quản trị nội dung? (Thu hồi hoặc thu hẹp quyền).

### Bước 4: Lập Báo Cáo Kiểm Toán & Kiến Nghị Cải Tiến
- Tổng hợp số liệu: Tổng số yêu cầu MCP đã phục vụ, số lượng khóa đang hoạt động, các sự cố bảo mật (nếu có).
- Ghi nhận vào báo cáo kỹ thuật theo mẫu chuẩn tại [templates/report_template.md](../templates/report_template.md).
