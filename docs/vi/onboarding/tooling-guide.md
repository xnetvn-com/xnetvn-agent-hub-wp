# Hướng Dẫn Sử Dụng Bộ Công Cụ Phát Triển (Tooling Guide)

> **Cẩm nang hướng dẫn sử dụng các công cụ dòng lệnh, script tự động hóa và công cụ kiểm thử trong xNetVN Agent Hub WP.**

---

## 1. Tổng Hợp Các Lệnh Dòng Lệnh Composer (Composer Scripts)

Các lệnh được định nghĩa sẵn trong `composer.json` phục vụ quy trình phát triển hàng ngày:

```bash
# 1. Kiểm tra định dạng mã nguồn theo chuẩn WordPress (PHPCS)
composer lint

# 2. Tự động sửa các lỗi định dạng đơn giản (PHPCBF)
composer lint:fix

# 3. Kiểm tra tính tương thích cú pháp PHP 7.2 cho các tệp bootstrap
composer lint:bootstrap

# 4. Phân tích tĩnh chất lượng mã nguồn mức cao nhất (PHPStan Level 8)
composer analyse

# 5. Chạy bộ Unit Tests
composer test:unit

# 6. Chạy bộ Integration Tests trên môi trường WordPress thực tế
composer test:integration

# 7. Kiểm tra tính toàn vẹn của gói ZIP phát hành
composer test:packaging

# 8. Chạy toàn bộ bộ kiểm tra chất lượng (Gate bắt buộc trước khi commit)
composer check

# 9. Đóng gói bản phát hành sản phẩm hoàn chỉnh
composer build
```

---

## 2. Hướng Dẫn Sử Dụng Script Tự Động Hóa Trong Thư Mục `bin/`

- **`bin/build-zip.sh`:**  
  Script đóng gói tệp ZIP phát hành chính thức. Tự động nạp composer dependencies sạch, loại bỏ các file phát triển và kiểm tra cấu trúc gói đầu ra.
- **`bin/verify-zip.sh`:**  
  Kiểm tra tệp ZIP đã đóng gói xem có vi phạm quy định về file rác, file nhạy cảm hay file thử nghiệm còn sót lại không.
- **`bin/make-pot.sh`:**  
  Trích xuất toàn bộ các chuỗi bản địa hóa đa ngôn ngữ từ mã nguồn PHP và JavaScript ra tệp `languages/xnetvn-agent-hub-wp.pot`.
- **`bin/test-integration.sh`:**  
  Script khởi chạy môi trường kiểm thử tích hợp tự động với WordPress database.

---

## 3. Công Cụ Kiểm Thử Trình Duyệt & E2E (Playwright & Axe Core)

```bash
# Cài đặt trình duyệt Playwright (nếu chưa cài)
npx playwright install --with-deps

# Chạy kiểm thử E2E trên trình duyệt Chromium
npm run test:e2e

# Chạy kiểm tra hợp đồng dependencies
npm run test:tooling
```
