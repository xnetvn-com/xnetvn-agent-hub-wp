# ADR-0020: Danh tính máy theo website

- Trạng thái: Accepted (thiết kế). Việc triển khai do bộ 47 phiếu ngày
  2026-11-17 đảm nhiệm; kiểm chứng runtime và lab dùng chung vẫn `NOT RUN`.
- Ngày: 2026-11-17
- Thay thế: không. Tài liệu mở rộng ADR-0005 và ADR-0018 mà không đổi hợp
  đồng của khóa cá nhân.

## Bối cảnh

Đợt rà soát WordPress.org ngày 2026-11-16 nêu vấn đề thẩm quyền quản trị từ
bên ngoài, không phải báo lỗi từng dòng. Mô hình khóa MCP hiện tại gắn mọi
khóa với một người dùng WordPress: `src/Auth/KeyService.php:50` tạo khóa cá
nhân `xnv_` cho người dùng, và `src/Domain/AuthContext.php:28` mang mã người
dùng đó vào mọi yêu cầu. ADR-0005 sau đó giao khóa với năng lực hiện có của
người dùng.

Mô hình đó đúng khi một người dùng MCP. Nó không thể đại diện cho một website
tự động hóa công việc biên tập mà không sở hữu, không mạo danh và không tạo
ra một tài khoản WordPress. Tạo tài khoản ẩn, `WP_User` giả, hoặc tiêm năng
lực cho người dùng ẩn danh sẽ cấp thẩm quyền WordPress mà không người thật
nào nắm giữ, và vấn đề của thư mục vẫn chưa được giải quyết.

Vì vậy chủ dự án quyết định một danh tính riêng cho website hiện tại. Quyết
định được ghi tại đây và được triển khai bởi bộ phiếu đã nêu trong đặc tả
site-machine-keys.

## Quyết định

### OD-01: Tài khoản được bảo vệ bị loại khỏi khóa cá nhân

Giữ quy trình khóa cá nhân cho người dùng thường. Loại `Administrator`,
`Super Admin` và tương đương quản trị (danh sách `RoleGuard::PRIVILEGED`,
không gồm `unfiltered_html`) khỏi việc tạo, xoay, gia hạn bản vá và xác thực
bearer của khóa `xnv_`. Việc quản lý khóa vẫn dành cho quản lý đã xác thực
bằng cookie và nonce.

### OD-02: Khóa website thuộc về website, không thuộc người dùng

Khóa mới `xns_` thuộc website hiện tại và thực thi không cần tài khoản
WordPress. `user_id = 0` là danh tính thực thi. Tài khoản ẩn, `WP_User` giả,
mạo danh quản trị và tiêm năng lực cho người dùng ID `0` đều bị cấm. Khóa
không xuất hiện trong `ApiKey` và không được biểu diễn như người dùng
WordPress.

### OD-03: Mọi khóa MCP đi qua phong bì an toàn đã rà soát

Áp dụng `SafeMcpEnvelope` và `SafeEditorialHtml` (`wp_kses` với
`unfiltered_html` bị vô hiệu trong thời gian ghi MCP) cho mọi khóa MCP, cả
`xnv_` và `xns_`. Mức nền là thẩm quyền biên tập (`editorial-v1`), không phải
quản trị toàn site.

### OD-04: Cấm là đình chỉ biên tập tại site hiện tại

Cấm nghĩa là gán vai trò `banned` không có năng lực, xóa năng lực trực tiếp,
và hủy các phiên WordPress hiện có. Chặn đăng nhập bằng mật khẩu, xác thực
Application Password và chặn đặt lại mật khẩu nằm ngoài quyết định này; chúng
không được tuyên bố là một phần của nó.

### OD-05: Quyền tác giả là ghi nhận, không phải thẩm quyền

Mọi lần tạo bài viết, trang và tệp đính kèm bằng máy phải chọn tác giả nội
dung đủ điều kiện qua `EditorialAuthors::require_eligible()`. Ghi nhận không
cấp quyền thực thi và không trở thành quyền sở hữu khóa. Sửa bài thông
thường giữ nguyên tác giả trước đó.

### OD-06: Kiểm duyệt bằng máy cần hai quyền riêng, mặc định tắt

`Ban users` (`ban-users`) và `Unban users` (`unban-users`) là hai quyền tùy
chọn độc lập, mặc định tắt. Bỏ cấm bằng máy chỉ khôi phục ảnh chụp an toàn
đã kiểm (`v1` hoặc `v2`); không có tham số `restore_role` tùy ý trong lệnh
máy.

### OD-07: Bảo vệ toàn mạng và hủy phiên toàn người dùng

Trên Multisite, giữ hủy phiên toàn người dùng bằng
`WP_Session_Tokens::destroy_all()`, gồm đăng xuất tại site khác. Bảo vệ tài
khoản `Administrator` và tương đương tại mọi site của mạng hiện tại, gồm
thành viên không hoạt động hoặc lưu trữ, trước cả hai hành động kiểm duyệt.
Phạm vi quét có giới hạn và đóng an toàn khi vượt 1.000 site.

### OD-08: Không chuyển đổi khóa cũ cho người dùng chưa triển khai

Chủ dự án xác nhận chưa có người dùng đã triển khai. Loại trừ chuyển đổi
khóa cũ, chuyển quyền sở hữu, thu hồi hàng loạt, dọn dữ liệu và di trú tương
thích cho khóa đã triển khai. Các hạn chế runtime vẫn áp dụng cho mọi khóa
được trình sau thay đổi.

### Kiến trúc chuẩn tắc

- Giữ nguyên `ApiKey`, `AuthContext`, `KeyService`, `RequestContext`, định
  dạng `xnv_` và các tuyến `/keys`.
- Thêm `SiteCredential`, `SiteScope`, `SiteAuthContext` và
  `SiteRequestContext::site_current()` theo kiểu bổ sung.
- Lưu khóa website trong bảng riêng theo site
  `{prefix}xnetvn_agent_hub_wp_site_keys`; không thêm cột `user_id`.
- Dùng tiền tố `xns_` và bộ mã hóa chặt; định tuyến theo tiền tố, không dự
  phòng chéo.
- Cấp quyền qua `SitePolicy` và chỉ chạy handler đã đăng ký trong
  `SiteDispatcher`; `SafeMcpEnvelope` tĩnh là biên ngoài.
- Ghi hành động máy với `principal_type = site`, `user_id` rỗng, `blog_id`
  và `credential_ref` có không gian tên (`xns:<key_id>`).
- Giữ tham chiếu người `xnv:` và máy `xns:` tách biệt để cùng một mã khóa 12
  ký tự không bao giờ trùng giữa hai loại danh tính.

## Hệ quả

- Website có thể tự động hóa công việc biên tập đã rà soát mà không cần tài
  khoản WordPress.
- Bề mặt khóa cá nhân hẹp hơn: tài khoản được bảo vệ mất quyền tạo khóa cá
  nhân và lối vào bearer.
- Mọi đường ghi MCP phải mang phong bì và biên KSES; một lối vòng là chặn
  phát hành.
- Kiểm duyệt chỉ có qua quyền máy riêng, được cấp rõ ràng, không qua quản
  trị người dùng chung.
- Dòng audit và hoạt động có thêm loại danh tính và tham chiếu khóa, nên
  hành động máy hiển thị mà không tạo người dùng WordPress giả.
- Việc triển khai đi qua cửa sổ danh mục `62` lên `63` năng lực giữa `FR_13`
  và `ISSUE_08`; danh mục, gói kỹ năng và ma trận vai trò được đồng bộ trong
  cửa sổ đó, không bằng cách hạ assertion.
- Rủi ro còn lại nằm ở chính sách transport, nghiệm thu lab và các luật gốc
  chưa xác minh; chúng được ghi rõ, không che giấu.

## Phương án đã cân nhắc

- **Tài khoản WordPress ẩn cho website.** Loại: tạo danh tính, thừa hưởng
  năng lực và không phân biệt được với người thật.
- **`WP_User` giả hoặc `user_id = 0` với năng lực được tiêm.** Loại: cấp
  thẩm quyền ẩn danh và phá mô hình năng lực.
- **Mạo danh quản trị.** Loại: vi phạm đặc quyền tối thiểu và yêu cầu của
  thư mục.
- **Dùng lại `xnv_` với phạm vi site.** Loại: vẫn gắn khóa với người và làm
  mờ việc thu hồi, ghi nhận.
- **Lưu khóa site trong bảng khóa cá nhân.** Loại: schema giả định có người
  dùng và buộc phải tạo chủ sở hữu giả.
- **Chặn đăng nhập và Application Password trong thay đổi này.** Hoãn theo
  OD-04; đây là quyết định bảo mật riêng.

## Kiểm chứng

- Thiết kế và triển khai do 47 phiếu nêu trong đặc tả site-machine-keys và
  chỉ mục điều phối theo đợt quản lý.
- Cổng cục bộ: PHPCS, PHPStan mức 8 không baseline, PHPUnit unit và kiểm
  đóng gói.
- Cổng lab dùng chung: tích hợp WordPress 6.9 và 7.1.2, multisite, ma trận
  REST/HTTP, E2E trình duyệt và client thật, chỉ chạy qua `rtl.py`.
- Mọi phiếu T3/vùng đỏ cần rà soát độc lập trước `Done`.
- Các luật gốc `L-01` đến `L-20` không có đủ trong checkout này. Chúng được
  ghi `UNVERIFIABLE` cho đến khi chủ dự án khôi phục nguồn hoặc miễn trừ rõ
  ràng; không phiếu nào được tuyên bố đã xác minh chúng.
- Tài liệu này không tuyên bố WordPress.org đã chấp thuận thư mục.

## Nguồn

- Vai trò và năng lực WordPress:
  https://developer.wordpress.org/plugins/users/roles-and-capabilities/
  (kiểm 2026-11-17).
- `WP_User::has_cap`:
  https://developer.wordpress.org/reference/classes/wp_user/has_cap/
  (kiểm 2026-11-17).
- Biên quyền tạo bài qua REST:
  https://developer.wordpress.org/reference/classes/wp_rest_posts_controller/create_item_permissions_check/
  (kiểm 2026-11-17).
- `wp_insert_post`:
  https://developer.wordpress.org/reference/functions/wp_insert_post/
  (kiểm 2026-11-17).
- Hủy phiên:
  https://developer.wordpress.org/reference/classes/wp_session_tokens/destroy_all/
  (kiểm 2026-11-17).
- Lưu token phiên:
  https://developer.wordpress.org/reference/classes/wp_user_meta_session_tokens/update_sessions/
  (kiểm 2026-11-17).
- Hướng dẫn thư mục:
  https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
  (kiểm 2026-11-17).
- FAQ nhà phát triển plugin:
  https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
  (kiểm 2026-11-17).
