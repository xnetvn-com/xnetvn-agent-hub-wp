# 💡 Feature Request (Yêu Cầu Tính Năng Mới)

> **Mẫu yêu cầu tính năng mới bao gồm các trường thông tin chi tiết và cần thiết để cho AI Coding Assistant và đội ngũ phát triển hiểu rõ yêu cầu tính năng cụ thể để triển khai một cách chuyên nghiệp và triệt để.**

---

## 1. Thông Tin Tổng Quan

- **Tên tính năng (Bắt buộc):**  
  _(Tên ngắn gọn, rõ ràng mô tả tính năng mới cần bổ sung)_

- **Module / Bộ công cụ liên quan (Bắt buộc):**
  - [ ] System Pack
  - [ ] Content Pack
  - [ ] Taxonomy Pack
  - [ ] Blocks Pack
  - [ ] Media Pack
  - [ ] Comments Pack
  - [ ] Appearance Pack
  - [ ] Settings Pack
  - [ ] Quản lý Khóa API / Xác thực
  - [ ] Nhật ký Kiểm toán / GDPR
  - [ ] Khác: ___________________

- **Mức độ ưu tiên (Priority) (Bắt buộc):**
  - [ ] Cao (Cần có cho bản phát hành kế tiếp)
  - [ ] Trung bình (Quan trọng nhưng có thể linh hoạt)
  - [ ] Thấp (Cải tiến trải nghiệm, ý tưởng trong tương lai)

---

## 2. Mô Tả & Lợi Ích

- **Mô tả chi tiết (Bắt buộc):**  
  _(Mô tả chi tiết về tính năng: mục đích, cách hoạt động, các thao tác người dùng hoặc AI Agent thực hiện)_

- **Lợi ích mang lại (Bắt buộc):**  
  _(Giải thích tại sao tính năng này quan trọng và nó sẽ mang lại giá trị gì cho người dùng, website hoặc hệ sinh thái AI Agent)_

---

## 3. Kịch Bản Sử Dụng (Use Cases)

- **Đối tượng người dùng chính:**  
  _(AI Agent qua MCP / Quản trị viên WordPress qua Admin UI / Lập trình viên bên thứ ba)_

- **Kịch bản thực tế 1:**  
  - *Bước 1:* AI Agent gửi yêu cầu MCP với các tham số...
  - *Bước 2:* Plugin xác thực quyền hạn và gọi handler nội bộ...
  - *Bước 3:* Kết quả trả về theo đúng định dạng JSON Schema mong đợi...

- **Kịch bản thực tế 2:**  
  _(Các kịch bản bổ sung hoặc trường hợp biên)_

---

## 4. Yêu Cầu Kỹ Thuật (Technical Requirements)

- **Cấp độ rủi ro (Risk Tier) dự kiến:**
  - [ ] `read` (Chỉ đọc)
  - [ ] `write` (Tạo hoặc cập nhật)
  - [ ] `destructive` (Xóa hoặc hủy)
  - [ ] `admin` (Thay đổi cấu hình hệ thống)

- **Quyền hạn WordPress tối thiểu cần có (Capability):**  
  _(Ví dụ: `read`, `edit_posts`, `upload_files`, `manage_options`)_

- **Lược đồ dữ liệu đầu vào (Input Schema dự kiến):**  
  ```json
  {
    "type": "object",
    "properties": {
      "param1": { "type": "string" }
    },
    "required": ["param1"]
  }
  ```

- **Lược đồ dữ liệu đầu ra (Output Schema dự kiến):**  
  ```json
  {
    "type": "object",
    "properties": {
      "success": { "type": "boolean" }
    },
    "required": ["success"]
  }
  ```

- **Các công nghệ, hàm Core API hoặc thư viện liên quan:**  
  _(Ví dụ: rest_do_request, wp_insert_post, wpdb, v.v.)_

---

## 5. Rủi Ro Bảo Mật & Ranh Giới An Toàn

- [ ] Tính năng này có làm thay đổi cơ sở dữ liệu không?
- [ ] Tính năng này có làm lộ thông tin nhạy cảm của người dùng không?
- [ ] Dữ liệu trả về có tuân thủ quy tắc dữ liệu không tin cậy (untrusted data) không?
- [ ] Có nguy cơ bị lạm dụng để tấn công DoS hoặc Prompt Injection không?

---

## 6. Tài Liệu Tham Khảo & Ghi Chú

- **Tài liệu tham khảo:**  
  _(Liên kết đến tài liệu WordPress API, tài liệu MCP Specification hoặc các ví dụ tương tự)_

- **Ghi chú bổ sung:**  
  _(Các thông tin khác cần lưu ý cho đội ngũ phát triển)_
