# Hướng Dẫn Cấu Hình Môi Trường (Environment Configuration)

> **Tài liệu chi tiết về các biến môi trường, hằng số cấu hình và cài đặt tùy chọn của xNetVN Agent Hub WP.**

---

## 1. Các Hằng Số PHP / WordPress Cấu Hình (Constants)

Các hằng số sau có thể được định nghĩa trong tệp `wp-config.php` để tùy chỉnh hành vi của plugin:

| Hằng số | Kiểu | Mặc định | Hành vi |
|---|---|---|---|
| `XNETVN_AGENT_HUB_WP_ALLOW_INSECURE` | `bool` | `false` | Chỉ cho HTTP khi môi trường là local/development; không bật trên production. |


---

## 2. Các Tùy Chọn Cơ Sở Dữ Liệu (WordPress Options)

Các cài đặt này được lưu trữ trong bảng `wp_options` của từng website (hoặc từng site con trong mạng Multisite):

### 2.1 Cấu Hình Hệ Thống (`xnetvn_agent_hub_wp_settings`)
Lưu dưới dạng mảng JSON hoặc serialize với các trường khóa:

| Cài đặt | Kiểu | Mặc định |
|---|---|---|
| `enabled` | `bool` | `true` |
| `allowed_roles_override` | `null or array` | `null` |
| `tier_ceiling` | `string` | `"destructive"` |
| `allow_admin_tier` | `bool` | `false` |
| `max_keys_per_user` | `int` | `10` |
| `max_key_lifetime_days` | `int` | `365` |
| `allowed_origins` | `array` | `[]` |
| `site_ip_allowlist` | `array` | `[]` |
| `trusted_proxy_headers` | `array` | `[]` |
| `trusted_proxy_cidrs` | `array` | `[]` |
| `fallback_header_enabled` | `bool` | `true` |
| `rate_limit_key_per_min` | `int` | `120` |
| `rate_limit_auth_fail_per_min_ip` | `int` | `10` |
| `output_max_bytes` | `int` | `262144` |
| `audit_retention_days` | `int` | `90` |
| `anonymize_ip` | `bool` | `false` |
| `exposed_third_party_abilities` | `array` | `[]` |
| `disable_adapter_default_server` | `bool` | `false` |
| `notify_key_created` | `bool` | `true` |
| `notify_key_revoked` | `bool` | `true` |
| `notify_key_expiring` | `bool` | `true` |
| `notify_unusual_ip` | `bool` | `true` |
| `delete_data_on_uninstall` | `bool` | `true` |
| `media_remote_fetch_enabled` | `bool` | `false` |
| `media_remote_max_bytes` | `int` | `10485760` |
| `media_remote_timeout_seconds` | `int` | `15` |
| `media_remote_max_redirects` | `int` | `3` |
| `media_data_max_bytes` | `int` | `1048576` |
| `media_pixels_max` | `int` | `40000000` |
| `media_mime_denylist` | `array` | `[]` |

Option WordPress không autoload lưu mảng PHP. Các mặc định trên lấy từ WpSiteSettings::DEFAULTS. Toolset của khóa lấy từ registry; không có option enabled_toolsets. Khoảng media: byte từ xa 1024..67108864; byte base64 1024..8388608; timeout 3..30 giây; chuyển hướng 0..5; điểm ảnh 1000000..100000000. Danh sách MIME chỉ thu hẹp. Tải URL mặc định tắt. Đây là ngân sách đề xuất, không phải năng lực production đã đo.

### 2.2 Khóa Bí Mật Pepper (`xnetvn_agent_hub_wp_pepper`)
- **Giá Trị:** Chuỗi nhị phân 32 byte sinh ngẫu nhiên bằng `random_bytes(32)`.
- **Cấu hình Autoload:** `autoload = 'no'` (Bắt buộc không nạp tự động vào bộ nhớ của mọi request WordPress thông thường).
- **Mục đích:** Khóa bí mật dùng cho hàm `hash_hmac('sha256', $secret, $pepper)` khi xác thực khóa API.

---

## 3. Cấu Hình Cho Từng Môi Trường (Environment Profiles)

### 3.1 Môi Trường Phát Triển Cục Bộ (Local / Development)
- Cho phép bật `XNETVN_AGENT_HUB_WP_ALLOW_INSECURE = true` nếu thử nghiệm không có chứng chỉ SSL cục bộ.
- Bật `WP_DEBUG = true` và `WP_DEBUG_LOG = true`.
- Sử dụng công cụ WP-Env hoặc Docker Compose.

### 3.2 Môi Trường Staging & Kiểm Thử Tự Động (Staging / CI)
- Bắt buộc bật HTTPS đầy đủ.
- Cấu hình Redis Cache để kiểm thử bộ đếm tần suất phân tán.
- Chạy toàn bộ ma trận kiểm thử tích hợp và E2E.

### 3.3 Môi Trường Sản Xuất (Production)
- Bắt buộc HTTPS/TLS với chứng chỉ hợp lệ.
- Trần site tối đa giữ nguyên mức `destructive` (không mở `admin` trừ khi thực sự cần thiết và có giám sát chặt chẽ).
- Cấu hình dải IP Proxy/CDN chính xác để bảo vệ chống giả mạo IP (IP Spoofing).
- Đảm bảo cơ sở dữ liệu MySQL sử dụng bộ máy InnoDB và đã cấu hình sao lưu định kỳ cho bảng `wp_xnetvn_agent_hub_keys` và option Pepper.
