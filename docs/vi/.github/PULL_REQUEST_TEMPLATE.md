# Mẫu Yêu Cầu Kéo Mã Nguồn (Pull Request Template)

> **Mẫu thông tin bắt buộc khi gửi Pull Request (PR) đóng góp vào xNetVN Agent Hub WP.**

---

## 1. Thông Tin Tổng Quan

- **Tiêu đề PR (Conventional Commits):**  
  _(Ví dụ: `feat(content): implement list-post-types ability with strict schema`)_

- **Liên kết Issue giải quyết:**  
  Fixes #_____ / Closes #_____

- **Loại thay đổi (Type of Change):**
  - [ ] `feat`: Tính năng mới
  - [ ] `fix`: Sửa lỗi
  - [ ] `refactor`: Tái cấu trúc mã nguồn (không đổi tính năng bên ngoài)
  - [ ] `perf`: Cải tiến hiệu năng
  - [ ] `test`: Bổ sung hoặc cập nhật bài kiểm thử
  - [ ] `docs`: Cập nhật tài liệu
  - [ ] `chore`: Cập nhật cấu hình build, dependencies

---

## 2. Mô Tả Thay Đổi & Giải Pháp Kỹ Thuật

- **Vấn đề cần giải quyết:**  
  _(Giải thích nguyên nhân gốc rễ hoặc yêu cầu nghiệp vụ)_

- **Giải pháp triển khai:**  
  _(Mô tả chi tiết các lớp, phương thức hoặc cấu trúc dữ liệu đã thêm hoặc chỉnh sửa)_

- **Tác động đến hệ thống & Tương thích ngược:**  
  - [ ] Có phá vỡ tương thích ngược không? (Breaking change)
  - [ ] Có thay đổi cấu trúc bảng cơ sở dữ liệu không? (Schema migration)
  - [ ] Có ảnh hưởng đến các ranh giới bảo mật bất biến không?

---

## 3. Bằng Chứng Kiểm Thử (Verification Evidence)

- **Các lệnh kiểm tra đã chạy thành công:**
  - [ ] `composer lint` (Không có lỗi PHPCS WordPress)
  - [ ] `composer lint:bootstrap` (Tương thích PHP 7.2)
  - [ ] `composer analyse` (PHPStan Level 8 không baseline)
  - [ ] `composer test:unit` (100% Unit test pass)
  - [ ] `composer test:integration` (100% Integration test pass trên WP thực tế)

- **Kết quả đo lường độ bao phủ (Coverage Delta):**  
  _(Ghi rõ tỷ lệ bao phủ trước và sau khi thay đổi, đảm bảo >= 90% cho domain core)_

---

## 4. Danh Mục Kiểm Tra An Toàn (Security & Quality Checklist)

- [ ] Không có thông tin nhạy cảm (token, secret, private key, passwords) được đưa vào code hoặc log.
- [ ] Mọi truy vấn cơ sở dữ liệu đều sử dụng `$wpdb->prepare` với tham số hóa đầy đủ.
- [ ] Dữ liệu đầu ra đi qua `OutputPipeline` và được kiểm tra hợp lệ với JSON Schema.
- [ ] Quyền hạn được kiểm tra ở mức đối tượng (Object-level authorization).
- [ ] Đã cập nhật tài liệu tương ứng tại `docs/vi/` và `CHANGELOG.md`.
