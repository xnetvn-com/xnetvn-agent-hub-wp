# Tiêu Chuẩn Thiết Kế Giao Diện Lập Trình (API Standards)

> **Quy chuẩn thiết kế giao thức MCP (Model Context Protocol) và giao diện REST API quản trị trong xNetVN Agent Hub WP.**

---

## 1. Ranh Giới Hai Giao Diện Độc Lập

Hệ thống cung cấp hai bề mặt API hoàn toàn độc lập với các mục đích và cơ chế bảo vệ khác nhau:

```
                                  HỆ THỐNG API
                                       │
            ┌──────────────────────────┴──────────────────────────┐
            ▼                                                     ▼
    [1. Tuyến Giao Thức MCP]                            [2. Tuyến Quản Trị REST]
- Tuyến: /wp-json/xnetvn-agent-hub-wp/mcp          - Tuyến: /wp-json/xnetvn-agent-hub-wp/v1/*
- Dành cho: AI Agents                              - Dành cho: Quản trị viên tại Dashboard WP
- Xác thực: Bearer Token duy nhất                  - Xác thực: Cookie WP + wp_rest Nonce
- Từ chối Cookie & Nonce (chống CSRF)              - Từ chối Bearer Token
```

---

## 2. Tiêu Chuẩn Giao Thức MCP (MCP Specification)

### 2.1 Định Danh & Đặt Tên Công Cụ (Tool Naming)
- **Cú pháp định danh:** `xnetvn/<verb>-<noun>` (viết thường hoàn toàn, phân tách bằng dấu gạch ngang).
- **Ví dụ chuẩn:**
  - `xnetvn/get-session-info`
  - `xnetvn/get-site-info`
  - `xnetvn/discover-abilities`
  - `xnetvn/get-ability-info`
  - `xnetvn/execute-ability`
  - `xnetvn/list-posts`
  - `xnetvn/create-post`

### 2.2 Ràng Buộc JSON Schema Nghiêm Ngặt
- Mọi Ability bắt buộc phải khai báo đầy đủ **Input Schema** và **Output Schema** dưới dạng root object.
- **Input Schema:**
  - `type: "object"`
  - `additionalProperties: false` (ngăn chặn chèn các tham số lạ ngoài ý muốn).
  - Khai báo rõ kiểu dữ liệu, độ dài tối đa (`maxLength`), định dạng chuỗi regex (`pattern`) và giá trị mặc định (`default`).
- **Output Schema:**
  - Cấu trúc phản hồi phải rõ ràng, có kiểu dữ liệu tường minh.
  - Các mảng dữ liệu (collections) phải được bọc trong object có phân trang: `{ items: [...], total: int, page: int, total_pages: int }`.

### 2.3 Quản Lý Cấp Độ Rủi Ro (Risk Tiers)
Mọi công cụ phải thuộc đúng 1 trong 4 cấp độ:
- `read`: Chỉ đọc dữ liệu không nhạy cảm (idempotent, an toàn).
- `write`: Tạo hoặc cập nhật nội dung (có side-effect nhưng có thể khôi phục).
- `destructive`: Xóa vĩnh viễn dữ liệu (không thể hoàn tác).
- `admin`: Thao tác thay đổi cấu hình hoặc can thiệp sâu hệ thống.

---

## 3. Tiêu Chuẩn REST API Quản Trị (`/v1/*`)

### 3.1 Quy Chuẩn Endpoint
- **Phương thức HTTP (HTTP Methods):**
  - `GET`: Truy vấn dữ liệu (không có side-effects).
  - `POST`: Tạo mới tài nguyên (ví dụ: tạo khóa API).
  - `PATCH` / `PUT`: Cập nhật tài nguyên (chỉ cho phép thu hẹp quyền - narrow-only).
  - `DELETE`: Xóa hoặc thu hồi tài nguyên (ví dụ: thu hồi khóa API).
- **Các endpoint chính:**
  - `GET /wp-json/xnetvn-agent-hub-wp/v1/keys`: Danh sách khóa API của người dùng hoặc toàn site.
  - `POST /wp-json/xnetvn-agent-hub-wp/v1/keys`: Tạo khóa API mới.
  - `POST /wp-json/xnetvn-agent-hub-wp/v1/keys/{id}/rotate`: Xoay vòng khóa API nguyên tử.
  - `DELETE /wp-json/xnetvn-agent-hub-wp/v1/keys/{id}`: Thu hồi khóa API.
  - `GET /wp-json/xnetvn-agent-hub-wp/v1/audit`: Truy vấn nhật ký kiểm toán có phân trang.
  - `GET /wp-json/xnetvn-agent-hub-wp/v1/settings`: Lấy cấu hình toàn site.
  - `POST /wp-json/xnetvn-agent-hub-wp/v1/settings`: Cập nhật cấu hình site.

### 3.2 Quy Chuẩn Mã Phản Hồi HTTP (Status Codes)
- `200 OK`: Yêu cầu thành công, có dữ liệu trả về.
- `201 Created`: Tạo tài nguyên thành công (kèm dữ liệu tài nguyên mới).
- `204 No Content`: Thao tác thành công, không có nội dung trả về.
- `400 Bad Request`: Dữ liệu đầu vào sai cú pháp hoặc vi phạm schema.
- `401 Unauthorized`: Thiếu thông tin chứng thực hoặc chứng thực không hợp lệ.
- `403 Forbidden`: Người dùng không có quyền hạn thực hiện hành động.
- `404 Not Found`: Không tìm thấy tài nguyên tương ứng.
- `429 Too Many Requests`: Vượt quá giới hạn tần suất yêu cầu.
- `500 Internal Server Error`: Lỗi máy chủ không mong muốn (che giấu chi tiết lỗi nhạy cảm).
- `503 Service Unavailable`: Dịch vụ tạm thời không khả dụng (ví dụ: thiếu plugin phụ thuộc).

## MCP tool execution errors

Tools use isError=true with fixed English reason/message text. Reviewed reasons: invalid_params, forbidden, not_found, post_type_unavailable, taxonomy_unavailable, duplicate_term, duplicate_user, protected_account, role_unavailable, cooldown_active, invalid_state, unsupported_operation, conflict, partial_failure, restore_failed, payload_too_large, file_type_not_allowed, image_too_large, quota_exceeded, remote_fetch_disabled, remote_fetch_refused, remote_fetch_failed, rate_limited, duplicate_comment, comments_closed, internal_error. Provider messages/data never pass through. Hidden tools retain the uniform tool_unavailable response. Unknown mutation outcomes require reconciliation before retrying. See ADR-0015, ADR-0017 and ADR-0018.
