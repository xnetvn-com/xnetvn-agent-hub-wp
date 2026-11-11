# Nghiệm thu Plugin Check

Plugin Check 2.1.0 được cài từ WordPress.org ngày 2026-10-04. Chạy kiểm tra trên ZIP production đã cài thay vì thư mục thử nghiệm phát triển chứa các bản phân phối thượng nguồn đã tải. Chất lượng nguồn được bao phủ riêng bằng WPCS, PHPCompatibility, PHPStan và kiểm thử.

Phát hiện ban đầu trong gói: languages/.gitkeep là tệp ẩn bị cấm; Code of Conduct cộng đồng là metadata phát hành không cần thiết; Tested up to 6.9 thấp hơn dòng ổn định hiện tại. Danh sách loại trừ phát hành hiện loại .gitkeep và CODE_OF_CONDUCT.md. Kiểm thử tích hợp thực tế đạt trên WordPress 6.9 và 7.1.2 trước khi đặt Tested up to 7.1 (header readme dùng dòng major, không có thành phần patch).

Cảnh báo đặt tên vẫn hiển thị và không bị chặn: các lần chạy đã ghi nhận dùng tên sản phẩm trước đây và đếm thuật ngữ `wordpress`. Tên đã chốt xNetVN Agent Hub WP loại bỏ thuật ngữ đó, nhưng `wp` trong tên, readme và slug vẫn là cảnh báo dự kiến mà lần chạy lại của Task 6 phải xác nhận lại; cảnh báo này chưa được quan sát dưới tên mới. Mã của bộ kiểm tra ghi rõ wp được phép nhưng có cảnh báo. Hướng dẫn 17 cấm nhãn hiệu dự án khác là thành phần duy nhất hoặc đầu tiên của slug; slug cố định bắt đầu bằng xnetvn. Bằng chứng này không xác lập quyền sử dụng nhãn hiệu hoặc sự chấp thuận của WordPress.org. Giữ tên và slug đã được chủ sở hữu duyệt; rà soát thư mục plugin vẫn là cửa phát hành bên ngoài. Xem xét lại nếu người rà soát thư mục yêu cầu đổi.

Bootstrap không gọi load_plugin_textdomain. Chuỗi giao diện chỉ được dịch tại hook hiển thị và dùng text domain cố định; kiểm tra gói đã cài là bằng chứng nghiệm thu U8. Không đưa vào bộ tải text domain thủ công cho plugin hướng tới WordPress.org.

Checkpoint đóng gói ngày 2026-10-05 được kích hoạt với vendor chỉ dành cho production trong bản cài WordPress 7.1.2 riêng. Plugin Check 2.1.0 báo 0 lỗi và 21 cảnh báo hiển thị: 12 phát hiện đầu vào/unslash, 6 phát hiện cơ sở dữ liệu khi gỡ cài đặt và 3 phát hiện đặt tên. Đầu vào bearer/socket/origin chính xác được xác thực mà không viết lại văn bản chung; metadata được giới hạn/che trước lưu trữ. Gỡ cài đặt dùng truy vấn bảng/option thuộc sở hữu đã chuẩn bị và API xóa WordPress thay vì cache bản chụp xóa. Các phát hiện vẫn hiển thị để reviewer đánh giá; kiểm tra quy chuẩn/bảo mật tĩnh và rà soát nguồn độc lập là bằng chứng riêng. Báo cáo này không phải phê duyệt WordPress.org hay nghiệm thu M1 đầy đủ.

## Cảnh báo ghi nhận ngày 2026-11-04

Lần chạy gói ngày 2026-11-04 dùng Plugin Check 2.1.0 trên ZIP phát hành do `composer build` tạo, báo 0 lỗi và 23 cảnh báo. Lần chạy này diễn ra trước khi đổi tên hiển thị, nên các cảnh báo đặt tên của nó mô tả tên cũ. Mọi nhóm được ghi nhận dưới đây; cả 23 cảnh báo vẫn hiển thị trong báo cáo.

| Phát hiện | Số lượng | Cách xử lý |
|---|---|---|
| `WordPress.Security.ValidatedSanitizedInput` thiếu unslash/sanitize | 12 | Đầu vào bearer, socket và Origin chính xác được xác thực mà không viết lại văn bản chung; `HTTP_USER_AGENT` và IP/UA kiểm toán được giới hạn trước khi dùng. Chỉ sửa khi kiểm thử hành vi chứng minh thay đổi an toàn; nếu không, giữ cảnh báo hiển thị kèm bằng chứng này. |
| Truy vấn trực tiếp `WordPress.DB.*` trong `uninstall.php` | 6 | Chỉ nằm trong gỡ cài đặt; bảng, option và user meta thuộc sở hữu plugin được xóa bằng truy vấn đã chuẩn bị và API xóa WordPress. Giữ hiển thị. |
| `WordPressVIPMinimum.Performance.WPQueryParams.PostNotIn_exclude` | 2 | Dương tính giả: `src/Abilities/Packs/Taxonomy/TaxonomySchemas.php:161` và `src/Abilities/Packs/Users/UserSchemas.php:264` là định nghĩa thuộc tính schema, không phải lệnh gọi `get_posts()`. Giữ hiển thị kèm bằng chứng dòng này. |
| `trademarked_term` | 3 | Ba cảnh báo đã ghi nhận đến từ tên cũ, vốn chứa thuật ngữ `wordpress`. Sau khi đổi tên, cảnh báo dự kiến là `wp` trong tên, readme và slug; lần chạy lại của Task 6 phải xác nhận lại vì cảnh báo này chưa được quan sát dưới tên đã chốt. `wp` được phép nhưng có cảnh báo; ghi nhận và không chặn. Slug giữ `xnetvn-agent-hub-wp`, và rà soát WordPress.org vẫn có thể nêu thuật ngữ này. |

Bộ chạy gói cài Plugin Check từ WordPress.org khi site gói biệt lập không có sẵn, sau đó yêu cầu phiên bản 2.1.0 và từ chối mọi phiên bản khác cho tới khi phiên bản đó được ghi nhận và rà soát. Chạy lại kiểm tra bằng:

```bash
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" sync
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec toolbox -- bash -lc 'cd /work && composer build && bash infra/remote/check-package.sh /work/dist/xnetvn-agent-hub-wp-1.0.0.zip'
```

Bản ghi này không phải phê duyệt của WordPress.org.

Nguồn chính thức, đã xác minh ngày 2026-10-04:
- https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- https://developer.wordpress.org/plugins/wordpress-org/how-your-readme-txt-works/
- https://wordpress.org/plugins/plugin-check/
- https://developer.wordpress.org/plugins/internationalization/how-to-internationalize-your-plugin/
