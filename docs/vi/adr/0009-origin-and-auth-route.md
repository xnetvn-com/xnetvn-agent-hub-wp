# ADR-0009: Origin, TLS và xác thực bearer khớp chính xác tuyến

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

MCP HTTP yêu cầu bảo vệ Origin. WordPress có thể lưu người dùng cookie vào cache trước khi REST_REQUEST tồn tại.

## Quyết định

Chỉ xác thực khóa tại đúng tuyến MCP, từ chối xác thực cookie ở tuyến đó và giữ nguyên các lỗi bảo vệ trước đó.

## Hệ quả

Xác thực lại ở giai đoạn muộn khi cần; header TLS từ proxy tin cậy phải đi kèm ràng buộc IP nguồn. Challenge chỉ có realm cần được xác minh bằng client thực tế.

## Phương án đã cân nhắc

Loại bỏ khớp tuyến theo tiền tố và chỉ xác thực tại callback truyền tải của adapter.

## Xác minh

N-10–N-19 và N-33–N-35; khảo sát host đã cài và client U4/U5/U10.

## Nguồn

- https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http (đã xác minh 2026-10-04).
