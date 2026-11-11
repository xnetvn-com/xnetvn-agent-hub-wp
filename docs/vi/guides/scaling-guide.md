# Hướng Dẫn Mở Rộng Hệ Thống Theo Chiều Ngang (Scaling Guide)

> **Chiến lược mở rộng hệ thống (Horizontal Scaling), tối ưu hiệu năng và kiến trúc chịu tải cao cho xNetVN Agent Hub WP.**

---

## 1. Mục Tiêu Khả Năng Mở Rộng (Scalability Targets)

- Hỗ trợ hàng triệu yêu cầu MCP mỗi ngày từ các hệ thống AI Agent phân tán.
- Đảm bảo thời gian phản hồi (Latency):
  - **p50 < 30ms** cho các tác vụ kiểm tra phiên và khám phá công cụ (`discover-abilities`).
  - **p95 < 100ms** cho các tác vụ thực thi công cụ thông thường (`execute-ability`).
- Kiến trúc phi trạng thái (Stateless Application Tier): Mọi máy chủ web chạy WordPress có thể mở rộng độc lập đằng sau bộ cân bằng tải (Load Balancer).

---

## 2. Mô Hình Mở Rộng Cấp Doanh Nghiệp (Enterprise Cluster Architecture)

```
                       [ Mạng Lưới AI Agents ]
                                  │
                                  ▼ (HTTPS)
                      [ Cloudflare / AWS CloudFront ]
                                  │
                                  ▼
                     [ Cân Bằng Tải (Load Balancer) ]
                                  │
             ┌────────────────────┼────────────────────┐
             ▼                    ▼                    ▼
     [ Web Node 1 ]       [ Web Node 2 ]       [ Web Node N ]
     (PHP-FPM WP)         (PHP-FPM WP)         (PHP-FPM WP)
             │                    │                    │
             └────────────────────┼────────────────────┘
                                  │
                     ┌────────────┴────────────┐
                     ▼                         ▼
             [ Redis Cluster ]        [ MySQL Primary / Replica ]
           (Rate Limit & Cache)       - Ghi: Master (InnoDB)
                                      - Đọc: Read-Replicas
```

---

## 3. Các Giải Pháp Mở Rộng Trọng Yếu

### 3.1 Sử Dụng Redis Cluster Cho Bộ Đếm Tần Suất
- Mặc định, nếu không có Redis, plugin sử dụng bảng `wp_options` với các câu lệnh SQL để tăng bộ đếm tần suất. Điều này có thể tạo ra điểm nghẽn cổ chai (I/O Bottleneck) khi lưu lượng lớn.
- Bằng cách kích hoạt Redis Persistent Object Cache:
  - Các phép tăng bộ đếm `INCR` được xử lý hoàn toàn trong RAM của Redis với độ trễ micro-giây.
  - Loại bỏ hoàn toàn tải I/O trên cơ sở dữ liệu MySQL đối với các yêu cầu chỉ đọc hoặc bị từ chối rate limit.

### 3.2 Tối Ưu Hóa Giao Dịch Ghi & Thắt Nút Cơ Sở Dữ Liệu
- **Thắt nút cập nhật Lần cuối sử dụng (`last_used_at`):**
  - Hệ thống áp dụng cơ chế thắt nút (throttling): Chỉ ghi nhận thời gian `last_used_at` vào cơ sở dữ liệu tối đa **1 lần mỗi 5 phút** cho mỗi khóa API.
  - Ngăn chặn hàng triệu lệnh `UPDATE` liên tục khi AI Agent gọi công cụ với tần suất cao.
- **Nhật ký kiểm toán theo lô (Batch Audit Inserts):**
  - Gom toàn bộ sự kiện kiểm toán trong request và thực thi duy nhất 1 câu lệnh `INSERT INTO ... VALUES (...), (...)` tại shutdown hook.

### 3.3 Phân Tách Cụm Đọc/Ghi (Read/Write Splitting)
- Đối với hệ thống có lưu lượng lớn, sử dụng giải pháp như AWS Aurora MySQL hoặc ProxySQL:
  - Các truy vấn xác thực khóa (`SELECT ... FROM *_keys WHERE key_id = ...`) có thể phân phối đến các Read Replicas (với độ trễ sao chép tối thiểu).
  - Các giao dịch tạo khóa, xoay vòng khóa và ghi audit được chuyển đến Primary Master.
