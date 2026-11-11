# ADR-0010: Metadata kiểm toán không chứa giá trị đối số

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

Nhật ký kiểm toán có thể trở thành nguồn thứ cấp chứa bí mật và dữ liệu cá nhân.

## Quyết định

Lưu định danh yêu cầu, kết quả, thời gian và tên đối số cấp cao nhất; tuyệt đối không lưu giá trị thông tin xác thực hoặc đối số.

## Hệ quả

Thời hạn lưu giữ, che IP và xuất/xóa dữ liệu cá nhân cần kiểm thử cùng cơ chế dọn dẹp theo lô có giới hạn.

## Phương án đã cân nhắc

Loại bỏ ghi toàn bộ header, body yêu cầu hoặc đầu ra công cụ.

## Xác minh

Kiểm thử giá trị mồi, các lô lưu giữ và kiểm thử tích hợp xuất/xóa dữ liệu người dùng.

## Nguồn

- https://developer.wordpress.org/plugins/privacy/ (đã xác minh 2026-10-04).
