# Phát triển

Sử dụng PHP 8.2+, Composer 2.4+ để kiểm toán bảo mật, Node 24, npm, Docker và WP-CLI. Cài phụ thuộc đã khóa bằng `composer install` và `npm ci`. Có thể dùng Composer PHAR cục bộ trong dự án mà không nâng cấp công cụ hệ thống.

| Kiểm tra | Lệnh |
|---|---|
| Metadata phụ thuộc | `composer validate --strict` |
| Quy chuẩn WordPress | `composer lint` |
| Cú pháp bootstrap PHP 7.2 | `composer lint:bootstrap` |
| Phân tích tĩnh | `composer analyse` |
| Kiểm thử đơn vị | `composer test:unit` |
| Toàn bộ cửa kiểm tra tĩnh/đơn vị | `composer check` |
| Cửa kiểm tra tài liệu | `composer check:docs` |
| Khởi động WordPress dùng một lần | `npm run env:start` |
| Tích hợp WordPress thực tế | `composer test:integration` |
| Ma trận từ chối HTTP từ xa | `composer test:http` |
| Rà soát kế hoạch từ xa | `npm run remote:plan` |
| Triển khai kế hoạch từ xa đã duyệt | `npm run remote:up` |
| Luồng trình duyệt từ xa | `npm run test:e2e` |
| Lỗ hổng phụ thuộc | `composer audit` |
| Mẫu bản dịch | `bash bin/make-pot.sh` |
| ZIP phát hành | `composer build` |
| Kiểm thử hồi quy loại trừ khi phát hành | `composer test:packaging` |

Service, cơ sở dữ liệu, website, trình duyệt và E2E/debug chạy qua remote-test-lab. Linter và kiểm thử đơn vị không cần service vẫn chạy local. Toolbox từ xa đã duyệt mặc định dùng WordPress 6.9; `composer test:integration -- 7.1.2` chọn bộ môi trường bản ổn định hiện tại. Tích hợp lõi và site trình duyệt dùng tiền tố bảng riêng. CI được host vẫn chạy wp-env dùng một lần; không khởi động trên máy trạm này.

`XNETVN_TEST_MULTISITE=1 composer test:integration -- 6.9` chạy bộ kiểm thử lõi cô lập dưới dạng multisite trong toolbox từ xa; dùng 7.1.2 cho bản ổn định hiện tại. Các bản cài trình duyệt không thay đổi. CI thêm hai tổ hợp multisite bắt buộc (PHP 8.2, WordPress tối thiểu/hiện tại, adapter tối thiểu) và truyền `WP_MULTISITE` trong container kiểm thử. Các tổ hợp tích hợp khác vẫn là single-site.

Môi trường hiện tại yêu cầu MCP Adapter chính thức, kích hoạt trước plugin này. CI định nghĩa kiểm tra đơn vị PHP 8.2–8.5 và các tổ hợp tích hợp WordPress ổn định. Tích hợp PHP 8.5 và WordPress trunk vẫn thử nghiệm cho đến khi có bằng chứng tương thích thực tế. wp-env 11.16 đánh dấu cài đặt hai môi trường là lỗi thời; vẫn giữ chúng cho cấu hình tests-cli bắt buộc đến khi xác minh việc chuyển sang cấu hình riêng.

Ghi đè phụ thuộc phát triển chọn phiên bản major tương thích đã vá của ajv, qs và ws vì công cụ Playground thượng nguồn ghim phiên bản có cảnh báo bảo mật đã công bố. Tệp khóa npm ghi phiên bản được chọn. Xác minh lại khởi động/tích hợp wp-env và npm audit mỗi khi đổi các ghi đè này; đây không phải phụ thuộc production của plugin.

Không chạy `wp-env clean` trên môi trường có dữ liệu giá trị. Kiểm thử gỡ cài đặt sơ bộ phải dùng site dùng một lần hoặc bản đóng gói vì gỡ cài đặt xóa tệp plugin. Bộ dựng phát hành tạo bản sao trong vùng staging, cài phụ thuộc chỉ dành cho production và chỉ xóa tệp khóa ở bản sao staging. Checkout nguồn giữ tệp khóa và phụ thuộc phát triển.

Lệnh build trước hết kiểm thử việc xác thực ZIP từ chối tệp môi trường/cấu hình ẩn và hạ tầng từ xa. Chạy `infra/remote/check-package.sh` trong toolbox lab sau build để kích hoạt và kiểm tra gói thực tế trong các bảng riêng chỉ dùng CLI. Bộ chặn thư kiểm thử WordPress ngăn gửi thư ra ngoài.

Ma trận HTTP chạy trong toolbox lab thuộc phạm vi quản lý, nhắm tới alias HTTPS WordPress 6.9 và service HTTP nội bộ. Nó tạo người dùng/khóa ngẫu nhiên dùng một lần, chỉ ghi thông tin xác thực vào thư mục 0700 phía máy chủ với tệp trạng thái 0600, và ghi kết quả chỉ gồm boolean/status. Runner từ chối fixture có sẵn cho đến khi đối soát, đồng thời dọn các khóa/người dùng/kiểm toán/option/tệp mu-plugin đã ràng buộc khi thoát bình thường. Container/tiến trình bị kết thúc cần đối soát rõ ràng bằng trạng thái riêng đã lưu. Cookie/nonce được tạo bằng cùng alias với client HTTP để COOKIEHASH WordPress khớp. Ngoại lệ chứng chỉ chỉ dành cho kiểm thử được giới hạn ở host lab trong danh sách cho phép; tuyệt đối không theo chuyển hướng. Không thành phần fixture nào được đưa vào ZIP production.

Nếu lựa chọn họ kết nối của Node trên host này thất bại trong khi HTTPS IPv4 thành công, có thể dùng `NODE_OPTIONS=--no-network-family-autoselection` chỉ trong tiến trình để chẩn đoán. Không tắt xác thực TLS.

Tham chiếu chính thức: [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/), [vòng đời PHP](https://www.php.net/supported-versions.php). Đã xác minh ngày 2026-10-04.
