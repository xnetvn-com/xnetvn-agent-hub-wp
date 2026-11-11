# Chính Sách Phân Quyền & Kiểm Soát Truy Cập (Access Policy)

> **Quy định về mô hình phân quyền người dùng, quản lý vai trò và nguyên tắc đặc quyền tối thiểu trong xNetVN Agent Hub WP.**

---

## 1. Nguyên Tắc Đặc Quyền Tối Thiểu (Principle of Least Privilege - PoLP)

- Mọi tác nhân AI (AI Agent) hoạt động trên WordPress chỉ được cấp quyền tối thiểu tuyệt đối cần thiết để thực hiện công việc cụ thể.
- Mọi khóa API mới tạo ra đều phải chỉ định rõ:
  - Cấp độ rủi ro tối đa cho phép (`tier_max`).
  - Danh sách các bộ công cụ cụ thể được phép sử dụng (`toolsets`).
  - Tùy chọn danh sách cho phép (Allowlist) và danh sách cấm (Denylist) đối với từng công cụ riêng biệt.
  - Tùy chọn giới hạn địa chỉ IP mạng cho phép gọi khóa (`cidr_restrictions`).

---

## 2. Các Vai Trò & Quyền Hạn Trong WordPress (Roles & Capabilities)

Plugin định nghĩa hai quyền hạn động (Dynamic Meta Capabilities) dựa trên quyền hạn cốt lõi của WordPress:

| Quyền Hạn Của Plugin | Quyền WordPress Tương Ứng | Nhóm Người Dùng Áp Dụng | Ý Nghĩa Kỹ Thuật |
|---|---|---|---|
| `use_xnetvn_agent_hub_wp` | Kế thừa từ `edit_posts` (hoặc cấu hình ghi đè) | Author, Editor, Administrator | Cho phép người dùng tạo, xem và quản lý các khóa API của **chính mình**, đồng thời sử dụng các khóa đó để gọi MCP Server. |
| `manage_xnetvn_agent_hub_wp` | Kế thừa từ `manage_options` (hoặc Super Admin trên Multisite) | Administrator, Super Admin | Cho phép xem danh sách khóa của toàn bộ người dùng, thu hồi khóa của người khác, xem toàn bộ nhật ký kiểm toán và chỉnh sửa cấu hình toàn site. |

---

## 3. Ranh Giới Giữa Chủ Sở Hữu & Quản Trị Viên

- **Chủ sở hữu khóa (Key Owner):**
  - Chỉ có quyền xem danh sách khóa do chính mình tạo ra.
  - Chỉ có quyền thực hiện thao tác **Xoay vòng khóa (Rotate)** đối với khóa của mình. Quản trị viên không thể xoay vòng khóa thay cho chủ sở hữu để ngăn chặn việc chiếm đoạt khóa bí mật mới sinh ra.
  - Khi tạo khóa có cấp độ `destructive` hoặc `admin`, chủ sở hữu bắt buộc phải nhập lại mật khẩu tài khoản WordPress hiện tại để xác thực lại danh tính.
- **Quản trị viên (Site Administrator):**
  - Có quyền xem danh sách khóa toàn site (nhưng không thể xem secret).
  - Có quyền **Thu hồi (Revoke)** bất kỳ khóa nào của bất kỳ người dùng nào nếu nghi ngờ bị lộ hoặc vi phạm chính sách bảo mật.
  - Có quyền cấu hình trần rủi ro của site (`site_ceiling`) và danh sách các Toolsets được kích hoạt trên hệ thống.

---

## 4. Quản Lý Quyền Trên WordPress Multisite (Mạng Nhiều Trang)

- Trên mạng Multisite, mỗi site con có cơ sở dữ liệu khóa và cài đặt độc lập.
- Super Administrator có quyền quản trị mặc định nhưng nếu site con có cấu hình chính sách từ chối tường minh, ranh giới an toàn tại hook `map_meta_cap` sẽ chặn quyền ủy quyền trước khi áp dụng cơ chế đặc quyền tối cao của WordPress Core.
- Việc chuyển đổi ngữ cảnh site (`switch_to_blog()`) luôn tính toán lại chính xác danh sách vai trò, cài đặt và khóa Pepper của site đích.
