# Hướng Dẫn Giám Sát & Cảnh Báo (Monitoring Guide)

> **Hướng dẫn thiết lập hệ thống giám sát hiệu năng, theo dõi chỉ số kỹ thuật và cảnh báo chủ động cho xNetVN Agent Hub WP.**

---

## 1. Các Chỉ Số Giám Sát Cốt Lõi (Key Metrics to Monitor)

Hệ thống cung cấp các chỉ số kỹ thuật quan trọng phục vụ tích hợp vào Prometheus, Grafana, Datadog hoặc ELK Stack:

| Nhóm Chỉ Số | Tên Chỉ Số | Ngưỡng Cảnh Báo Bình Thường | Ngưỡng Cảnh Báo Nguy Hiểm (Alert) |
|---|---|---|---|
| **Lưu lượng MCP** | Tốc độ yêu cầu (Requests Per Second - RPS) | 10 - 200 RPS | Đột biến > 500 RPS trong 1 phút |
| **Tỷ lệ Lỗi** | Tỷ lệ lỗi 401 Unauthorized | < 1% | > 5% (Nguy cơ bị tấn công Brute-force token) |
| **Tỷ lệ Từ Chối** | Tỷ lệ lỗi 403 Forbidden | < 0.5% | > 2% (Sai cấu hình Origin hoặc phân quyền) |
| **Giới Hạn Tần Suất**| Số lượng phản hồi 429 Too Many Requests | < 0.1% | > 2% (Client bị nghẽn hoặc DoS) |
| **Thời Gian Xử Lý**| Độ trễ phản hồi (Response Latency p95) | < 100ms | > 300ms liên tục trong 5 phút |
| **Cơ Sở Dữ Liệu** | Dung lượng bảng `*_audit` | < 1,000,000 dòng | > 5,000,000 dòng (Tiến trình cron dọn dẹp bị lỗi) |

---

## 2. Truy vết yêu cầu

- Audit logger gán cho mỗi yêu cầu một mã ngắn (`request_id`, 16 ký tự hex sinh từ 8 byte ngẫu nhiên) khi ghi bản ghi (`src/Audit/AuditLogger.php`).
- Mã được lưu trong bảng audit `{prefix}xnetvn_agent_hub_wp_audit`; bảng Activity không hiển thị mã này, nên truy vấn trực tiếp bảng khi cần.
- Mã không được trả về dưới dạng header HTTP, nên khi báo sự cố hãy ghi lại thời điểm gần đúng, key ID và tên ability, rồi tra bản ghi trong Activity hoặc trong bảng.

---

## 3. Health check và probe

Plugin không đăng ký endpoint health riêng. Dùng tín hiệu chuẩn của WordPress cùng dữ liệu trạng thái của plugin:

- **Liveness:** gọi REST index của site (`/wp-json/`) trả `200` khi PHP và WordPress nhận yêu cầu.
- **Readiness:** cơ sở dữ liệu trả lời, hai bảng `{prefix}xnetvn_agent_hub_wp_keys` và `{prefix}xnetvn_agent_hub_wp_audit` truy vấn được, và MCP Adapter chính thức đang hoạt động. **Agent Hub > Overview** hiển thị phiên bản adapter; route `/status` (cần xác thực) báo thêm việc server đã được tạo hay chưa.
- Plugin không trả `503` khi thiếu phụ thuộc; adapter thiếu hoặc ngoài khoảng sẽ tắt MCP server trong khi phần còn lại vẫn hoạt động (`src/Bootstrap/Requirements.php`).

