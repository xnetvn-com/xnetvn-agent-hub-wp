# Hợp đồng khóa máy theo website

- Trạng thái: Thiết kế đã chấp nhận (2026-11-17). Việc triển khai do bộ 47
  phiếu lập kế hoạch; kiểm thử sản phẩm, tích hợp và thư mục là `NOT RUN` cho
  đến khi phiếu tương ứng tạo bằng chứng.
- Thẩm quyền: quyết định của chủ dự án `OD-01` đến `OD-08` trong
  [ADR-0020](../adr/0020-site-machine-principals.md). Bản tiếng Anh là chuẩn;
  tệp tiếng Việt là bản dịch tương đương.
- Phạm vi: danh tính `xns_` không cần tài khoản, lưu trữ, xác thực, chính
  sách, thao tác biên tập, audit, API quản lý và giao diện quản trị của nó.
  Hợp đồng khóa cá nhân `xnv_` giữ nguyên định dạng và tuyến.

## Mô hình danh tính

Plugin có đúng hai loại danh tính MCP:

| Danh tính | Khóa | Danh tính thực thi | Quản lý |
|---|---|---|---|
| Người dùng | `xnv_` (không đổi) | Mã người dùng WordPress đang sống | Tuyến `/keys` và trang cá nhân hiện có |
| Website | `xns_` (mới) | Không có tài khoản WordPress; `user_id = 0` | Chỉ cookie và nonce, tuyến `/site-keys` |

`principal_type` luôn rõ ràng (`user` hoặc `site`). Định tuyến tiền tố chặt:
khóa `xns_` không bao giờ rơi về kho khóa cá nhân, và khóa `xnv_` không bao
giờ vào bộ xác thực site. Yêu cầu mang đồng thời ngữ cảnh người và site bị
từ chối. Danh tính site không đặt người dùng hiện tại của WordPress và không
nhận năng lực qua filter trên người dùng ID `0`.

Ghi nhận tác giả tách khỏi thực thi. Yêu cầu máy có thể ghi công một tác giả
nội dung đủ điều kiện, nhưng tác giả đó không phải chủ khóa và không nhận
quyền thực thi từ việc ghi công.

## Bản đồ quyết định

| Quyết định | Mục hợp đồng |
|---|---|
| OD-01 | Khóa cá nhân `xnv_` loại tài khoản được bảo vệ; xem Xác thực và ngữ cảnh yêu cầu và Vòng đời và hạn mức. |
| OD-02 | Danh tính site không cần tài khoản (`user_id = 0`); xem Mô hình danh tính và Lưu trữ và schema. |
| OD-03 | Mọi khóa MCP đi qua phong bì tĩnh và biên KSES; xem Chính sách và phong bì an toàn. |
| OD-04 | Cấm là đình chỉ biên tập tại site, không chặn đăng nhập hay đặt lại mật khẩu; xem Người dùng và kiểm duyệt. |
| OD-05 | Quyền tác giả là ghi nhận, không phải thẩm quyền; xem Nội dung và phân loại và Phương tiện. |
| OD-06 | Cấm và bỏ cấm là hai quyền riêng, mặc định tắt; xem Người dùng và kiểm duyệt. |
| OD-07 | Quản trị được bảo vệ toàn mạng và phiên bị hủy toàn người dùng; xem Người dùng và kiểm duyệt. |
| OD-08 | Không chuyển đổi khóa cũ cho người dùng chưa triển khai; xem Rủi ro còn lại và ngoài phạm vi. |

## Định dạng token

- Tiền tố: `xns_`.
- Thân: 12 ký tự base32 thường cho mã khóa, rồi `_`, rồi 43 ký tự base64url
  không đệm mang 32 byte ngẫu nhiên.
- Tổng độ dài: đúng 60 ký tự. Chữ hoa, khoảng trắng, sai độ dài, sai bảng
  ký tự và đầu vào `xnv_` đều bị từ chối.
- Sinh khóa dùng CSPRNG của nền tảng. Bí mật chỉ được tiết lộ một lần qua
  `IssuedSiteKey::reveal()`; lần hai, clone, serialize hoặc debug không được
  làm lộ.
- Xác minh bằng HMAC-SHA256 với pepper ngẫu nhiên riêng và so sánh thời gian
  hằng. Hash, pepper và token thô không vào log, audit, phản hồi đọc REST
  hay thông báo lỗi.
- `SiteTokenCodec::parse()` trả về dạng `ParsedToken` hiện có để các phiếu
  sau không tự tạo hợp đồng token thứ hai.
- Định dạng token cá nhân `xnv_` không đổi.

## Đối tượng giá trị miền

`SiteCredential` là đối tượng bất biến với `id` và `blog_id` dương, `KeyId`,
`name` từ 1 đến 100 ký tự, `SiteScope`, `created_at` UTC, `revoked_at`,
`created_by`, `revoked_by`, `last_used_at` và `last_used_ip` có thể rỗng. Nó
không có thuộc tính `user_id` hay chủ sở hữu.

`SiteScope` là đối tượng bất biến gồm:

- `profile = 'editorial-v1'` duy nhất;
- `toolsets` đã chuẩn hóa, duy nhất, bắt buộc có `system`;
- `allowed_abilities` không rỗng, đã sắp xếp, duy nhất; danh sách rỗng ném lỗi
  và không bao giờ là ký tự đại diện;
- `denied_abilities` duy nhất;
- `permissions` chỉ gồm `ban-users` và `unban-users`, mặc định rỗng;
- tối đa 100 mục CIDR trong `ip_allowlist`;
- `tier_ceiling` rõ ràng; và
- `expires_at` UTC bắt buộc.

Quyền kiểm duyệt đòi hỏi trần quản trị, toolset `users`, tên
`xnetvn/set-user-status` trong danh sách cho phép và không có trong danh sách
từ chối. `is_narrower_than()` chỉ chấp nhận giữ nguyên profile, tập con
toolsets, tập con khác rỗng của năng lực được phép, tập cha của năng lực bị
từ chối, tập con quyền, CIDR nằm trong, trần không cao hơn và hạn không muộn
hơn. `to_array()` và `from_array()` chặt và đóng an toàn khi khóa lạ hoặc giá
trị hỏng.

`SiteAuthContext` gắn khóa, mã yêu cầu không rỗng, IP client và loại danh
tính cố định `site`. Nó cung cấp `blog_id()`, `credential_ref()`
(`xns:<key_id>`), `has_permission()` và `__debugInfo()` an toàn không có bí
mật.

`SiteRequestContext` thêm `site_current()` bên cạnh `current()` của người. Nó
cũng cung cấp `has_conflicting_principals()`; xung đột bị từ chối.

## Lưu trữ và schema

Khóa website nằm trong `{prefix}xnetvn_agent_hub_wp_site_keys`, không nằm
trong bảng khóa cá nhân và không có cột `user_id`:

| Cột | Kiểu và quy tắc |
|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT`, khóa chính |
| `key_id` | `VARCHAR(12)`, duy nhất |
| `blog_id` | `BIGINT UNSIGNED`, đánh chỉ mục cùng trạng thái và hạn |
| `name` | `VARCHAR(100)` |
| `secret_hash` | `VARCHAR(64)` |
| `pepper_id` | `INT UNSIGNED` |
| `scope_json` | `LONGTEXT`, giải mã chặt |
| `expires_at` | `DATETIME`, UTC |
| `created_at` | `DATETIME`, UTC |
| `created_by` | `BIGINT UNSIGNED NULL` |
| `revoked_at` | `DATETIME NULL` |
| `revoked_by` | `BIGINT UNSIGNED NULL` |
| `revoke_reason` | `VARCHAR(64) NULL` |
| `last_used_at` | `DATETIME NULL` |
| `last_used_ip` | `VARCHAR(45) NULL` |
| `last_used_ua` | `VARCHAR(255) NULL` |

`Schema::VERSION` tăng `1 -> 2` cho bảng này và sau đó `2 -> 3` cho cột
audit. Di trú idempotent và không di chuyển hay xóa dòng khóa cá nhân. Mọi
lần đọc và ghi của kho đều giới hạn theo `blog_id` hiện tại, gồm tra cứu
theo khóa chính và mã khóa. `scope_json` hỏng ném lỗi lưu trữ thay vì nhận
mặc định dễ dãi.

`SiteKeyRepository` cung cấp chèn, tìm theo mã khóa, tìm theo ID, danh sách
phân trang, đếm khóa hoạt động, đổi tên/bản vá, thu hồi, cập nhật lần dùng
cuối và xoay nguyên tử. Xoay dùng kết nối cơ sở dữ liệu đã ghim, không phát
lại: lỗi trước commit hoàn tác và giữ hạn cũ; kết quả commit không rõ ném lỗi
lưu trữ có kiểu và không bao giờ báo thành công.

## Vòng đời và hạn mức

`SiteKeyService` là đường cấp khóa duy nhất:

- `ACTIVE_QUOTA = 10` khóa hoạt động mỗi blog.
- `DEFAULT_EXPIRY_DAYS = 90`, giới hạn bởi thiết lập tuổi khóa hiện có.
- `MAX_ROTATE_GRACE_HOURS = 24`, mặc định `24`.
- Danh tính quản lý lấy từ `get_current_user_id()`, kiểm trực tiếp bằng
  `user_can( ..., Capabilities::MANAGE )`, không do người gọi cung cấp.
- `create` và `rotate` cần mật khẩu hiện tại hợp lệ và giới hạn thử lại, tần
  suất hiện có.
- Phạm vi được tạo từ bộ mặc định đã rà soát và chỉ có thể thu hẹp. Quyền
  kiểm duyệt còn cần chủ dự án bật mức quản trị trong thiết lập site và trần
  quản trị.
- Đếm khóa hoạt động và create, patch, rotate, revoke chạy dưới
  `SiteKeyLock` (`xnetvn_ahwp_sk_<blog_id>`), luôn nhả trong `finally`.
- Khi đủ hạn mức, create và rotate trả `409` với lỗi giới hạn khóa. Xoay
  không tự thu hồi khóa cũ để lấy chỗ.
- Patch chỉ nhận thay đổi thu hẹp; từ chối trường mật khẩu mới, quyền mới,
  toolset thêm, danh sách cho phép bị xóa, trần cao hơn hoặc hạn muộn hơn.
- Thu hồi một chiều và ghi người quản lý cùng lý do. Xóa người tạo sau đó
  không làm mất hiệu lực khóa.

Bề mặt REST chỉ trả `201` khi tạo và xoay, `200` khi đọc và patch, dạng xóa
hiện có khi thu hồi, và `409` khi quá hạn mức. Bí mật chỉ xuất hiện trong
phản hồi cấp và xoay.

## Xác thực và ngữ cảnh yêu cầu

`SiteAuthenticator::authenticate()` chạy theo thứ tự trước khi gắn ngữ cảnh:

1. MCP site được bật; HTTPS; Origin được phép.
2. Hạn mức tần suất theo IP và theo site/khóa.
3. Trạng thái hoạt động: chưa thu hồi và chưa hết hạn.
4. Giao danh sách IP cho phép của site và khóa.
5. Xác minh bí mật thời gian hằng.
6. HMAC giả khi không biết mã khóa, để thời gian không tiết lộ tồn tại.

Lỗi dùng dạng an toàn hiện có `401`, `403`, `429` và `503`.

Trên đúng tuyến MCP, `Authenticator` đọc header bearer thô. Token `xns_` chỉ
được định tuyến sang bộ xác thực site và gắn ngữ cảnh site; `determine_user`
trả `0`. Token `xnv_` đi đường người hiện có, nay có kiểm tra tài khoản được
bảo vệ. Header lặp, thông tin trong query hoặc body và token quá lớn bị từ
chối trước khi đọc kho. `rest_result()` đóng an toàn khi xung đột danh tính,
yêu cầu người dùng hiện tại vẫn là `0` với yêu cầu site, giữ lỗi bảo mật
trước đó và giữ `Cache-Control: no-store`. Token site không được chấp nhận
trên tuyến quản lý `/wp-json/xnetvn-agent-hub-wp/v1/*` hay tuyến lõi
`/wp/v2/*`.

`ServerGateway` nhận ngữ cảnh người đã xác minh hoặc ngữ cảnh site đã xác
minh. Đường site cần `get_current_blog_id() === blog_id()` và
`get_current_user_id() === 0`. `scope_hint()` có thể trả `SiteScope` cho token
`xns_`, nhưng gợi ý không cấp quyền. `tool_names()` chuyển `SiteScope` sang
`Registry::names_for_site_scope()`. Chỉ gateway được phụ thuộc lớp adapter.

## Chính sách và phong bì an toàn

`SafeMcpEnvelope` là danh sách cho phép tĩnh do mã nguồn sở hữu. Nó cho phép
các thao tác biên tập và hệ thống đã rà soát và hai thao tác hồ sơ cá nhân
chỉ với danh tính người. Ở tầng phong bì, nó cũng cho phép
`xnetvn/set-user-status` với danh tính site; quyền kiểm duyệt riêng được
`SitePolicy` và handler thực thi. Nó từ chối tên lạ, bên thứ ba, fixture và
quản trị người dùng chung. Không thiết lập, filter, mức khóa hay hook nào
được mở rộng nó; tiện ích chỉ có thể từ chối thêm.

Bộ biên tập và hệ thống đã rà soát gồm 51 thao tác: System 5, Content 19 cộng
`xnetvn/list-content-authors`, Taxonomy 9, Media 7 và Comments 10. Các thao
tác người dùng khác không thuộc bộ này.

`SafeEditorialHtml` chạy ghi MCP trong phạm vi KSES tái nhập, gỡ
`unfiltered_html`, chụp và chỉ thêm callback KSES chưa có trên
`content_save_pre`, `content_filtered_save_pre`, `excerpt_save_pre`,
`title_save_pre` và `pre_comment_content`, rồi khôi phục đúng hook cũ trong
`finally`. Nó không bao giờ gọi `kses_remove_filters()` mù. Trường văn bản
thuần bị bóc thẻ; nội dung giàu được lọc như HTML bài viết.

`SitePolicy::evaluate()` kiểm phong bì trước, rồi profile, toolsets, tên được
phép và bị từ chối rõ ràng, thiết lập site, trần mức và quyền kiểm duyệt.
Filter mở rộng chỉ từ chối được biến cho phép thành từ chối.
`SiteDispatcher` đăng ký handler máy do server sở hữu, đóng băng sau đăng ký,
kiểm lại schema và chính sách mỗi lần gọi, áp hạn mức công việc đắt dưới
`site:<blog_id>:<key_id>` và đưa kết quả qua đường ống đầu ra hiện có. Nó
phân giải handler đã đăng ký rõ ràng theo đúng tên chuẩn; ngoài ra, với đúng
tên chuẩn `xnetvn/list-content-authors`, nó phân giải một handler dựng sẵn
cố định do mã sở hữu khi danh mục đã gắn chứa đúng định nghĩa đó. Handler
dựng sẵn không đi qua `register()`, không bao giờ là lớp hay callback động,
và đầu vào không cung cấp handler, lớp hay callback; cùng cách phân giải điều
khiển `has_handler()` và `execute()`, nó chỉ chạy sau `SitePolicy`, và
`register()` từ chối tên dành riêng của nó như một bản trùng. Cùng cách phân
giải áp cho chín tên taxonomy đã rà soát; chúng là handler dựng sẵn cố định
do mã sở hữu và không đi qua `register()`; mọi thao tác khác vẫn chỉ chạy
qua handler đã đăng ký rõ ràng. Handler thiếu không phân
biệt được với không khả dụng và không bao giờ rơi về closure người.

`Registry::names_for_site_scope()` chỉ trả tên có handler máy đã đăng ký, qua
phong bì và được phạm vi cùng thiết lập site cho phép. Khám phá và
`execute-ability` dùng cùng đường, nên thao tác ẩn không thể chạy theo tên.
`execute-ability` giữ mức đọc nhưng mức, phạm vi và quyền của đích vẫn áp
dụng.

## Ma trận thao tác biên tập

Danh mục có 63 định nghĩa chuẩn. Bề mặt máy có giới hạn; không phải toàn bộ
bề mặt người.

| Miền | Số lượng | Thẩm quyền máy |
|---|---|---|
| System | 5 | Phiên, thông tin site, khám phá, thông tin năng lực và `execute-ability` theo chính sách; phiên site báo `user = null` và `principal.type = site`. |
| Content | 20 | 19 công cụ nội dung hiện có cộng `xnetvn/list-content-authors`; kiểm đối tượng, loại, trạng thái và meta; tác giả rõ ràng khi tạo; KSES; thuật ngữ đã kiểm; biên lai lỗi một phần. |
| Taxonomy | 9 | Bản đồ năng lực biên tập cố định, kiểm resolver và thứ bậc, gán ID số nguyên, KSES. |
| Media | 7 | Tác giả bắt buộc khi máy tải lên, lưu qua API tải lõi với `user_id = 0`, chính sách tải, tải từ xa an toàn, KSES. |
| Comments | 10 | Kiểm bài, cha, loại và trạng thái, KSES bắt buộc, bình luận máy với `user_id = 0` và `comment_author = WordPress MCP`. |
| Users: hồ sơ cá nhân | 2 | Chỉ người; máy không truy cập. |
| Users: kiểm duyệt | 1 | Chỉ `xnetvn/set-user-status`, với quyền cấm và bỏ cấm riêng và bảo vệ toàn mạng. |
| Users: quản trị khác | 9 | Vĩnh viễn không khả dụng cho MCP: tạo, sửa, vai trò, liệt kê, lấy, vai trò, năng lực, đặt lại, kết thúc phiên. |

## Nội dung và phân loại

Tạo nội dung máy cần tác giả đủ điều kiện rõ ràng;
`EditorialAuthors::require_eligible()` đọc lại người dùng, kiểm thành viên
blog hiện tại, `edit_posts`, không bị cấm và đủ điều kiện không được bảo vệ.
`list-content-authors` chỉ trả `{id, name}`, quét tối đa 1.000 ứng viên và
đóng an toàn bằng `503` thay vì cắt lặng. Sửa thường giữ tác giả trước.
Nhân bản, khôi phục bản sửa, ghi meta và ghi hàng loạt nằm trong biên kiểm
đối tượng và KSES; thay đổi một phần trả biên lai rõ ràng.

Ghi phân loại dùng bản đồ năng lực biên tập cố định, resolver đối tượng và
thứ bậc, quy tắc thuật ngữ mặc định, ID số nguyên chặt và cùng biên KSES cho
người và máy. Thuật ngữ lạ và taxonomy không khớp bị từ chối trước khi ghi.

## Phương tiện

Tải lên bằng máy cần tác giả đủ điều kiện rõ ràng và lưu qua API tải lõi,
không ghi trực tiếp hệ thống tệp. Danh tính thực thi vẫn là `user_id = 0`;
ghi công không trở thành thẩm quyền thực thi. Tải lên của người giữ schema
tác giả tùy chọn hiện có và từ chối ghi đè kiểu máy. Chính sách tải, biên
MIME và kích thước, bộ tải từ xa an toàn và dọn tệp tạm vẫn hiệu lực. Chú
thích, mô tả, tiêu đề và văn bản thay thế đi qua biên HTML cho cả hai danh
tính.

## Bình luận

Tạo và sửa bình luận thực thi đúng quy tắc bài, cha, loại và trạng thái, buộc
KSES trên nội dung và trường tác giả, và dùng `user_id = 0` với
`comment_author = WordPress MCP` cho yêu cầu máy. IP riêng và user agent
không bị lộ. Hành động kiểm duyệt vẫn là thao tác biên tập có giới hạn, không
trở thành quản trị bình luận chung.

## Người dùng và kiểm duyệt

Thao tác thay đổi người dùng bằng máy duy nhất là `xnetvn/set-user-status`:

- `ban-users` cần cho `banned`; `unban-users` cần cho `active`; không quyền
  nào ngụ ý quyền kia.
- `restore_role` bị từ chối trong mọi lệnh máy. Bỏ cấm chỉ khôi phục
  `BanSnapshot` hợp lệ (`v1` hoặc `v2`), không có vai trò hay năng lực trực
  tiếp đặc quyền.
- Đích phải tồn tại, là thành viên blog hiện tại và qua
  `NetworkUserProtection` cả trước thao tác lẫn ngay trước lần ghi vai trò
  cuối. Tài khoản Administrator và tương đương được bảo vệ tại mọi site
  trong mạng; phạm vi quét có giới hạn và đóng an toàn trên 1.000 site.
- Cấm gán vai trò `banned` không năng lực, xóa năng lực trực tiếp và gọi
  `WP_Session_Tokens::destroy_all()` toàn người dùng.
- Ghi một phần giữ ảnh chụp và trả biên lai lỗi một phần; không tự thử lại mù
  và không báo hoàn tác sạch.
- Lệnh cấm bị từ chối với `xnetvn_agent_hub_wp_invalid_state` trước mọi lần
  ghi ảnh chụp, vai trò, năng lực hay phiên khi điểm khôi phục mà lệnh sẽ để
  lại không dùng được: danh sách vai trò rỗng, có vai trò không thể gán, hoặc
  có năng lực trực tiếp đặc quyền được ghi.
- Lệnh cấm lặp lại dùng lại ảnh chụp đã lưu, xóa năng lực trực tiếp còn sót
  và hủy toàn bộ phiên không thể hoàn tác, trong khi byte ảnh chụp đã lưu giữ
  nguyên.
- Lệnh bỏ cấm thành công đòi hỏi bản đồ năng lực trực tiếp chính xác sau khi
  bỏ khóa vai trò. Một năng lực trực tiếp đang sống mà ảnh chụp không nêu tên
  trả `xnetvn_agent_hub_wp_partial_failure` và giữ ảnh chụp, thay vì trả biên
  lai thành công xóa điểm khôi phục duy nhất.
- `xnetvn_agent_hub_wp_internal_error` vẫn là lỗi.
- Chặn đăng nhập bằng mật khẩu, thu hồi Application Password và chặn đặt lại
  mật khẩu nằm ngoài phạm vi theo `OD-04`.

## Audit và hoạt động

Dòng audit có thêm `principal_type` (`user` hoặc `site`), `blog_id` và
`credential_ref` (`xnv:<key_id>` hoặc `xns:<key_id>`). Với dòng site,
`user_id` và cột `key_id` cũ là `NULL`; tham chiếu máy chỉ nằm trong
`credential_ref`. Dòng người giữ `key_id` hiện có. Giá trị tham số không bao
giờ được ghi log, và cả hai tiền tố đều bị bóc. Kho chỉ hiển thị đúng tám cột
danh sách: `occurred_at`, `user_id`, `ability`, `outcome`, `transport`,
`principal_type`, `blog_id`, `credential_ref`.

Truy vấn REST audit nhận `principal_type` và mẫu `credential_ref` chặt, luôn
gắn blog hiện tại và cần năng lực quản lý cho truy vấn máy, chéo người dùng
hoặc `all`. Năm tổ hợp tham số xung đột trả `400 rest_invalid_param`; `key_id`
trần vẫn chỉ dành cho người. Phản hồi ép kiểu số, không có bí mật hay tham
số, và giữ `Cache-Control: no-store`.

Màn hình Hoạt động giữ hợp đồng năm cột, hiển thị `Website MCP key` và
`Not applicable` cho dòng máy, không tạo người dùng WordPress giả. Quản lý
khóa và hoạt động vẫn là thao tác cookie và nonce; bearer không thể quản lý
khóa.

## API quản lý REST

Các tuyến `/xnetvn-agent-hub-wp/v1/site-keys` chỉ dùng cookie và nonce và cần
`Capabilities::MANAGE`:

| Tuyến | Hợp đồng |
|---|---|
| `GET /site-keys` | Phân trang, trạng thái tùy chọn, không tham số chéo blog hay mã người dùng, `{items,page,per_page}`; `page` và `per_page` phản chiếu yêu cầu. |
| `POST /site-keys` | Tên, toolsets và danh sách năng lực tùy chọn, quyền, CIDR, hạn, mật khẩu hiện tại bắt buộc; `201` kèm view và bí mật một lần. |
| `GET /site-keys/{id}` | Chỉ view site, không bao giờ có bí mật. |
| `PATCH /site-keys/{id}` | Chỉ trường thu hẹp; mở rộng là `409`. |
| `DELETE /site-keys/{id}` | Thu hồi với dạng xóa hiện có. |
| `POST /site-keys/{id}/rotate` | Mật khẩu hiện tại bắt buộc, ân hạn tùy chọn 0 đến 24 giờ, `201` kèm view và bí mật một lần. |

Tổng số cố ý không được công bố trong phần này: phản hồi danh sách không có
`total`, `total_pages` hay `X-WP-Total`, và `page` cùng `per_page` là siêu dữ
liệu phân trang duy nhất.

Ma trận hiện có vẫn chi phối: tại cổng quản lý, `400` tham số không hợp lệ, `401` thiếu cookie,
`403` nonce, năng lực hoặc mật khẩu hiện tại bị từ chối, `409` mở rộng, hạn
mức đang hoạt động hoặc xoay chưa giải quyết, `429` ngân sách thử lại mật
khẩu đã cạn và `503` lỗi lưu trữ hoặc khóa; `404` theo từng tuyến và ma trận
lỗi chung vẫn áp dụng.

View gồm danh tính, phạm vi, mốc thời gian, ghi nhận quản lý và siêu dữ liệu
lần dùng cuối, không bao giờ có `secret`, `secret_hash`, `pepper_id` hay
`user_id`. Trường lạ hoặc do người gọi tự khai bị từ chối. Schema và hành vi
`/keys` hiện có cho khóa cá nhân không đổi.

## Giao diện quản trị

Trang Khóa có khu `WordPress MCP API Keys` riêng cho khóa website, với tạo,
hiện một lần, thu hẹp, xoay, thu hồi và liệt kê. Nó hiển thị phạm vi, quyền,
hạn và lần dùng cuối, không hiển thị bí mật sau phản hồi cấp. Khu Hoạt động
giữ năm cột và gắn nhãn tham chiếu khóa site cho dòng máy, không tạo người
dùng giả. Mọi hành động dùng cookie, nonce mới, kiểm năng lực trực tiếp và
đường ống tài nguyên quản trị hiện có.

## Quyền riêng tư, thông báo và vòng đời

Bản ghi khóa site là dữ liệu của site. Xuất và xóa theo chính sách quyền
riêng tư xử lý chúng mà không tạo email chủ sở hữu hay người dùng WordPress
giả. Dọn vòng đời chỉ xóa dòng khóa đã thu hồi hoặc hết hạn và siêu dữ liệu
của chúng; gỡ cài đặt xóa bảng và tùy chọn theo hợp đồng hiện có. Đóng gói
gồm lớp mới, di trú và tài nguyên, loại trừ bằng chứng cục bộ và bí mật. Vô
hiệu hóa không âm thầm cấp hay mở rộng khóa.

## Lỗi và giới hạn

Các hợp đồng hiện có giữ nguyên: `401` từ chối khóa, `403` bị cấm, `409`
xung đột hạn mức hoặc trạng thái, `429` vượt tần suất, `503` không khả dụng,
`400` tham số không hợp lệ chặt, biên lai lỗi một phần cho thay đổi con đã
ghi, và `Cache-Control: no-store` trên phản hồi được bảo vệ. Danh sách khóa,
CIDR, kích thước trang, quét ứng viên, kích thước tải, dòng audit và nhóm
tần suất đều có giới hạn. Thông báo lỗi không lộ token, hash, pepper, dữ liệu
riêng hay sự tồn tại tài khoản ngoài hợp đồng an toàn.

## Cửa sổ tích hợp CI

`FR_13` thêm năng lực thứ 63. Kiểm thử tích hợp đầy đủ đang khẳng định cứng
số `62`, và kỳ vọng `content => 19` theo gói, được dự kiến sẽ đỏ giữa `FR_13`
và `ISSUE_08`. Đây là cửa sổ chuyển tiếp có kế hoạch, không phải lý do hạ
assertion. Danh mục, gói kỹ năng và ma trận vai trò được cập nhật nguyên tử
trong `ISSUE_07` và `ISSUE_08`.

## Luật gốc L-01..L-20

Định nghĩa chuẩn của các luật gốc `L-01` đến `L-20` không có đủ trong
checkout này. Chúng được ghi `UNVERIFIABLE` cho đến khi chủ dự án khôi phục
nguồn hoặc miễn trừ rõ ràng. Không nghiệm thu hay trả lời thư mục nào được
tuyên bố đã xác minh chúng.

| Luật | Trạng thái |
|---|---|
| L-01 | UNVERIFIABLE |
| L-02 | UNVERIFIABLE |
| L-03 | UNVERIFIABLE |
| L-04 | UNVERIFIABLE |
| L-05 | UNVERIFIABLE |
| L-06 | UNVERIFIABLE |
| L-07 | UNVERIFIABLE |
| L-08 | UNVERIFIABLE |
| L-09 | UNVERIFIABLE |
| L-10 | UNVERIFIABLE |
| L-11 | UNVERIFIABLE |
| L-12 | UNVERIFIABLE |
| L-13 | UNVERIFIABLE |
| L-14 | UNVERIFIABLE |
| L-15 | UNVERIFIABLE |
| L-16 | UNVERIFIABLE |
| L-17 | UNVERIFIABLE |
| L-18 | UNVERIFIABLE |
| L-19 | UNVERIFIABLE |
| L-20 | UNVERIFIABLE |

## Bản đồ phiếu

Tệp phiếu chuẩn nằm dưới `.local/docs/en/issues/v1.0/`; chỉ mục điều phối
ghi đường dẫn đầy đủ và phụ thuộc. Mỗi phiếu dưới đây truy về `OD-01` đến
`OD-08` và hợp đồng này.

| Đợt | Phiếu | Trọng tâm |
|---|---|---|
| W00 | ISSUE_01 | Hợp đồng này và ADR-0020 |
| W01 | FR_01 | Loại tài khoản được bảo vệ khỏi khóa cá nhân |
| W01 | FR_02 | Phong bì an toàn tĩnh trên mọi đường thực thi |
| W01 | FR_04 | Đối tượng khóa, phạm vi và ngữ cảnh site |
| W01 | FR_20 | Bảo vệ quản trị toàn mạng |
| W02 | FR_03 | Biên KSES cho nội dung và bình luận |
| W02 | FR_06 | Bộ mã token site và wrapper riêng |
| W02 | FR_08 | Ngữ cảnh yêu cầu máy và bóc dữ liệu |
| W02 | FR_21 | Ghi nhận ảnh chụp cấm |
| W02 | FR_31 | Loại tài khoản được bảo vệ trong xác thực bearer |
| W03 | FR_05 | Lưu khóa theo site và schema phiên bản 2 |
| W03 | FR_26 | Biên HTML hồ sơ |
| W04 | FR_07 | Vòng đời khóa site có giới hạn và khóa |
| W05 | FR_09 | Xác thực site không thừa hưởng cookie |
| W06 | FR_12 | Chính sách site và dispatcher |
| W07 | FR_13 | Chọn tác giả biên tập và năng lực 63 |
| W07 | FR_27 | Gateway server nhận máy |
| W08 | FR_10 | Tuyến REST quản lý khóa site |
| W08 | FR_15 | Thao tác phân loại máy |
| W08 | FR_16 | Schema tác giả phương tiện |
| W08 | FR_28 | Schema tác giả nội dung |
| W08 | ISSUE_07 | Tài liệu danh mục và bộ kiểm gói kỹ năng ở 63 |
| W09 | FR_11 | Khóa site trong trang quản trị |
| W09 | FR_14 | Thao tác nội dung máy |
| W09 | ISSUE_08 | Ma trận vai trò danh mục ở 63 |
| W10 | FR_17 | Thao tác phương tiện máy |
| W11 | FR_18 | Quy trình bình luận máy |
| W12 | FR_19 | Danh tính máy trong phiên và khám phá |
| W13 | FR_22 | Quyền cấm và bỏ cấm máy riêng |
| W14 | FR_23 | Audit danh tính, blog và tham chiếu khóa |
| W14 | ISSUE_12 | Đặc tả tích hợp và ma trận phong bì |
| W15 | FR_24 | Quyền riêng tư và thông báo khóa site |
| W15 | FR_29 | Truy vấn hoạt động theo danh tính |
| W15 | ISSUE_13 | Ma trận HTTP và fixture client thật |
| W16 | FR_25 | Dọn vòng đời và đóng gói phát hành |
| W16 | FR_30 | Hiển thị hoạt động thuộc site |
| W17 | ISSUE_02 | Ranh giới kiến trúc và rủi ro còn lại |
| W17 | ISSUE_11 | Đặc tả E2E giao diện trình duyệt |
| W17 | ISSUE_14 | SVG, mẫu dịch và ảnh chụp danh sách |
| W18 | ISSUE_03 | Hợp đồng API khóa và thiết lập |
| W18 | ISSUE_15 | Tài liệu kiến trúc và hiệu năng |
| W19 | ISSUE_04 | Hướng dẫn người dùng và tham chiếu kỹ năng |
| W20 | ISSUE_09 | Hướng dẫn, vận hành và README |
| W21 | ISSUE_10 | Chỉ mục tài liệu, chuẩn UI và quét bí mật |
| W22 | ISSUE_16 | Đóng gói, kiểm unit audit và chỉ mục tài liệu |
| W23 | ISSUE_05 | Nghiệm thu bảo mật trên lab dùng chung |
| W24 | ISSUE_06 | Phản hồi thư mục dựa trên sự thật |

## Rủi ro còn lại và ngoài phạm vi

- `RequestsDownloadTransport.php:73-126` là đối tượng quyết định của chủ dự
  án: giữ transport đã rà soát và ghi rõ kiểm soát, hoặc gia cố trước. Nhánh
  `feature/http-api-transport` đang treo không thuộc hợp đồng này.
- Lab dùng chung bị chặn ngày 2026-11-16. Không thể tuyên bố nghiệm thu
  runtime cho đến khi lab được khôi phục; kiểm cục bộ không thay thế.
- Các luật gốc vẫn `UNVERIFIABLE`.
- WordPress.org chấp thuận thư mục, CI hosted, production, phát hành và gửi
  thư mục không được tài liệu này tuyên bố.
- Chuyển đổi khóa cũ, thu hồi hàng loạt và di trú khóa đã triển khai bị loại
  vì chưa có người dùng đã triển khai.
- Chặn đăng nhập, thu hồi Application Password và chặn đặt lại mật khẩu nằm
  ngoài quyết định cấm.
- Quản trị người dùng chung và tuyến REST lõi không mở cho khóa site.

## Kiểm chứng

| Cổng | Nơi | Kỳ vọng |
|---|---|---|
| PHPCS và cú pháp bootstrap | Cục bộ | Thoát 0 |
| PHPStan mức 8 | Cục bộ | Không lỗi, không baseline |
| Bộ unit | Cục bộ | Tất cả qua |
| Tương đương tài liệu và thiết lập | Cục bộ | `composer check:docs` thoát 0 |
| Đóng gói | Cục bộ | Audit ZIP thoát 0 |
| Tích hợp 6.9 và 7.1.2 | Lab dùng chung | Tất cả qua |
| Multisite và cách ly mạng | Lab dùng chung | Tất cả qua |
| Ma trận HTTP và client thật | Lab dùng chung | Tất cả qua |
| E2E trình duyệt | Lab dùng chung | Tất cả qua |
| Nghiệm thu bảo mật | Lab dùng chung | PASS có bằng chứng |

Mọi thay đổi hành vi bắt đầu bằng kiểm thử đỏ. Mọi phiếu T3/vùng đỏ cần rà
soát độc lập trước `Done`. Lab bị chặn, câu trả lời chủ dự án thiếu hoặc điều
kiện tiên quyết không có đều được báo là bị chặn, không bao giờ là PASS.

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
- Hướng dẫn thư mục:
  https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
  (kiểm 2026-11-17).
- FAQ nhà phát triển plugin:
  https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
  (kiểm 2026-11-17).
- Nội bộ: [ADR-0020](../adr/0020-site-machine-principals.md),
  [giao năng lực](../adr/0005-capability-intersection.md),
  [chính sách người dùng](../adr/0018-users-policy.md),
  [danh mục năng lực](../architecture/ability-catalog.md),
  [tổng quan kiến trúc](../architecture/overview.md),
  [mô hình mối đe dọa](../security/threat-model.md),
  [kiểm thử](../TESTING.md), [môi trường](../ENVIRONMENT.md) và
  [vận hành](../operations.md).
