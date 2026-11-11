# Kiến trúc và các ranh giới bất biến

Sản phẩm là plugin WordPress cung cấp các Ability riêng tư qua MCP Adapter chính thức. Namespace PHP: `XNetVN\AgentHubWP`; thư mục gốc PSR-4: `src/`; text domain/slug/ID máy chủ: `xnetvn-agent-hub-wp`; namespace ability: `xnetvn/`. Tiền tố hàm, hook và option: `xnetvn_agent_hub_wp_`. Giấy phép: GPL-2.0-or-later. Yêu cầu tối thiểu PHP 8.2 và WordPress 6.9.

Lõi hiện có kiểm tra yêu cầu, kernel provider, lưu trữ theo site, chính sách capability/scope động, xác thực khớp chính xác tuyến, registry riêng tư và năm công cụ hệ thống qua MCP Adapter chính thức. Kiểm toán/quyền riêng tư, quản lý xác thực bằng cookie và giao diện hồ sơ/quản trị đã được triển khai với bằng chứng có phạm vi. Các pack Content và Media đã hiện thực theo sửa đổi 2026-10-08; pack Comments (10 tool, ADR-0016), Taxonomy (9 tool, ADR-0017) và Users (12 tool, ADR-0018) đã hiện thực. Nghiệm thu runtime đầy đủ và OAuth vẫn còn mở. Trạng thái hoàn tất được ghi nhận riêng với các thành phần đã triển khai.

## Vòng đời yêu cầu HTTP
1. Chỉ khớp tuyến `/xnetvn-agent-hub-wp/mcp` đã chuẩn hóa; quản lý dùng `/xnetvn-agent-hub-wp/v1` và nằm ngoài xác thực khóa bearer.
2. Các lớp bảo vệ Origin và TLS từ chối yêu cầu không an toàn trước xác thực. Cho phép thiếu Origin đối với client không phải trình duyệt; từ chối Origin null được chỉ định rõ hoặc origin ngoài danh sách.
3. Xác thực bearer thay thế danh tính cookie và tạo AuthContext riêng cho yêu cầu. Người dùng cookie đã được cache sớm phải được xác thực lại tại mức ưu tiên 80 của xác thực REST. Giữ nguyên mọi lỗi bảo vệ trước đó.
4. Kiểm tra lại hết hạn/thu hồi khóa, sự tồn tại và capability hiện tại của chủ sở hữu, trạng thái bật site và ràng buộc IP. Thực thi giới hạn tần suất theo khóa/IP.
5. Adapter xử lý thương lượng giao thức và tầng truyền tải qua facade ServerGateway duy nhất.
6. Lọc danh sách công cụ và kiểm tra lại chính sách khi gọi cũng như tại callback quyền ability đã được bọc. Điều phối REST lõi vẫn chịu trách nhiệm về quyền theo từng đối tượng.
7. Xác thực schema, thực thi ngân sách đầu ra/tài nguyên và loại bỏ trường nhạy cảm; trả về nội dung có cấu trúc và văn bản JSON. Kiểm toán chỉ ghi tên đối số cấp cao nhất.
8. Trả header no-store/nosniff, challenge 401 và hướng dẫn thử lại 429 khi phù hợp.

STDIO dùng người dùng WordPress được chọn rõ ràng, bỏ qua bảo vệ HTTP nhưng vẫn thực thi capability và chính sách site. Chỉ cho phép thiếu AuthContext đối với STDIO, tuyệt đối không đối với HTTP.

## Quyền hiệu lực
Mọi điều kiện phải đồng thời đạt: capability/quyền đối tượng của lõi; cấp độ ability <= trần khóa và site; toolset đã bật; ràng buộc cho phép/từ chối rõ ràng; cấp admin được bật rõ ràng; context HTTP đã xác thực. Các cấp: read < write < destructive < admin. Trần site mặc định là destructive; admin tắt. Quy tắc capability là động: `use_xnetvn_agent_hub_wp` theo edit_posts trừ khi có ghi đè vai trò; quản lý theo manage_options hoặc quản lý network.

Ability bên thứ ba cần được phê duyệt rõ ràng, có metadata an toàn và cơ chế chặn kiểm tra quyền trên phiên bản WordPress tương thích. Thiếu annotation mặc định là destructive. Bộ lọc cấp độ chỉ được nâng rủi ro; bộ lọc chính sách chỉ được từ chối thêm quyền truy cập. Không âm thầm cung cấp đường đi bên thứ ba bỏ qua registry riêng tư.

Super administrator của multisite giữ quyền sử dụng và quản lý mặc định, nhưng ma trận vai trò site rõ ràng và chính sách hỏng vẫn từ chối sử dụng được ủy quyền. Ranh giới `map_meta_cap` chỉ được từ chối chạy trước cơ chế bỏ qua của lõi dành cho super administrator; cơ chế suy ra động `user_has_cap` thông thường vẫn được giữ. Quyền quản lý/sửa chữa và capability WordPress không liên quan được bảo toàn. Chuyển site đánh giá lại vai trò, cài đặt, bảng khóa và pepper của site đang hoạt động mà không ghi định nghĩa capability vào vai trò.

## Ranh giới các lớp
Admin/REST/CLI gọi service. Service phụ thuộc hợp đồng miền. Lưu trữ và giới hạn tần suất triển khai các hợp đồng. Pack độc lập với xác thực, tầng truyền tải, quản trị và API adapter. Registry là nguồn có thẩm quyền cho metadata ability; không giả định chuyển tiếp tùy ý meta của lõi.

Ưu tiên điều phối nội bộ `rest_do_request()` cho các miền lõi. Tuyệt đối không gọi endpoint REST bằng một yêu cầu HTTP khác và không bỏ qua callback kiểm tra quyền. Chỉ dùng API WordPress trực tiếp khi không có thao tác REST tương ứng.

## Các loại trừ vĩnh viễn
Không thực thi SQL, PHP hoặc shell tùy ý; không có trình sửa tệp, sửa wp-config/.htaccess, ghi tệp ngoài API lõi được cho phép, truy cập bí mật/salt/token/băm mật khẩu, hay công cụ quản lý Application Password/API-key. Không cung cấp cấu hình vô hiệu hóa bảo vệ của plugin này. Không chấp nhận ZIP plugin/theme tùy ý.

## Bề mặt mở rộng công khai
`xnetvn_agent_hub_wp_loaded` nhận kernel đã đăng ký. `xnetvn_agent_hub_wp_taxonomy_allowed` là cổng chỉ-từ-chối cho taxonomy (true để giữ, còn lại để từ chối; không bao giờ mở rộng allowlist của resolver). `xnetvn_agent_hub_wp_user_privileged_caps` chỉ được thêm (không bao giờ bớt mặc định); `xnetvn_agent_hub_wp_user_allowed` chỉ được từ chối (chỉ đúng `true` mới qua, không bao giờ mở rộng vùng bảo vệ); `xnetvn_agent_hub_wp_user_meta_writable` chỉ được thêm với mặc định rỗng (chỉ khóa đã đăng ký show_in_rest, tập cấm cứng luôn áp dụng). Các action vòng đời khóa, bộ lọc chính sách chỉ được từ chối và bộ lọc nâng cấp rủi ro trong tương lai cần kiểm thử, tài liệu và mục changelog. Kiểm tra yêu cầu phải từ chối an toàn trước khi tải PHP hiện đại hoặc mã adapter.

Nguồn chính thức: [Abilities API](https://developer.wordpress.org/apis/abilities-api/), [xác thực REST](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/), [phiên bản MCP](https://modelcontextprotocol.io/specification/versioning), [công cụ MCP](https://modelcontextprotocol.io/specification/2026-07-28/server/tools). Đã xác minh ngày 2026-10-04.

## Bằng chứng bảng chân trị chính sách

64 tổ hợp cấp độ ability/khóa/site khẳng định rủi ro ability <= cả hai trần. Các trường hợp bổ sung độc lập từ chối khi thiếu context HTTP, tầng truyền tải không xác định, site tắt, thiếu capability WordPress, thiếu toolset, hạn chế cho phép/từ chối rõ ràng, admin tắt và descriptor bên thứ ba chưa được phê duyệt. Hook chính sách bổ sung giữ nguyên phép giao cơ sở và có thể từ chối thêm; kết quả hook sai định dạng/lỗi bị từ chối. Hook cấp độ chỉ được nâng rủi ro. Các lớp quyền registry và handler ràng buộc chủ thể hiện tại, đồng thời kiểm tra lại chính sách. Schema đầu ra được xác thực lại sau khi che dữ liệu/áp dụng giới hạn.

## Sanctioned media writes

The permanent file-editing exclusion does not prohibit attachment persistence through core upload APIs into the active site uploads directory. Only bounded validated temporary files and reviewed core upload paths are allowed. No generic filesystem operation is exposed as a tool. Settings have one canonical inventory in [ENVIRONMENT.md](../ENVIRONMENT.md).
