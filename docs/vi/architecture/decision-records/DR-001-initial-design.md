# DR-001: Quyết Định Kiến Trúc Ban Đầu & Lựa Chọn Adapter MCP

> **Architecture Decision Record (ADR / DR-001)**  
> **Trạng thái:** Đã chấp thuận (Accepted)  
> **Ngày phê duyệt:** 2026-10-04  
> **Người tham gia quyết định:** Lead Architect, Security Specialist, Core Maintainers

---

## 1. Bối Cảnh (Context)
Khi tích hợp giao thức Model Context Protocol (MCP) vào hệ sinh thái WordPress, có hai hướng tiếp cận chính:
1. **Tự phát triển toàn bộ máy chủ giao thức MCP từ đầu trong plugin:** Tự viết socket, tự triển khai giao thức JSON-RPC qua REST và quản lý phiên.
2. **Sử dụng Official WordPress MCP Adapter (`mcp-adapter`) kết hợp với Abilities API:** Dựa trên chuẩn chính thức của cộng đồng WordPress cốt lõi để chuẩn hóa cách đăng ký và phát hiện khả năng tương tác.

Ngoài ra, cần một cơ chế xác thực an toàn tuyệt đối, vì các phương thức mặc định như Cookie, Application Passwords hoặc Basic Auth không đáp ứng được yêu cầu về ranh giới an toàn cho AI Agent:
- Cookie dễ bị tấn công CSRF / DNS Rebinding từ trình duyệt.
- Application Passwords cấp quyền toàn phần bằng quyền của người dùng mà không có cơ chế thu hẹp phạm vi hạt mịn (scope) hay giới hạn cấp độ rủi ro (risk tier).

---

## 2. Quyết Định Kiến Trúc (Decision)

1. **Ủy quyền giao vận cho `mcp-adapter` thông qua `ServerGateway`:**
   - Plugin `xnetvn-agent-hub-wp` sẽ đóng vai trò là một nhà cung cấp Ability an toàn (Capability-scoped Provider), không tự viết lại giao thức MCP.
   - Toàn bộ việc kết nối với `mcp-adapter` được đóng gói bên trong lớp `XNetVN\AgentHubWP\Mcp\ServerGateway`.
2. **Thiết kế mô hình Khóa API do người dùng sở hữu (User-Owned API Keys):**
   - Khóa có định dạng chuẩn: `xnv_<key_id>_<secret>` (tổng cộng 60 ký tự).
   - Lưu trữ dạng băm mật mã học `HMAC-SHA256` với khóa Pepper 32-byte độc lập trên từng site.
   - Quyền hạn của khóa tuân theo nguyên tắc phân quyền giao thoa: $\text{Quyền User} \cap \text{Scope Khóa} \cap \text{Trần Site} \cap \text{Toolset}$.
3. **Tách biệt rạch ròi 2 tuyến đường xác thực:**
   - Tuyến MCP (`/wp-json/xnetvn-agent-hub-wp/mcp`): Chỉ nhận Bearer Token, từ chối mọi Cookie.
   - Tuyến Quản trị REST (`/wp-json/xnetvn-agent-hub-wp/v1/*`): Chỉ nhận Cookie phiên đăng nhập của WordPress kèm `wp_rest` nonce.

---

## 3. Hệ Quả & Đánh Đổi (Consequences)

### Tích cực:
- Khả năng tương thích tối đa với hệ sinh thái WordPress Abilities trong tương lai.
- Triệt tiêu hoàn toàn rủi ro CSRF và tấn công chiếm quyền điều khiển phiên qua trình duyệt.
- Người dùng có thể tạo nhiều khóa cho các Agent khác nhau với phạm vi quyền hạn và thời hạn riêng biệt.
- Xoay vòng khóa API không làm gián đoạn dịch vụ nhờ cơ chế thời gian ân hạn 24 giờ.

### Tiêu cực / Thách thức:
- Cần cài đặt thêm plugin phụ thuộc `mcp-adapter` để máy chủ MCP hoạt động.
- Cần quản lý bảng cơ sở dữ liệu riêng (`*_keys`, `*_audit`) và quy trình migration per-site.
