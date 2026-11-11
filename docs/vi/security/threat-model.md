# Mô hình đe dọa

Đây là thiết kế bảo mật bắt buộc, không phải bằng chứng về ranh giới bảo mật đã triển khai. Plugin hiện có máy chủ MCP riêng tư và khóa thuộc người dùng; trạng thái nghiệm thu được theo dõi riêng. Xem lại mô hình trước khi thay đổi xác thực, tải lên, trình nâng cấp, multisite và OAuth.

| Đe dọa | Kiểm soát và bằng chứng bắt buộc |
|---|---|
| Giả mạo danh tính/đánh cắp khóa | Bí mật ngẫu nhiên 256 bit; HMAC với pepper riêng; tuyến chính xác; chỉ bearer; 401 thống nhất; kiểm tra hết hạn/thu hồi/chủ sở hữu |
| Sửa đổi trái phép/leo thang đặc quyền | Lấy giao capability người dùng với scope; quyền đối tượng lõi; PATCH khóa chỉ được thu hẹp; admin tắt; bộ lọc chỉ được từ chối |
| Chối bỏ trách nhiệm | ID yêu cầu an toàn và kiểm toán kết quả; UTC; không thông tin xác thực hoặc giá trị đối số; thời hạn lưu giữ có giới hạn |
| Tiết lộ thông tin | Schema đầu ra/lọc trường; không bí mật/băm; UI được escape; báo cáo riêng; kiểm soát xuất/xóa |
| Từ chối dịch vụ | Giới hạn tần suất khóa/IP; giới hạn payload/độ sâu/đầu ra/bulk/timeout; dọn cron có giới hạn |
| Nâng đặc quyền | Không thực thi tùy ý/sửa tệp; nguồn nâng cấp tin cậy; trần vai trò; tuân thủ DISALLOW_FILE_MODS/EDIT |
| CSRF/DNS rebinding | Origin đã xác thực; quản lý bằng nonce+cookie; MCP từ chối cookie kể cả có nonce; danh sách proxy tin cậy |
| SSRF | API HTTP WordPress an toàn; chỉ endpoint http(s) công khai; kiểm tra IPv4/IPv6/DNS/chuyển hướng; giới hạn thời gian/kích thước |
| Prompt injection | Nội dung site là dữ liệu không tin cậy; mô tả công cụ cố định; scope, toolset và xác nhận giới hạn tác động; tên/mô tả/URL/meta term và người dùng là dữ liệu, xóa cần tier destructive kèm dry_run và chặn term mặc định; công cụ quản trị người dùng cần tier admin kèm vùng bảo vệ, không đường mật khẩu/email chính chủ |
| Lạm dụng taxonomy | Allowlist taxonomy quản lý được kèm danh sách chặn và bộ lọc deny-only; gán chỉ ID nguyên, không bỏ lặng lẽ, không tạo term ngoài ý muốn; kiểm trước cha-con vòng; chặn term mặc định và bài rác; giới hạn bulk với khe costly |
| Leo thang đặc quyền người dùng | Vùng bảo vệ Admin (UserTargetGuard: super admin/administrator/vai trò đặc quyền/cap trực tiếp/has_cap hiệu lực) không ngoại lệ kể cả chính chủ; cổng vai trò gán được (RoleGuard) đổi một vai trò nguyên tử; vòng đời banned kèm bản chụp, gỡ cap trực tiếp và hủy phiên; không mật khẩu qua MCP; email/mật khẩu chính chủ bất biến; cooldown và ngân sách tạo/reset theo phút |
| Xâm phạm chuỗi cung ứng | Tệp khóa; audit/rà soát phụ thuộc; adapter chính thức cài riêng; cửa phiên bản và kiểm thử hợp đồng |
| Rò rỉ dữ liệu cá nhân | Kiểm toán chỉ lưu tên đối số cấp cao nhất; che IP; lưu giữ/xuất/xóa; không email/username khi thiếu xin tường minh kèm edit_user; không băm avatar/capabilities/phiên/khóa trong đầu ra; kiểm thử canary |
| Truy cập chéo site | Tiền tố/pepper/context theo site; switch_to_blog có giới hạn; ability network chỉ cho super administrator |

Ánh xạ OWASP API Top 10 (2023): API1 phân quyền đối tượng qua controller lõi; API2 vòng đời thông tin xác thực; API3 schema nghiêm ngặt và kiểm soát trường nhạy cảm; API4 giới hạn tài nguyên; API5 phân quyền cấp độ/toolset/hàm; API6 xác nhận rủi ro cao; API7 kiểm soát SSRF; API8 mặc định từ chối an toàn; API9 danh mục công cụ theo phiên bản; API10 sử dụng thượng nguồn an toàn.

Kiểm thử phủ định phải gồm khóa hợp lệ kèm Origin/TLS xấu (lỗi trước đó không thể trở thành thành công), danh tính cookie đã cache kèm khóa người dùng khác, nhầm lẫn tuyến, thực thi ability trực tiếp bị cấm, mở rộng scope, giá trị mồi kiểm toán, DNS rebinding, base64 quá lớn và ranh giới gỡ cài đặt.

Tham chiếu chính thức: [bảo mật WordPress](https://developer.wordpress.org/apis/security/), [OWASP API Security](https://owasp.org/API-Security/), [thực hành bảo mật MCP](https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices). Đã xác minh ngày 2026-10-04.

Media persistence is limited to core raw-body attachment upload APIs. Filename components, content MIME, approved/core MIME intersection, image pixels/memory and per-key expensive-work slots are checked before persistence. Temporary files are removed in finally. Password confirmation applies to issuing destructive/admin keys; per-call elicitation remains M6.
