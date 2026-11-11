# Trung Tâm Hỗ Trợ & Liên Hệ Kỹ Thuật (Support Guide)

> **Thông tin liên hệ, quy trình yêu cầu hỗ trợ và cam kết dịch vụ (SLA) cho xNetVN Agent Hub WP.**

---

## 1. Các Kênh Hỗ Trợ Chính Thức

| Kênh Hỗ Trợ | Địa Chỉ / Liên Kết | Mục Đích & Phạm Vi |
|---|---|---|
| **GitHub Issues** | [GitHub Repository Issues](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/issues) | Báo cáo lỗi kỹ thuật (Bugs), yêu cầu tính năng mới (Feature Requests) và thảo luận kiến trúc mở. |
| **Email Kỹ Thuật** | `support@xnetvn.net` | Hỗ trợ kỹ thuật chuyên sâu, giải đáp thắc mắc tích hợp cho doanh nghiệp. |
| **Email Bản Quyền** | `license@xnetvn.net` | Các vấn đề liên quan đến giấy phép sử dụng, cấp phép thương mại hoặc tuân thủ bản quyền. |
| **Bảo Mật Kín** | `security@xnetvn.net` | Báo cáo lỗ hổng bảo mật nhạy cảm (Private Security Disclosures). |

---

## 2. Cam Kết Thời Gian Phản Hồi (SLA)

| Mức Độ Nghiêm Trọng | Định Nghĩa | Thời Gian Phản Hồi Mục Tiêu |
|---|---|---|
| **P1 - Blocker / Critical** | Lỗ hổng bảo mật nghiêm trọng, sập hệ thống, rò rỉ dữ liệu hoặc xác thực tê liệt hoàn toàn. | Phản hồi trong vòng **2 giờ**, cập nhật mỗi 4 giờ cho đến khi có bản vá. |
| **P2 - Major** | Lỗi chức năng cốt lõi (không thể tạo khóa, lỗi kiểm toán, lỗi adapter) nhưng có giải pháp tạm thời. | Phản hồi trong vòng **12 giờ**, kế hoạch xử lý trong vòng 48 giờ. |
| **P3 - Minor / Normal** | Các lỗi nhỏ về giao diện, tài liệu, hoặc câu hỏi hướng dẫn sử dụng. | Phản hồi trong vòng **24 - 48 giờ làm việc**. |
| **P4 - Feature Request** | Đề xuất tính năng mới hoặc cải tiến hiệu năng. | Xem xét và phản hồi trong chu kỳ lập kế hoạch bản phát hành (Sprint Planning). |

---

## 3. Quy Trình Nhận Hỗ Trợ Nhanh Chóng

Để giúp đội ngũ kỹ thuật hỗ trợ bạn nhanh nhất:
1. Đọc kỹ tài liệu [TROUBLESHOOTING.md](TROUBLESHOOTING.md) và [FAQ.md](FAQ.md).
2. Chuẩn bị thông tin môi trường:
   - Phiên bản WordPress và phiên bản PHP đang sử dụng.
   - Phiên bản plugin `xnetvn-agent-hub-wp` và plugin `mcp-adapter`.
   - Log lỗi từ `wp-content/debug.log` (đã xóa các thông tin nhạy cảm như token, mật khẩu, IP nội bộ).
3. Sử dụng đúng mẫu báo cáo tại [templates/bug_report.md](templates/bug_report.md).
