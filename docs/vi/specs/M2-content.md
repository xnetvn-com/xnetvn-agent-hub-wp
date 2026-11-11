# M2 — Nội dung, taxonomy và block

Trạng thái: Content đã được hiện thực theo phê duyệt ngày 2026-10-08; nghiệm thu chạy thật đầy đủ và rà soát bảo mật M1 độc lập vẫn mở. Taxonomy và Blocks vẫn trong kế hoạch. Giữ danh mục 32 tool của milestone; tính năng này chỉ triển khai 19 tool nội dung.

## Thẩm quyền và ranh giới

Dùng registry riêng tư, chủ thể hiện tại, chính sách cấp độ/toolset và pipeline đầu ra cuối của M1. Pack phụ thuộc hợp đồng được tiêm, tuyệt đối không phụ thuộc lớp adapter/auth/admin. Điều phối REST WordPress nội bộ là mặc định; không khởi tạo yêu cầu HTTP đến URL REST của chính site. Mọi điều phối giữ xác thực dữ liệu và callback quyền của lõi. Cấm tuyến, namespace, lớp controller, SQL hoặc trường tùy ý do bên gọi cung cấp.

Chỉ phân giải post type và taxonomy từ đối tượng `show_in_rest` đã đăng ký của site. Suy ra namespace/rest_base controller đã rà soát thay vì giả định chuỗi bên gọi là đường dẫn an toàn. Xác thực ID dương, đối chiếu type thực tế của mục hiện có với type đã chọn và ràng buộc revision với cha thực tế. Từ chối dạng controller không khả dụng hoặc không được hỗ trợ bằng lỗi an toàn.

## S01 — Helper REST nội bộ

`RestBackedAbility` nhận method/route tin cậy và tham số đã xác thực, dựng `WP_REST_Request`, gọi `rest_do_request()` và ánh xạ `WP_Error` hoặc status>=400 thành lỗi thực thi công cụ an toàn. Giữ mã lý do đã rà soát; bỏ SQL, stack trace, dữ liệu yêu cầu thô và chi tiết lỗi provider tùy ý. Không bỏ qua callback quyền của handler.

Hỗ trợ phân trang collection có giới hạn từ header phản hồi. Phép chiếu đầu ra loại `_links`, `_embedded`, mật khẩu và mọi trường ngoài DTO rõ ràng. `fields` có thể thu hẹp đầu ra đã khai báo; trường định danh bắt buộc vẫn còn. Mọi schema đầu ra là đối tượng gốc nghiêm ngặt. Mảng collection logic trong bảng gốc được biểu diễn bằng `{items:[...]}`; thêm phân trang khi có, nhất quán với yêu cầu nội dung có cấu trúc của M1.

Mặc định `content_format=rendered`; định dạng cho phép là raw/rendered/excerpt. Chỉ yêu cầu edit context sau khi capability sửa type tương ứng đạt. Dữ liệu bài viết thô còn cần `edit_post` trên ID cụ thể. Không trả title/excerpt/content thô bằng cách chuyển sang phương án dự phòng sau khi edit request bị từ chối. Không xóa bộ lọc KSES lõi hoặc giả danh chủ thể khác.

## S02 — Pack nội dung

Toolset `content`; khả năng hiển thị mặc định theo `edit_posts`, còn kiểm tra đối tượng/type vẫn có thẩm quyền. Các cấp read/write/destructive dưới đây đã cố định. Schema đầu vào từ chối trường không biết. Bắt buộc page collection >=1, per_page 1..100, bulk ID mặc định 1..50, ID đối tượng dương, chuỗi có giới hạn và dấu thời gian ISO8601. Tạo mặc định draft; bỏ trường khi cập nhật nghĩa là giữ giá trị hiện tại.

| Ability | Cấp độ | Nền tảng và kiểm tra bổ sung | Đầu ra logic |
|---|---|---|---|
| list-post-types | read | GET types; chỉ type có REST | items: slug,name,rest_base,hierarchical,supports |
| list-post-statuses | read | GET statuses | items: slug,name |
| list-posts | read | GET collection của type đã phân giải; bộ lọc status/search/author/categories/tags/date/order/page đã rà soát | items,total,total_pages,page |
| get-post | read | GET type/ID đã phân giải; raw cần quyền sửa đối tượng | post |
| create-post | write | POST collection; status/publish/future, REST meta đã đăng ký, media đại diện và term giữ kiểm tra lõi | post |
| update-post | write | POST item; sửa đối tượng, chuyển trạng thái và chỉ trường đã cung cấp | post |
| trash-post | write | DELETE item không force; ngữ nghĩa thùng rác của lõi | id,status=trash |
| restore-post | write | POST item với draft/publish; xác minh đang trong thùng rác và lõi cho phép chuyển trạng thái | post |
| delete-post | destructive | DELETE item force=true; quyền xóa lõi | id,deleted=true |
| duplicate-post | write | Đọc có phân quyền rồi tạo có phân quyền; không chuyển tiếp password/private/meta ngoài danh sách cho phép | post |
| bulk-update-posts | write | Lặp cập nhật item có phân quyền và giới hạn; không tuyên bố giao dịch toàn cục | updated,failed:id+safe code |
| list-revisions | read | GET collection revision của cha thực tế | items:revision |
| get-revision | read | GET revision ràng buộc với cha được yêu cầu | revision |
| restore-revision | write | Kiểm tra sửa cha, ràng buộc cha/type của revision, rồi wp_restore_post_revision và đọc lại an toàn | post |
| set-featured-media | write | POST featured_media; giữ kiểm tra attachment/type | post |
| stick-post | write | POST sticky; chỉ post type được hỗ trợ và quyền sticky lõi | post |
| get-post-meta | read | GET có phân quyền; chỉ khóa show_in_rest đã đăng ký | meta object |
| update-post-meta | write | POST có phân quyền; chỉ schema đã đăng ký và phân quyền meta | meta object |
| search-content | read | GET search; type/subtype đã rà soát và phân trang có giới hạn | items:id,title,url,type,subtype |

Post DTO cho phép `id,type,status,title,slug,link,date,modified,author,excerpt,content,featured_media,categories,tags,sticky,meta` cùng dấu hiệu cắt ngắn đã khai báo. Mỗi trường chọn có type rõ ràng. ID và quan hệ cha không bao giờ lấy từ nội dung không tin cậy. Metadata đã đăng ký được chiếu theo schema đã khai báo và pipeline đầu ra nhạy cảm; không đưa vào accessor option/user-meta tổng quát. Mô tả nêu nội dung site là dữ liệu không tin cậy và lõi có thể lọc HTML theo `unfiltered_html`.

Báo cáo lỗi bulk giữ thành công/thất bại từng mục, không giả số không/thành công khi provider lỗi. Không thử lại mù quáng kết quả sửa đổi chưa biết. Thao tác nhân bản/khôi phục/gán term phải báo trung thực thất bại một phần và tránh mở rộng quyền của mục nguồn hoặc sao chép bí mật được bảo vệ.

## S03 — Pack taxonomy

Toolset `taxonomy`: list-taxonomies/read, list-terms/read, get-term/read, create-term/write, update-term/write, assign-post-terms/write, delete-term/destructive. Chỉ phân giải tuyến taxonomy có REST đã đăng ký. Xác thực tương thích cha phân cấp và ràng buộc taxonomy của term/đối tượng. Quyền manage_terms/assign_terms/edit_terms/delete_terms của lõi áp dụng đúng taxonomy và đối tượng. Giữ từ chối xóa term mặc định của lõi. Gán hỗ trợ replace/append rõ ràng mà không cấp quyền tạo term như tác dụng phụ.

Collection nhận search,parent,hide_empty,page/per_page với giá trị giới hạn; đầu ra là đối tượng nghiêm ngặt chứa trường term/items trong danh sách cho phép. Gán bài viết theo taxonomy vẫn là cập nhật bài viết có phân quyền và xác minh taxonomy đã đăng ký cho post type đó.

Đã thay thế một phần bởi M2-taxonomy (ADR-0017, 2026-10-09): chín tool taxonomy gồm bulk-create-terms và bulk-assign-post-terms; gán dùng hàm lõi Direct với replace/append/remove, chỉ ID nguyên, lấp term mặc định và chặn bài rác. Xem docs/vi/specs/M2-taxonomy.md là nguồn có thẩm quyền cho taxonomy.

## S04 — Pack block

Toolset `blocks`, cả sáu ability cấp read: list-block-types, list-block-patterns, get-block-pattern, parse-blocks, validate-blocks và render-blocks. Dùng controller/API lõi đã đăng ký, phân trang ổn định khi có và không đi tắt qua hệ thống tệp/nguồn từ xa. Không nhầm controller reusable-block `/wp/v2/blocks` với metadata block type, pattern đã đăng ký hoặc phân tích markup tùy ý.

Phân tích/xác thực/render giới hạn byte đầu vào, độ sâu lồng và số block/node. Xác thực tên, thuộc tính theo schema block thực tế đã đăng ký; type không biết thất bại an toàn. Render preview bằng `do_blocks`, rồi làm sạch cho chủ thể bằng quy tắc KSES lõi. Xem callback block động là mã đã cài tin cậy nhưng vẫn thực thi ngân sách thực thi/đầu ra và không cung cấp context render đặc quyền. Đầu ra vẫn là dữ liệu nội dung không tin cậy. Không thêm thao tác export/archive/helper của trình biên tập.

## Ma trận nghiệm thu và trình tự

1. Thêm hợp đồng helper thất bại và fixture tích hợp từ xa cho Editor, Author, Contributor, route/ID không khả dụng, 403/404 và raw context bị cấm. Triển khai helper và chứng minh phép chiếu đầu ra riêng tư.
2. Triển khai lát nội dung nhỏ theo thứ tự bảng, mỗi lát dùng lại hợp đồng pack, trường hợp hành động hợp lệ, thiếu quyền, thiếu ID và schema không hợp lệ. Kiểm thử type tùy chỉnh có REST và lệch type, Author với bài người khác, từ chối publish/future cho Contributor, kiểm tra term/media và meta đã/chưa đăng ký.
3. Kiểm thử kết quả một phần khi nhân bản/bulk, >50 mục, tráo cha revision, truy cập dữ liệu raw, status/date/order không hợp lệ, giữ KSES và từ chối trần khóa destructive.
4. Triển khai taxonomy với ma trận vai trò phân cấp/không phân cấp/term mặc định/gán. Triển khai block với paragraph/heading, tên/schema không biết, lồng sâu, ngân sách render động và làm sạch script.
5. Chạy fixture từ xa đã duyệt trên WordPress 6.9 và 7.1.2, cửa chất lượng nguồn/độ bao phủ, ZIP production và Plugin Check. Chạy client MCP thực tế tạo/cập nhật/xóa nội dung được phép và từ chối sửa đổi chéo chủ sở hữu. Đo tools/list với cả ba pack bật và giữ kiểm thử hồi quy bảo mật M1.
6. Cập nhật danh mục/bao phủ controller, vận hành/changelog và báo cáo cột mốc. Chỉ đánh dấu M2 được nghiệm thu khi có đủ mọi bằng chứng; CI trên host, production và xuất bản vẫn là cửa riêng.

## Nguồn chính thức đã xác minh

Đã xác minh ngày 2026-10-05 với tham chiếu chính thức hiện tại và mã lõi 6.9 đã tải: [điều phối REST nội bộ](https://developer.wordpress.org/reference/functions/rest_do_request/), [schema và tuyến Posts REST](https://developer.wordpress.org/rest-api/reference/posts/), [khôi phục revision](https://developer.wordpress.org/reference/functions/wp_restore_post_revision/), [controller REST reusable-block](https://developer.wordpress.org/rest-api/reference/blocks/). Phải khám phá controller/schema tại runtime trên cả hai fixture từ xa đã duyệt trước khi viết mã dựa trên giả định cụ thể về controller.

## Approved content/media amendment (2026-10-08)

ADR-0013 supersedes the original post-type and DTO limits for this feature. E-01 through E-08 were approved by the owner. Content implementation may proceed before M1 independent acceptance; release remains gated. The extended post DTO includes the eight E-01 fields when core exposes their supported schema. expected_modified is a UTC best-effort comparison, not an atomic lock. See ADR-0013 through ADR-0015 and the implementation plan.
