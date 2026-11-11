# Hướng Dẫn Đóng Góp Phát Triển Dự Án (Contributing Guide)

> **Cảm ơn bạn đã quan tâm và muốn đóng góp cho xNetVN Agent Hub WP! Chúng tôi chào đón mọi đóng góp từ cộng đồng lập trình viên trên toàn cầu.**

---

## 1. Quy Tắc Ứng Xử (Code of Conduct)
Mọi thành viên tham gia đóng góp đều phải tuân thủ Quy tắc ứng xử của dự án tại [CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md). Chúng tôi cam kết xây dựng một môi trường hòa nhập, cởi mở, tôn trọng và chuyên nghiệp.

---

## 2. Quy Trình Làm Việc Với Git (Git Workflow)

1. **Fork Kho Lưu Trữ:** Tạo bản sao kho lưu trữ về tài khoản cá nhân của bạn trên GitHub.
2. **Tạo Nhánh Tính Năng (Feature Branch):** Luôn tạo nhánh mới từ nhánh `main` với quy ước đặt tên chuẩn:
   - Tính năng mới: `feature/<ma-ticket>-<mo-ta-ngan>` (Ví dụ: `feature/M2-add-content-pack`)
   - Sửa lỗi: `bugfix/<ma-ticket>-<mo-ta-ngan>` (Ví dụ: `bugfix/fix-token-parsing-length`)
   - Tái cấu trúc: `refactor/<ma-ticket>-<mo-ta-ngan>`
   - Tài liệu: `docs/<mo-ta-ngan>`
3. **Thực Hiện Thay Đổi Nhỏ & Tăng Dần (Incremental Changes):** Tránh các Pull Request khổng lồ gộp nhiều tính năng không liên quan.
4. **Quy Ước Thông Điệp Commit (Conventional Commits):**
   - Định dạng bắt buộc bằng tiếng Anh: `<type>(<scope>): <subject>`
   - Ví dụ:
     - `feat(auth): add ip cidr filtering to token validation`
     - `fix(ratelimit): prevent race condition in atomic counter`
     - `docs(vi): update installation guide and architecture specs`
     - `test(mcp): add negative test cases for invalid origin header`

---

## 3. Tiêu Chuẩn Lập Trình (Coding Standards)

- **Ngôn ngữ trong mã nguồn:** Bắt buộc sử dụng tiếng Anh chuẩn cho toàn bộ mã nguồn (tên lớp, phương thức, biến, bình luận trong code, docblocks).
- **Tuân thủ chuẩn WordPress & PHPStan:**
  - Áp dụng nghiêm ngặt WordPress Coding Standards (`WordPress-Extra`, `WordPress-Core`, `WordPress-Docs`).
  - Phân tích tĩnh **PHPStan Level 8 không baseline**.
  - Tương thích PHP 8.2 cho runtime và PHP 7.2 cho các tệp Bootstrap.
- **Quy tắc bảo mật:**
  - Luôn sử dụng Prepared Statements (`$wpdb->prepare`) khi truy vấn SQL.
  - Làm sạch dữ liệu đầu vào (Sanitize Input) và thoát dữ liệu đầu ra (Escape Output).
  - Không bao giờ lưu trữ hoặc ghi log các thông tin nhạy cảm (token, mật khẩu, hash).

---

## 4. Kiểm Thử Bắt Buộc (Testing Standards)

- Mọi tính năng hoặc bản sửa lỗi mới bắt buộc phải đi kèm với bài kiểm tra tự động (Unit Test hoặc Integration Test).
- Áp dụng phương pháp TDD: Viết test trước, đảm bảo test thất bại, sau đó viết mã nguồn tối thiểu để test vượt qua.
- Chạy toàn bộ bộ kiểm tra trước khi gửi Pull Request:
  ```bash
  composer check
  ```

---

## 5. Quy Trình Gửi Pull Request (PR Process)

1. Đẩy các commit lên nhánh của bạn trên GitHub.
2. Tạo Pull Request trỏ vào nhánh `main` của kho lưu trữ chính.
3. Điền đầy đủ thông tin theo mẫu tại [templates/pr_template.md](templates/pr_template.md).
4. Đảm bảo toàn bộ các bước kiểm tra tự động (CI Pipeline) trên GitHub Actions đều báo xanh (Pass).
5. Ít nhất một thành viên trong nhóm nòng cốt (Core Maintainers) sẽ review mã nguồn của bạn và đưa ra phản hồi.

---

## 6. Tệp Làm Việc và Kế Hoạch

Bản làm việc của kế hoạch, đặc tả và báo cáo tạm không phải tài liệu công khai; đặc tả và báo cáo chuẩn nằm dưới `docs/`. Giữ bản làm việc trong cây `.local/docs/` bị Git bỏ qua: `plans/`, `specs/`, `reports/`, `superpowers/plans/` và `en/issues/`. Chúng không bao giờ vào gói phát hành.

Bốn kế hoạch thiết kế lịch sử trước đây nằm dưới `docs/superpowers/plans/` đã được chuyển khỏi cây tài liệu công khai. Chúng vẫn đọc được tại commit `9a709433d895255865b3ddccb5c2fc96eb4c4e8a`:

- `docs/superpowers/plans/2026-10-09-comments-pack.md`
- `docs/superpowers/plans/2026-10-09-taxonomy-pack.md`
- `docs/superpowers/plans/2026-10-10-users-pack.md`
- `docs/superpowers/plans/2026-11-04-wordpress-org-submission-readiness.md`

Dùng [cây kế hoạch tại commit đó](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/tree/9a709433d895255865b3ddccb5c2fc96eb4c4e8a/docs/superpowers/plans) khi cần ngữ cảnh gốc.
