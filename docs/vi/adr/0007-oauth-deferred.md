# ADR-0007: Hoãn OAuth đến M7

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

Connector đám mây tạo thêm ranh giới tin cậy riêng cho danh tính và token.

## Quyết định

Dùng khóa API cho HTTP và người dùng WP-CLI được chỉ định rõ cho STDIO trong giai đoạn một; thiết kế OAuth riêng ở M7.

## Hệ quả

Không tuyên bố tương thích với connector ChatGPT hoặc Claude đám mây khi chỉ có xác thực bằng khóa.

## Phương án đã cân nhắc

Loại bỏ việc giả định khóa API bearer triển khai cơ chế khám phá OAuth.

## Xác minh

M7 yêu cầu kiểm thử PKCE S256, metadata, ràng buộc audience và không chuyển tiếp token.

## Nguồn

- https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization (đã xác minh 2026-10-04).
