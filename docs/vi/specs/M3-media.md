# M3 — Hợp đồng Media

Thẩm quyền thiết kế: E-03 đến E-07 được duyệt ngày 2026-10-08. Mã đã hiện thực;
nghiệm thu chạy thật đầy đủ và cổng bảo mật độc lập vẫn mở. Comments thuộc phần
milestone riêng, nằm ngoài tính năng này. Bản tiếng Anh cùng đường dẫn là nguồn chuẩn.

## Ranh giới thực thi riêng tư

Cả bảy ability dùng toolset `media`, quyền hiển thị `upload_files`. Quyền hiển thị
không thay thế quyền theo đối tượng của core. HTTP bắt buộc có AuthContext của chủ
khóa; tier của khóa/site và allow/deny vẫn giao với quyền hiện tại. Pack không đổi
danh tính. Route attachment được core phân giải tại thời điểm gọi.

| Ability | Tier | Cơ chế | Ràng buộc bổ sung |
|---|---|---|---|
| list-media | read | REST GET danh sách | Bộ lọc/phân trang giới hạn; không có trash |
| get-media | read | REST GET đối tượng | ID đúng attachment; raw cần edit_post |
| update-media | write | REST POST đối tượng | Ít nhất một trường được duyệt; kiểm quyền cha |
| upload-media-from-data | write | REST POST thân thô | Base64 chuẩn và giới hạn; nhận diện byte thật |
| upload-media-from-url | write | Tải trực tiếp + REST POST thân thô | Mặc định tắt; HTTPS công khai; transport riêng ghim IP |
| delete-media | destructive | REST DELETE force=true | Xóa vĩnh viễn cả kích thước phụ |
| get-media-upload-limits | read | Trực tiếp | Giao giới hạn/MIME của core và plugin cho người hiện tại |

Khai báo tường minh readonly/destructive/idempotent/openWorldHint. Chỉ tải URL có
openWorldHint=true. Tool đọc và update-media có tính idempotent; upload và xóa không
được khai là idempotent. Mô tả tool cố định bằng tiếng Anh, coi nội dung site là dữ liệu.

## Đầu vào

Mọi schema gốc là object, additionalProperties=false. ID là số nguyên dương;
post=0 nghĩa là gỡ cha. page>=1; per_page=1..100, mặc định20. content_format là
raw/rendered/excerpt, mặc định rendered. fields chỉ thu hẹp DTO, luôn giữ id/status.
Không được chuyển raw bị từ chối thành rendered.

list-media nhận search<=200, media_type tối đa5 loại đã duyệt, mime_type tối đa10,
parent>=0, author tối đa20 ID, after/before ISO8601 có múi giờ, status inherit/private,
orderby/order đã duyệt, phân trang và content_format/fields. Quyền xem nội dung riêng
tư và tổng số trang do core quyết định. Bộ lọc sai hoặc không được hỗ trợ bị từ chối.

update-media cần id và ít nhất một trường: title<=1000, alt_text<=1000,
caption<=5000, description<=10000, post>=0. Không nhận author/status/slug/source_url,
guid/MIME hoặc thay tệp. Cha khác0 phải tồn tại, không là attachment/revision và
người hiện tại phải có quyền sửa cha đó.

Upload dữ liệu cần filename dài1..255, mime_type khai báo<=127 và data_base64.
Trần chuỗi tĩnh là11184812 ký tự; giới hạn byte động được kiểm trước/sau giải mã
strict. Từ chối padding không chuẩn, khoảng trắng và tiền tố data:. Metadata dùng
cùng allowlist của update-media.

Upload URL cần HTTPS dài<=2048; filename và metadata đã duyệt là tùy chọn. Khi thiếu
tên tệp, lấy từ đường dẫn URL ban đầu, không tin Content-Disposition phía xa.
MIME lấy từ byte tải về.

get-media-upload-limits không nhận tham số; trả max_upload_bytes,
data_upload_max_bytes, remote_fetch_enabled, remote_fetch_max_bytes, pixels_max
và allowed_mime_types đã sắp xếp. Không trả đường dẫn hoặc thông tin xác thực.

## Đầu ra và biên nhận mutation

DTO đóng chỉ cho id, date/date_gmt, modified/modified_gmt, slug, status, link,
title/caption/description, alt_text, media_type, mime_type, source_url, author,
post, media_details và truncated tùy chọn. UTC có hậu tố Z; post=null của core được
chuẩn hóa thành0. Văn bản theo content_format. media_details chỉ cho
width/height/filesize, file tương đối an toàn và sizes chứa
width/height/mime_type/source_url. Không EXIF/image_meta, guid, _links, _embedded,
meta tùy ý, đường dẫn tuyệt đối hoặc byte tệp. Danh sách trả
items,total,total_pages,page lấy tổng từ header nội bộ của core.

Xóa trả id,deleted=true; không thể hoàn tác và có thể làm hỏng liên kết nội dung.
Mọi kết quả qua OutputPipeline: kiểm schema, xóa dữ liệu nhạy cảm, giới hạn byte,
kiểm schema lại. MutationResponse/MutationResult là lớp bao nội bộ, giữ ID core đã
quan sát qua bước chiếu/kiểm đầu ra; không bao giờ được gửi trên giao thức. Lỗi sau
khi đã ghi trả partial_failure với created_id/affected_id đáng tin cậy. Không giữ
thông điệp/data tùy ý từ provider. Phải đọc lại để đối soát trước khi thử lại kết quả
một phần hoặc chưa chắc chắn.

## An toàn upload

Loại được phép là giao giữa allowlist và get_allowed_mime_types của người hiện tại,
trừ denylist và hook chỉ-từ-chối. Allowlist: JPEG, PNG, GIF, WebP, AVIF, PDF,
MPEG/OGG/WAV audio, MP4/WebM video. Chỉ chuẩn hóa nhãn dò WAV tương đương
audio/x-wav thành audio/wav; vẫn đối chiếu đuôi/core/khai báo. unfiltered_upload
không bỏ qua kiểm tra.

Cấm SVG/HTML/XML/JavaScript/CSS, đuôi thực thi/script/cấu hình server/tệp nén, kể cả
thành phần nguy hiểm trong đuôi kép. Cấm slash, backslash, NUL, ký tự điều khiển,
'..' và tên quá dài. Core làm sạch khoảng trắng hợp lệ. Đuôi cuối phải khớp nội dung
thật và MIME core đã duyệt. Ảnh phải có kích thước hợp lệ, không vượt trần điểm ảnh
và ngân sách bộ nhớ trước khi core sinh ảnh phụ.

Chỉ dùng tệp tạm do wp_tempnam tạo, xóa trong finally. Chỉ ghi bằng REST attachment
thân thô của core, không giả $_FILES/get_file_params. Giữ hạn mức, sanitization,
quyền và hook core. Gỡ header attachment-ID khỏi phản hồi MCP bao ngoài.

## An toàn tải từ xa

Mặc định tắt. Cần cURL/Requests và hằng số ghim IP; thiếu thì từ chối. Chỉ HTTPS,
không userinfo, chỉ cổng443, chỉ tên miền; cấm nhãn đơn/local/internal/host chính
site. IDN chỉ chuẩn hóa khi có intl. Kiểm mọi bản ghi A/AAAA theo dải IP cấm; từ
chối tập rỗng/quá nhiều hoặc lẫn địa chỉ công khai và riêng. Kiểm URL của WordPress
chỉ là lớp bổ sung, không được mở rộng chính sách riêng. `WP_HTTP_BLOCK_EXTERNAL` và
`WP_ACCESSIBLE_HOSTS` của site được kiểm tra trước khi phân giải DNS và lặp lại trên
mỗi bước chuyển hướng; host được phép không vượt qua chính sách địa chỉ riêng và
kiểm DNS đầy đủ.

ADR-0014 thay cách hook HTTP toàn cục bằng Requests đi kèm core, chọn Curl và bộ
Hooks riêng. Trước truyền: đặt URL đã duyệt, xóa CONNECT_TO/proxy, ghim IP đã kiểm,
chỉ HTTPS, xác minh peer/host bằng CA core, đặt header cố định, tắt giải nén và giới
hạn thời gian/byte stream. Không đăng ký hook HTTP toàn cục. Chuyển hướng thủ công;
mỗi bước kiểm lại toàn bộ chính sách và ghim lại. Chỉ nhận200, byte không rỗng/không
quá trần, không Content-Encoding. Bỏ qua MIME/tên do server xa khai. Xóa tệp tạm
trung gian/lỗi. Mọi từ chối chính sách cùng mã/thông điệp remote_fetch_refused.

Ngân sách tốn kém tiêu thụ costly() cho hai tool upload và bulk nội dung. Tối đa hai
slot MySQL không chờ, thuộc connection, theo site/khóa; STDIO theo actor tường minh.
Connection đóng sẽ nhả khóa worker chết; theo dõi cục bộ ngăn lấy lại cùng khóa
trên một connection để vượt trần. Không có TTL hết hạn khi công việc còn chạy. Lỗi
storage không bao giờ được hiểu là slot trống.

Requests max_bytes chỉ giới hạn số byte lưu, không dừng đường truyền. Dùng cùng
giới hạn cho lưu trữ và callback CURLOPT_XFERINFOFUNCTION trả1 khi vượt trần;
CURLE_ABORTED_BY_CALLBACK tránh nhánh thử lại của Requests khi lỗi ghi. Không dựa
vào ngoại lệ trong request.progress: PHP/cURL đã cài trì hoãn ngoại lệ tới phần
đuôi phản hồi. Hồi quy TLS thật kiểm tra dừng trước phần đuôi, giữ nguyên ngưỡng
thời gian và kích thước fixture ban đầu.

Trên multisite, cả hai đường upload kiểm tra is_upload_space_available trước khi
giải mã hoặc tải mạng. Hết hạn ngạch trả quota_exceeded, thay vì hiểu giới hạn
upload bằng0 của core thành dữ liệu sai hoặc payload quá lớn. Core vẫn kiểm tra
lại hạn ngạch khi lưu attachment.

## Cài đặt và nghiệm thu

ENVIRONMENT.md là danh mục cài đặt chuẩn. Mặc định media: remote enabled=false;
remote max10485760 byte (1024..67108864), timeout15 giây (3..30), redirects3 (0..5),
data max1048576 byte (1024..8388608), pixels40000000 (1000000..100000000), denylist
MIME rỗng. Đây là ngân sách đề xuất, không phải năng lực production đã đo. Storage,
REST quản lý và UI dùng chung khoảng; quản lý vẫn cần cookie+nonce+capability.

Nghiệm thu cần MD-01..35, SS-01..24; WordPress6.9/7.1.2 và multisite; HTTP upload
ngoài DIR_TESTDATA; N-01..35; ứng dụng MCP thật; accessibility trình duyệt; Plugin
Check cho ZIP và review độc lập. Một mẫu HTTPS công khai thành công không chứng
minh ma trận DNS rebinding, chuyển hướng, stream nén hoặc cleanup có kiểm soát.
Các cổng chưa xác minh phải được ghi rõ trong báo cáo.
