# ADR-0008: Ability dựa trên REST

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

Controller lõi thực thi trường đã đăng ký, KSES và capability theo từng đối tượng.

## Quyết định

Ưu tiên điều phối nội bộ bằng rest_do_request và giữ nguyên xác thực dữ liệu cùng quyền đối tượng của lõi.

## Hệ quả

Đầu ra phải được định hình lại và lọc; dùng API trực tiếp phải kiểm tra capability/đối tượng rõ ràng.

## Phương án đã cân nhắc

Loại bỏ sửa trực tiếp cơ sở dữ liệu và gọi REST qua HTTP vòng về chính site.

## Xác minh

Kiểm thử quyền đối tượng cho Author/Contributor/Editor, schema không hợp lệ và định hình lại phản hồi.

## Nguồn

- https://developer.wordpress.org/reference/functions/rest_do_request/ (đã xác minh 2026-10-04).
