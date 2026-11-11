# ADR-0018: Chính sách users

Trạng thái: Accepted (2026-10-10). Chủ dự án đã chốt D1..D4 ngày 2026-10-09; duyệt EU-01..EU-08 và EU-10..EU-20 để triển khai trên nhánh làm việc cho tác vụ này (EU-09 hoãn). Bản tiếng Anh (`docs/en/adr/0018-users-policy.md`) là nguồn chuẩn.

## Bối cảnh

Danh mục M5 liệt kê mười tool users ở trạng thái Planned với `update-my-profile` hiển thị `list_users` và `set-user-role` ghi backing REST. Đọc trực tiếp mã WordPress (6.9.10 và 7.1.3, 2026-10-09) cho thấy: site đơn có `edit_users` sửa được cả administrator, REST đổi vai trò không nguyên tử và cho nhiều vai trò, `default_role` có thể thành đặc quyền, liệt kê ngữ cảnh edit bỏ lặng lẽ người dùng nhưng vẫn đếm đủ, và core không có khóa tài khoản. Nguồn chuẩn: WordPress 7.1.3/6.9.10, MCP 2026-07-28 tools, adapter 0.7.0, NIST SP 800-63B-4.

## Quyết định

- D1 (chủ dự án): vùng bảo vệ Admin toàn diện; không ngoại lệ kể cả chính chủ và super admin.
- D2 (chủ dự án): không mật khẩu thô qua MCP; không đổi email/mật khẩu chính chủ qua MCP.
- D3 (chủ dự án): cấm bằng vai trò `banned` không quyền kèm bản chụp, gỡ cap trực tiếp, hủy phiên.
- D4 (chủ dự án): hoãn `delete-user`, thiết kế reassign bắt buộc sau.
- EU-01: UserTargetGuard missing/self/protected/denied/allowed, không cache.
- EU-02: PCS theo 4.3.2 (ngoài unfiltered_html); filter chỉ-thêm.
- EU-03: hiển thị `read` cho `get-my-profile`/`update-my-profile`.
- EU-04: thêm `get-my-profile`, `set-user-status`, `terminate-user-sessions`; HardDeny ADMIN +2.
- EU-05: `create-user` REST với vai trò tường minh, mật khẩu ngẫu nhiên, đọc lại, thông báo.
- EU-06: `set-user-role` Direct nguyên tử một vai trò (lệch ghi chú REST của danh mục theo L-20).
- EU-07/EU-08: luồng cấm/mở cấm kèm bản chụp/phiên và Capabilities từ chối `banned`.
- EU-09: hoãn; không chặn đăng nhập/app-password/reset ở phiếu này.
- EU-10/EU-11: hủy phiên và đặt lại kèm cooldown/ngân sách, không trả liên kết.
- EU-12: `update-user` đổi email người khác, giữ thư báo địa chỉ cũ.
- EU-13: DTO allowlist nhóm A/B, ID view-context cộng dựng từ WP_User, không avatar/caps.
- EU-14: RestBackedAbility kind `user` quan sát `rest_insert_user` với định danh WP_User.
- EU-15: bốn mã an toàn mới và ánh xạ mã core theo mã chính xác trước.
- EU-16: OutputPipeline thêm `user_activation_key`, `session_tokens`.
- EU-17: meta đọc khóa đăng ký, ghi mặc định từ chối với allowlist chỉ-thêm và denylist cứng.
- EU-18: một vai trò mỗi tài khoản qua MCP.
- EU-19: ngân sách phút và cooldown 900s đề xuất (cần đo).
- EU-20: ba filter công khai chỉ-thêm/chỉ-từ-chối như đặc tả.

## Hệ quả

Mười hai tool users đã hiện thực; cập nhật danh mục, overview, threat model, api-standards, operations và changelog; vẫn cần rà soát bảo mật độc lập M5 trước phát hành; EU-09, ngân sách theo giờ, bulk/network/delete-user để dành phiếu sau.
