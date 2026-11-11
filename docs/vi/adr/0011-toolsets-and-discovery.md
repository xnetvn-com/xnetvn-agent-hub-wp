# ADR-0011: Toolset và khám phá có giới hạn

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

Danh sách công cụ lớn tiêu tốn ngữ cảnh mô hình và có thể tiết lộ capability không khả dụng.

## Quyết định

Nhóm ability vào toolset có scope, có công cụ khám phá hệ thống và cơ chế liệt kê xác định.

## Hệ quả

Đo payload 20/60/120 công cụ trước khi chọn ngưỡng liệt kê; thực thi qua công cụ meta phải kiểm tra lại registry và chính sách.

## Phương án đã cân nhắc

Loại bỏ việc mặc định công khai mọi ability bên thứ ba đã đăng ký.

## Xác minh

Danh sách được lọc theo scope, sắp xếp ổn định và phép đo 20/60/120 công cụ tại M1-S00.

## Nguồn

- https://modelcontextprotocol.io/specification/2026-07-28/server/tools (đã xác minh 2026-10-04).
