# ADR-0012: Lưu địa chỉ IP dưới dạng văn bản

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

Địa chỉ nhị phân tương tác không thuận lợi với cơ chế escape ký tự và định dạng cơ sở dữ liệu.

## Quyết định

Dùng VARCHAR(45) cho địa chỉ IPv4/IPv6 chuẩn hóa và kiểm tra CIDR rõ ràng.

## Hệ quả

Chuẩn hóa và xác thực IP; không được nhầm lẫn proxy tin cậy với IP client.

## Phương án đã cân nhắc

Loại bỏ header chuyển tiếp chưa được xác thực và địa chỉ nhị phân trong định dạng chuỗi SQL.

## Xác minh

Kiểm thử IPv4/IPv6/CIDR và chuỗi lưu trữ chính xác trong kiểm thử tích hợp repository.

## Nguồn

- https://developer.wordpress.org/reference/classes/wpdb/ (đã xác minh 2026-10-04).
