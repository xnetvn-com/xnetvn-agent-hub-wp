# Bằng chứng tương thích MCP Adapter đã cài

Đã xác minh với MCP Adapter chính thức 0.7.0 cài riêng trên WordPress 6.9 ngày 2026-10-04. Ability riêng tư/máy chủ tùy chỉnh tạm thời chỉ dùng cho khảo sát và đã xóa sau đó. Tầng truyền tải HTTP của nó từ chối mọi yêu cầu; không quyết định bảo mật nguyên mẫu nào là một phần của sản phẩm.

| Câu hỏi | Bằng chứng / kết quả hiện tại | Trạng thái nghiệm thu |
|---|---|---|
| U1: ability riêng tư | Ability đăng ký bỏ public/show_in_rest được chấp nhận khi đăng ký công cụ máy chủ tùy chỉnh rõ ràng; số máy chủ tùy chỉnh=1; thực thi trả kết quả ok cố định; công khai qua máy chủ mặc định=false | Đã xác minh runtime |
| U2: phiên bản runtime | WP_MCP_VERSION và Core McpAdapter::VERSION đều trả 0.7.0 | Đã xác minh runtime |
| U3: hook lõi mới hơn | Nguồn lớp chính thức 6.9 và 7.0.6 thiếu hook permission-result/pre-execute/invoked; nguồn WP7.1.2 đã cài ghi và triển khai chúng từ 7.1.0 | Đã xác minh nguồn theo phiên bản |
| U4: khám phá sau 401 chỉ có realm | Inspector 2.9.0 với header Bearer rõ ràng nhận 401 chỉ có realm và thử OAuth tương tác; chạy không TTY trả auth_required. Cấu hình header tĩnh không tắt cơ chế dự phòng 401. Ghi rõ stored-auth-only cho phép thử phủ định không giám sát và xác minh thành công với khóa hợp lệ riêng | Đã xác minh hành vi client runtime; cửa khóa sản phẩm hợp lệ đang chờ |
| U5: tuyến / cookie cache | Khảo sát HTTP thực tế tuyến pretty/query đạt ở bản cài root và WordPress 6.9 thư mục con cài độc lập, gồm cấu hình permalink Plain thực tế. Cookie đăng nhập hợp lệ được cache tại plugins_loaded ghi user1 khi REST_REQUEST=false, sau đó REST auth thấy user1 mà không có thêm determine_current_user. Cần xác thực lại muộn | Đã xác minh runtime; vẫn cần kiểm thử xác thực sản phẩm |
| U6: ngân sách liệt kê | Payload tổng hợp 20/60/120 công cụ đo 8,931/26,771/53,531 byte; byte/4 chỉ là ước tính thô, không đo bằng tokenizer. Mục tiêu liệt kê trực tiếp đề xuất ~60 công cụ; còn cần đo payload/hiệu năng pack thực tế | Đã xác minh phép đo tổng hợp; cửa pack thực tế đang chờ |
| U7: header client thực tế | Cú pháp header CLI Inspector 2.9 đã xác minh với 401 chỉ có realm; client STDIO liệt kê và gọi công cụ riêng tư thành công. Đối số tiến trình con CLI đứng trước -- và option Inspector theo sau. Ví dụ client khác cần xác minh độc lập với nguồn/runtime hiện tại | Inspector đã xác minh; client khác đang chờ |
| U10: header phản hồi lỗi auth | HTTP 401 thực tế trả từ xác thực REST có WWW-Authenticate Bearer realm và no-store sau rest_post_dispatch, cho trường hợp pretty/plain và cookie cache | Đã xác minh runtime |

Lõi WordPress chèn show_in_rest=false vào metadata hiệu lực. Vì vậy L-01 nghĩa là đối số đăng ký của plugin không bao giờ đặt public/show_in_rest và khả năng công khai hiệu lực vẫn false; kiểm thử yêu cầu thuộc tính vắng mặt trong metadata lõi đã chuẩn hóa sẽ kiểm tra sai ranh giới.

## Chữ ký API đã cài
- create_server(server_id, server_route_namespace, server_route, server_name, server_description, server_version, mcp_transports, error_handler, observability_handler=null, tools=[], resources=[], prompts=[], transport_permission_callback=null) trả adapter hoặc WP_Error. Công cụ rõ ràng chấp nhận tên ability hoặc đối tượng McpTool.
- mcp_adapter_init(adapter) phát một lần. Trong WP-CLI, khởi tạo tại init mức ưu tiên 20; HTTP khởi tạo tại rest_api_init mức ưu tiên 15.
- mcp_adapter_tools_list(tools, server, schema): tools là bản ghi schema Tool có getName(), không phải mảng thuần.
- mcp_adapter_pre_tool_call(args, tool_name, mcp_tool, server): trả WP_Error dừng sớm lời gọi.
- mcp_adapter_tool_call_result(result, args, tool_name, mcp_tool, server): result là đầu ra ability thô; adapter lắp ghép văn bản JSON và structuredContent sau đó. Không trả envelope giao thức đã lắp ghép như kết quả ability thô.
- Lỗi callback quyền tầng truyền tải không phải hợp đồng challenge xác thực. Giữ hành vi 401/header ở lớp xác thực REST WordPress có phạm vi tuyến.

Các chữ ký này được đọc từ bản phát hành đã cài. ZIP phát hành không chứa thư mục tài liệu kho mã; nguồn chính thức theo phiên bản bổ sung cho kiểm tra mã đã cài. Chỉ ServerGateway của sản phẩm được dùng lớp adapter.

## Quyết định đang chờ

Mốc annotation Content/Media, 2026-10-08: tools/list MCP thật qua adapter0.7.0
trên fixture HTTP WordPress6.9 và7.1.2 của lab trả31 tool. Ma trận HTTPS kiểm tra
openWorldHint cho đủ26 tool mới: chỉ upload URL là true;25 tool còn lại là false.
U-ANN-1 đã được xác minh trên các bản đã cài này; không cần xử lý riêng adapter
ngoài ServerGateway. Xem tests/E2E/content-media-matrix.php và
infra/remote/run-content-media.sh.
ADR-0003 được chấp nhận dựa trên đăng ký riêng tư 0.7.0 đã cài, nguồn theo phiên bản, khảo sát tuyến/header HTTP và Inspector STDIO thực tế ở chế độ cũ/hiện đại. HTTP khóa sản phẩm và client bổ sung vẫn là cửa nghiệm thu M1 riêng. Adapter hiện có ngoài khoảng đã xác minh phải để plugin tải được trong khi đóng khởi động MCP. Mu-plugin tạm ở root/thư mục con đã xóa; cấu hình thư mục con dùng một lần đã được loại khỏi web root được phục vụ.

Nguồn chính thức:
- https://wordpress.org/plugins/mcp-adapter/
- https://downloads.wordpress.org/plugin/mcp-adapter.0.7.0.zip
- https://github.com/WordPress/WordPress/blob/7.0.6/wp-includes/abilities-api/class-wp-ability.php
- https://developer.wordpress.org/apis/abilities-api/
