# Đặc tả triển khai lõi M1

## Mục tiêu và thẩm quyền
Triển khai kiến trúc khóa API thuộc sở hữu người dùng, xác thực khớp chính xác tuyến, giao capability và ability MCP riêng tư được mô tả trong kế hoạch triển khai ban đầu. Định danh, tuyến, tên bảng, mặc định và loại trừ đã cố định vẫn có tính ràng buộc. Thực thi tự chủ, kiểm thử từ xa có phạm vi và commit/push an toàn trên nhánh tính năng đã được cho phép. Phát hành, merge và công bố production vẫn là các cửa riêng.

## Giao diện và trình tự
1. Khảo sát adapter đã cài chứng minh ability riêng tư trên máy chủ tùy chỉnh, phiên bản runtime, chữ ký hook, phiên bản tối thiểu của hook lõi, hành vi cookie cache/tuyến, header 401 và cấu hình client thực tế. Ghi từng U1–U7/U10 độc lập; không suy ra kết quả thực tế từ quét tĩnh.
2. Miền: enum RiskTier (read/write/destructive/admin, thứ hạng có thứ tự), value object KeyId/KeySecret/Toolset đã xác thực, Scope/ApiKey/AuthContext bất biến và quyết định từ chối. Lớp miền lõi không gọi API WordPress. Bản ghi khóa mang định danh và scope, tuyệt đối không mang token thô.
3. Mật mã: KeyGenerator::generate(): GeneratedKey; KeyHasher::hash(secret, pepper): string và verify(secret, pepper, stored): bool; TokenParser::parse(token): ?ParsedToken. GeneratedKey chỉ hiển thị token đầy đủ một lần. Bề mặt debug/JSON/tuần tự hóa không bao giờ tiết lộ bí mật. Token sai định dạng bị từ chối trước truy cập repository.
4. Lưu trữ: schema theo site chỉ tiến về phía trước, repository dùng truy vấn đã chuẩn bị, thử lại tính duy nhất có giới hạn, cập nhật scope chỉ được thu hẹp, vô hiệu cache, ghi lần dùng có điều tiết và lô kiểm toán. Cài đặt/pepper không autoload.
5. Capability dùng/quản lý động; bảo vệ tuyến/Origin/TLS; auth riêng cho yêu cầu; đường xử lý khóa thiếu an toàn về thời gian; kiểm tra chủ sở hữu/hết hạn/IP/site và giới hạn tần suất khóa/IP có giới hạn. REST auth mức ưu tiên 80 giữ lỗi trước đó và xác thực lại mọi người dùng cookie đã cache sớm.
6. Chính sách và registry riêng tư bọc callback quyền cho mọi tầng truyền tải. Chỉ ServerGateway import lớp adapter. Lọc liệt kê/gọi và kiểm tra lại ability được gọi trong công cụ meta.
7. Hợp đồng schema/đầu ra, pack hệ thống, kiểm toán/quyền riêng tư/lưu giữ/gỡ cài đặt tối thiểu an toàn, REST quản lý bằng cookie+nonce, chỉnh sửa chỉ được thu hẹp/luân chuyển/kiểm tra mật khẩu, UI hồ sơ/quản trị có khả năng tiếp cận.
8. End-to-end N-01–N-35 và client runtime, đo hiệu năng, tài liệu vận hành và rà soát bảo mật độc lập.

## Ràng buộc schema và an toàn
Tên có dạng `xnetvn/<verb-object>` và MCP dùng dấu gạch nối. Mỗi định nghĩa có schema đối tượng đầu vào/đầu ra nghiêm ngặt, cấp độ rủi ro, toolset, capability hiển thị và annotation nhất quán. Đối số đăng ký bỏ metadata public/show_in_rest; lõi có thể chèn mặc định false của nó và giá trị đó phải luôn false. Bản đồ định nghĩa của plugin là nguồn có thẩm quyền.

Đầu ra cuối phải vẫn khớp schema sau che dữ liệu và áp dụng giới hạn. Không bịa thành công, nội dung có cấu trúc không hợp lệ hoặc envelope bị cắt mà schema không khai báo. Nếu không tạo được đầu ra hợp lệ có giới hạn, làm công cụ thất bại với lỗi thực thi an toàn. Nội dung do site tạo là dữ liệu không tin cậy.

Không công cụ nào được truy cập SQL/PHP/shell/trình sửa tệp, bí mật cấu hình, quản lý thông tin xác thực, ZIP plugin/theme tùy ý hoặc cài đặt vô hiệu hóa bảo vệ. Bộ lọc động chỉ được tăng rủi ro hoặc từ chối truy cập.

## Kiểm thử và nghiệm thu
Độ bao phủ đơn vị Domain và helper Auth thuần >=90% cho M1-S01 gốc; phạm vi Domain/Auth/Policy kết hợp rộng hơn >=90% và tổng thể >=80% cho phát hành; tính duy nhất 10,000 khóa; vector HMAC đã biết độc lập; bảng token sai định dạng/quá lớn; kiểm tra debug/xuất bí mật; >=30 trường hợp chính sách; ma trận vai trò; vòng đời repository; kiểm tra nonce/quyền sở hữu/scope quản lý; toàn bộ N-01–N-35 qua HTTP thực tế; hợp đồng pack nghiêm ngặt và tích hợp WP_Ability. Hai client MCP thực tế và đo hiệu năng là cửa runtime rõ ràng, không thay thế kiểm thử đơn vị.

Nghiệm thu tĩnh/local: composer check, tích hợp trên WP6.9/bản ổn định hiện tại, audit phụ thuộc sạch, ZIP production và Plugin Check trên gói đã cài. CI trên host, hành vi connector đám mây ngoài, mức sẵn sàng production và nghiệm thu thư mục cần bằng chứng riêng, vẫn chưa được xác minh cho đến khi thực sự chạy.
