# Tiêu Chuẩn Lập Trình & Định Dạng Mã Nguồn (Coding Standards)

> **Bộ quy tắc và tiêu chuẩn định dạng mã nguồn áp dụng cho toàn bộ dự án xNetVN Agent Hub WP.**

---

## 1. Nguyên Tắc Cốt Lõi
- **Mã nguồn như do một người viết ra:** Nhất quán, sạch sẽ, tường minh và tuân thủ các chuẩn mực cao nhất của ngành công nghiệp phần mềm.
- **Tiêu chuẩn ngôn ngữ kép (Dual-Language Protocol):**
  - **Mã nguồn (Source Code):** Bắt buộc 100% tiếng Anh chuyên nghiệp cho tên biến, tên hàm, tên lớp, hằng số, comment giải thích logic, Docblocks/PHPDoc và commit message.
  - **Tài liệu gốc tại `docs/vi/`:** Bắt buộc 100% tiếng Việt chuẩn mực làm cơ sở Single Source of Truth.
- **Không dead code / commented-out code:** Tuyệt đối không lưu lại mã nguồn rác, mã không còn dùng hoặc đoạn mã bị comment lại trong VCS.
- **Không Magic Numbers / Magic Strings:** Tất cả các giá trị hằng số phải được khai báo rõ ràng trong Enum hoặc Hằng số lớp (`class constants`).

---

## 2. Tiêu Chuẩn PHP (PHP Coding Standards)

### 2.1 Chuẩn Tuân Thủ
- **WordPress Coding Standards:** `WordPress-Core`, `WordPress-Extra`, `WordPress-Docs` (thông qua `phpcs.xml.dist`).
- **PHPStan:** Phân tích tĩnh cấp độ cao nhất **Level 8** không có file baseline (`phpstan.neon.dist`).
- **Khai báo nghiêm ngặt:** Bắt buộc có `declare(strict_types=1);` ở đầu mọi tệp PHP trong `src/` và `tests/`.
- **Tương thích phiên bản:**
  - Runtime code (`src/`): PHP 8.2 trở lên (sử dụng Typed Properties, Readonly Classes/Properties, Constructor Promotion, Match expressions).
  - Bootstrap code (`src/Bootstrap/`, `uninstall.php`, `xnetvn-agent-hub-wp.php`): Tương thích từ cú pháp PHP 7.2 trở lên để thông báo lỗi an toàn trên môi trường cũ.

### 2.2 Quy Ước Đặt Tên (Naming Conventions)
- **Namespace:** `XNetVN\AgentHubWP\...` (theo chuẩn PSR-4).
- **Lớp, Interface, Trait, Enum:** `PascalCase` (ví dụ: `SlidingWindowLimiter`, `KeyRepository`, `RiskTier`).
- **Phương thức & Hàm:** `snake_case` theo truyền thống của WordPress API hoặc `camelCase` khi triển khai interface bên ngoài; ưu tiên nhất quán `snake_case` cho phương thức nội bộ trong WordPress context (ví dụ: `is_available()`, `register()`, `get_site_info()`).
- **Biến & Thuộc tính:** `snake_case` (ví dụ: `$plugin_file`, `$audit_repository`).
- **Hằng số (Constants):** `UPPER_SNAKE_CASE` (ví dụ: `XNETVN_AGENT_HUB_WP_VERSION`).
- **Biến boolean:** Bắt đầu bằng `is_`, `has_`, `can_`, `should_` (ví dụ: `$is_active`, `$has_permission`).

### 2.3 Thụt Lề & Độ Dài Dòng
- **Thụt lề:** Sử dụng 4 dấu cách (spaces) hoặc 1 tab chuẩn theo cấu hình PHPCS WordPress. Không pha trộn giữa tab và space trong cùng một tệp.
- **Độ dài dòng:** Khuyến nghị dưới 100 ký tự, tối đa không quá 120 ký tự.

### 2.4 Docblock & Chú Thích (PHPDoc)
- 100% các lớp, giao diện, phương thức public và thuộc tính phải có Docblock đầy đủ.
- Khai báo rõ ràng `@param`, `@return`, `@throws` kèm mô tả chi tiết:
  ```php
  /**
   * Xác thực và phân tích chuỗi Bearer Token.
   *
   * @param string $token Chuỗi token thô nhận từ Authorization header.
   * @return ParsedToken|null Trả về đối tượng token đã phân tích hoặc null nếu sai cú pháp.
   * @throws InvalidArgumentException Khi định dạng token không thể chấp nhận.
   */
  ```

---

## 3. Tiêu Chuẩn JavaScript & CSS

### 3.1 JavaScript (`assets/admin.js`)
- Sử dụng JavaScript ES6+ hiện đại, đóng gói trong IIFE hoặc Module để tránh xung đột biến toàn cục (`global scope pollution`).
- Tương tác DOM an toàn qua `addEventListener`, không sử dụng `innerHTML` với chuỗi không rõ nguồn gốc để triệt tiêu lỗ hổng DOM-based XSS.
- Quản lý tương tác phi đồng bộ qua `async/await` và `fetch()` API kết hợp `wp_rest` nonce.

### 3.2 CSS (`assets/admin.css`)
- Đặt tiền tố rõ ràng cho mọi class selector: `.xnetvn-agent-hub-...` để tránh ghi đè giao diện quản trị WordPress cốt lõi.
- Hỗ trợ đầy đủ chế độ Responsive (Mobile/Tablet/Desktop) và chế độ Dark Mode của trình duyệt nếu áp dụng.
- Đảm bảo độ tương phản màu sắc đạt chuẩn WCAG 2.2 AA (tối thiểu 4.5:1 cho văn bản thông thường).
