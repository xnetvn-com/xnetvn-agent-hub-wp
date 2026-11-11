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

## 2. Truy Vết Yêu Cầu Phân Tán (Request Tracing với Request ID)

- Mỗi yêu cầu MCP khi gửi đến hệ thống đều được cấp phát một mã định danh duy nhất **`request_id`** (định dạng UUID v4).
- Mã này xuất hiện trong:
  1. Phản hồi JSON-RPC của MCP.
  2. Bảng nhật ký kiểm toán `wp_xnetvn_agent_hub_audit.request_id`.
  3. Tiêu đề phản hồi HTTP: `X-Request-ID: <uuid>`.
- Khi người dùng hoặc AI Agent gặp sự cố, việc cung cấp `request_id` cho Quản trị viên sẽ giúp tra cứu tức thì toàn bộ bối cảnh xử lý của yêu cầu đó trong tích tắc.

---

## 3. Cấu Hình Health Checks & Probes

Hệ thống cung cấp endpoint kiểm tra sức khỏe hoạt động cho bộ cân bằng tải (Load Balancer) và Kubernetes/Docker:

- **Liveness Probe (Kiểm tra tiến trình còn sống):**
  - Gửi `GET /wp-json/xnetvn-agent-hub-wp/v1/health` (hoặc kiểm tra route REST API cơ bản).
  - Trả về HTTP `200 OK` chứng minh máy chủ PHP-FPM và WordPress đang nhận yêu cầu bình thường.
- **Readiness Probe (Kiểm tra sẵn sàng nhận tải):**
  - Kiểm tra xem kết nối cơ sở dữ liệu MySQL có thông suốt không và bảng `*_keys` có truy vấn được không.
  - Kiểm tra trạng thái kích hoạt của plugin `mcp-adapter`.
  - Nếu bất kỳ thành phần nào gặp sự cố, trả về HTTP `503 Service Unavailable` để Load Balancer tạm thời cô lập node đó.
