# ADR-0006: Phiên bản runtime tối thiểu và bootstrap an toàn

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

WordPress 6.9 có Abilities API. Runtime không được hỗ trợ cần nhận thông báo quản trị an toàn thay vì lỗi phân tích cú pháp.

## Quyết định

Yêu cầu PHP >=8.2 và WordPress >=6.9; các tệp bootstrap trước bước kiểm tra phải phân tích cú pháp được trên PHP 7.2.

## Hệ quả

Hỗ trợ bảo mật PHP 8.2 kết thúc ngày 2026-12-31; thay đổi phiên bản tối thiểu sau này cần chính sách tương thích được công bố.

## Phương án đã cân nhắc

Loại bỏ việc tải lớp hiện đại qua Composer autoload trước khi kiểm tra yêu cầu.

## Xác minh

Kiểm thử ma trận phiên bản, kiểm tra bootstrap bằng PHPCompatibilityWP và kiểm tra cú pháp trên CI. Tích hợp PHP 8.5 vẫn ở mức thử nghiệm.

## Nguồn

- https://www.php.net/supported-versions.php (đã xác minh 2026-10-04).
