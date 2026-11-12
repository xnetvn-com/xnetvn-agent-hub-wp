# Chính Sách Lưu Trữ & Hủy Bỏ Dữ Liệu (Data Retention Policy)

> **Quy định về thời hạn lưu trữ, chu kỳ dọn dẹp tự động và phương thức hủy bỏ an toàn dữ liệu kiểm toán và khóa API trong xNetVN Agent Hub WP.**

---

## 1. Mục Đích & Nguyên Tắc

- Đảm bảo hệ thống duy trì đầy đủ bằng chứng kiểm toán phục vụ công tác điều tra an toàn thông tin và giám sát tuân thủ.
- Đồng thời tuân thủ nguyên tắc giới hạn thời gian lưu trữ dữ liệu cá nhân theo Quy định chung về bảo vệ dữ liệu (GDPR Điều 5.1(e)).
- Ngăn ngừa tình trạng phình to cơ sở dữ liệu làm suy giảm hiệu năng website theo thời gian.

---

## 2. Thời Hạn Lưu Trữ Quy Định (Retention Schedule)

| Loại Dữ Liệu | Nơi Lưu Trữ | Thời Hạn Lưu Trữ Mặc Định | Cơ Chế Hủy Bỏ |
|---|---|---|---|
| **Khóa API Đang Hoạt Động** | `*_xnetvn_agent_hub_wp_keys` | Tối đa 365 ngày (hoặc theo ngày hết hạn `expires_at` do người dùng đặt) | Tự động hết hiệu lực khi đến ngày; thu hồi lập tức khi người dùng yêu cầu |
| **Khóa API Đã Thu Hồi / Hết Hạn** | `*_xnetvn_agent_hub_wp_keys` | 30 ngày sau khi thu hồi/hết hạn | Tự động dọn dẹp qua tiến trình Cron hàng ngày |
| **Nhật Ký Kiểm Toán (Audit Logs)** | `*_xnetvn_agent_hub_wp_audit` | **90 ngày** (Có thể cấu hình từ 30 đến 365 ngày trong Cài đặt) | Tự động xóa theo lô nhỏ qua Cron hàng ngày |
| **Bộ Đếm Giới Hạn Tần Suất** | `wp_options` / Redis Cache | 60 giây (theo cửa sổ trượt) | Tự động hết hạn (TTL) hoặc xóa khi quá 5 phút |
| **Dữ Liệu Cá Nhân Của Người Dùng Đã Bị Xóa** | `*_xnetvn_agent_hub_wp_keys` & `audit` | Ngay lập tức khi có lệnh xóa | Kích hoạt qua quy trình WordPress Erase Personal Data |

---

## 3. Quy Trình Dọn Dẹp Định Kỳ (Automated Purge Workflow)

1. **Lập lịch Cron Job:**  
   Hệ thống đăng ký sự kiện cron hàng ngày `xnetvn_agent_hub_wp_purge_audit`.
2. **Xóa theo lô có giới hạn (Bounded Batches):**  
   - Tiến trình thực hiện xóa tối đa **500 bản ghi** trong một câu lệnh `DELETE ... WHERE created_at < ... LIMIT 500`.
   - Nếu số lượng bản ghi cần xóa lớn hơn 500, hệ thống sẽ tự động lập lịch tiếp theo để xử lý phần tồn đọng nhằm không gây khóa bảng cơ sở dữ liệu dài hoặc làm chậm các truy vấn của người dùng website.
3. **Ghi nhận kết quả dọn dẹp:**  
   Số lượng bản ghi đã được dọn dẹp thành công sẽ được ghi nhận vào nhật ký hệ thống để phục vụ công tác giám sát.

---

## 4. Xử Lý Khi Gỡ Bỏ Hoàn Toàn Plugin (Uninstallation)

- Khi plugin bị gỡ bỏ thông qua menu Quản trị Plugins hoặc lệnh `wp plugin delete`:
  - **Mặc định:** Quy trình `uninstall.php` sẽ xóa bỏ hoàn toàn tất cả các bảng cơ sở dữ liệu (`wp_xnetvn_agent_hub_wp_keys`, `wp_xnetvn_agent_hub_wp_audit`), khóa Pepper và toàn bộ các options liên quan.
  - **Bảo lưu dữ liệu:** Nếu Quản trị viên đã bật tùy chọn `preserve_on_uninstall` trong trang Cài đặt, toàn bộ dữ liệu bảng sẽ được giữ nguyên vẹn để phục vụ việc kích hoạt lại sau này.
