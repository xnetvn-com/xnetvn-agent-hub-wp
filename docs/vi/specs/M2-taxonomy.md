# M2 — Hợp đồng Taxonomy

Căn cứ thiết kế: ET-01 đến ET-16 đã duyệt ngày 2026-10-09 (ADR-0017).
Hiện thực đã có; nghiệm thu runtime đầy đủ và cổng bảo mật độc lập vẫn còn mở.
Content (19 tool) và Media (7 tool) đã hiện thực theo sửa đổi 2026-10-08;
Comments (10 tool) theo ADR-0016. Blocks vẫn trong kế hoạch.

## Ranh giới thực thi riêng tư

Cả chín ability dùng toolset `taxonomy` và quyền hiển thị `read`. Quyền hiển
thị không bao giờ thay thế quyền đối tượng của core (`edit_term`,
`delete_term`, `assign_term` theo từng term, `edit_terms`/`assign_terms` theo
taxonomy khi tạo, `edit_post` theo từng bài khi gán). HTTP yêu cầu AuthContext
của chủ khóa đã xác minh; tier khóa/site và allow/deny vẫn giao với quyền hiện
tại. Pack không bao giờ đổi danh tính.

| Ability | Tier | Backing | Ràng buộc thêm |
|---|---|---|---|
| list-taxonomies | read | REST GET taxonomies | Lọc taxonomy quản lý được, gợi ý quyền, ID term mặc định |
| list-terms | read | REST GET collection | Bộ lọc/phân trang giới hạn, chặn parent với taxonomy phẳng, từ chối trang vượt tổng |
| get-term | read | REST GET item | ID chính xác kèm ràng buộc taxonomy |
| create-term | write | REST POST collection | Kiểm trước cha và meta, cờ slug_adjusted |
| update-term | write | REST POST item | Ràng buộc taxonomy, kiểm trước cha, ít nhất một trường đổi |
| assign-post-terms | write | Direct wp_set/remove_object_terms | Chuỗi 13 bước, chỉ ID nguyên, lấp term mặc định, chặn bài rác |
| bulk-create-terms | write,costly | REST lặp | ≤50 mục, created/existing/failed, không giao dịch toàn cục |
| bulk-assign-post-terms | write,costly | Direct lặp | ≤50 bài, chỉ append/remove, kết quả từng bài |
| delete-term | destructive,costly | REST DELETE force=true | Chặn term mặc định, dry_run báo tác động, khe costly |

Mỗi định nghĩa có đủ annotation readonly/destructive/idempotent/openWorldHint
tường minh. Mọi mô tả là tiếng Anh cố định và coi tên, mô tả, slug, meta của
term là dữ liệu không tin cậy.

## Hợp đồng đầu vào

Mọi input gốc là object với additionalProperties=false. ID là số nguyên dương.
page>=1; per_page 1..100, mặc định 20. Slug taxonomy khớp
`^[a-z0-9_-]{1,32}$` và được phân giải qua TaxonomyResolver tại thời điểm gọi.
Tên term 1..200 ký tự sau trim; slug 1..200; mô tả đến 5000; meta đến 20 khóa
đã đăng ký show_in_rest. ID term khi gán là số nguyên duy nhất, tối đa 100; ID
bài của bulk tối đa 50 và duy nhất.

`list-taxonomies` nhận bộ lọc `post_type` tuỳ chọn. `list-terms` nhận `search`,
`parent` (chỉ taxonomy phân cấp), `include`/`exclude` (tối đa 100), `slug` (tối
đa 50), `post`, `hide_empty`, `orderby` id/name/slug/count/description/include,
`order`, `page`/`per_page`, `description_max_chars` 1..5000 mặc định 300 và
`fields`. Ba trường hợp bị từ chối: `parent` trên taxonomy phẳng,
`orderby=include` mà thiếu `include`, và `page` vượt `max(1,total_pages)`.
`get-term` cần `taxonomy` và `id`. `create-term` cần `taxonomy` và `name`.
`update-term` cần `taxonomy`, `id` và ít nhất một trường được phép đổi.
`assign-post-terms` cần `post_id`, `taxonomy`, `term_ids` và `mode`
replace/append/remove (chỉ `replace` được rỗng). `bulk-create-terms` cần
`taxonomy` và 1..50 mục không kèm meta. `bulk-assign-post-terms` cần
`taxonomy`, 1..50 `post_ids`, 1..100 `term_ids` và `mode` append/remove.
`delete-term` cần `taxonomy` và `id`, kèm `dry_run` tuỳ chọn.

## Chính sách taxonomy và phân cấp

TaxonomyResolver (ET-01) chỉ cho một taxonomy đi qua khi nó tồn tại,
`show_in_rest` đúng bằng `true`, không thuộc danh sách chặn tường minh
(`nav_menu`, `link_category`, `post_format`, `wp_theme`,
`wp_template_part_area`, `wp_pattern_category`), dùng đúng
`WP_REST_Terms_Controller`, phơi đúng route `/{namespace}/{rest_base}` dự kiến,
gắn với ít nhất một post type được quản lý và qua bộ lọc deny-only
`xnetvn_agent_hub_wp_taxonomy_allowed`. Mọi từ chối dùng chung
`taxonomy_unavailable`.

Taxonomy phân cấp hỗ trợ `parent` 0..N; taxonomy phẳng từ chối input `parent`.
Kiểm trước khi gán cha xét tồn tại, cùng taxonomy, không phải chính nó và không
phải hậu duệ (ET-09); không bao giờ dựa vào việc core âm thầm làm phẳng cây.
Term mặc định (`default_{taxonomy}`, `default_term_{taxonomy}`) không xóa được
(ET-10) và được lấp lại khi tập gán rỗng đúng như core (ET-12). Bài ở trạng
thái trash hoặc auto-draft bị từ chối gán (ET-13).

## Đầu ra và an toàn

DTO term nghiêm ngặt chỉ cho `id`, `taxonomy`, `name`, `slug`, `description`,
`description_truncated`, `parent` (chỉ taxonomy phân cấp), `count` (chỉ bài đã
xuất bản), `link` (chỉ chuỗi), `meta` (chỉ khóa đã đăng ký, khi xin rõ trường)
và `slug_adjusted` (chỉ ở thao tác ghi). `term_taxonomy_id`, `term_group`,
`_links`, `_embedded` và trường do plugin thêm không bao giờ rời khỏi hệ thống.
Collection dùng `items,total,total_pages,page` từ header của core. Gán term trả
`post_id,taxonomy,mode,term_ids,added,removed,default_term_applied`. Bulk gán
trả `taxonomy,mode,updated,unchanged,failed`. Bulk tạo trả
`taxonomy,created,existing,failed` kèm chỉ số đầu vào. Xóa trả
`id,taxonomy,deleted` cùng `children_reparented`, hoặc bản ghi tác động
dry_run không ghi gì.

Mọi kết quả qua OutputPipeline: kiểm schema, che dữ liệu nhạy cảm, giới hạn
byte và kiểm lại lần cuối. Biên nhận `RestBackedAbility` tổng quát hóa (ET-07)
quan sát `rest_insert_{taxonomy}` (WP_Term) và `delete_term` khớp taxonomy;
ID term và ID bài không bao giờ nhầm nhau. Mã lỗi core ánh xạ theo ET-08, gồm
`taxonomy_unavailable` và `duplicate_term`; dữ liệu từ provider không bao giờ
đi qua. Lỗi sau khi ghi đã xác nhận trả `partial_failure` kèm `created_id` hoặc
`affected_id` tin cậy. ID lạ không bao giờ bị bỏ lặng lẽ; ID dạng chuỗi không
bao giờ tạo term như tác dụng phụ.

## Cài đặt và nghiệm thu

Pack không thêm cài đặt site, chỉ thêm một bộ lọc công khai deny-only
`xnetvn_agent_hub_wp_taxonomy_allowed`. Nghiệm thu cần TX-01..122 thuộc yêu cầu
đã duyệt trên WordPress 6.9/7.1 (single-site và multisite), ma trận vai trò
cho Contributor/Author/Editor/Administrator (thêm Subscriber khi bật
`allowed_roles_override`), toàn bộ hồi quy N-01..35, hành vi ứng dụng MCP thật,
Plugin Check trên gói phát hành và rà soát bảo mật độc lập. Các cổng chưa xác
minh được ghi riêng trong báo cáo thực thi.

Đã kiểm chứng 2026-10-09 với nguồn WordPress 6.9.10/7.1.3, MCP 2026-07-28
(danh sách tất định, annotation không tin cậy, schema object nghiêm ngặt) và
adapter 0.7.0. Yêu cầu chi tiết: `FEATURE_REQUEST_v0.1_2026-10-09_03_quan-ly-taxonomy-qua-mcp` trong kho phiếu cục bộ.
