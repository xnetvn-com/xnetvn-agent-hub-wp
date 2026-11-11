# ADR-0005: Lấy giao của capability và scope

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

Vai trò có thể thay đổi sau khi tạo khóa; bản chụp capability cố định sẽ duy trì quyền đã bị thu hồi.

## Quyết định

Lấy giao giữa capability WordPress hiện tại với cấp độ khóa/site, toolset và các ràng buộc cho phép/từ chối.

## Hệ quả

Quyền dùng khóa mặc định theo edit_posts; quyền quản lý theo quyền quản lý site/network. Bộ lọc chỉ được thu hẹp quyền truy cập.

## Phương án đã cân nhắc

Loại bỏ bản chụp vai trò và việc coi scope là một nguồn cấp quyền độc lập.

## Xác minh

Kiểm thử tích hợp ma trận vai trò và >=30 trường hợp bảng chân trị chính sách; HTTP không có context luôn bị từ chối.

## Nguồn

- https://developer.wordpress.org/plugins/users/roles-and-capabilities/ (đã xác minh 2026-10-04).
