# Vận hành và ranh giới xác minh

Lõi triển khai sáu pack ability riêng tư (62 tool), xác thực có scope, quản lý thông tin xác thực, kiểm toán/quyền riêng tư và trang hồ sơ/quản trị. Nghiệm thu runtime đầy đủ và các cột mốc sau vẫn còn mở. Một phần kiểm tra đạt không xác lập mức sẵn sàng production.

## Khóa

Tạo khóa trên trang Profile của chủ sở hữu. Tạo/luân chuyển trả token một lần; xác nhận hoặc Escape xóa hộp thoại và tải lại không bao giờ khôi phục được token. Lưu trong kho bí mật của client. Khóa hiện có chỉ có thể bị hạn chế hơn; tạo khóa mới khi thêm quyền.

Quản lý cần cookie WordPress đã đăng nhập và nonce `wp_rest` tương ứng. Chỉ khóa bearer hoặc danh tính Application Password không thể thay thế. Người quản lý có thể liệt kê/thu hồi khóa của chủ sở hữu khác; luân chuyển thuộc chủ sở hữu. Cấp Destructive/Admin kiểm tra mật khẩu hiện tại với ngân sách năm lần thử mỗi phút. Thao tác ghi nhạy cảm với quota dùng khóa cơ sở dữ liệu không chặn, ràng buộc kết nối. Khóa API và named lock cần một database primary có thẩm quyền; hành vi multi-primary/NDB chưa được xác minh.

Luân chuyển còn cần InnoDB và kết nối mysqli không có giao dịch bao ngoài. Chèn khóa mới và cập nhật ân hạn khóa cũ commit cùng nhau; không thử lại ghi trên kết nối thay thế. Nếu mất phản hồi commit, kiểm tra danh sách khóa được làm mới và thu hồi mọi khóa thay thế chưa được hiển thị trước khi thử lại. Chỉ lỗi phản hồi không xác lập cơ sở dữ liệu đã commit hay chưa.

Thu hồi khóa bị ảnh hưởng tại Profile hoặc Agent Hub → Keys. Xác thực đọc hết hạn/thu hồi đã lưu và quyền chủ sở hữu hiện tại. Mất vai trò từ chối truy cập; xóa chủ sở hữu thu hồi khóa của họ. Thông báo đang chờ kiểm tra lại ràng buộc khóa/chủ sở hữu gốc.

## HTTPS và chẩn đoán

Dùng HTTPS cho MCP. HTTP thường cần đồng thời môi trường local/phát triển và hằng số phát triển rõ ràng. Site trình duyệt từ xa tắt ngoại lệ đó. Phê duyệt origin trình duyệt rõ ràng; token không bao giờ thuộc URL.

Chỉ header proxy và CIDR đã cấu hình được xác nhận TLS trong plugin. IP client chuyển tiếp không thay peer socket. Hosting viết lại `REMOTE_ADDR` thay đổi ranh giới này: lab tắt cấu hình `mod_remoteip` rộng của image Apache chính thức. Điều này không tuyên bố peer proxy là địa chỉ gốc của khách truy cập.

Overview gửi header mẫu không bí mật tới chẩn đoán quản lý và chỉ nhận boolean về sự hiện diện. Nếu thiếu, kiểm tra xử lý header Apache/Nginx/FastCGI hoặc WAF. Giữ Authorization mà không bỏ qua kiểm tra xác thực hoặc TLS. Phản hồi quản lý và MCP dùng `no-store` và `nosniff`.

## Kiểm toán, quyền riêng tư và email

Kiểm toán đệm metadata có giới hạn và ghi một INSERT tại shutdown. Nó ghi định danh/lý do/thời gian cố định và tên đối số cấp cao nhất đã lọc. Bỏ đối số thô, thông tin xác thực, header và băm. User agent được giới hạn và che mẫu thông tin xác thực có thể nhận biết. Che IP tùy chọn áp dụng IPv4 /24 và IPv6 /48 cho metadata kiểm toán và sử dụng khóa.

Lưu giữ hằng ngày dùng lô có giới hạn và lập lịch riêng cho tồn đọng. Nó cũng dọn bucket tần suất và marker thông báo hết hạn. Quy trình quyền riêng tư đã xác nhận của WordPress xuất metadata khóa/kiểm toán và lựa chọn thông báo của chủ sở hữu; xóa dữ liệu xóa khóa cùng marker liên quan, bỏ tùy chọn và ẩn danh liên kết kiểm toán. Nó cũng ẩn danh ID người thu hồi trên khóa của chủ sở hữu khác mà không xóa thông tin xác thực của người đó. Xóa dùng lô còn lại đầu tiên để tránh bỏ sót do offset và kiểm tra hoàn tất mọi tham chiếu chủ sở hữu/người thu hồi còn lại.

Thông báo tạo, quản trị viên thu hồi, hết hạn trong bảy ngày và thay đổi mạng tuân theo lựa chọn site và từ chối nhận của chủ sở hữu. Thư chạy sau giải phóng khóa sửa đổi khóa API. Kiểm thử chặn mọi thư; gửi thực tế chưa xác minh. `wp_mail()` chấp nhận không chứng minh thư đến hộp thư.

Gỡ cài đặt xóa mọi cron hook thuộc sở hữu, gồm sự kiện có đối số. Xóa mặc định chỉ drop bảng thuộc sở hữu và option có tiền tố. Bảo toàn rõ ràng giữ dữ liệu. Tùy chọn toàn cục vẫn còn nếu bất kỳ site network nào bảo toàn dữ liệu; nếu không, các lô chủ sở hữu có giới hạn xóa chúng. Gỡ cài đặt network lớn chưa xác minh. Bằng chứng đồng thời, cô lập và sự cố Redis có phạm vi ghi tại [checkpoint hiệu năng](architecture/performance.md); backend cache khác và failover production chưa xác minh.

Hủy kích hoạt tách biệt với gỡ cài đặt. Trên single site, nó xóa bốn sự kiện cron thuộc sở hữu plugin trên site hiện tại. Hủy kích hoạt toàn mạng duyệt mọi site theo trang giới hạn và chạy cùng bước dọn dẹp dưới `switch_to_blog()`, khôi phục blog hiện tại trong `finally`. Khóa, dòng kiểm toán, cài đặt và tùy chọn người dùng vẫn được giữ nguyên.

Cache bền vững phải cung cấp tăng nguyên tử dùng chung và thất bại khi lưu trữ không khả dụng. Không bật chế độ graceful không hỗ trợ của provider Redis: dự phòng bộ nhớ local có thể mất bộ đếm dùng chung. Cache toàn cục lỗi có thể dừng WordPress trước plugin này, trả 500 lõi thay vì 503 không khả dụng của Agent Hub. Phục hồi backend trước; đối soát kết quả sửa đổi chưa biết trước thử lại. Khởi động lại/flush cache có thể xóa bucket tần suất tạm nên thao tác hạ tầng phải nằm ngoài công cụ được ủy quyền có thể đặt lại chính giới hạn của chúng. Lab dùng bộ nhớ giới hạn với `noeviction`, tiền tố site duy nhất và timeout đọc số nguyên tương thích provider đã ghim.

## Vận hành taxonomy

Cấp khóa với toolset `taxonomy` (thêm `content` khi agent tạo bài rồi gán term). Việc hằng ngày dùng tier `write`; chỉ cấp `destructive` khi cho xóa term. Contributor/Author tạo được thẻ phẳng theo core nhưng không tạo được danh mục phân cấp; Editor/Administrator quản lý mọi term trong quyền đối tượng của mình.

Bật `show_in_rest` cho taxonomy cũ cũng mở route đọc term công khai cho khách chưa đăng nhập; chỉ bật khi tên term không nhạy cảm. Gán dùng hàm lõi trực tiếp: không đổi post_modified, không gửi save_post, không tạo revision, nhưng số đếm và hook quan hệ vẫn chạy. Count chỉ đếm bài đã xuất bản; bằng 0 không chứng minh không ai dùng. Xóa là vĩnh viễn: con được nâng lên, quan hệ bị gỡ, meta bị xóa, nên xem trước bằng dry_run.

## Vận hành người dùng

Cấp khóa với toolset `users`. Tra cứu hằng ngày dùng tier `read`; sửa hồ sơ chính chủ dùng tier `write`; quản trị tài khoản cần tier `admin` kèm `allow_admin_tier` của site (mặc định tắt, chỉ cho người có `manage_options` kèm nhập lại mật khẩu). Mặc định chỉ Administrator thấy 10 công cụ tra cứu/quản trị; Contributor trở lên thấy 2 công cụ hồ sơ chính chủ khi được dùng plugin.

Vùng bảo vệ Admin không có ngoại lệ: administrator, super admin và người/vai trò mang quyền đặc quyền không sửa, đổi vai trò, cấm, đặt lại hay hủy phiên qua MCP được, kể cả bằng chính khóa của họ; chính chủ chỉ sửa trường hồ sơ không nhạy cảm. Không nhận/trả mật khẩu; tài khoản mới nhận mật khẩu ngẫu nhiên của server kèm email core để tự đặt; đặt lại chỉ gửi email cho chính chủ tài khoản kèm cooldown và ngân sách. Cấm chuyển tài khoản sang vai trò `banned` không quyền, gỡ cap trực tiếp và hủy phiên cookie; mở cấm khôi phục bản chụp sau khi kiểm lại. Khóa của người bị cấm dừng ngay ở xác thực mà không bị thu hồi. Đổi email giữ nguyên thư báo địa chỉ cũ của core; phiên tồn tại sau đổi email trừ khi hủy. Editor mặc định có `unfiltered_html` và vẫn gán được; mở rộng bảo vệ bằng `xnetvn_agent_hub_wp_user_privileged_caps` khi cần. Loại thân request `/wp/v2/users` khỏi plugin ghi nhật ký vì `create-user` truyền mật khẩu ngẫu nhiên một lần.

## Lab từ xa

Service/cơ sở dữ liệu/website/trình duyệt/E2E thực hiện qua `remote-test-lab`. Tệp compose có WordPress 6.9/7.1.2, cơ sở dữ liệu và toolbox với tổng giới hạn 2560 MiB; Playwright tạm thêm 2048 MiB. Cổng chỉ trên loopback và Hestia cung cấp HTTPS. `.env.rtl` bị Git bỏ qua chứa giá trị kiểm thử; môi trường thực và private key không được đồng bộ.

Rà soát ranh giới tài nguyên và bí mật đã render của lab trước triển khai. Thay đổi toàn máy chủ cần người dùng duyệt riêng. Image ghim phiên bản/digest và archive 6.9 chính thức đã xác minh SHA-256 cung cấp đầu vào tái lập. Tên miền, địa chỉ, CIDR proxy tin cậy và mật khẩu kiểm thử được cung cấp qua cấu hình môi trường bị Git bỏ qua thay vì literal nội bộ được commit. Cấu hình trình duyệt từ xa chỉ nhận site lab dự án đã cấu hình, xử lý chứng chỉ tự ký, tắt ghi hình và xóa trường thông tin xác thực trước ảnh chụp lỗi tự động.

`infra/remote/check-package.sh` kiểm tra ZIP đã build trong bản cài WordPress chỉ CLI và tiền tố bảng riêng. Nó sao chép lõi và phụ thuộc kiểm thử, kích hoạt plugin đóng gói không có vendor phát triển và chạy Plugin Check. Bộ chặn thư ngăn gửi ra ngoài, không sửa bảng site trình duyệt. Xác thực gói tách biệt với quét thư mục nguồn và nghiệm thu cột mốc đầy đủ.

Preflight ban đầu báo Docker prune mỗi giờ gốc và hai bộ quy tắc bridge firewall cũ. Giữ stack thuộc phạm vi quản lý hoạt động và thu `diagnose` ngay khi thất bại. Đổi cron/firewall/toàn máy chủ cần cho phép riêng; chưa thực hiện thay đổi nào.

Tham chiếu chính thức, đã xác minh ngày 2026-10-05: [xác thực REST](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/), [bộ xuất](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/), [bộ xóa](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/), [gỡ cài đặt](https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/), [named lock](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html), [WCAG 2.2](https://www.w3.org/TR/WCAG22/). Bộ tích hợp lõi từ xa đã đạt nhưng nghiệm thu runtime cột mốc đầy đủ và production vẫn chưa được xác minh.
