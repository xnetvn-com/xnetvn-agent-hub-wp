# Lộ trình

Triển khai theo từng bước. Một cột mốc chỉ hoàn tất sau khi các kiểm tra nghiệm thu chạy thành công; lập kế hoạch không đồng nghĩa đã triển khai.

| Cột mốc | Sản phẩm bàn giao | Nghiệm thu |
|---|---|---|
| M0 | Bootstrap, công cụ, đóng gói, CI và kiến trúc | Các cửa kiểm thử đơn vị/tích hợp/lint/phân tích tĩnh/Plugin Check/ZIP |
| M1 | Khóa người dùng, xác thực khớp chính xác tuyến, chính sách, ability riêng tư, facade MCP, kiểm toán và quản trị | N-01–N-35; hai client MCP thực tế; kiểm thử quyền vai trò/đối tượng |
| M2 | Nội dung, taxonomy và block | Hợp đồng pack nghiêm ngặt; phân quyền đối tượng REST thực tế |
| M3 | Media và bình luận | Giới hạn SSRF/DNS/MIME/base64 và kiểm thử quyền kiểm duyệt |
| M4 | Giao diện và cài đặt | Danh sách cho phép, cài đặt quản trị nhạy cảm, tài liệu loại trừ công cụ hỗ trợ trình biên tập |
| M5 | Người dùng (12 tools đã làm, ADR-0018), plugin và bảo trì | Trần vai trò (RoleGuard/UserTargetGuard), vòng đời vai trò banned, hạn chế trình nâng cấp lõi, rà soát bảo mật độc lập |
| M6 | Resource/prompt, CLI, network, xác nhận, bản dịch, khả năng tiếp cận | Tương đương giữa các tầng truyền tải, cô lập theo site và bằng chứng tiếp cận trên trình duyệt |
| M7 | OAuth và tích hợp tùy chọn; chuẩn bị phát hành | Kiểm thử ranh giới tin cậy OAuth và hợp đồng theo từng tích hợp |

Cửa phát hành ổn định 1.0 yêu cầu Plugin Check sạch, không còn phát hiện bảo mật nghiêm trọng chưa xử lý, độ bao phủ Domain/Auth/Policy >=90%, tổng thể >=80%, tài liệu đầy đủ, hiệu năng đã đo và ba client MCP thực tế. Mục tiêu hiệu năng chưa được xác minh cho đến khi đo: trung vị auth/policy <5 ms với object cache; liệt kê khoảng 60 công cụ <50 ms; bộ nhớ tăng thêm <8 MB/yêu cầu.

OAuth và connector đám mây nằm ngoài xác thực giai đoạn một. Xuất bản lên WordPress.org, đổi cài đặt kho mã và triển khai production cần được cho phép rõ ràng.

---

# Lộ Trình Phát Triển Dự Án (Product Roadmap)

> **Kế hoạch phát triển tính năng, các cột mốc quan trọng (Milestones) và tầm nhìn tương lai của xNetVN Agent Hub WP.**

---

## 📍 Tầm Nhìn Dài Hạn

Xây dựng một nền tảng MCP Server tiêu chuẩn vàng cho toàn bộ hệ sinh thái WordPress, giúp các AI Agents có thể thực hiện mọi tác vụ quản trị, sáng tạo nội dung, tối ưu SEO, xử lý truyền thông và tự động hóa vận hành một cách an toàn, minh bạch, có thể mở rộng theo chiều ngang (Horizontal Scaling) phục vụ quy mô hàng tỷ yêu cầu mỗi ngày.

---

## 🎯 Các Cột Mốc Phát Triển Chi Tiết

```
[M1: Core Foundation]  ──►  [M2: Content & Taxonomy]  ──►  [M3: Media & Comments]  ──►  [M4: Appearance & Site]
     (Đã hiện thực; chờ nghiệm thu)   (Hiện tại: còn block)   (Đã hiện thực; chờ nghiệm thu)   (Kế hoạch)
```

---

### Cột Mốc 1: Nền Tảng Cốt Lõi (M1 - Core Foundation) — [ĐÃ HIỆN THỰC; CHỜ NGHIỆM THU]
- [x] Kiến trúc Kernel sạch, nạp ServiceProvider có trật tự xác định.
- [x] Khởi tạo cơ sở dữ liệu `*_keys` và `*_audit` với tiền tố per-site.
- [x] Sinh khóa bí mật Pepper 32-byte độc lập trên từng site.
- [x] Mô hình khóa API định dạng `xnv_<key_id>_<secret>` với băm `HMAC-SHA256`.
- [x] Tách biệt tuyến đường: Tuyến MCP nhận Bearer Token; Tuyến REST nhận Cookie + Nonce.
- [x] Cơ chế phân quyền giao thoa (Capability Intersection) và các cấp độ rủi ro (Risk Tiers).
- [x] Giới hạn tần suất trượt (Sliding Window Limiter) theo Key và theo IP.
- [x] Bộ bảo vệ giao vận (TransportGuard) và nguồn gốc (OriginGuard).
- [x] Bộ công cụ hệ thống cốt lõi (`SystemPack` với 5 abilities: `get-session-info`, `get-site-info`, `discover-abilities`, `get-ability-info`, `execute-ability`).
- [x] Tích hợp bộ lọc dữ liệu nhạy cảm đầu ra (`OutputPipeline`).
- [x] Nhật ký kiểm toán bất đồng bộ (`AuditLogger`) và dọn dẹp định kỳ (`Retention`).
- [x] Tích hợp xuất và xóa dữ liệu cá nhân theo GDPR WordPress.
- [x] Giao diện quản trị Admin Pages & Quản lý khóa tại Profile người dùng đạt chuẩn WCAG 2.2 AA.

---

### Cột Mốc 2: Nội Dung, Phân Loại & Khối (M2 - Content, Taxonomy, Blocks) — [ĐANG TRIỂN KHAI 🔄]
- [ ] Xây dựng trợ thủ `RestBackedAbility` gọi nội bộ an toàn qua `rest_do_request()`.
- [x] Triển khai `ContentPack` (19 abilities; chờ nghiệm thu):
  - Danh sách kiểu bài viết, trạng thái bài viết (`list-post-types`, `list-post-statuses`).
  - Đọc, tìm kiếm, lọc danh sách bài viết (`list-posts`, `get-post`, `search-content`).
  - Tạo, cập nhật, xóa tạm, khôi phục, xóa vĩnh viễn bài viết (`create-post`, `update-post`, `trash-post`, `restore-post`, `delete-post`).
  - Nhân bản bài viết an toàn (`duplicate-post`) và cập nhật hàng loạt có giới hạn (`bulk-update-posts`).
  - Quản lý bản sửa đổi bài viết (`list-revisions`, `get-revision`, `restore-revision`).
  - Quản lý siêu dữ liệu bài viết (`get-post-meta`, `update-post-meta`) theo danh sách trắng đã đăng ký.
- [x] Triển khai `TaxonomyPack` (9 abilities, ADR-0017):
  - Danh sách phân loại, điều khoản phân loại (`list-taxonomies`, `list-terms`, `get-term`).
  - Tạo, sửa, gán và xóa điều khoản (`create-term`, `update-term`, `assign-post-terms`, `delete-term`).
  - Đảm bảo và gán hàng loạt (`bulk-create-terms`, `bulk-assign-post-terms`) có giới hạn và kết quả từng mục.
- [ ] Triển khai `BlocksPack` (6 abilities):
  - Tra cứu khối và mẫu khối (`list-block-types`, `list-block-patterns`, `get-block-pattern`).
  - Phân tích cú pháp, xác thực và kết xuất khối an toàn (`parse-blocks`, `validate-blocks`, `render-blocks`).

---

### Cột Mốc 3: Đa Phương Tiện & Tương Tác (M3 - Media, Comments & Moderation) — [ĐÃ HIỆN THỰC; CHỜ NGHIỆM THU]
- [x] Triển khai `MediaPack` (7 abilities):
  - Duyệt và lấy thông tin file đính kèm (`list-media`, `get-media`).
  - Tải lên file đa phương tiện từ URL hoặc dữ liệu nhị phân với kiểm duyệt MIME và giới hạn dung lượng nghiêm ngặt.
  - Cập nhật và xóa tệp đính kèm.
- [x] Triển khai `CommentsPack` (10 abilities, ADR-0016):
  - Đọc, lọc và lấy bình luận (`list-comments`, `get-comment`).
  - Tạo, trả lời và sửa bình luận (`create-comment`, `reply-comment`, `update-comment`).
  - Kiểm duyệt bình luận (duyệt, spam, thùng rác, xóa vĩnh viễn, kiểm duyệt hàng loạt).

---

### Cột Mốc 4: Giao Diện, Cài Đặt & Mở Rộng Hệ Thống (M4 - Appearance & Settings) — [KẾ HOẠCH 📅]
- [ ] Triển khai `AppearancePack`:
  - Quản lý Themes, Menus, Menu Locations, Widgets, Sidebars.
  - Quản lý Block Templates, Template Parts, Global Styles và Custom CSS.
- [ ] Triển khai `SettingsPack`:
  - Đọc và cập nhật các tùy chọn website theo danh sách trắng được bảo vệ bởi quyền `manage_options`.
- [ ] OAuth 2.0 Authorization Server được hoãn tới M7 theo ADR-0007.
- [ ] SDK tích hợp cho Python và TypeScript.

## Content and media implementation authority (2026-10-08)

E-01 through E-08 and early implementation were approved. Content has 19 tools; media has seven including get-media-upload-limits. Implementation and runtime acceptance remain separate. M1 independent security acceptance still gates release. Taxonomy, Blocks and Comments remain outside this feature. Remote media requires all-answer DNS validation, IP pinning, strict MIME/base64/file limits and MD/SS regression evidence.

Cập nhật trạng thái (2026-11-03): taxonomy (ADR-0017) và comments (ADR-0016) đã được triển khai, đang chờ nghiệm thu; blocks vẫn nằm trong kế hoạch. Cập nhật này không thay đổi biên bản quyết định ngày 2026-10-08 ở trên.
