# Kiến Trúc Hệ Thống & Ranh Giới Bất Biến

> **Tài liệu đặc tả kiến trúc tổng thể, mô hình phân tầng, nguyên lý phân quyền và các ranh giới bảo mật bất biến của xNetVN Agent Hub WP.**

---

## 1. Nguyên Tắc Thiết Kế Cốt Lõi

1. **Clean Architecture & Hexagonal Architecture (Ports and Adapters):**
   - Tách biệt hoàn toàn tầng Domain Logic khỏi các chi tiết kỹ thuật hạ tầng (WordPress Database, HTTP Request, MCP Adapter).
   - Tầng Domain (`src/Domain/`) không phụ thuộc vào bất kỳ hàm hay hằng số nào của WordPress.
   - Mọi tương tác với cơ sở dữ liệu và hệ thống ngoài đều thông qua các Contracts (Interfaces) tại `src/Contracts/`.
2. **Nguyên Lý Đặc Quyền Tối Thiểu (Principle of Least Privilege):**
   - AI Agent chỉ được cấp quyền tối thiểu cần thiết để hoàn thành nhiệm vụ.
   - Không cho phép thực thi mã nguồn tự do, truy vấn SQL trực tiếp hoặc chỉnh sửa cấu hình hệ thống máy chủ.
3. **Phân Quyền Giao Thoa (Capability Intersection):**
   - Khóa API không bao giờ có thể cấp quyền vượt quá quyền hạn của chính người dùng sở hữu khóa đó.
4. **Phòng Thủ Đa Tầng (Defense in Depth):**
   - Mỗi yêu cầu MCP phải vượt qua 7 vòng kiểm soát liên tiếp: Route Matcher -> Transport/TLS Guard -> Origin Guard -> Authenticator (Token Parsing & HMAC) -> Rate Limiter -> Capability/Policy Engine -> Object-level Authorization.

---

## 2. Mô Hình Phân Tầng (Layered Structure)

```
┌─────────────────────────────────────────────────────────────┐
│ 1. TẦNG GIAO VẬN & CỔNG VÀO (HTTP / TRANSPORT LAYER)        │
│    - RouteGate: Khớp chính xác tuyến /xnetvn-agent-hub-wp/mcp│
│    - TransportGuard: Bắt buộc TLS/HTTPS                     │
│    - OriginGuard: Kiểm duyệt tiêu đề Origin                 │
└─────────────────────────────────────────────────────────────┘
                               ▲
                               │
┌─────────────────────────────────────────────────────────────┐
│ 2. TẦNG XÁC THỰC & GIỚI HẠN (AUTH & RATE LIMIT LAYER)       │
│    - TokenParser: Phân tích cú pháp xnv_<key_id>_<secret>   │
│    - KeyHasher & PepperStore: HMAC-SHA256, timing-safe      │
│    - Authenticator: Sinh AuthContext request-local          │
│    - SlidingWindowLimiter: 120 req/min/key, 10 fail/min/ip  │
└─────────────────────────────────────────────────────────────┘
                               ▲
                               │
┌─────────────────────────────────────────────────────────────┐
│ 3. TẦNG KẾT NỐI MCP & CHÍNH SÁCH (MCP GATEWAY & POLICY)     │
│    - ServerGateway: Cổng giao tiếp với mcp-adapter          │
│    - Registry: Quản lý danh mục Abilities đã thẩm định      │
│    - Policy & TierResolver: Tính toán quyền giao thoa       │
│    - HardDeny & ToolVisibility: Loại trừ truy cập trái phép │
└─────────────────────────────────────────────────────────────┘
                               ▲
                               │
┌─────────────────────────────────────────────────────────────┐
│ 4. TẦNG GÓI CHỨC NĂNG (ABILITY PACKS)                       │
│    - SystemPack: 5 công cụ hệ thống (Discovery, Info, Exec) │
│    - ContentPack (19): bài viết, bản sửa đổi, meta, media   │
│    - Taxonomy (9), Media (7), Comments (10), Users (12)     │
│    - OutputPipeline: Lọc dữ liệu, kiểm tra Schema, Redaction│
└─────────────────────────────────────────────────────────────┘
                               ▲
                               │
┌─────────────────────────────────────────────────────────────┐
│ 5. TẦNG DỮ LIỆU & KIỂM TOÁN (PERSISTENCE & AUDIT)           │
│    - WpdbKeyRepository: CRUD khóa API với Prepared SQL      │
│    - WpdbAuditRepository: Ghi nhật ký kiểm toán không secret│
│    - WpOwnerLock: Khóa phân tán bảo vệ cập nhật đồng thời   │
│    - WpPrivacyStore: Hỗ trợ GDPR Export & Erasure           │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Ranh Giới Bảo Mật Bất Biến (Security Invariants)

Hệ thống cam kết duy trì tuyệt đối các ranh giới bảo mật sau đây trong mọi phiên bản:

### 3.1 Ranh Giới Xác Thực Tuyến Đường (Exact Route Matching)
- **Tuyến MCP (`/wp-json/xnetvn-agent-hub-wp/mcp`):**
  - Chỉ chấp nhận header `Authorization: Bearer`; token có dạng `xnv_<key_id>_<secret>`.
  - Từ chối mọi yêu cầu sử dụng Cookie session, ngay cả khi có `wp_rest` nonce hợp lệ. Điều này ngăn chặn hoàn toàn tấn công CSRF thông qua trình duyệt người dùng.
- **Tuyến Quản Trị REST (`/wp-json/xnetvn-agent-hub-wp/v1/*`):**
  - Chỉ chấp nhận Cookie phiên người dùng đăng nhập WordPress + `wp_rest` nonce hợp lệ.
  - Từ chối xác thực bằng Bearer API Key.

### 3.2 Quy Chuẩn Khóa API & Mật Mã Học
- Khóa gồm định danh công khai (`key_id`, 12 ký tự Base32) và mã bí mật (`secret`, 32 bytes ngẫu nhiên Base64URL không padding).
- Chỉ lưu trữ chuỗi băm:
  $$\text{secret\_hash} = \text{HMAC-SHA256}(\text{secret}, \text{pepper})$$
- Pepper được tạo ngẫu nhiên 32-byte độc lập trên từng site (`xnetvn_agent_hub_wp_pepper`), không dùng chung với WordPress AUTH_SALT / SECURE_AUTH_SALT.
- So sánh hash sử dụng `hash_equals()` chống tấn công dò thời gian (timing attacks).
- Nếu không tìm thấy key_id trong DB, hệ thống vẫn thực hiện tính toán HMAC giả lập (dummy HMAC) để đảm bảo thời gian phản hồi đồng nhất.

### 3.3 Độc Lập Khóa Mức Hàng Khi Xoay Vòng Khóa (Atomic Key Rotation)
- Xoay vòng khóa API là hành động quan trọng nhất.
- Hệ thống yêu cầu kết nối `mysqli` độc lập có hỗ trợ giao dịch ACID trên bảng InnoDB.
- Khóa bản ghi hiện tại bằng câu lệnh `SELECT ... FOR UPDATE`, chèn bản ghi khóa mới và đặt thời gian hết hạn ân hạn (`grace period`) 24 giờ cho khóa cũ trong cùng một giao dịch.

### 3.4 Cấm Tuyệt Đối Các Công Cụ Sau (Permanent Exclusions)
- **Không bao giờ cung cấp tool thực thi mã tùy ý:** Không có hàm `eval()`, `exec()`, `passthru()`, `system()`, `shell_exec()`.
- **Không bao giờ cho phép truy vấn SQL trực tiếp:** Không có tool gửi câu lệnh SQL tùy ý đến cơ sở dữ liệu.
- **Không bao giờ cho phép sửa tệp hệ thống:** Không cho phép chỉnh sửa tệp `.php`, `wp-config.php`, `.htaccess`.
- **Không cho phép quản lý Secrets qua MCP:** Agent không có tool để đọc hash, tạo khóa API khác hoặc thay đổi quyền quản trị plugin.

---

## 4. Phân Quyền Giao Thoa (Capability Intersection Model)

Để một Ability được phép thực thi, tất cả các điều kiện trong ma trận sau phải thỏa mãn đồng thời:

$$\text{Allow} = \text{UserCap} \land (\text{AbilityTier} \le \text{KeyTierMax}) \land (\text{AbilityTier} \le \text{SiteCeiling}) \land \text{ToolsetEnabled} \land \text{ScopeAllow} \land \neg \text{ScopeDeny}$$

Trong đó:
- **`UserCap`:** Người dùng WordPress sở hữu khóa phải có quyền tương ứng (ví dụ: `edit_posts` cho việc tạo bài, `manage_options` cho việc cấu hình).
- **`RiskTier`:** Phân loại rủi ro của Ability:
  - `read` (Cấp 1): Chỉ đọc dữ liệu không nhạy cảm.
  - `write` (Cấp 2): Tạo hoặc cập nhật nội dung.
  - `destructive` (Cấp 3): Xóa bài viết vĩnh viễn, xóa chuyên mục.
  - `admin` (Cấp 4): Thay đổi cấu hình cốt lõi (mặc định bị khóa ở cấp độ site).
- **`KeyTierMax`:** Cấp độ rủi ro tối đa được chỉ định khi tạo khóa API.
- **`SiteCeiling`:** Giới hạn rủi ro tối đa toàn site do Quản trị viên hệ thống thiết lập trong Cài đặt (mặc định: `destructive`).

---

## 5. Xử Lý Nội Dung & Đường Ống Dữ Liệu Đầu Ra (Output Pipeline)

- Tất cả nội dung trả về từ WordPress được xem là **Dữ liệu Không Tin Cậy (Untrusted Content Data)**.
- Dữ liệu trả về cho AI Agent bắt buộc phải đi qua `OutputPipeline`:
  1. Loại bỏ các trường siêu dữ liệu nội bộ không khai báo trong Schema (`_links`, `_embedded`).
  2. Ẩn toàn bộ thông tin nhạy cảm (mật khẩu người dùng, hash, application passwords, private keys).
  3. Định dạng bài viết: Hỗ trợ `rendered` (đã qua lọc KSES), `excerpt` và `raw` (chỉ khi có quyền chỉnh sửa đối tượng cụ thể).
  4. Xác thực lại toàn bộ cấu trúc đầu ra theo đúng `outputSchema` đã công bố. Nếu kết quả không khớp schema hoặc bị lỗi, hệ thống trả về lỗi thực thi an toàn thay vì trả về dữ liệu sai cấu trúc.

## Authoritative security boundary

The current authorization formula and permanent exclusions are maintained in [architecture/overview.md](architecture/overview.md). Every operation requires current core object permissions, key/site risk ceilings, enabled key toolset, allow/deny constraints, explicit admin opt-in when applicable, and a verified AuthContext on HTTP. Sanctioned uploads are through core APIs only. Do not interpret any abbreviated diagram above as permission to omit these terms.
