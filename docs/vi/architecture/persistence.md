# Triển khai lưu trữ theo site và các lớp bảo vệ

Lõi chỉ tạo các bảng khóa/kiểm toán đã ghi trong tài liệu và option phiên bản schema chỉ tiến về phía trước. Migration dùng lease option được chiếm quyền nguyên tử thay vì đọc/sửa/ghi transient, và chỉ chủ sở hữu lease mới được giải phóng. Activation và plugins_loaded chạy cùng phép kiểm tra phiên bản nhẹ. Từ chối phiên bản schema tương lai; site vẫn tải với chẩn đoán an toàn được trì hoãn.

Tra cứu khóa dùng lượt đọc cơ sở dữ liệu mới qua chỉ mục. Đối tượng metadata ApiKey an toàn không chứa bí mật, HMAC hay pepper. StoredKey giữ bộ xác minh mờ có HMAC nằm ngoài các thuộc tính đối tượng có thể xuất. Va chạm định danh bị từ chối khi chèn; cập nhật scope so sánh nguyên tử với bản chụp đã đọc và chỉ được thu hẹp. Thu hồi không có khoảng thời gian cache thông tin xác thực lỗi thời. Ghi lần dùng cuối tối đa một lần mỗi năm phút.

Luân chuyển commit INSERT khóa thay thế và UPDATE thời hạn khóa cũ cùng nhau trên một kết nối mysqli gốc được cố định. SQL được chuẩn bị qua API placeholder WordPress. Ranh giới giao dịch hẹp chủ động tránh hành vi kết nối lại/thử lại của wpdb: phát lại thao tác ghi sau mất kết nối có thể thoát khỏi giao dịch gốc. Nó khóa và kiểm tra lại hàng cũ dự kiến, yêu cầu InnoDB, đồng thời từ chối giao dịch bao ngoài hoặc autocommit bị tắt thay vì commit ngầm công việc của bên gọi khác. Không chuyển đổi bảng. Sự kiện tạo và hiển thị một lần diễn ra sau commit; lỗi cập nhật/trigger thông thường rollback cả hai thao tác ghi mà không tạo khóa bù. Database drop-in không cung cấp kết nối mysqli hoặc định tuyến truy vấn qua nhiều primary cần được xử lý tương thích riêng và bị từ chối an toàn khi luân chuyển.

Mất xác nhận commit có thể khiến client không chắc chắn dù cơ sở dữ liệu đã commit cả hai thay đổi. Tải lại metadata khóa của chủ sở hữu và đối soát/thu hồi khóa thay thế chưa được hiển thị trước khi thử lại; không tự động phát lại luân chuyển. Ranh giới lỗi phân tán này không tiết lộ token và không chứng minh thao tác thất bại chỉ vì mất phản hồi.

Truy vấn ràng buộc giá trị một lần vào mẫu cố định; lỗi cơ sở dữ liệu là không khả dụng, tuyệt đối không phải số lượng rỗng. Lỗi SQL thô bị chặn và các mục mới trong mảng saved-query của WordPress được che sau thao tác. Nhật ký host/cơ sở dữ liệu độc lập nằm ngoài kiểm soát plugin. Chèn kiểm toán chỉ chấp nhận metadata và tên đối số trong danh sách cho phép, dùng một câu lệnh theo lô và giữ giới hạn truy vấn/dọn dẹp.

Bucket giới hạn tần suất dùng thao tác object cache bền vững nguyên tử khi khả dụng. Khi không có cache bền vững, dùng tăng SQL nguyên tử trong option theo site không autoload, tránh mất lượt tăng giữa các PHP worker. Bucket cũ có phương thức dọn dẹp có giới hạn; việc lập lịch được nối với lưu giữ kiểm toán ở M1-S09. Danh tính peer HTTP dùng địa chỉ socket đã xác thực; giá trị forwarded-for không tin cậy không thể định nghĩa lại danh tính này.

Cài đặt khớp mặc định đã ghi. trusted_proxy_cidrs là ràng buộc địa chỉ rõ ràng bắt buộc cùng trusted_proxy_headers. Origin được xác thực dưới dạng origin đã tuần tự hóa mà không phân giải DNS/HTTP ra ngoài. Chính sách lưu sai định dạng đóng quyền sử dụng được ủy quyền; người quản lý giữ quyền sửa chữa theo capability WordPress. Sửa chữa bảo toàn hạn chế hợp lệ và yêu cầu thay thế rõ ràng trường đã biết nhưng không hợp lệ thay vì âm thầm khôi phục mặc định dễ dãi.

Kiểm thử capability multisite bao phủ cơ chế bỏ qua của lõi dành cho super administrator, từ chối ma trận vai trò rõ ràng, chính sách hỏng, thay đổi vai trò/cài đặt giữa các site và không sửa vai trò bền vững. Migration theo yêu cầu của site mới chỉ tạo bảng thuộc site đó; cùng ID khóa công khai và pepper vẫn độc lập giữa các tiền tố. Dùng cờ môi trường kiểm thử `WP_MULTISITE=1` của lõi đã xác minh bên trong toolbox lab; biến tên gần giống `WP_TESTS_MULTISITE` không chọn multisite trong bộ kiểm thử lõi đã cài.

Các bộ tích hợp multisite lõi 6.9 và 7.1.2 đầy đủ đã đạt 94 kiểm thử/503 assertion trong lab có phạm vi. Lượt single-site chạy các trường hợp gốc và dùng helper `skipWithoutMultisite()` rõ ràng của lõi cho bốn trường hợp chỉ dành cho network. Fixture kết nối độc lập khởi tạo blog ID thực tế trước khi kiểm thử tranh chấp khóa chủ sở hữu; fixture xóa chủ sở hữu network xóa tài khoản thay vì chỉ xóa tư cách thành viên. Những sửa chữa này giữ nguyên ngữ nghĩa lỗi bắt buộc. Nghiệm thu HTTP/client/cache/hiệu năng đầy đủ vẫn tách riêng.

Hook đã triển khai: xnetvn_agent_hub_wp_loaded(kernel), xnetvn_agent_hub_wp_auth_failed(reason, peer_ip), xnetvn_agent_hub_wp_allowed_origins(origins). Auth chạy tại determine_current_user20 / REST auth80; bảo vệ Origin/TLS chạy tại REST auth5. Giữ nguyên từ chối trước đó và xác thực thành công trước đó không thay thế được context khóa plugin hợp lệ. Trạng thái yêu cầu được đặt lại tại shutdown.

Kiểm thử đã sử dụng cơ sở dữ liệu WordPress thực tế, thay đổi vai trò, tuyến chính xác, điều kiện Origin/TLS, xác minh thông tin xác thực, mất/khôi phục vai trò, thu hồi, hết hạn, thiếu header và chia sẻ bộ đếm. N-01–N-35 đầy đủ và nghiệm thu adapter/quản lý/kiểm toán vẫn là các cửa riêng về sau.

Tham chiếu chính thức (đã xác minh ngày 2026-10-04):
- https://developer.wordpress.org/plugins/creating-tables-with-plugins/
- https://developer.wordpress.org/reference/classes/wpdb/prepare/
- https://developer.wordpress.org/reference/functions/add_option/
- https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/

Nguồn multisite, đã xác minh ngày 2026-10-05:
- https://developer.wordpress.org/reference/classes/wp_user/has_cap/
- https://developer.wordpress.org/reference/hooks/map_meta_cap/
- https://developer.wordpress.org/reference/functions/switch_to_blog/
- https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/

Nguồn luân chuyển, đã xác minh ngày 2026-10-05:
- https://developer.wordpress.org/reference/classes/wpdb/__get/
- https://developer.wordpress.org/reference/classes/wpdb/remove_placeholder_escape/
- https://developer.wordpress.org/reference/classes/wpdb/query/
- https://dev.mysql.com/doc/refman/8.4/en/set-transaction.html
- https://mariadb.com/docs/server/reference/sql-statements/transactions/start-transaction
- https://www.php.net/manual/en/mysqli.begin-transaction.php
