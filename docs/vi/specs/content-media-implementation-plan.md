# Kế hoạch triển khai Content và Media

Trạng thái: được duyệt triển khai ngày 2026-10-08; nghiệm thu đầy đủ vẫn mở. Bản tiếng Anh cùng đường dẫn là kế hoạch kỹ thuật chuẩn và sổ bằng chứng.

## Mục tiêu và thẩm quyền

Triển khai 19 tool Content và 7 tool Media trong phiếu yêu cầu ngày 2026-10-08. Chủ dự án đã duyệt rõ E-01 đến E-08 và cho phép bắt đầu triển khai trước khi M1 được nghiệm thu độc lập. Cổng bảo mật vẫn bắt buộc trước phát hành. Hợp nhất các roadmap; không khôi phục phiếu M2 tiếng Việt cũ. Không commit, push, merge hoặc publish khi chưa có cho phép riêng.

Thực thi trực tiếp bằng `superpowers:executing-plans`; dùng TDD và giữ bằng chứng. Không cần mã thư viện tham khảo hay phụ thuộc runtime mới.

## Kiến trúc

Giữ xác thực, chính sách, registry riêng tư và pipeline đầu ra là ranh giới thực thi duy nhất. Pack cài đặt `AbilityPack`, dùng hợp đồng chung và điều phối các yêu cầu REST nội bộ đã review mà không đổi danh tính. Post type được phân giải tại thời điểm gọi. Chỉ `ServerGateway` import lớp adapter.

Dùng mapper lý do an toàn và helper REST dùng chung; Content và Media có schema và projection nghiêm ngặt riêng. Upload dùng thân yêu cầu REST thô, không giả lập multipart. Tải từ xa mặc định tắt và phải fail closed khi không chứng minh được ghim IP trước khi gửi. Việc nặng dùng slot MySQL nguyên tử theo từng khóa, thuộc quyền sở hữu của kết nối, dọn trong `finally`.

## Ràng buộc

- PHP >=8.2, WordPress >=6.9, MCP Adapter >=0.7.0 <0.8.0.
- Cú pháp bootstrap và `uninstall.php` giữ tương thích PHP 7.2.
- Nguồn, mô tả và tài liệu chuẩn dùng tiếng Anh; tiếng Việt là bản dịch.
- Ability riêng tư, schema object nghiêm ngặt, lỗi an toàn cố định, không ghi giá trị đối số vào log, không vượt quyền hay vượt tầng truyền tải.
- Không chạy service hoặc trình duyệt ở máy local. Mọi kiểm integration/HTTP/E2E chạy qua `rtl.py`.
- Không tuyên bố sẵn sàng production hoặc nghiệm thu cột mốc từ test local.

## Trọng tâm review

1. Core bỏ lặng meta không biết: từ chối rõ khóa chưa đăng ký trước khi ghi; giữ quyền core theo từng khóa và làm sạch đầu ra.
2. Projection sau khi ghi thành công có thể lỗi: báo kết quả không xác định/một phần mà không thử lại hoặc tuyên bố đã rollback.
3. Filter HTTP có thể đổi tầng truyền tải hoặc header: chứng minh ghim trước khi truyền, không suy ra tầng truyền tải an toàn từ một hook chạy sau khi đã truy cập mạng.
4. Lease cũ không được để yêu cầu trước nhả slot của yêu cầu mới: dùng token sở hữu và lưu trữ nguyên tử thay cho khóa đọc/ghi tạm.
5. Hành vi trash, cha của attachment và meta đã đăng ký của core khác nhau theo phiên bản: đọc controller đã cài và ghim hành vi trên cả hai fixture kiểm thử.

## Công việc theo thứ tự

Mỗi việc hành vi theo RED (khẳng định mới đỏ), GREEN (thay đổi sản phẩm tối thiểu), REFACTOR (giữ bộ kiểm xanh). Không có bước commit nào được duyệt.

- [x] Đọc git, đặc tả hiện hành, kiến trúc, ADR, registry, cài đặt và lab.
- [x] Baseline `composer check`: 177 test, 50 416 khẳng định; PHPStan sạch.
- [x] Xác minh WordPress 6.9/7.1.2 và adapter 0.7.0 đã cài trên lab.
- [ ] Ghi ADR 0013 (kiểu/DTO/xung đột), 0014 (media/fetch/cài đặt/ngân sách), 0015 (lỗi an toàn); tạo `M3-media.md`; cập nhật M2 và danh mục.
- [ ] Lỗi an toàn: thêm `Abilities/ReasonMapper.php`; test ở `tests/Unit/Abilities/ReasonMapperTest.php` và integration pipeline/gateway. Giao diện: `error(string): WP_Error`, `sanitize(WP_Error): WP_Error`, `reason(string, int): string`. Chỉ giữ lý do trong allowlist; thay thông điệp/dữ liệu thượng nguồn. Bao CT-38/39 và mọi điểm vào đầu ra.
- [ ] Helper REST: `Abilities/RestBackedAbility.php` phơi `dispatch(string, string, array): WP_REST_Response|WP_Error` và `collection(WP_REST_Response, int, Closure): array|WP_Error`. Test bao callback quyền thật của core, schema sai, route thiếu, header phân trang an toàn và lỗi filter trên cả hai phiên bản.
- [ ] `Content/PostTypeResolver.php`: `resolve(string): WP_Post_Type|WP_Error`; route phân giải qua core tại thời điểm gọi; đúng controller mặc định, từ chối kiểu hệ thống, CPT đăng ký muộn, hook deny-only. Bao CT-06..11/40.
- [ ] Schema Content và `ContentPack.php`: định nghĩa qua AbilityFactory; chỉ mở rộng PostProjection cho E-01 đã duyệt. Contract-test 19 tên, tier, hiển thị, annotation tường minh và schema/ví dụ nghiêm ngặt.
- [ ] Tool đọc: types/statuses/list/get/search/meta. Bao CT-05/14..16/20/33..35, phân trang, không rò bản nháp qua search, từ chối raw và projection meta đã đăng ký.
- [ ] Tool ghi: create/update/trash/restore/delete; xử lý ngày UTC, kiểm tra ngày tương lai, `expected_modified` nỗ lực tối đa; bao CT-01..04/22/28/30..32/42.
- [ ] Revision, thumbnail và sticky: ràng buộc bài cha và quyền đối tượng chính xác; bao CT-12/13/27/29. Restore Direct kiểm trước khi ghi và đọc lại qua REST.
- [ ] Ghi duplicate/bulk/meta: allowlist, loại trừ khóa được bảo vệ, không sao chép tác giả, tối đa 50 ID duy nhất, kết quả từng ID trung thực. Bao CT-17..21/41.
- [ ] Đăng ký ContentPack trong `Plugin.php`; kiểm danh tính HTTP, trần scope/tier/site, mất/phục hồi vai trò và cách ly multisite (CT-23..26/37).
- [ ] Cài đặt E-04: WpSiteSettings, SettingsController, Pages; dải số dùng chung gồm `redirects=0`, kiểm denylist, ranh giới cookie/nonce; sinh và ghi mọi mặc định thật vào `ENVIRONMENT.md`.
- [ ] Schema Media, MediaProjection và MediaPack read/update/delete/limits; `media_details` lồng nghiêm ngặt, không EXIF/đường dẫn tuyệt đối, quyền cha và xóa không thể hoàn tác. Bao MD-02/11..19/29/31.
- [ ] UploadPolicy và upload dữ liệu thân thô: base64 chuẩn tắc, giới hạn trước khi giải mã, thành phần tên tệp không an toàn, giao MIME thật với core, ngân sách ảnh, dọn tệp tạm. Bao MD-01/03..10/28/32..35, gồm cả ngoài `DIR_TESTDATA`.
- [ ] Ngân sách tác vụ nặng nguyên tử trong registry; tối đa hai lời gọi nặng đang chạy mỗi khóa, nhả ở mọi lối ra, cách ly site/khóa và phục hồi lease hết hạn. Bao MD-30 và tiêu thụ ngân sách của bulk.
- [ ] Chính sách DNS/URL và SafeRemoteFetcher; DNS tiêm được cho unit test, ghim cURL chỉ HTTPS thật cho runtime, chuyển hướng thủ công có giới hạn, giới hạn stream, không rò credential/proxy, dọn dẹp và deadline tổng. Bao SS-01..24 và MD-20..28. Giữ tắt khi chưa có bằng chứng U-FETCH-1/2.
- [ ] Cập nhật toàn bộ tài liệu công khai/changelog, kết quả ADR, operations, adapter notes, threat model và roadmap; ánh xạ C-01..18 sang đã xử lý hoặc loại trừ được chủ dự án duyệt.
- [ ] Nghiệm thu remote: integration 6.9/7.1.2 và multisite, đủ N-01..35, CT-01..42, MD-01..35, SS-01..24, accessibility cài đặt trên trình duyệt, client MCP thật, thời gian tools/list 31 tool thật, Plugin Check, `composer check` và build.
- [ ] Review bảo mật độc lập; sửa phát hiện bằng test đỏ/xanh. Báo riêng phần local/tĩnh, runtime remote, hosted CI, production và phát hành.

## Nguồn và quan sát nền

Đã xác minh ngày 2026-10-08 bằng tài liệu chính thức và mã nguồn đã cài trên remote:

- [Điều phối REST nội bộ](https://developer.wordpress.org/reference/functions/rest_do_request/)
- [Upload attachment thân thô](https://developer.wordpress.org/reference/classes/wp_rest_attachments_controller/upload_from_data/)
- [Kiểm URL và các filter của nó](https://developer.wordpress.org/reference/functions/wp_http_validate_url/)
- [Hướng dẫn ability của adapter chính thức](https://github.com/WordPress/mcp-adapter/blob/trunk/docs/guides/creating-abilities.md)

Mã nguồn fixture runtime, chứ không phải tài liệu trunk đang thay đổi, quyết định API chính xác của adapter/Requests. `ServerGateway::tool_result()` hiện cũng làm mất lỗi an toàn; C-17 phải bao cả nó cùng OutputPipeline và Registry.

Lab hiện chạy năm container khoẻ của dự án này (giới hạn 2,75 GiB), còn khoảng 8 GiB RAM trống và 218 GiB đĩa. Có hai luật firewall cũ ngoài namespace được báo; không có sửa chữa toàn máy chủ nào được duyệt hoặc cần cho mạng lab này. Chạy lại preflight trước khi deploy/E2E.

## Sổ bằng chứng

2026-10-08: cây bắt đầu sạch tại `3c86e69`; nhánh tính năng `feat/content-media-mcp-2026-10-08`; chưa có thay đổi mã sản phẩm ở baseline. Composer hệ thống phát cảnh báo deprecation; các cổng của dự án vẫn đạt. Rà soát toàn mã, nghiệm thu runtime và review độc lập vẫn đang tiếp tục.

## Mốc tiến độ đã kiểm chứng — tiếp tục ngày 2026-10-08

- `composer check` local đạt trước các tinh chỉnh biên nhận/chính sách tệp cuối cùng: PHPCS/tương thích bootstrap, PHPStan mức 8 không baseline, 184 unit test, 50 478 khẳng định. Chạy lại sau các thay đổi mã cuối.
- Integration single-site WordPress 7.1.2 đạt 128 test/940 khẳng định với bốn ca bỏ qua có chủ đích dành cho multisite, trước các tinh chỉnh biên nhận cuối.
- HTTP N-01..35 gốc đạt 35/35, không còn ID nào.
- Ma trận HTTPS Content/Media thật đạt 54 khẳng định, đúng 31 tool, toàn bộ 26 annotation openWorldHint mới, từ chối chéo chủ/Contributor/khóa chỉ đọc, token xung đột UTC khớp, upload PNG thân thô ngoài `DIR_TESTDATA`, update/delete.
- Baseline trình duyệt đạt 3 ca; ca thứ tư về accessibility/dải cài đặt media đã được thêm và phải chạy trên mã nguồn cuối.
- Nguyên nhân gốc lỗi upload chỉ qua HTTP: thư mục `uploads` thuộc root với quyền 755 trên lab khiến Apache không ghi được. Chỉ sửa volume `uploads` của dự án này sang `www-data` và thêm bước chuẩn bị quyền sở hữu lúc khởi động có phạm vi. Mọi probe upload CLI và mã đo lường ngoại lệ production/fixture tạm thời đã được gỡ.
- Review độc lập phát hiện thiếu biên nhận mutation sau projection và kiểm tra đầu ra cuối. `MutationResponse` giữ ID do core quan sát; `MutationResult` vẫn nội bộ cho tới khi OutputPipeline kiểm/che/giới hạn đầu ra. Test nay bao ID phản hồi bị xóa, đầu ra render quá lớn, projection metadata, ngoại lệ projection media và lỗi đọc lại revision.
- Tên tệp hợp lệ có dấu cách và alias detector WAV chuẩn tắc đã được tái hiện bằng test đỏ và sửa; cả hai ca remote mục tiêu nay đạt.

Danh sách việc còn lại tại mốc đó (được thay thế ở nơi có bằng chứng mới): lượt cuối single/multisite 6.9/7.1.2 đầy đủ, toàn bộ ca CT/MD/SS mở rộng cùng fixture DNS/chuyển hướng/stream mã hóa có kiểm soát, lượt trình duyệt cuối, cấp chứng chỉ tin cậy công khai cho ứng dụng client HTTP thật, Plugin Check/build trên gói, đồng bộ EN/VI đầy đủ và review độc lập cuối cho ranh giới mutation đã sửa. Chưa thực hiện commit/push/xuất bản. Mục tiêu đầy đủ vẫn chưa hoàn thành.

## Mốc TLS native và client đã phát hành

Chủ dự án yêu cầu tự tạo chứng chỉ và xác nhận không có endpoint HTTPS công khai có kiểm soát. Một chứng chỉ test bảy ngày được tạo trong container `wp69` của dự án này, ngoài document root; khóa riêng không bao giờ được xuất. Chỉ chứng chỉ công khai được chuyển qua `rtl.py` vào thư mục tin cậy chỉ root đọc được của toolbox. Fixture lắng nghe cổng 443 trong container, không thêm ánh xạ cổng host. Bootstrap chỉ dành cho lab nhận diện TLS native của Apache tách biệt với header proxy. Chứng chỉ công khai của Hestia không bị thay.

Ứng dụng Inspector và FastMCP CLI đã phát hành đạt: liệt kê có scope với TLS xác minh, đúng danh tính chủ khóa, đọc tổng hợp được phép, ẩn tier cao hơn và từ chối sau thu hồi. FastMCP còn đạt tạo/sửa thật, từ chối chéo chủ, upload PNG, ảnh đại diện và xóa vĩnh viễn qua cùng route MCP sản phẩm. Đây là TLS native trong mạng lab remote, không phải tuyên bố tương thích GUI desktop hay client Hestia. Luồng ingress Hestia được kiểm chứng riêng bằng HTTP matrix thật. Bốn ca trình duyệt đạt, gồm dải cài đặt media và accessibility tự động; artifact nằm trong thư mục `remote-test20261008T111845Z`.

Một hồi quy review bắt buộc riêng đã tái hiện lỗi RELEASE_LOCK sau khi ghi thành công. Dọn dẹp nay giữ kết quả và đóng phần việc nặng tiếp theo; ba test ngân sách đạt. Cấu trúc phát hành loại `SUPPORT.md` sau khi Plugin Check báo tài liệu gốc không cần thiết. Mốc gói gần nhất trước khi loại trừ đó có 0 lỗi/22 cảnh báo hiển thị (21 phát hiện nền đã biết cộng `SUPPORT.md`). Kiểm tra artifact cuối phải chạy lại sau thay đổi mã. Nghiệm thu DNS/chuyển hướng/stream mã hóa/dọn dẹp có kiểm soát vẫn chưa xong; dùng fixture TLS thuộc sở hữu cho tầng mạng tất định tiếp theo.

## Mốc ngắt tải native và hồi quy — 2026-10-08

Fixture TLS đã gửi ngay 2048 byte đầu; ngoại lệ trong `request.progress` vẫn chỉ trả ra sau đuôi phản hồi trễ hai giây. Đã tái hiện bằng đo thời điểm callback. Nay `CURLOPT_XFERINFOFUNCTION` trả 1 khi vượt trần, `max_bytes` giới hạn byte lưu cùng mức. Giữ nguyên fixture ban đầu và ngưỡng 1,5 giây. Không dựa vào ngoại lệ PHP bị trì hoãn hoặc nhánh thử lại `CURLE_WRITE_ERROR`. Kiểm thử header có đối chứng dương để chứng minh fixture nhận biết Authorization/Cookie tổng hợp trước khi kiểm tra việc bỏ chúng ở production.

Bằng chứng trên mã hiện tại:

- `composer check`: 185 unit test/50 482 khẳng định; PHPCS, cú pháp bootstrap PHP 7.2 và PHPStan mức 8 đều đạt, không baseline.
- Integration: 147 test trên từng fixture WordPress 6.9/7.1.2; single-site 1 019 khẳng định với 5 ca bỏ qua có chủ đích dành cho multisite; multisite 1 045 khẳng định, không bỏ qua. Bao kiểm hết hạn ngạch trên cả hai đường upload.
- TLS/mạng thật trong lab: 6 test/56 khẳng định trên mỗi bản core, gồm ghim một hostname vốn không phân giải được, chuyển hướng thủ công, giữ gzip, dừng ngay khi quá byte, deadline lẻ và không chuyển tiếp credential.
- Orchestration tất định: DNS đổi sang địa chỉ riêng ở bước sau, chuyển hướng private/hạ cấp/userinfo/khác cổng 443/quá dài, vòng lặp có giới hạn, dọn dẹp trung gian, lỗi/ngoại lệ provider, phản hồi rỗng/status/mã hóa và dọn tệp sau thành công rõ ràng. Thông điệp/dữ liệu provider vẫn được che.
- Hồi quy HTTP N-01..35: 35 ca đạt. FastMCP đã phát hành chạy lại đạt tạo/sửa, từ chối chéo chủ kèm đối chiếu, upload PNG, ảnh đại diện và xóa qua TLS container thuộc sở hữu đã xác minh.
- Review độc lập chỉ đọc không phát hiện blocker cụ thể mới ở ranh giới transport/ngân sách đã sửa; review không nghiệm thu toàn bộ ma trận SSRF hay cột mốc.

Bộ kiểm được tách có chủ đích: chính sách URL công khai không bao giờ bị nới để nhận IP test riêng. Test transport native dùng target nội bộ đã kiểm trước; test policy/orchestration tiêm DNS và transport tất định. Đây không phải bằng chứng cho mọi ca CT/MD/SS đã nêu hay fixture DNS công khai đổi IP thật. Còn mở: kiểm runtime PHP 8.3/8.4 (U-FETCH-4), ánh xạ đủ các ca nghiệm thu, bằng chứng C-01..18/L-01..20, đồng bộ EN/VI và review cột mốc cuối. Bằng chứng đóng gói và HTTPS tools/list được ghi sau các kiểm tra riêng. Không kèm tuyên bố hosted CI, production, commit, push hay phát hành.

## Kiểm tra nội dung bổ sung và đối chiếu tài liệu

Hai hồi quy RED/GREEN tiếp theo đã đóng lỗi filter truy vấn core mở rộng search sang bản nháp (kiểm lại ID/type/publish thật trước projection) và lỗi yêu cầu raw sai kiểu (not_found trước quyền raw của đối tượng). Dữ liệu cha attachment bị thiếu nay được bỏ qua; chỉ null hiện diện của core mới chuẩn hóa thành 0. Review độc lập chỉ đọc không phát hiện blocker cụ thể trong các sửa này.

Integration trên mã sản phẩm mới nhất đạt 149 test trên cả bốn tổ hợp phiên bản/site: 1 023 khẳng định cộng 5 ca bỏ qua có chủ đích ở single-site; 1 049 khẳng định, không bỏ qua trên multisite. `composer check` đạt 186 unit test và 50 485 khẳng định. `check:docs` nay so 30 khóa/mặc định EN/VI với hằng PHP thật; cố ý làm lệch mặc định bị từ chối và khôi phục thì đạt. Không còn đụng tên tài liệu do hoa/thường. Đối xứng tệp EN/VI chưa hoàn tất: phiếu M2 tiếng Việt cũ vẫn vắng theo quyết định của chủ dự án, phiếu yêu cầu tiếng Việt đang hiệu lực vẫn cần bản tiếng Anh, và phiếu Comments tiếng Việt mới nằm ngoài tác vụ này nên được giữ nguyên.

Ma trận HTTPS mới nhất mỗi bản đạt 54 kiểm tra/31 tool và 50 722 byte descriptor. WP 6.9 có p50/p95 57,826168/60,494067 ms; WP 7.1.2 là 61,285566/63,619065 ms. Đây là phép đo toàn request, vượt 50 ms; chúng không tách riêng chi phí chính sách. Harness kiểm gói nay chỉ xóa bản giải nén plugin cũ dùng một lần của nó trước khi giải nén: cách ghi đè trước đây giữ lại `SUPPORT.md` bị loại từ một lượt cũ. Giải nén sạch trả 0 lỗi/21 cảnh báo nền đã biết. Hash artifact phụ thuộc từng checkpoint và phải đọc từ bản build hiện tại.
