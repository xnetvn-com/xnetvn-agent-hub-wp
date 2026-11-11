# Các Câu Hỏi Thường Gặp (FAQ)

> **Giải đáp các câu hỏi thường gặp về cài đặt, vận hành và tính năng của xNetVN Agent Hub WP.**

---

### Q1: xNetVN Agent Hub khác gì so với REST API mặc định của WordPress?
**Trả lời:**  
WordPress REST API được thiết kế cho các ứng dụng web thông thường sử dụng Cookie hoặc Application Passwords với quyền hạn bằng đúng quyền của người dùng. Trong khi đó, **xNetVN Agent Hub**:
1. Chuẩn hóa giao thức theo chuẩn **Model Context Protocol (MCP)** dành riêng cho AI Agents.
2. Cung cấp cơ chế **Khóa API sở hữu cá nhân (User-Owned API Keys)** với khả năng giới hạn phạm vi hạt mịn (Scope narrowing), giới hạn trần rủi ro (`read`, `write`, `destructive`) và giới hạn theo từng bộ công cụ (Toolsets).
3. Tách biệt hoàn toàn xác thực: Tuyến MCP **chỉ nhận Bearer Token** và từ chối Cookie để triệt tiêu nguy cơ CSRF.
4. Tích hợp sẵn bộ lọc dữ liệu nhạy cảm (Output Pipeline), giới hạn tần suất trượt và nhật ký kiểm toán không lưu secret.

---

### Q2: Tại sao tôi không thể sử dụng Cookie đăng nhập để gọi MCP Server?
**Trả lời:**  
Đây là nguyên tắc bảo mật bất biến. Nếu cho phép Cookie đăng nhập gọi tuyến MCP, bất kỳ trang web độc hại nào người dùng truy cập trong trình duyệt đều có thể thực hiện tấn công CSRF hoặc DNS Rebinding để gửi lệnh ngầm đến AI Agent trên WordPress. Do đó, tuyến MCP bắt buộc phải sử dụng Bearer Token với khóa API bí mật được cấp phát riêng.

---

### Q3: Nếu người dùng bị hạ quyền hoặc bị xóa tài khoản, khóa API của họ sẽ ra sao?
**Trả lời:**  
- **Khi bị hạ quyền:** Hệ thống kiểm tra quyền hạn thực tế của người dùng theo thời gian thực tại mỗi yêu cầu. Ví dụ: Nếu một Biên tập viên bị chuyển thành Cộng tác viên, khóa API của họ sẽ lập tức bị từ chối khi thực hiện các hành động xuất bản bài viết, ngay cả khi phạm vi khóa trước đó có quyền `write`.
- **Khi tài khoản bị xóa:** Khóa API của tài khoản đó sẽ tự động bị thu hồi vĩnh viễn và mọi yêu cầu sẽ bị từ chối với mã lỗi `401 Unauthorized`.

---

### Q4: Plugin có làm lộ mật khẩu, thông tin cá nhân hoặc bài viết nháp không?
**Trả lời:**  
- **Mật khẩu & Secrets:** Tuyệt đối không. Đường ống dữ liệu đầu ra (`OutputPipeline`) loại bỏ hoàn toàn các trường mật khẩu, hash băm, token hoặc application passwords.
- **Bài viết nháp / Riêng tư:** AI Agent chỉ có thể đọc hoặc xem bài viết nháp nếu chính tài khoản WordPress sở hữu khóa API đó có quyền xem bài viết nháp (quyền `edit_others_posts` hoặc tác giả của bài nháp đó).

---

### Q5: Làm thế nào để xoay vòng khóa API (Key Rotation) mà không làm gián đoạn dịch vụ?
**Trả lời:**  
Khi bạn thực hiện thao tác **Xoay vòng khóa (Rotate Key)** trong trang quản trị:
1. Hệ thống sẽ sinh ra một khóa API mới nguyên tử.
2. Khóa cũ sẽ được cấp một thời gian ân hạn (Grace Period) mặc định là **24 giờ** trước khi hết hạn hoàn toàn.
3. Trong 24 giờ này, bạn có thể cập nhật khóa mới vào cấu hình của AI Agent (ví dụ: Claude Desktop, Cursor) mà không sợ hệ thống bị gián đoạn.

---

### Q6: Plugin có hỗ trợ WordPress Multisite (Mạng nhiều website) không?
**Trả lời:**  
Có. Plugin hỗ trợ đầy đủ WordPress Multisite. Mỗi site con trong mạng có cơ sở dữ liệu khóa, nhật ký kiểm toán, cài đặt và khóa Pepper hoàn toàn độc lập. Quyền của Super Administrator được kiểm soát chặt chẽ để đảm bảo không bị rò rỉ quyền hạn giữa các site.
