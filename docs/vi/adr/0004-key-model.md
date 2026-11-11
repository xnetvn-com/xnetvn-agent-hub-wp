# ADR-0004: Khóa API của người dùng và pepper riêng

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-04

## Bối cảnh

Khóa là thông tin xác thực được ủy quyền và không thể mở rộng đặc quyền hiện tại của chủ sở hữu.

## Quyết định

Sử dụng khóa ngẫu nhiên thuộc sở hữu người dùng và HMAC-SHA256 với pepper ngẫu nhiên riêng; chỉ hiển thị mỗi bí mật một lần.

## Hệ quả

Mất pepper khiến các khóa mất hiệu lực. Danh sách, nhật ký, bản xuất và chẩn đoán tuyệt đối không được chứa thông tin xác thực hoặc giá trị băm.

## Phương án đã cân nhắc

Loại bỏ lưu trữ bản rõ, mã hóa có thể giải ngược, dùng salt WordPress làm pepper và truyền bearer trong chuỗi truy vấn.

## Xác minh

Vector HMAC đã biết, từ chối token sai định dạng, kiểm thử định dạng/entropy và giá trị mồi kiểm toán.

## Nguồn

- https://www.php.net/manual/en/function.hash-hmac.php (đã xác minh 2026-10-04).
