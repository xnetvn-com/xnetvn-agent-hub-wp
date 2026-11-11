# Sổ Tay Vận Hành Dịch Vụ Máy Chủ MCP (MCP Server Runbook)

> **Sổ tay hướng dẫn vận hành chi tiết dịch vụ máy chủ MCP xNetVN Agent Hub WP.**

---

## 1. Thông Tin Dịch Vụ Cốt Lõi

- **Tên Dịch Vụ:** xNetVN Agent Hub MCP Server (`xnetvn-agent-hub-wp`).
- **Endpoint MCP:** `POST /wp-json/xnetvn-agent-hub-wp/mcp`
- **Giao Thức:** MCP qua HTTP Streamable / JSON-RPC 2.0.
- **Tiến trình phụ thuộc:** Nginx, PHP 8.2-FPM, MySQL 8.0/MariaDB, Redis Cache (tùy chọn), Plugin `mcp-adapter`.
- **Người chịu trách nhiệm vận hành:** Đội ngũ Quản trị Hệ thống / SRE xNetVN.

---

## 2. Các Tình Huống Vận Hành Chuẩn (Standard Operating Procedures)

### SOP-01: Kiểm tra sức khỏe dịch vụ MCP
```bash
# Gửi yêu cầu kiểm tra endpoint
curl -i -X POST https://your-domain.com/wp-json/xnetvn-agent-hub-wp/mcp \
  -H "Content-Type: application/json"

# Kết quả mong đợi:
# HTTP/1.1 401 Unauthorized (Vì chưa cung cấp Bearer token, chứng minh route hoạt động và bảo vệ tốt)
```

### SOP-02: Kiểm tra kết nối từ AI Agent với Khóa Hợp Lệ
```bash
curl -i -X POST https://your-domain.com/wp-json/xnetvn-agent-hub-wp/mcp \
  -H "Authorization: Bearer <YOUR_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{"jsonrpc": "2.0", "id": 1, "method": "tools/list", "params": {}}'

# Kết quả mong đợi:
# HTTP/1.1 200 OK
# Phản hồi danh sách 5 tools của SystemPack
```

### SOP-03: Xử lý nghẽn bộ nhớ đệm hoặc lỗi bộ đếm Rate Limit
Nếu nghi ngờ bộ đếm Rate Limit trong Redis hoặc Database bị treo hoặc ghi nhận sai lệch:
```bash
# Xóa bộ nhớ đệm WordPress
wp cache flush

# Kiểm tra log PHP-FPM
tail -n 100 /var/log/php8.2-fpm.log
```

---

## 3. Các Chỉ Báo Lỗi Cần Xử Lý Khẩn Cấp

1. **Tỷ lệ lỗi 401 tăng đột biến (> 100 req/phút từ 1 IP):**
   - Hành động: Địa chỉ IP đó có thể đang quét tấn công dò token. Kiểm tra IP trong nhật ký kiểm toán và thêm vào cấu hình chặn tại Firewall/Fail2ban hoặc Cloudflare WAF.
2. **Lỗi 500 xuất hiện trên tuyến MCP:**
   - Hành động: Kiểm tra ngay `wp-content/debug.log` để xem có lỗi PHP Fatal hoặc lỗi cú pháp trong plugin hay không.
3. **Bộ nhớ RAM của MySQL tăng cao bất thường:**
   - Hành động: Kiểm tra số lượng dòng của bảng `wp_xnetvn_agent_hub_audit`. Nếu quá lớn, kích hoạt chạy lệnh cron dọn dẹp:
     ```bash
     wp cron event run xnetvn_agent_hub_wp_retention_purge
     ```
