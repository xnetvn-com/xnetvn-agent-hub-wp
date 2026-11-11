# Hợp đồng khóa API (mục tiêu M1)

Khóa có dạng `xnv_<key_id>_<secret>`. Định danh công khai gồm 12 ký tự base32 viết thường `[a-z2-7]`; bí mật gồm 32 byte ngẫu nhiên, mã hóa thành 43 ký tự base64url không có padding. Tổng độ dài là 60. Từ chối token không khớp `^xnv_[a-z2-7]{12}_[A-Za-z0-9_-]{43}$` trước khi truy cập cơ sở dữ liệu.

Chỉ lưu HMAC-SHA256(secret, pepper) dưới dạng 64 ký tự thập lục phân, kèm định danh pepper. Tạo pepper riêng dài 32 byte, lưu không autoload trong `xnetvn_agent_hub_wp_pepper`; không suy ra từ salt WordPress. So sánh bằng hash_equals và thực hiện HMAC giả khi thiếu định danh. Token thô chỉ xuất hiện một lần khi tạo/luân chuyển, tuyệt đối không trong danh sách, kiểm toán, bản xuất hoặc chẩn đoán.

Mỗi khóa thuộc một người dùng trên một site. Scope gồm tier_max, toolset, danh sách ability cho phép/từ chối tùy chọn, hạn chế IP CIDR và thời điểm hết hạn. Capability người dùng hiện tại được lấy giao với scope ở mọi yêu cầu. Chủ sở hữu tạm thời bị cấm nhận 403 mà không thu hồi khóa; chủ sở hữu bị xóa nhận 401 và khóa bị thu hồi. Thông tin xác thực không xác định, sai định dạng, bị thu hồi và hết hạn trả cùng thông báo 401 công khai. Lý do nội bộ thuộc metadata kiểm toán an toàn.

Sử dụng bảng khóa và kiểm toán theo site, thao tác cơ sở dữ liệu có chuẩn bị, dấu thời gian UTC và chuỗi IP VARCHAR(45). Key ID có chỉ mục duy nhất, với tối đa ba lần thử lại việc sinh ID. Cập nhật lần dùng cuối tối đa một lần mỗi năm phút. Sửa scope chỉ được thu hẹp đặc quyền và thời hạn. Luân chuyển tạo khóa mới và giới hạn thời gian ân hạn của khóa cũ (mặc định 24 giờ).

Quản lý khóa cần xác thực bằng cookie đăng nhập, nonce wp_rest hợp lệ và quyền sở hữu hoặc capability quản lý. Tạo khóa destructive/admin cần xác thực lại mật khẩu, thời hạn có giới hạn và giới hạn tần suất. Khóa bearer MCP không thể quản lý khóa hoặc xác thực vào các tuyến REST khác. Tuyệt đối không bật tham số token bearer trong truy vấn hoặc chuyển tiếp token.

Chính sách mặc định: 10 khóa hoạt động/người dùng; thời hạn tối đa 365 ngày; 120 yêu cầu/phút/khóa; 10 lần xác thực thất bại/phút/IP. Thu hồi vô hiệu hóa cache ngay lập tức. Thông báo quản lý và xuất/xóa dữ liệu cá nhân không bao giờ tiết lộ bí mật hoặc giá trị băm.

Nguồn chính thức: [xác thực WordPress](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/), [PHP random_bytes](https://www.php.net/manual/en/function.random-bytes.php), [hash_hmac](https://www.php.net/manual/en/function.hash-hmac.php), [hash_equals](https://www.php.net/manual/en/function.hash-equals.php). Đã xác minh ngày 2026-10-04.
