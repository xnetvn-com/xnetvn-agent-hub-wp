# Tuân Thủ Tiêu Chuẩn Bảo Mật Dữ Liệu PCI DSS (PCI DSS Compliance)

> **Báo cáo và hướng dẫn tuân thủ Tiêu chuẩn Bảo mật Dữ liệu Ngành Thẻ Thanh toán (PCI DSS v4.0) áp dụng cho môi trường xNetVN Agent Hub WP.**

---

## 1. Phạm Vi Áp Dụng (Scope)

- **xNetVN Agent Hub WP** là plugin máy chủ MCP phục vụ tích hợp AI Agent vào WordPress.
- Plugin **không trực tiếp xử lý, lưu trữ hoặc truyền tải dữ liệu chủ thẻ (Primary Account Number - PAN hoặc Sensitive Authentication Data - SAD)**.
- Tuy nhiên, trong các môi trường thương mại điện tử (WooCommerce / WordPress e-Commerce), máy chủ MCP hoạt động cùng máy chủ với ứng dụng bán hàng. Do đó, việc tuân thủ các nguyên tắc của PCI DSS v4.0 là bắt buộc để không làm tăng rủi ro cho môi trường dữ liệu chủ thẻ (Cardholder Data Environment - CDE).

---

## 2. Các Biện Pháp Đáp Ứng Yêu Cầu PCI DSS v4.0

### Yêu Cầu 2: Áp Dụng Cấu Hình An Toàn Mặc Định (Secure Configurations)
- Mặc định, plugin vô hiệu hóa hoàn toàn cấp độ rủi ro `admin`.
- Mặc định từ chối các kết nối HTTP không mã hóa và từ chối các Origin không được liệt kê trong danh sách tin cậy.

### Yêu Cầu 3: Bảo Vệ Dữ Liệu Được Lưu Trữ (Protect Stored Account Data)
- Khóa bí mật API của người dùng không bao giờ được lưu trữ dưới dạng văn bản rõ (cleartext).
- Sử dụng thuật toán băm mật mã học một chiều `HMAC-SHA256` kết hợp khóa Pepper độc lập.
- Đường ống dữ liệu đầu ra (`OutputPipeline`) loại bỏ toàn bộ thông tin nhạy cảm trước khi gửi phản hồi cho AI Agent.

### Yêu Cầu 6: Phát Triển & Duy Trì Hệ Thống Phần Mềm An Toàn (Secure Systems and Software)
- Mã nguồn được kiểm tra phân tích tĩnh ở cấp độ khắt khe nhất (PHPStan Level 8 không baseline).
- Không có lỗi bảo mật trong danh mục OWASP Top 10 (chống SQL Injection, XSS, CSRF).
- Mọi truy vấn cơ sở dữ liệu đều sử dụng kỹ thuật Prepared Statements có tham số hóa.

### Yêu Cầu 8: Xác Thực & Quản Lý Định Danh (Identification and Authentication)
- Mỗi yêu cầu MCP bắt buộc phải mang Bearer Token được cấp phát riêng cho từng người dùng duy nhất.
- Hỗ trợ giới hạn thời hạn sử dụng khóa (tối đa 365 ngày) và quy trình xoay vòng khóa không gián đoạn dịch vụ.
- Yêu cầu người dùng xác thực lại mật khẩu tài khoản khi phát hành khóa có quyền thao tác dữ liệu phá hủy (`destructive`).

### Yêu Cầu 10: Ghi Nhật Ký & Giám Sát Mọi Truy Cập Tài Nguyên (Log and Monitor Access)
- Bảng nhật ký kiểm toán ghi lại toàn bộ hoạt động truy cập qua MCP: thời gian UTC, ID người dùng, ID khóa, hành động và địa chỉ IP của client.
- Nhật ký kiểm toán được bảo vệ toàn vẹn và có cơ chế dọn dẹp định kỳ có kiểm soát.
