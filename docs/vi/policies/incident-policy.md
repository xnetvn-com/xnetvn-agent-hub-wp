# Quy Trình Quản Lý & Ứng Phó Sự Cố (Incident Response Policy)

> **Quy trình phân loại mức độ nghiêm trọng, các bước leo thang và kịch bản ứng phó sự cố kỹ thuật và an toàn thông tin cho xNetVN Agent Hub WP.**

---

## 1. Phân Loại Mức Độ Nghiêm Trọng Của Sự Cố (Severity Levels)

| Cấp Độ | Tên Gọi | Tiêu Chí Xác Định | Kênh Thông Báo & Thời Gian Phản Hồi |
|---|---|---|---|
| **P0** | **Khẩn cấp (Critical)** | - Lỗ hổng bảo mật bị khai thác công khai (RCE, SQLi, rò rỉ secret/pepper).<br>- Toàn bộ hệ thống MCP hoặc website WordPress bị tê liệt.<br>- Cơ sở dữ liệu bị hỏng hoặc mất tính toàn vẹn khóa. | Kích hoạt báo động 24/7.<br>Phản hồi trong vòng **15 phút**.<br>Cập nhật trạng thái mỗi 30 phút. |
| **P1** | **Nghiêm trọng (High)** | - Xác thực khóa API bị lỗi diện rộng.<br>- Chức năng xoay vòng khóa hoặc quản lý khóa bị lỗi.<br>- Tấn công Brute-force hoặc DDoS làm nghẽn tài nguyên. | Phản hồi trong vòng **1 giờ**.<br>Cập nhật trạng thái mỗi 2 giờ. |
| **P2** | **Trung bình (Medium)** | - Một gói công cụ cụ thể (ví dụ: Content Pack) bị lỗi.<br>- Bộ đếm giới hạn tần suất gặp lỗi hiển thị sai số.<br>- Lỗi hiển thị giao diện quản trị không ảnh hưởng luồng chính. | Phản hồi trong vòng **4 giờ**.<br>Cập nhật trạng thái hàng ngày. |
| **P3** | **Thấp (Low)** | - Các lỗi giao diện nhỏ, sai sót tài liệu hoặc cảnh báo linter không nghiêm trọng. | Xử lý theo kế hoạch sprint bảo trì định kỳ. |

---

## 2. Quy Trình Ứng Phó 5 Bước Tiêu Chuẩn

```
[1. Phát hiện & Ghi nhận] ──► [2. Cô lập & Giảm thiểu] ──► [3. Phân tích nguyên nhân] ──► [4. Khắc phục & Triển khai] ──► [5. Báo cáo & Rút kinh nghiệm]
```

### Bước 1: Phát Hiện & Ghi Nhận (Detection & Identification)
- Nhận cảnh báo từ hệ thống giám sát thời gian thực, nhật ký kiểm toán bất thường, hoặc báo cáo từ người dùng/chuyên gia bảo mật qua email `security@xnetvn.net`.
- Tạo ngay một kênh theo dõi sự cố riêng (Incident Channel) và phân công Chỉ huy Sự cố (Incident Commander).

### Bước 2: Cô Lập & Ngăn Chặn Thiệt Hại (Containment)
- Đối với sự cố lộ khóa hoặc nghi ngờ khóa bị đánh cắp:
  - Lập tức thực hiện lệnh thu hồi khóa khẩn cấp thông qua WP-CLI hoặc giao diện quản trị:
    ```bash
    wp eval 'xnetvn_revoke_key("key_id");'
    ```
- Đối với sự cố rò rỉ mã bí mật Pepper:
  - Sinh lại Pepper mới và thu hồi toàn bộ các khóa API hiện tại để buộc người dùng tạo lại khóa mới.
- Đối với sự cố tấn công DDoS / Brute-force:
  - Chặn dải IP độc hại ngay tại tầng Web Server (Nginx) hoặc CDN (Cloudflare).

### Bước 3: Phân Tích Nguyên Nhân Gốc Rễ (Root Cause Analysis - RCA)
- Kiểm tra toàn bộ nhật ký kiểm toán `wp_xnetvn_agent_hub_wp_audit` và file log hệ thống `debug.log`.
- Tái hiện lỗi trên môi trường cô lập (Sandbox / Remote Test Lab).
- Xác định điểm yếu trong logic, cấu hình hoặc ranh giới phân quyền.

### Bước 4: Khắc Phục & Phát Hành Bản Vá (Remediation & Patching)
- Viết bài kiểm thử hồi quy (Regression Test) tái hiện chính xác lỗi.
- Sửa chữa mã nguồn, đảm bảo `composer check` vượt qua 100%.
- Đóng gói và phát hành bản vá khẩn cấp (Hotfix Release, ví dụ: bump PATCH version `v0.1.1`).

### Bước 5: Đánh Giá & Báo Cáo Sau Sự Cố (Post-Mortem)
- Lập biên bản sự cố kỹ thuật theo mẫu chuẩn tại [templates/report_template.md](../templates/report_template.md).
- Ghi nhận bài học kinh nghiệm, cập nhật tài liệu vận hành và bổ sung các ca kiểm thử tự động để ngăn ngừa sự cố tái diễn.
