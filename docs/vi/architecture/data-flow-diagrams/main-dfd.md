# Sơ Đồ Luồng Dữ Liệu Chính (Data Flow Diagram - DFD)

> **Mô hình hóa đường đi của dữ liệu qua các tầng xử lý của xNetVN Agent Hub WP.**

---

## 1. Sơ Đồ Xử Lý Luồng Yêu Cầu MCP (MCP Request Flow)

```mermaid
sequenceDiagram
    autonumber
    actor Client as AI Agent (Client)
    participant Guard as Transport & Origin Guard
    participant Auth as Authenticator & Pepper
    participant Limiter as Sliding Window Limiter
    participant Gateway as MCP Gateway
    participant Policy as Capability Policy
    participant Ability as Ability Handler
    participant Pipeline as Output Pipeline
    participant Audit as Audit Logger (Shutdown)
    participant DB as MySQL Database

    Client->>Guard: HTTP POST /wp-json/xnetvn-agent-hub-wp/mcp (Bearer Token)
    Guard->>Guard: Kiểm tra HTTPS & Tiêu đề Origin
    alt Không thỏa mãn Guard
        Guard-->>Client: Phản hồi 403 Forbidden / 400 Bad Request
    else Guard Hợp Lệ
        Guard->>Auth: Chuyển tiếp yêu cầu
        Auth->>Auth: Phân tích cú pháp Token xnv_<key_id>_<secret>
        Auth->>DB: Đọc bản ghi Key & Pepper (hash_equals)
        Auth->>Limiter: Kiểm tra hạn mức 120 req/min/key & 10 fail/min/ip
        alt Xác thực thất bại hoặc vượt Rate Limit
            Auth-->>Client: Phản hồi 401 Unauthorized / 429 Too Many Requests
        else Xác thực thành công
            Auth->>Gateway: Khởi tạo AuthContext an toàn
            Gateway->>Policy: Kiểm tra quyền giao thoa (UserCap ∩ KeyTier ∩ SiteCeiling)
            alt Quyền hạn bị từ chối
                Policy-->>Client: Phản hồi Tool Execution Error (Permission Denied)
            else Quyền hạn hợp lệ
                Policy->>Ability: Thực thi Handler nội bộ (rest_do_request)
                Ability->>Pipeline: Trả về kết quả thô
                Pipeline->>Pipeline: Lọc dữ liệu nhạy cảm & Kiểm tra Output Schema
                Pipeline-->>Client: Phản hồi JSON-RPC kết quả chuẩn
                Ability->>Audit: Lưu sự kiện kiểm toán vào bộ đệm (RequestScope)
            end
        end
    end
    Audit->>DB: Ghi hàng loạt (Batch INSERT) tại shutdown hook
```

---

## 2. Sơ Đồ Luồng Xoay Vòng Khóa API Nguyên Tử (Atomic Key Rotation Flow)

```mermaid
sequenceDiagram
    autonumber
    actor Admin as Quản Trị Viên (Browser)
    participant REST as KeysController (REST API)
    participant Lock as WpOwnerLock (MySQL GET_LOCK)
    participant Service as KeyService
    participant DB as MySQL Database (InnoDB)

    Admin->>REST: POST /wp-json/xnetvn-agent-hub-wp/v1/keys/{id}/rotate (Cookie + Nonce)
    REST->>REST: Xác thực Cookie & Kiểm tra wp_rest Nonce
    REST->>Lock: Yêu cầu khóa độc quyền người dùng (WpOwnerLock)
    alt Không lấy được khóa
        Lock-->>Admin: Phản hồi 409 Conflict (Thao tác đang diễn ra)
    else Khóa thành công
        Lock->>Service: Thực hiện xoay vòng khóa
        Service->>DB: Khởi động giao dịch mysqli BEGIN TRANSACTION
        Service->>DB: SELECT khóa cũ FOR UPDATE
        Service->>Service: Sinh khóa mới xnv_<new_id>_<secret> & Tính HMAC
        Service->>DB: INSERT khóa mới vào bảng *_keys
        Service->>DB: UPDATE khóa cũ đặt expires_at = NOW() + 24 giờ
        Service->>DB: COMMIT TRANSACTION
        Service->>Lock: Giải phóng khóa độc quyền người dùng
        Service-->>Admin: Trả về khóa mới (Secret chỉ hiển thị 1 lần duy nhất)
    end
```
