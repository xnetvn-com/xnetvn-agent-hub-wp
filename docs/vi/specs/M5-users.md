# M5 — Hợp đồng Users

Căn cứ thiết kế: D1 đến D4 đã chốt ngày 2026-10-09; EU-01 đến EU-08 và EU-10 đến EU-20 đã duyệt triển khai trên nhánh làm việc theo chỉ đạo của chủ dự án cho tác vụ này (EU-09 hoãn). Bản tiếng Anh (`docs/en/specs/M5-users.md`) là nguồn chuẩn.

## Ranh giới thực thi riêng tư

Mười hai ability dùng toolset `users`. Mười tool dùng quyền hiển thị `list_users`; `get-my-profile` và `update-my-profile` dùng `read`. Quyền hiển thị không thay thế quyền đối tượng của core (`list_users` để liệt kê, `edit_user`/`promote_user`/`create_users` theo từng mục tiêu). HTTP yêu cầu AuthContext của chủ khóa đã xác minh; tier khóa/site và danh sách allow/deny vẫn giao với quyền hiện tại. Pack không bao giờ đổi actor.

| Ability | Tier | Backing | Ràng buộc thêm |
|---|---|---|---|
| list-users | read | REST GET danh sách view | Lọc/phân trang có giới hạn, trạng thái active/banned/any, từ chối trang vượt tổng |
| get-user | read | REST GET chi tiết view | ID chính xác, kèm ban khi bị cấm |
| list-roles | read | Direct wp_roles | Cờ privileged/assignable/banned/is_default/capability_count, sắp xếp |
| get-user-capabilities | read | Direct WP_User | Cần edit_user, tối đa 500 cap, giao với PCS |
| get-my-profile | read | REST GET me edit | Chỉ chính chủ, email khi xin |
| update-my-profile | write | REST POST me | Không email/username/password/roles, meta mặc định từ chối ghi |
| create-user | admin | REST POST danh sách | Vai trò gán được tường minh, mật khẩu ngẫu nhiên xóa sau dùng, đọc lại kiểm vùng bảo vệ, thông báo |
| update-user | admin | REST POST chi tiết | Chuỗi chuẩn, trùng email/slug, meta allowlist, đọc lại |
| set-user-role | admin | Direct WP_User::set_role | Chuỗi chuẩn, cần promote_user, một vai trò nguyên tử, đọc lại |
| set-user-status | admin | Direct set_role/caps/sessions | Bản chụp, vai trò banned, gỡ cap trực tiếp, hủy phiên, mở cấm an toàn |
| send-password-reset | admin | Direct retrieve_password | Chuỗi chuẩn, bị cấm thì invalid_state, ngân sách 10/phút, cooldown 900s |
| terminate-user-sessions | admin | Direct WP_Session_Tokens | Chuỗi chuẩn, mục tiêu bị cấm được phép, đếm rồi hủy |

Mỗi definition có đủ bốn annotation readonly/destructive/idempotent/openWorldHint. Mọi mô tả là tiếng Anh cố định, coi tên/tiểu sử/URL/meta người dùng là dữ liệu không tin cậy.

## Hợp đồng đầu vào

Mọi gốc đầu vào là object với additionalProperties=false. ID là số nguyên dương. page>=1; per_page=1..100, mặc định 20. Slug vai trò khớp `^[a-z0-9_-]{1,64}$` và qua RoleGuard lúc gọi. Username `^[A-Za-z0-9._@-]{1,60}$`; email 3..100; tên hiển thị 1..250; họ/tên/nickname 100; URL 100; tiểu sử 5000; slug người dùng `^[a-z0-9-]{1,50}$`; locale 20 kiểm theo core; meta tối đa 20 khóa, mặc định từ chối mọi ghi. `create-user` bắt buộc username, email, role; không password/mảng roles. `update-user`/`update-my-profile` cần ít nhất một trường. `set-user-status` có status active/banned, reason_code spam/abuse/security/policy/other, restore_role đơn gán được.

## Hợp đồng đầu ra

`list-users` trả `{items,total,total_pages,page}` kèm `truncated` khi bị bóp. DTO người dùng là allowlist: id, name, slug, link (chỉ khi chuỗi), status active/banned, roles, registered_date UTC Z, protected, description, url, ban `{banned_at,reason_code}` chỉ khi banned kèm bản chụp hợp lệ, cộng nhóm B username/email/first_name/last_name/nickname/locale/meta chỉ khi xin và có `edit_user`. Không bao giờ có avatar_urls, capabilities, pass, khóa kích hoạt, session, links, embeds hay trường plugin tiêm. `get-user-capabilities` trả caps đã sắp tối đa 500 kèm `truncated`, giao PCS, protected, status. Ghi trả biên nhận MutationResult; lỗi trả PartialFailure kèm created_id/affected_id. Mọi đầu ra qua OutputPipeline xóa nhạy cảm và giới hạn byte.

## Bảo vệ và thông tin xác thực

UserTargetGuard đánh giá missing/self/protected/denied/allowed mỗi request, không cache: super admin, vai trò administrator, vai trò đặc quyền, cap trực tiếp đặc quyền hoặc `has_cap` hiệu lực thuộc PCS thì protected; filter deny-only `xnetvn_agent_hub_wp_user_allowed` chỉ được từ chối. PCS theo 4.3.2 (ngoài unfiltered_html); assignable cần đúng mẫu, tồn tại, không banned, không đặc quyền, thuộc editable_roles. Vai trò banned không quyền, tạo lười, từ chối khi có sẵn mà có quyền. Bản chụp là JSON version qua user option, xác thực nghiêm ngặt. Không mật khẩu trong mọi schema; mật khẩu ngẫu nhiên 32 ký tự xóa sau REST; liên kết đặt lại không bao giờ trả; đổi email giữ thư báo địa chỉ cũ; không Set-Cookie ở luồng hồ sơ chính chủ.

## Lỗi và giới hạn

Bốn mã mới `duplicate_user`, `protected_account`, `role_unavailable`, `cooldown_active` với thông điệp cố định; ánh xạ mã core `rest_user_*`/`existing_user_*`/`no_password_reset`/`invalidcombo`/`retrieve_password_email_failure` theo mã chính xác trước. Thứ tự chuỗi admin chuẩn: not_found, protected_account, invalid_state, forbidden. Ngân sách: tạo 5/phút/chủ, reset 10/phút/chủ kèm cooldown 900s/mục tiêu (số đề xuất). Không bulk, network, delete-user (D4 hoãn), đặt mật khẩu, cap riêng lẻ, sửa định nghĩa vai trò hay quản lý khóa.

Không thêm cài đặt site mới: toolset `users` tự động từ registry; không đụng `WpSiteSettings`, `ENVIRONMENT.md` hay màn hình quản trị.
