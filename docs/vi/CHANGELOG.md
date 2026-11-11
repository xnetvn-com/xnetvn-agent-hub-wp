# Lịch Sử Thay Đổi (Changelog)

Tất cả các thay đổi đáng chú ý của dự án **xNetVN Agent Hub WP** sẽ được ghi lại trong tài liệu này.
Định dạng được dựa trên [Keep a Changelog](https://keepachangelog.com/vi/1.0.0/) và dự án tuân thủ [Semantic Versioning (SemVer)](https://semver.org/lang/vi/).

---

## [1.0.0] - 2026-11-04

### Đã thêm (Added)
- **Bộ công cụ người dùng (12 tools, toolset `users`):** vùng bảo vệ Admin, chặn vai trò gán được, vai trò `banned` không quyền kèm bản chụp và hủy phiên, công cụ hồ sơ chính chủ, tạo tài khoản đúng một vai trò với mật khẩu ngẫu nhiên, mã lỗi an toàn và bộ lọc chỉ-từ-chối.
- **Bộ công cụ taxonomy (9 tools, toolset `taxonomy`):** phân giải taxonomy quản lý được, chặn cha-con vòng, bảo vệ term mặc định, xóa thử dry_run, gán trực tiếp chỉ ID nguyên có lấp mặc định và chặn bài rác, tạo/gán hàng loạt giới hạn và term meta chỉ khóa đã đăng ký.
- **Bộ công cụ bình luận (10 tools, toolset `comments`):** kiểm duyệt đúng quyền từng bình luận qua hàm lõi (giữ IP gốc), tạo mới mặc định chờ duyệt, ép lọc KSES, xóa vĩnh viễn hai bước và dọn spam/thùng rác theo lô có mốc chốt.
- **Tách biệt xác thực REST & MCP:** Quản trị qua REST API chỉ dùng Cookie + Nonce, hiển thị khóa 1 lần duy nhất, xoay vòng khóa nguyên tử, yêu cầu xác thực lại mật khẩu đối với khóa có cấp độ rủi ro cao.
- **Kiểm toán & Quyền riêng tư:** Bộ đệm nhật ký kiểm toán, dọn dẹp định kỳ (retention), hỗ trợ xuất và xóa dữ liệu cá nhân theo GDPR/CCPA, thông báo vòng đời khóa và hỗ trợ gỡ cài đặt sạch cron.
- **Giao diện quản trị:** Trang Profile và 6 trang quản trị (Overview, Keys, Audit, Policy, Settings, Diagnostics) với hộp thoại bí mật thuần JS, khôi phục focus, Clipboard API và render DOM an toàn đạt chuẩn tiếp cận WCAG 2.2 AA.
- **Bộ công cụ hệ thống M1 (5 Abilities):** `get-session-info`, `get-site-info`, `discover-abilities`, `get-ability-info`, `execute-ability`.
- **Cổng kết nối Adapter MCP:** Tích hợp với `mcp-adapter` chính thức của WordPress trên tuyến `/xnetvn-agent-hub-wp/mcp`.
- **Đường ống dữ liệu đầu ra (OutputPipeline):** Lọc bỏ trường nhạy cảm, làm sạch dữ liệu, kiểm tra Schema đầu ra nghiêm ngặt.
- **Cơ sở dữ liệu Per-site:** Khởi tạo bảng `*_keys` và `*_audit`, migration an toàn với lease nguyên tử.
- **Mô hình Khóa API an toàn:** Băm `HMAC-SHA256` với khóa bí mật Pepper 32-byte độc lập trên từng site.
- **Bảo vệ giao vận & Nguồn gốc:** Bắt buộc TLS/HTTPS và kiểm duyệt tiêu đề Origin.
- **Bộ giới hạn tần suất trượt:** 120 yêu cầu/phút/khóa và 10 lần thử sai/phút/IP (hỗ trợ Redis Object Cache).
- **Bộ tài liệu tiếng Việt hoàn chỉnh:** Xây dựng toàn diện thư mục `docs/vi/` cho người đọc tiếng Việt.
- **Hủy kích hoạt:** Xóa bốn sự kiện cron thuộc sở hữu plugin trên site hiện tại; khi hủy kích hoạt toàn mạng, duyệt mọi site theo trang giới hạn với `switch_to_blog()`/`restore_current_blog()` trong `finally`. Khóa, dòng kiểm toán và cài đặt không bị xóa.
- **Chính sách HTTP của site:** Remote media fetch kiểm tra `WP_HTTP_BLOCK_EXTERNAL` và `WP_ACCESSIBLE_HOSTS` trước khi phân giải DNS và trên mỗi bước chuyển hướng; host được phép không vượt qua chính sách địa chỉ riêng và phân giải DNS đầy đủ.

### Đã thay đổi (Changed)
- Chốt bản phát hành ứng viên 1.0.0 dưới tên hiển thị xNetVN Agent Hub WP; slug, tệp chính, thư mục và text domain giữ nguyên `xnetvn-agent-hub-wp`.

### Đã sửa (Fixed)
- Xử lý tương thích tiêu đề `HTTP_AUTHORIZATION` khi chạy sau Apache/Nginx FastCGI.
- Đảm bảo tính nguyên tử tuyệt đối khi xoay vòng khóa trên MySQL InnoDB, ngăn chặn lỗi tranh chấp tài nguyên.
- Ngăn chặn lỗi bypass quyền của Super Administrator trên WordPress Multisite khi site con áp dụng chính sách giới hạn riêng.
- Xóa bỏ an toàn các tham chiếu người thu hồi khóa và tùy chọn thông báo theo quy trình GDPR mà không ảnh hưởng đến khóa của chủ sở hữu khác.
- Làm sạch toàn bộ các cron event có tham số khi gỡ bỏ plugin.

---

## [0.1.0] - 2026-10-04

### Đã thêm (Added)
- Bản phát hành nền tảng ban đầu (Pre-release foundation).
- Khởi tạo kiến trúc Kernel, ServiceProvider, các giao ước Domain Contracts và khung kiểm thử tự động.

## Bổ sung Content/Media — có trong 1.0.0

- Thêm 19 tool nội dung và 7 tool media riêng tư, giữ quyền theo đối tượng của core, schema đóng, DTO được duyệt và phát hiện xung đột UTC.
- Tải dữ liệu qua thân REST thô; tải HTTPS mặc định tắt, kiểm DNS đầy đủ, ghim IP trước gửi và giới hạn byte.
- Giữ biên nhận mutation đến hết kiểm tra đầu ra; giới hạn tác vụ nặng theo site/khóa. Nghiệm thu bảo mật và phát hành vẫn là các cổng riêng.
