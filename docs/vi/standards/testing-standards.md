# Tiêu Chuẩn Viết Kiểm Thử Tự Động (Testing Standards)

> **Quy chuẩn kỹ thuật khi viết bài kiểm thử Unit Test, Integration Test và E2E Test cho xNetVN Agent Hub WP.**

---

## 1. Nguyên Tắc Cốt Lõi Khi Viết Test

1. **Phương Pháp Phát Triển Hướng Kiểm Thử (Test-Driven Development - TDD):**
   - **Bước 1 (Đỏ):** Viết bài kiểm thử phản ánh yêu cầu mới hoặc tái hiện lỗi được báo cáo; chạy test và xác nhận test thất bại.
   - **Bước 2 (Xanh):** Viết đoạn mã tối thiểu để test vượt qua thành công.
   - **Bước 3 (Tái cấu trúc):** Tối ưu hóa mã nguồn, làm sạch cấu trúc trong khi vẫn giữ test luôn xanh.
2. **Cấu Trúc Bài Kiểm Thử Chuẩn (Given - When - Then / Arrange - Act - Assert):**
   - Mọi test case phải phân tách rõ ràng 3 phần:
     ```php
     public function test_should_deny_request_when_token_is_expired(): void {
         // Given: Chuẩn bị dữ liệu và trạng thái khóa đã hết hạn
         $expired_key = $this->create_expired_key();
         
         // When: Thực hiện hành động xác thực
         $result = $this->authenticator->authenticate($expired_key);
         
         // Then: Xác minh kết quả bị từ chối với lý do chính xác
         $this->assertTrue($result->is_denied());
         $this->assertSame(FailureReason::KeyExpired, $result->reason);
     }
     ```
3. **Mỗi Test Phải Hoàn Toàn Độc Lập (Test Isolation):**
   - Kết quả của một test không được phụ thuộc vào thứ tự chạy hoặc trạng thái lưu lại từ test khác.
   - Mọi thay đổi dữ liệu trong cơ sở dữ liệu WordPress hoặc biến môi trường phải được dọn dẹp sạch sẽ trong phương thức `tearDown()`.

---

## 2. Tiêu Chuẩn Unit Testing (PHPUnit + Brain Monkey)

- **Vị trí tệp:** Đặt trong thư mục `tests/Unit/` phản ánh đúng cấu trúc thư mục của `src/` (ví dụ: `src/Auth/KeyGenerator.php` tương ứng với `tests/Unit/Auth/KeyGeneratorTest.php`).
- **Đặt tên phương thức test:** Bắt đầu bằng tiền tố `test_should_<ExpectedBehavior>_when_<StateUnderTest>`.
- **Sử dụng Mocks có trách nhiệm:**
  - Mock các Interface hợp đồng (`src/Contracts/`) bằng Mockery.
  - Sử dụng Brain Monkey để kiểm soát các hàm WordPress toàn cục (`add_action`, `do_action`, `apply_filters`, `get_option`).
  - Không mock các đối tượng Value Objects thuần túy (ví dụ: `KeyId`, `Scope`, `RiskTier`).

---

## 3. Tiêu Chuẩn Integration Testing (WP-PHPUnit)

- **Vị trí tệp:** Đặt trong thư mục `tests/Integration/`.
- **Kế thừa lớp cơ sở:** Kế thừa từ `WP_UnitTestCase`.
- **Kiểm thử trên Database thực tế:**
  - Kiểm tra tương tác thực sự với bảng MySQL của WordPress.
  - Kiểm tra các giao dịch khóa mức hàng (`FOR UPDATE`) và khóa phân tán `GET_LOCK()`.
  - Kiểm tra các hook của WordPress được kích hoạt đúng thứ tự và đúng tham số.

---

## 4. Đo Lường Độ Bao Phủ Mã Nguồn (Code Coverage Requirements)

- **Mục tiêu Coverage:**
  - Tối thiểu **90%** Line & Branch Coverage đối với các tầng: `Domain`, `Auth`, `Policy`.
  - Tối thiểu **80%** toàn bộ dự án cho các phiên bản phát hành chính thức.
- **Lệnh tạo báo cáo Coverage:**
  ```bash
  # Thu thập độ bao phủ mã nguồn chi tiết
  composer test:unit -- --coverage-html .local/reports/coverage
  ```
- **Không chạy theo số lượng hình thức:** Quan trọng hơn tỷ lệ phần trăm là chất lượng của các ca kiểm thử biên (Boundary Cases), ca kiểm thử dữ liệu sai/độc hại (Negative Testing) và kiểm tra ranh giới bảo mật.
