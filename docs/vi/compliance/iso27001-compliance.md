# Tuân Thủ Tiêu Chuẩn Bảo Mật ISO/IEC 27001 (ISO 27001 Compliance)

> **Báo cáo đáp ứng các biện pháp kiểm soát an toàn thông tin theo tiêu chuẩn quốc tế ISO/IEC 27001:2022 cho xNetVN Agent Hub WP.**

---

## 1. Bản Đồ Ánh Xạ Biện Pháp Kiểm Soát Phụ Lục A (Annex A Controls Mapping)

| Mã Kiểm Soát ISO 27001:2022 | Tên Biện Pháp Kiểm Soát | Biện Pháp Kỹ Thuật Đã Triển Khai Trong Dự Án |
|---|---|---|
| **A.5.15** | Quản lý kiểm soát truy cập (Access control) | Phân quyền hạt mịn giao thoa giữa Quyền User WordPress $\cap$ Scope Khóa API $\cap$ Trần Site $\cap$ Toolset. |
| **A.5.16** | Quản lý danh tính (Identity management) | Định danh dựa trên tài khoản WordPress chính chủ; mỗi khóa API thuộc sở hữu duy nhất một cá nhân. |
| **A.5.17** | Thông tin xác thực (Authentication information) | Sử dụng mã bí mật 256-bit ngẫu nhiên sinh từ `random_bytes()`, băm `HMAC-SHA256` kết hợp Pepper độc lập. |
| **A.8.9** | Quản lý cấu hình (Configuration management) | Cấu hình bảo mật được quản lý tập trung qua `WpSiteSettings`, có giá trị mặc định an toàn (Secure by Default). |
| **A.8.15** | Ghi nhật ký (Logging) | Bảng `*_audit` ghi lại mọi yêu cầu MCP, có mã định danh `request_id`, thời gian UTC, kết quả thực thi. |
| **A.8.20** | An toàn mạng (Network security) | Bắt buộc giao thức HTTPS/TLS; kiểm duyệt nghiêm ngặt tiêu đề Origin chống CSRF và DNS Rebinding. |
| **A.8.24** | Sử dụng mật mã học (Use of cryptography) | Sử dụng các hàm mật mã học tiêu chuẩn của PHP/OpenSSL (`hash_hmac`, `hash_equals`, `random_bytes`), không tự sáng chế mật mã. |
| **A.8.25** | Vòng đời phát triển an toàn (Secure development lifecycle) | Áp dụng TDD, CI/CD tự động, kiểm tra PHPCS WordPress, phân tích tĩnh PHPStan Level 8 không baseline. |

---

## 2. Kiểm Soát Độc Lập Khóa Mức Hàng & Toàn Vẹn Giao Dịch

- Tuân thủ yêu cầu kiểm soát toàn vẹn dữ liệu (Data Integrity): Thao tác xoay vòng khóa sử dụng giao dịch nguyên tử InnoDB với lệnh khóa hàng `SELECT ... FOR UPDATE`, đảm bảo không bao giờ tồn tại trạng thái nửa vời hoặc thất lạc khóa khi hệ thống xảy ra sự cố đột ngột.
