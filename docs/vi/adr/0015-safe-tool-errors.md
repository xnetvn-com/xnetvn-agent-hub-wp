# ADR-0015: Allowlisted execution reasons

- Trạng thái: Đã chấp thuận
- Ngày: 2026-10-08
- Thẩm quyền: chủ dự án duyệt rõ ràng E-01 đến E-08 và cho phép triển khai ngay.

## Quyết định

Xem bản tiếng Anh cùng đường dẫn để biết hợp đồng kỹ thuật chuẩn. Áp dụng phạm vi đã duyệt, giữ nguyên cổng rà soát bảo mật độc lập trước phát hành.

## Kiểm chứng

Theo kế hoạch triển khai Content/Media; chấp thuận thiết kế không đồng nghĩa nghiệm thu chạy thật.

## Quyết định về biên nhận mutation

Giữ ID được quan sát độc lập khi tạo/sửa/xóa qua phản hồi REST thành công, phép chiếu và OutputPipeline. MutationResponse/MutationResult chỉ là lớp bao nội bộ. ID PartialFailure đáng tin cậy được giữ qua sanitization; data lỗi tùy ý từ provider không được giữ. Khôi phục revision giữ ID cha đã biết qua bước đọc lại. Bulk báo lỗi riêng từng mục và tiếp tục.
