# ADR-0002: Abilities API và adapter chính thức

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

Các phiên bản giao thức và việc xác thực tầng truyền tải cần tuân theo một triển khai chính thức được duy trì.

## Quyết định

Sử dụng Abilities API của lõi và MCP Adapter chính thức được cài riêng, thông qua một facade ServerGateway duy nhất.

## Hệ quả

Các pack độc lập với tầng truyền tải; tính tương thích của adapter cần được khảo sát thực nghiệm trên runtime.

## Phương án đã cân nhắc

Loại bỏ việc tự xây dựng ngăn xếp giao thức MCP và đóng gói adapter kèm sản phẩm.

## Xác minh

M1 kiểm thử công cụ riêng tư trên máy chủ tùy chỉnh, các phiên bản giao thức và quyền HTTP/STDIO.

## Nguồn

- https://wordpress.org/plugins/mcp-adapter/ (đã xác minh 2026-10-04).
