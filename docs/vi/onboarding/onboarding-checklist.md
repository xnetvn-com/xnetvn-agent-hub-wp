# Danh Mục Kiểm Tra Hội Nhập Thành Viên Mới (Onboarding Checklist)

> **Lộ trình từng bước giúp lập trình viên và thành viên mới hòa nhập và bắt đầu đóng góp hiệu quả vào xNetVN Agent Hub WP.**

---

## 📅 Tuần 1: Thiết Lập Môi Trường & Tiếp Cận Dự Án

### Ngày 1: Đọc Tài Liệu Cơ Bản & Làm Quen
- [ ] Đọc tài liệu [README.md](../README.md) để hiểu tổng quan sứ mệnh và mục tiêu dự án.
- [ ] Đọc tài liệu [ARCHITECTURE.md](../ARCHITECTURE.md) để nắm vững các ranh giới bảo mật bất biến.
- [ ] Đọc [CODE_OF_CONDUCT.md](../CODE_OF_CONDUCT.md) và [CONTRIBUTING.md](../CONTRIBUTING.md).
- [ ] Đọc [standards/coding-standards.md](../standards/coding-standards.md) để nắm chuẩn viết code.

### Ngày 2 - 3: Thiết Lập Môi Trường Phát Triển Cục Bộ
- [ ] Cài đặt PHP 8.2+ và Composer 2.x trên máy trạm.
- [ ] Cài đặt Node.js 20+ và npm.
- [ ] Clone kho lưu trữ dự án: `git clone https://github.com/xnetvn-com/xnetvn-agent-hub-wp.git`.
- [ ] Chạy `composer install` và `npm install`.
- [ ] Khởi động môi trường WordPress cục bộ qua wp-env hoặc Docker Compose: `npm run env:start`.
- [ ] Chạy kiểm tra chất lượng ban đầu: `composer check` (đảm bảo 100% xanh).

### Ngày 4 - 5: Tìm Hiểu Luồng Xử Lý & Chạy Thử Test Cases
- [ ] Chạy bộ Unit Tests: `composer test:unit`.
- [ ] Mở tệp `src/Abilities/Packs/System/SystemPack.php` và tìm hiểu cách 5 System Abilities được định nghĩa.
- [ ] Tạo một khóa API thử nghiệm tại màn hình quản trị và dùng cURL/Postman gọi thử tuyến `/wp-json/xnetvn-agent-hub-wp/mcp`.

---

## 📅 Tuần 2: Thực Hiện Nhiệm Vụ Đầu Tiên (First PR)

- [ ] Nhận một issue khởi đầu (Good First Issue) hoặc bài tập bổ sung test case từ Mentor.
- [ ] Tạo nhánh tính năng tuân thủ quy ước: `git checkout -b feature/...`.
- [ ] Áp dụng phương pháp TDD: Viết test trước, viết mã giải quyết vấn đề sau.
- [ ] Chạy kiểm tra: `composer check`.
- [ ] Tạo Pull Request theo mẫu [templates/pr_template.md](../templates/pr_template.md).
- [ ] Tiếp thu phản hồi code review từ Core Maintainers và hoàn thiện PR để được merge.
