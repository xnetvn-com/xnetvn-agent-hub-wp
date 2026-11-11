# Bảng Thuật Ngữ Kỹ Thuật (Glossary)

> **Bảng tra cứu và định nghĩa các khái niệm, thuật ngữ kỹ thuật sử dụng trong xNetVN Agent Hub WP.**

---

| Thuật Ngữ | Tiếng Anh / Viết Tắt | Định Nghĩa & Ý Nghĩa Kỹ Thuật |
|---|---|---|
| **AI Agent** | Artificial Intelligence Agent | Thực thể phần mềm tự hành được điều khiển bởi mô hình ngôn ngữ lớn (LLM), có khả năng suy luận, lập kế hoạch và sử dụng các công cụ (tools) để hoàn thành mục tiêu. |
| **Model Context Protocol** | MCP | Giao thức mở tiêu chuẩn hóa phương thức các mô hình AI kết nối và truy xuất dữ liệu cũng như thực thi công cụ (Tools/Resources/Prompts) từ các máy chủ bên ngoài an toàn. |
| **Abilities API** | WordPress Abilities API | Giao diện API mới của hệ sinh thái WordPress nhằm định nghĩa các khả năng tương tác của website theo dạng các hành động có cấu trúc dữ liệu đầu vào/đầu ra rõ ràng. |
| **MCP Adapter** | `mcp-adapter` | Plugin adapter chính thức của WordPress, đóng vai trò cầu nối chuyển dịch giao thức mạng MCP sang hệ thống Abilities nội bộ của WordPress. |
| **Khóa API (API Key)** | User-Owned API Key | Chuỗi chứng thực định danh kép gồm ID công khai và bí mật (`xnv_<key_id>_<secret>`), thuộc sở hữu của một người dùng cụ thể và bị ràng buộc bởi quyền của người dùng đó. |
| **Cấp Độ Rủi Ro** | Risk Tier | Phân loại mức độ ảnh hưởng của hành động: `read` (chỉ đọc), `write` (tạo/sửa), `destructive` (xóa/hủy), `admin` (quản trị hệ thống). |
| **Bộ Công Cụ** | Toolset | Nhóm các Abilities liên quan theo miền nghiệp vụ: `system` (hệ thống), `content` (nội dung bài viết), `taxonomy` (phân loại), `blocks` (khối Gutenberg), `media` (đa phương tiện), `comments` (bình luận), `users` (người dùng và vai trò), `appearance` (giao diện), `settings` (cài đặt), `plugins` (quản lý plugin), `maintenance` (bảo trì site). |
| **Phân Quyền Giao Thoa** | Capability Intersection | Cơ chế tính toán quyền thực tế của Agent bằng cách lấy giao điểm toán học giữa quyền gốc của tài khoản người dùng, phạm vi khóa API và giới hạn trần của website. |
| **Pepper** | Cryptographic Pepper | Chuỗi bí mật ngẫu nhiên 32-byte lưu trữ riêng biệt tại server, dùng kết hợp với Secret của khóa API trong hàm băm `HMAC-SHA256` để phòng chống tấn công bảng Rainbow khi cơ sở dữ liệu bị lộ. |
| **Timing Attack** | Timing Attack / Side-Channel | Kỹ thuật tấn công đo thời gian phản hồi của máy chủ để suy đoán từng byte mật khẩu hoặc token. Được phòng ngừa bằng `hash_equals()` và Dummy HMAC. |
| **Sliding Window Limiter** | Sliding Window Rate Limiter | Thuật toán giới hạn tần suất yêu cầu dựa trên cửa sổ thời gian trượt, giúp làm mịn lưu lượng và ngăn chặn tấn công vét cạn (brute-force). |
| **Atomic Rotation** | Atomic Key Rotation | Quy trình xoay vòng khóa API diễn ra trong một giao dịch cơ sở dữ liệu nguyên tử (ACID), đảm bảo không bao giờ xảy ra trạng thái mất khóa hoặc trùng lặp. |
| **Output Pipeline** | Output Pipeline / Sanitization | Chuỗi xử lý lọc dữ liệu đầu ra: loại bỏ các liên kết nội bộ không cần thiết, ẩn thông tin nhạy cảm, định dạng nội dung an toàn và xác thực cấu trúc theo JSON Schema. |
| **Prompt Injection** | Prompt Injection Attack | Kỹ thuật tấn công chèn lệnh độc hại vào nội dung dữ liệu nhằm đánh lừa mô hình AI thực thi các hành động trái phép. Phòng chống bằng cách đối xử mọi nội dung trang web là untrusted data. |
| **Audit Trail** | Audit Trail / Audit Logging | Nhật ký kiểm toán bất biến ghi lại lịch sử các yêu cầu MCP: thời gian, định danh khóa, người dùng, tên công cụ và kết quả thực thi (không ghi giá trị tham số). |
| **GDPR / CCPA** | Personal Data Privacy Regulations | Các quy định quốc tế về bảo vệ dữ liệu cá nhân, yêu cầu hệ thống phải hỗ trợ trích xuất (Export) và xóa bỏ (Erasure) thông tin liên quan đến người dùng. |
