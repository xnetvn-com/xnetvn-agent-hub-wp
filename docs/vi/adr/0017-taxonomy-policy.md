# ADR-0017: Chính sách Taxonomy

Trạng thái: Accepted (2026-10-09). Chủ dự án đã duyệt ET-02, ET-03, ET-04,
ET-05, ET-06 ngày 2026-10-09; toàn bộ ET-01..ET-16 được duyệt triển khai trên
nhánh làm việc theo chỉ đạo của chủ dự án cho tác vụ này. Bản tiếng Anh
(`docs/en/adr/0017-taxonomy-policy.md`) là nguồn chuẩn.

## Bối cảnh

M2 S03 chốt bảy tool taxonomy dùng REST và gán qua REST bài viết. Comments Pack
(ADR-0016) là mẫu pack mới nhất. Đọc trực tiếp mã nguồn WordPress 6.9.10 và
7.1.3 (2026-10-09) cho thấy gán qua REST bài viết không append/remove an toàn
và bỏ lặng ID lạ, trong khi REST term hoạt động đúng. Nguồn chuẩn: WordPress
7.1.3/6.9.10 (bản ổn định 2026-10-06), MCP 2026-07-28 tools, adapter 0.7.0.

## Quyết định

- ET-01: allowlist `TaxonomyResolver` (taxonomy bật REST, controller mặc
  định, route đúng dự kiến, danh sách chặn, post type được quản lý, filter chỉ
  từ chối `xnetvn_agent_hub_wp_taxonomy_allowed`); lỗi chung
  `taxonomy_unavailable`.
- ET-02: hiển thị `read` cho cả chín tool; quyền đối tượng vẫn quyết định.
- ET-03: theo đúng quy tắc tạo của core (taxonomy phẳng cần `assign_terms`,
  phân cấp cần `edit_terms`).
- ET-04: gán dùng hàm lõi Direct kèm kiểm quyền tường minh và chỉ ID nguyên;
  ghi rõ không có tác dụng phụ `save_post`/`post_modified`/revision.
- ET-05/ET-06: thêm `bulk-create-terms` và `bulk-assign-post-terms` (tối đa
  50, costly, kết quả từng mục, không giao dịch toàn cục; `bulk-assign` chỉ
  append/remove).
- ET-07: tổng quát hóa biên nhận `RestBackedAbility` cho `WP_Term` với `kind`
  tường minh; không dùng `get_post_type` cho term.
- ET-08: thêm `taxonomy_unavailable` và `duplicate_term` cùng bảng ánh xạ lõi
  ưu tiên mã lỗi; với `rest_cannot_delete` kiểm HTTP status trước.
- ET-09: kiểm trước cha chặt hơn core (tồn tại, cùng taxonomy, không phải
  chính nó, không phải hậu duệ).
- ET-10: chặn term mặc định (`default_{tax}`, `default_term_{tax}`) trước kiểm
  quyền để trả `invalid_state` rõ ràng; trạng thái mặc định là public.
- ET-11: `delete-term` với `dry_run` báo tác động mà không ghi; vẫn cần quyền
  với term không phải mặc định.
- ET-12: lấp lại assignment đã rỗng bằng term mặc định ở nơi core làm vậy.
- ET-13: từ chối gán cho bài ở trạng thái trash/auto-draft bằng
  `invalid_state`.
- ET-14: cờ `slug_adjusted`; không so nguyên văn tên/mô tả (chuẩn hóa entity
  và KSES).
- ET-15: `delete-term` costly; `list-taxonomies` trả `can_assign`/`can_edit`/
  `can_delete`/`default_term_id` (có thể null).
- ET-16: term meta chỉ cho khóa đã đăng ký `show_in_rest`, kiểm trước, tối đa
  20 khóa; `bulk-create` không mang meta.

## Hệ quả

Đã cập nhật danh mục, api-standards, M2-taxonomy, overview, roadmap,
threat-model, performance, operations và changelog (EN rồi VI). Không thêm cài
đặt site. Không đăng ký/xóa taxonomy, không gộp term, không hỗ trợ non-REST.
Nếu core về sau hỗ trợ append/remove an toàn với ID nghiêm ngặt, xem lại ET-04
bằng một ADR mới. Các mục U-TX được giải quyết bằng bằng chứng lab ở nơi thiết
kế phụ thuộc vào chúng; phần chưa biết còn lại được nêu rõ trong báo cáo cuối.
