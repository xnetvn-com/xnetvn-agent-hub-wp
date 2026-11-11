# M3 — Hợp đồng bình luận (Comments)

Thẩm quyền thiết kế: EC-01 đến EC-11 được duyệt ngày 2026-10-09 (ADR-0016).
Hiện thực đã có; nghiệm thu runtime đầy đủ và cổng bảo mật độc lập vẫn còn
mở. Media được đặc tả riêng ở M3-media.

## Ranh giới thực thi riêng tư

Cả mười ability dùng toolset `comments` và quyền hiển thị
`moderate_comments`. Quyền hiển thị không bao giờ thay thế quyền theo đối
tượng của lõi (`edit_comment` trên từng bình luận, `edit_post` trên bài cha).
HTTP đòi hỏi AuthContext của chủ khóa đã xác minh; tier của khóa/site và danh
sách allow/deny vẫn giao với quyền hiện tại. Pack không bao giờ đổi danh
tính. Route bình luận là `/wp/v2/comments` cố định của lõi.

| Ability | Tier | Backing | Ràng buộc bổ sung |
|---|---|---|---|
| list-comments | read | REST GET tập hợp | Bộ lọc/phân trang giới hạn; allowlist kiểu |
| get-comment | read | REST GET một mục | ID chính xác; allowlist kiểu; raw/email cần edit_comment |
| get-comment-counts | read | Direct | wp_count_comments; bài cần quyền edit_post |
| create-comment | write | REST POST một mục | Bài đích đã duyệt; mặc định chờ duyệt |
| reply-comment | write | REST POST một mục | Bài/cha lấy từ bình luận cha; luồng approve_parent |
| update-comment | write | Direct wp_update_comment | edit_comment; trường allowlist; ép KSES |
| moderate-comment | write | Direct hàm trạng thái lõi | edit_comment; bảng chuyển; cổng thùng rác |
| bulk-moderate-comments | write,costly | Direct lặp | ≤50 ID duy nhất; kết quả từng mục; hoãn đếm |
| delete-comment | destructive | Direct wp_delete_comment force | Chỉ từ spam/trash |
| purge-comments | destructive,costly | Direct WP_Comment_Query + xóa | Chỉ spam/trash; mốc chốt; giới hạn; khung giờ |

Mỗi định nghĩa có đủ bốn annotations readonly/destructive/idempotent/
openWorldHint. Mọi mô tả tool là tiếng Anh cố định và coi nội dung bình luận,
tên/URL tác giả là dữ liệu không tin cậy.

## Hợp đồng đầu vào

Mọi đầu vào gốc là object với additionalProperties=false. ID là số nguyên
dương. page>=1; per_page=1..100, mặc định 20. content_format là raw/rendered,
mặc định rendered. fields chỉ thu hẹp DTO đã duyệt; id và status luôn giữ.
Truy cập raw và author_email bị từ chối thì không rơi về giá trị khác.

list-comments nhận post (tối đa 20 ID), status approved/hold/spam/trash/all
(mặc định approved; all nghĩa là đã duyệt cộng chờ duyệt), type
comment/pingback/trackback (mặc định comment), parent (tối đa 20 ID, 0 nghĩa
là bình luận gốc), author (tối đa 20 ID), bộ lọc author_email (không làm lộ
email), search≤200, after/before ISO8601 có múi giờ (đổi sang giờ site),
orderby date_gmt/id/post/parent, order, page/per_page, content_max_chars
1..5000 mặc định 500, và fields. Bộ lọc không hỗ trợ hoặc sai định dạng bị từ
chối.

create-comment cần post và content (1..65525 byte, sau trim không rỗng);
status approved/hold mặc định hold. Không có đầu vào tác giả, IP, ngày, kiểu,
cha hay meta; bình luận luôn mang danh tính chủ khóa. reply-comment cần
parent_id và content với cùng quy tắc status, cộng approve_parent (mặc định
false); bài và cha lấy từ bình luận cha. update-comment cần id và ít nhất một
trong content, author_name (1..245 ký tự/byte), author_url (rỗng hoặc
http/https, ≤200 byte); không có đầu vào post, parent, status, danh tính hay
meta. moderate-comment cần id và một trong
approve/hold/spam/unspam/trash/untrash. bulk-moderate-comments cần 1..50 ID
duy nhất và một action. delete-comment cần id. purge-comments cần status
spam/trash, limit 1..100 tùy chọn mặc định 50 và mốc before tùy chọn.

## Máy trạng thái và đầu ra

Trạng thái chuẩn hóa gồm approved, hold, spam và trash. Đổi về đúng trạng
thái hiện tại thành công với changed=false. approve từ spam/trash, trash từ
spam, và trash khi thùng rác bị tắt đều bị từ chối. unspam/untrash khôi phục
trạng thái trước spam/trash do lõi ghi lại (thiếu bản ghi thì về hold). Mọi
chuyển đổi đọc trước, ghi bằng đúng hàm lõi, rồi đọc lại xác minh; lệch nhau
báo internal_error kèm biên nhận đột biến.

DTO bình luận chặt chỉ gồm id, post, parent, type, status, author,
author_name, author_url, author_email (chỉ khi xin rõ và có edit_comment),
content, content_truncated, date, date_gmt (UTC có hậu tố Z) và link. IP,
user-agent, avatar, _links, _embedded và meta không bao giờ trả. Danh sách
dùng items,total,total_pages,page từ header phân trang của lõi. Đếm báo
approved, hold, spam, trash, post_trashed, all (đã duyệt cộng chờ duyệt) và
total. Kiểm duyệt trả id,status,previous_status,changed. Hàng loạt trả
updated,unchanged,failed (id kèm mã an toàn). Xóa trả id,deleted=true. Dọn trả
status,deleted,skipped,failed,remaining,cutoff (UTC có hậu tố Z). Trả lời cộng
thêm parent_approved.

Mọi kết quả đi qua OutputPipeline kiểm tra, biên tập, giới hạn byte và kiểm
tra lần cuối. Phong bì MutationResponse/MutationResult giữ ID do lõi quan sát
qua chiếu và kiểm tra đầu ra; không bao giờ gửi ra ngoài. Lỗi sau khi đã ghi
được xác nhận cho partial_failure kèm created_id hoặc affected_id. Thông điệp/
dữ liệu tùy ý của nhà cung cấp bị loại. Đọc lại và đối chiếu trước khi thử lại
một đột biến chưa rõ kết quả.

## Ghi chú an toàn

Tạo và sửa ép wp_filter_kses trên pre_comment_content bất kể unfiltered_html;
chỉ thêm khi chưa gắn và chỉ gỡ khi chính mình thêm, luôn trong finally. Tạo
mới giới hạn bộ lọc pre_comment_approved chỉ hạ tự duyệt thành chờ duyệt,
không bao giờ ghi đè phán quyết spam. Xóa hai bước và vĩnh viễn: bình luận con
được nâng lên, meta bình luận bị xóa, không hoàn tác. Dọn chỉ chọn spam/trash
cũ hơn một mốc chốt tính một lần để bình luận mới đến còn nguyên; tác tử dừng
khi deleted bằng 0 vì skipped giữ remaining khác 0. Hàng loạt và dọn dùng ngân
sách việc tốn kém (tối đa hai suất đồng thời mỗi site/khóa). Duyệt có thể gửi
email cho tác giả bài qua hook của lõi; tạo qua REST không phát thông báo
comment_post.

## Cài đặt và nghiệm thu

Pack này không thêm cài đặt site. Đóng bình luận của bài dùng lại update-post
của Content Pack (comment_status/ping_status). Nghiệm thu đòi hỏi CM-01..77
theo yêu cầu đã duyệt, cả hai fixture WordPress 6.9/7.1.2 và multisite, mọi
hồi quy N-01..35, hành vi ứng dụng MCP thật (hàng đợi kiểm duyệt, trả lời
nháp, dọn spam, từ chối vượt quyền), Plugin Check đóng gói và rà soát bảo mật
độc lập. Cổng chưa xác minh giữ tường minh trong báo cáo thực hiện.
