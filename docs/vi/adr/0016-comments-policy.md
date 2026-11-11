# ADR-0016: Chính sách bình luận và kiểm duyệt trực tiếp

- Trạng thái: Đã chấp nhận
- Ngày: 2026-10-09
- Thẩm quyền: chủ dự án phê duyệt tường minh trong phiên triển khai (toàn bộ EC-01 đến EC-11 được duyệt cho bản dựng này).

## Quyết định

EC-01 đến EC-11 được chấp nhận. Đổi trạng thái, sửa, xóa và dọn bình luận gọi thẳng hàm quản trị của lõi (wp_set_comment_status, wp_spam_comment, wp_unspam_comment, wp_trash_comment, wp_untrash_comment, wp_delete_comment, wp_update_comment) kèm kiểm tra edit_comment trên từng bình luận, vì đường cập nhật của REST ghi đè IP gốc của người bình luận, đưa vào thùng rác thành xóa vĩnh viễn khi EMPTY_TRASH_DAYS bằng 0, đổi trạng thái lặp lại trả lỗi HTTP 500, và kiểm tra sửa của REST dùng lối tắt moderate_comments lỏng hơn. Đọc và tạo mới giữ đi qua REST nội bộ để thừa hưởng kiểm tra hợp lệ, chống flood/trùng và quyền theo đối tượng. Xóa đòi hỏi trạng thái spam hoặc trash trước. Từ chối trash khi thùng rác bị tắt. Bình luận mới mặc định chờ duyệt. Email chỉ trả khi được xin rõ và có edit_comment; IP, user-agent và avatar không bao giờ trả. Chỉ quản lý kiểu comment, pingback và trackback; kiểu note và kiểu lạ báo not_found. Ép KSES cho nội dung do agent ghi bất kể unfiltered_html. ReasonMapper thêm duplicate_comment và comments_closed; RestBackedAbility quan sát biên nhận WP_Comment và hook xóa bình luận.

## Kiểm chứng

Hiện thực đã có; nghiệm thu runtime đầy đủ và cổng rà soát bảo mật độc lập vẫn còn mở. Ma trận CM-01 đến CM-77, cả hai fixture WordPress 6.9/7.1.2 và multisite, mọi hồi quy N-01 đến N-35, hành vi ứng dụng MCP thật và Plugin Check đóng gói là bắt buộc trước phát hành.
