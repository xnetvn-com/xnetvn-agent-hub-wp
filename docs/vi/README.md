# xNetVN Agent Hub WP

[![Phiên bản](https://img.shields.io/badge/version-v1.0.0-blue.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777bb4.svg)](INSTALL.md)
[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.9-21759b.svg)](INSTALL.md)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](LICENSE)
[![Bảo mật: OWASP](https://img.shields.io/badge/Security-OWASP%20API-red.svg)](security/threat-modeling.md)

Trước đây là xNetVN Agent Hub for WordPress.

> **xNetVN Agent Hub WP** là plugin WordPress nền tảng cung cấp máy chủ **Model Context Protocol (MCP)** chuẩn hóa, bảo mật cấp doanh nghiệp, cho phép các tác nhân Trí tuệ Nhân tạo (AI Agents) tương tác có kiểm soát, phân quyền hạt mịn với WordPress thông qua Abilities API.

---

## 📌 Mục Lục

1. [Tổng Quan Dự Án](#-tổng-quan-dự-án)
2. [Tính Năng Nổi Bật](#-tính-năng-nổi-bật)
3. [Kiến Trúc & Ranh Giới Bảo Mật](#-kiến-trúc--ranh-giới-bảo-mật)
4. [Yêu Cầu Hệ Thống](#-yêu-cầu-hệ-thống)
5. [Cài Đặt & Kích Hoạt Nhanh](#-cài-đặt--kích-hoạt-nhanh)
6. [Hướng Dẫn Sử Dụng](guides/operation-guide.md)
7. [Quản Lý Khóa API (API Keys)](#-quản-lý-khóa-api-api-keys)
8. [Kiểm Thử & Đảm Bảo Chất Lượng](#-kiểm-thử--đảm-bảo-chất-lượng)
9. [Cấu Trúc Thư Mục Tài Liệu](#-cấu-trúc-thư-mục-tài-liệu)
10. [Đóng Góp & Bản Quyền](#-đóng-góp--bản-quyền)

---

## 🚀 Tổng Quan Dự Án

Trong bối cảnh các mô hình AI Agent (như Claude Desktop, Cursor, OpenAI Agents, v.v.) phát triển mạnh mẽ, việc kết nối AI vào hệ thống CMS quản lý nội dung cốt lõi của doanh nghiệp đòi hỏi một lớp bảo vệ nghiêm ngặt:
- Không cho phép AI can thiệp vào mã nguồn hoặc hạ tầng máy chủ.
- Kiểm soát quyền hạn chính xác theo từng tài khoản WordPress thực tế.
- Ghi vết kiểm toán (audit trail) minh bạch, tuân thủ quyền riêng tư dữ liệu cá nhân (GDPR/CCPA).
- Ngăn chặn triệt để Prompt Injection, rò rỉ dữ liệu nhạy cảm và tấn công từ chối dịch vụ (DoS).

**xNetVN Agent Hub WP** được thiết kế dựa trên Clean Architecture và Hexagonal Architecture, vận hành thông qua adapter MCP chính thức của WordPress (`mcp-adapter`), cung cấp các Abilities được phân loại theo cấp độ rủi ro (Risk Tiers) và bộ công cụ (Toolsets).

---

## ✨ Tính Năng Nổi Bật

- **Mô Hình Khóa API Sở Hữu Bởi Người Dùng (User-Owned API Keys):**
  - Khóa API dạng `xnv_<key_id>_<secret>` (độ dài 60 ký tự, Base32 + Base64URL).
  - Băm bằng thuật toán `HMAC-SHA256` kết hợp Pepper 32-byte độc lập của từng website.
  - Secret chỉ xuất hiện duy nhất 1 lần khi cấp phát hoặc xoay vòng (rotation).
- **Phân Quyền Giao Thoa (Capability Intersection):**
  - Quyền hạn thực tế của Agent là phép giao: $\text{Quyền User} \cap \text{Phạm vi Key} \cap \text{Trần Site} \cap \text{Toolset}$
  - Các cấp độ rủi ro (Risk Tiers): `read` < `write` < `destructive` < `admin` (mặc định trần site là `destructive`, `admin` bị vô hiệu hóa mặc định).
- **Tách Biệt Tuyến Đường & Cơ Chế Xác Thực (Transport Separation):**
  - Tuyến MCP (`/wp-json/xnetvn-agent-hub-wp/mcp`): Chỉ chấp nhận Bearer Token, từ chối mọi Cookie.
  - Tuyến Quản trị REST (`/wp-json/xnetvn-agent-hub-wp/v1/*`): Chỉ chấp nhận Cookie đăng nhập hợp lệ + `wp_rest` nonce.
- **Giới Hạn Tần Suất Trượt (Sliding Window Rate Limiting):**
  - Giới hạn 120 yêu cầu/phút trên mỗi khóa API.
  - Giới hạn 10 lần xác thực thất bại/phút trên mỗi địa chỉ IP.
- **Bảo Vệ Nguồn Gốc & Giao Vận (Origin & Transport Guard):**
  - Yêu cầu TLS/HTTPS bắt buộc trên môi trường production.
  - Kiểm tra Origin nghiêm ngặt; từ chối `null` Origin hoặc các Origin không nằm trong danh sách tin cậy.
- **Nhật Ký Kiểm Toán & Quyền Riêng Tư (Audit & Privacy):**
  - Ghi nhật ký kiểm toán bất đồng bộ khi tắt request (shutdown hook).
  - Tuyệt đối không lưu giá trị tham số, mật khẩu, hash hoặc token.
  - Tích hợp công cụ Xuất dữ liệu cá nhân và Xóa dữ liệu cá nhân chuẩn WordPress GDPR.
- **Bộ Công Cụ Hệ Thống M1 (5 System Abilities Cốt Lõi):**
  1. `xnetvn/get-session-info`: Lấy thông tin phiên, quyền hạn và hạn mức còn lại.
  2. `xnetvn/get-site-info`: Lấy thông tin cơ bản về website và phiên bản WordPress.
  3. `xnetvn/discover-abilities`: Khám phá các công cụ được phép sử dụng.
  4. `xnetvn/get-ability-info`: Lấy chi tiết JSON Schema của một công cụ cụ thể.
  5. `xnetvn/execute-ability`: Ủy quyền thực thi công cụ được phép với schema nghiêm ngặt.
- **Các Pack Nghiệp Vụ Đã Triển Khai (57 tool ngoài bộ hệ thống):**
  - **Content (19):** bài viết, bản sửa đổi, metadata, ảnh đại diện, trạng thái ghim, cập nhật hàng loạt và tìm kiếm.
  - **Taxonomy (9):** phân loại, điều khoản, gán và thao tác hàng loạt có giới hạn.
  - **Media (7):** liệt kê, đọc, cập nhật, tải lên từ URL/dữ liệu, giới hạn và xóa.
  - **Comments (10):** đọc, tạo, trả lời, sửa, kiểm duyệt, kiểm duyệt hàng loạt và xóa vĩnh viễn.
  - **Users (12):** liệt kê, hồ sơ, vai trò, trạng thái và quản trị phiên; thay đổi tài khoản yêu cầu cấp Admin.

---

## 🏛️ Kiến Trúc & Ranh Giới Bảo Mật

```
[ AI Agent Client ]
        │  (HTTP POST /wp-json/xnetvn-agent-hub-wp/mcp)
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 1. Guards: RouteGate, OriginGuard, TransportGuard (TLS)    │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. Authenticator: Bearer Token Parser, HMAC-SHA256, Limiter │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. ServerGateway (MCP Adapter Facade)                       │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. Registry & Policy: Capability Intersection Engine        │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. Ability Packs: SystemPack / ContentPack (REST Dispatch)  │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. OutputPipeline: Redaction, Schema Validation, Boundaries  │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 7. AuditLogger (Shutdown Hook): Ghi nhận không lưu secret   │
└─────────────────────────────────────────────────────────────┘
```

Xem chi tiết tại [Tài Liệu Kiến Trúc (ARCHITECTURE.md)](ARCHITECTURE.md) và [Mô Hình Đe Dọa (threat-modeling.md)](security/threat-modeling.md).

---

## 💻 Yêu Cầu Hệ Thống

| Thành Phần | Yêu Cầu Tối Thiểu | Khuyến Nghị |
|---|---|---|
| **PHP** | 8.2 (với các extension: `mysqli`, `json`, `hash`, `pcre`) | 8.3 trở lên |
| **WordPress** | 6.9 | 6.9 hoặc 7.x |
| **Cơ sở dữ liệu** | MySQL 8.0+ / MariaDB 10.6+ (hỗ trợ InnoDB) | MySQL 8.4 LTS |
| **Plugin phụ thuộc** | `mcp-adapter` (Official WordPress MCP Adapter) | Kích hoạt phiên bản ổn định |
| **Web Server** | Nginx / Apache với HTTPS hợp lệ | Nginx + PHP-FPM + Redis Cache |

---

## 📦 Cài Đặt & Kích Hoạt Nhanh

### Bước 1: Cài đặt Plugin phụ thuộc
Cài đặt và kích hoạt plugin `mcp-adapter` trên website WordPress của bạn.

### Bước 2: Tải và cài đặt xNetVN Agent Hub
Tải file zip bản phát hành hoặc clone mã nguồn vào thư mục `wp-content/plugins/xnetvn-agent-hub-wp/`:
```bash
cd wp-content/plugins/
git clone https://github.com/xnetvn-com/xnetvn-agent-hub-wp.git
cd xnetvn-agent-hub-wp
composer install --no-dev --optimize-autoloader
```

### Bước 3: Kích hoạt Plugin
Kích hoạt plugin thông qua WP-CLI hoặc màn hình quản trị WordPress:
```bash
wp plugin activate xnetvn-agent-hub-wp
```

Xem hướng dẫn chi tiết tại [Tài Liệu Cài Đặt (INSTALL.md)](INSTALL.md).

---

## 🔑 Quản Lý Khóa API (API Keys)

1. Đăng nhập vào trang quản trị WordPress với tài khoản có quyền `edit_posts` trở lên.
2. Điều hướng tới **Hồ sơ (Profile)** của bạn hoặc menu **Agent Hub → API Keys**.
3. Nhấn **"Tạo khóa API mới" (Create New Key)**:
   - Đặt tên gợi nhớ cho khóa.
   - Chọn cấp độ rủi ro tối đa (`read`, `write`, `destructive`).
   - Chọn các bộ công cụ (toolsets) được phép truy cập (`system`, `content`,...).
   - Đặt thời hạn hết hạn (tối đa 365 ngày).
   - Tùy chọn: Nhập danh sách dải IP CIDR được phép gọi khóa.
4. **Lưu ý quan trọng:** Sao chép khóa API bí mật hiển thị trong hộp thoại. Khóa này sẽ **không bao giờ hiển thị lại** sau khi đóng hộp thoại!

---

## 🧪 Kiểm Thử & Đảm Bảo Chất Lượng

Dự án áp dụng quy trình kiểm thử toàn diện TDD:
```bash
# Kiểm tra định dạng mã nguồn (PHPCS)
composer lint

# Phân tích tĩnh mức độ cao nhất (PHPStan Level 8 không baseline)
composer analyse

# Chạy Unit Tests
composer test:unit

# Chạy Integration Tests trên môi trường WordPress
composer test:integration

# Chạy toàn bộ bộ kiểm tra tích hợp
composer check
```

Xem thêm tại [Tài Liệu Kiểm Thử (TESTING.md)](TESTING.md).

---

## 📚 Cấu Trúc Thư Mục Tài Liệu

Tài liệu tiếng Việt nằm tại `docs/vi/`; bản tiếng Anh tương ứng nằm tại `docs/en/`:
- **[Mục lục chi tiết tài liệu (index.md)](index.md)**
- **Tiêu chuẩn kỹ thuật (`standards/`):** [Coding Standards](standards/coding-standards.md), [API Standards](standards/api-standards.md), [Testing Standards](standards/testing-standards.md).
- **Chính sách (`policies/`):** [Security Policy](policies/security-policy.md), [Privacy Policy](policies/privacy-policy.md), [Data Retention](policies/data-retention-policy.md).
- **Hướng dẫn (`guides/`):** [Developer Guide](guides/developer-guide.md), [Operation Guide](guides/operation-guide.md), [Deployment Guide](guides/deployment-guide.md).
- **Tuân thủ (`compliance/`):** [GDPR Compliance](compliance/gdpr-compliance.md), [ISO 27001](compliance/iso27001-compliance.md), [PCI DSS](compliance/pci-dss-compliance.md).
- **Vận hành & Ứng phó sự cố (`operations/`):** [Runbook](operations/runbooks/serviceA-runbook.md), [Incident Response](operations/incident-response.md), [Backup & Restore](operations/backup-restore.md).

---

## 📄 Đóng Góp & Bản Quyền

- Xem hướng dẫn đóng góp mã nguồn tại **[CONTRIBUTING.md](CONTRIBUTING.md)**.
- Xem quy tắc ứng xử cộng đồng tại **[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)**.
- Giấy phép bản quyền: **GNU General Public License v2.0 or later (GPL-2.0-or-later)**. Xem tệp **[LICENSE](LICENSE)**.

**Bản quyền:**
```
Copyright (c) 2026 xNetVN Inc.
Website: https://xnetvn.com/
Liên hệ cấp phép: license@xnetvn.net
```
