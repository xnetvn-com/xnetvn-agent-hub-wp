# Tổng Quan Kiến Trúc Hệ Thống (System Overview)

> **Mô tả chi tiết kiến trúc kỹ thuật, mối quan hệ giữa các thành phần và ranh giới trách nhiệm trong xNetVN Agent Hub WP.**

---

## 1. Bản Đồ Thành Phần Kiến Trúc Cốt Lõi

Hệ thống được tổ chức theo các phân hệ nghiệp vụ độc lập, giao tiếp với nhau thông qua Interfaces (Contracts):

```
┌────────────────────────────────────────────────────────────────────────┐
│                        XNetVN\AgentHubWP\Plugin                        │
│                     (Hạt nhân điều phối Kernel)                        │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │ Khởi tạo & nạp các ServiceProvider
                                   ▼
┌───────────────────┬───────────────────┬────────────────────────────────┐
│ 1. PHÂN HỆ AUTH   │ 2. PHÂN HỆ MCP    │ 3. PHÂN HỆ REST & ADMIN        │
│ - Authenticator   │ - ServerGateway   │ - ManagementGate               │
│ - KeyService      │ - Registry        │ - KeysController               │
│ - KeyGenerator    │ - SystemPack      │ - AuditController              │
│ - KeyHasher       │ - OutputPipeline  │ - SettingsController           │
│ - PepperStore     │ - SchemaBuilder   │ - Pages (Admin UI)             │
├───────────────────┼───────────────────┼────────────────────────────────┤
│ 4. PHÂN HỆ BẢO VỆ │ 5. PHÂN HỆ CHÍNH  │ 6. PHÂN HỆ LƯU TRỮ             │
│ - RouteGate       │   SÁCH (POLICY)   │ - WpdbKeyRepository            │
│ - TransportGuard  │ - Policy          │ - WpdbAuditRepository          │
│ - OriginGuard     │ - TierResolver    │ - WpSiteSettings               │
│ - SlidingWindow-  │ - HardDeny        │ - WpOwnerLock                  │
│   Limiter         │ - ToolVisibility  │ - Migrator                     │
└───────────────────┴───────────────────┴────────────────────────────────┘
```

---

## 2. Chi Tiết Trách Nhiệm Từng Phân Hệ

### 2.1 Phân Hệ Xác Thực & Khóa API (`src/Auth/`)
- **`KeyGenerator`:** Sinh chuỗi ngẫu nhiên 60 ký tự tuân thủ định dạng `xnv_<key_id>_<secret>`.
- **`KeyHasher`:** Thực thi tính toán chữ ký `HMAC-SHA256` với khóa Pepper bí mật.
- **`PepperStore`:** Lưu trữ và đọc khóa Pepper độc lập từ cơ sở dữ liệu (`autoload = 'no'`).
- **`Authenticator`:** Nhận request, trích xuất Bearer Token, kiểm tra giới hạn tần suất, đối chiếu mã băm và khởi tạo đối tượng `AuthContext`.
- **`KeyService`:** Nghiệp vụ quản lý vòng đời khóa: tạo mới, xoay vòng nguyên tử (Rotate), thu hồi (Revoke) và cập nhật phạm vi quyền (chỉ cho phép thu hẹp - narrow-only).

### 2.2 Phân Hệ MCP Gateway & Abilities (`src/Mcp/`, `src/Abilities/`)
- **`ServerGateway`:** Điểm duy nhất trong toàn bộ plugin tương tác với plugin bên ngoài `mcp-adapter`. Đóng gói chuyển đổi giao thức mạng sang lời gọi Ability nội bộ.
- **`Registry`:** Quản lý kho danh mục Abilities được phép, đăng ký các Ability Pack và áp dụng chính sách phân quyền.
- **`OutputPipeline`:** Chuỗi xử lý đầu ra: loại bỏ trường nhạy cảm, ẩn thông tin PII, định dạng text (`rendered` / `raw`) và xác thực lại tính toàn vẹn với JSON Schema.

### 2.3 Phân Hệ Chính Sách & Đánh Giá Quyền (`src/Policy/`)
- **`TierResolver`:** Phân giải và so sánh các cấp độ rủi ro (`read` < `write` < `destructive` < `admin`).
- **`Policy`:** Tính toán ma trận quyền giao thoa giữa Quyền User WordPress $\cap$ Scope Khóa API $\cap$ Trần Site $\cap$ Toolset.
- **`HardDeny`:** Danh sách cấm cứng nhằm chặn đứng các hành vi gọi công cụ nguy hiểm.

### 2.4 Phân Hệ Lưu Trữ & Cơ Sở Dữ Liệu (`src/Persistence/`)
- **`WpdbKeyRepository`:** Thao tác bảng `wp_xnetvn_agent_hub_keys` sử dụng 100% Prepared Statements (`$wpdb->prepare`).
- **`WpdbAuditRepository`:** Thao tác bảng `wp_xnetvn_agent_hub_audit`, hỗ trợ ghi theo lô tại shutdown hook.
- **`WpOwnerLock`:** Triển khai hàm `GET_LOCK()` của MySQL bảo vệ thao tác ghi đồng thời của người dùng.
- **`Migrator`:** Quản lý phiên bản cấu trúc bảng cơ sở dữ liệu forward-only.

### 2.5 Phân Hệ Quản Trị & REST (`src/Rest/`, `src/Admin/`)
- Cung cấp các endpoint REST API nội bộ bảo vệ bởi Cookie + Nonce phục vụ màn hình quản trị của WordPress.
- Xây dựng giao diện trực quan tại trang **Hồ sơ người dùng** và **Agent Hub** tuân thủ chuẩn WCAG 2.2 AA.
