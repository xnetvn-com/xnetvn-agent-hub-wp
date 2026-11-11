# ADR-0014: Bounded media and remote fetching

- Trạng thái: Đã chấp thuận
- Ngày: 2026-10-08
- Thẩm quyền: chủ dự án duyệt rõ ràng E-01 đến E-08 và cho phép triển khai ngay.

## Quyết định

Xem bản tiếng Anh cùng đường dẫn để biết hợp đồng kỹ thuật chuẩn. Áp dụng phạm vi đã duyệt, giữ nguyên cổng rà soát bảo mật độc lập trước phát hành.

## Kiểm chứng

Theo kế hoạch triển khai Content/Media; chấp thuận thiết kế không đồng nghĩa nghiệm thu chạy thật.

## Quyết định sau review bảo mật: transport riêng cho từng request

Loại bỏ cách dùng wp_safe_remote_get với hook HTTP toàn cục vì hook có thể đổi tên tệp, URL hoặc CONNECT_TO trước khi chứng minh ghim IP. Dùng Requests đi kèm core, chọn Curl và bộ Hooks riêng. Ghim trước curl_exec; đặt lại URL/CONNECT_TO, tắt proxy và giải nén, giữ xác minh CA/TLS và trần byte. Không đăng ký hook HTTP toàn cục. Vẫn áp dụng chính sách URL/DNS độc lập và wp_http_validate_url. Đây là thay đổi cơ chế thực thi, không mở rộng quyền tool.

## Quyết định về dọn ngân sách

Lỗi storage khi nhả khóa không được thay thế kết quả công việc đã hoàn tất. Khi chưa xác nhận nhả, giữ quyền sở hữu trong tiến trình và từ chối tác vụ nặng tiếp theo đến cuối request. Registry giữ lớp bao biên nhận đến khi ngân sách trả về rồi mới kiểm đầu ra. Không thêm hook công khai; không hiểu storage không khả dụng thành slot trống.

## Điều tra ngày 2026-11-06: tương đương với WordPress HTTP API cấp cao

- Trạng thái: điều tra được ghi nối vào quyết định đã chấp thuận; không thay thế quyết định ngày 2026-10-08.
- Câu hỏi: có thể thay downloader riêng cho từng request bằng WordPress HTTP API cấp cao mà không làm yếu H-01 đến H-14 không?
- Kết luận: **KHÔNG TƯƠNG ĐƯƠNG** với các phiên bản tương thích đã kiểm và các hợp đồng hiện tại. Transport riêng cho từng request vẫn là cơ chế được chấp thuận.
- Thẩm quyền: quan sát mã nguồn, suy luận và thuộc tính runtime chưa kiểm được ghi nhãn riêng. Không chạy test runtime, lab, hosted CI, client thật hoặc nghiệm thu production cho điều tra này.
- Ảnh chụp mã nguồn dự án: `aaf3e4d83e2786c3af4016aa99de5e2f19413215`.
- Ngày kiểm: 2026-11-06.

### Nguồn và phiên bản

| Bề mặt | WordPress 6.9 | WordPress 7.1.2 |
|---|---|---|
| Hàm cấp cao | `wp-includes/http.php:81-85` | `wp-includes/http.php:81-85` |
| Vòng đời HTTP request | `wp-includes/class-wp-http.php:169-468` | `wp-includes/class-wp-http.php:169-469` |
| Kiểm URL lõi | `wp-includes/http.php:563-653` | `wp-includes/http.php:559-670` |
| Cầu nối hook Requests | `wp-includes/class-wp-http-requests-hooks.php:51-77` | `wp-includes/class-wp-http-requests-hooks.php:51-77` |
| Requests đi kèm | 2.0.11 | 2.0.17 |
| Điều phối Requests | `wp-includes/Requests/src/Requests.php:451-469,656-689` | cùng dòng |
| Transport cURL | `wp-includes/Requests/src/Transport/Curl.php:171-217,362-460,533-559` | `:172-218,366-464,537-563` |
| Nguồn lõi tham chiếu | `https://core.svn.wordpress.org/tags/6.9/wp-includes/` | `https://core.svn.wordpress.org/tags/7.1.2/wp-includes/` |

Hai tag 6.9 và 7.1.2 là fixture tương thích của dự án, không phải tuyên bố 7.1.2 là bản phát hành hiện tại. WordPress 7.1.4 và 6.9.11 là bản hiện hành tại ngày kiểm nhưng không được kiểm cho quyết định này; kết luận chỉ giới hạn trong hai tag đã ghim ở trên.

### Vòng đời request quan sát được

Trình tự giống nhau ở hai phiên bản đã kiểm. Số dòng `class-wp-http.php` không ghi chú là vị trí 6.9; từ dòng 302 trở đi tệp 7.1.2 lệch một dòng (ví dụ hook `:344`/`:345`, đăng ký redirect `:352`/`:353`, proxy `:400`/`:401`, `Requests::request()` `:415`/`:416`, hook response `:440`/`:441` và `:467`/`:468`). Độ lệch cURL nằm trong bảng nguồn.

1. `wp_safe_remote_get()` đặt `reject_unsafe_urls=true` rồi giao cho `WP_Http::request()` (`http.php:81-85`). Hàm này không cài ghim socket, không chọn transport và không tạo bộ hook riêng cho request.
2. `WP_Http::request()` phân tích mặc định, sau đó chạy `http_request_args` (`class-wp-http.php:252`) trước mọi short-circuit, kiểm URL hoặc chọn transport. Filter này nhận mảng tham số; URL là tham số riêng truyền theo giá trị và không bị filter này đổi.
3. Hàm chạy `pre_http_request` (`:277`). Kết quả khác false trả về mà không truyền dữ liệu; filter này có thể short-circuit nhưng không sửa URL.
4. Nếu `reject_unsafe_urls` là true, hàm gọi `wp_http_validate_url()` (`:284-285`). Hàm này phân giải một địa chỉ IPv4 bằng `gethostbyname()` và áp dụng kiểm địa chỉ cục bộ riêng; không liệt kê toàn bộ câu trả lời A/AAAA và không có đường IPv6 (`http.php:563-653` ở 6.9; `:559-670` ở 7.1.2).
5. Hàm áp dụng chính sách chặn site (`:301`) và chọn tên tệp streaming (`:310-323`).
6. Hàm tự tạo `WP_HTTP_Requests_Hooks` (`:340-345`) và đăng ký kiểm redirect (`:351-353`), tên tệp, số redirect, `max_bytes`, cookie, xác minh TLS, filter `https_ssl_verify` và cấu hình proxy (`:355-409`).
7. Hàm gọi `WpOrg\Requests\Requests::request()` (`:415`).
8. `Requests::request()` gộp mặc định và gọi `set_defaults()`, nơi đăng ký handler auth, proxy và cookie trên bộ hook (`Requests.php:451-469,656-689`), rồi dispatch `requests.before_request` (`Requests.php:455`) và chọn transport. Hook này nhận URL và `$options` theo tham chiếu và được nối thành action toàn cục `requests-requests.before_request`; đây là bề mặt đổi URL, không phải `http_request_args`. Callback toàn cục cũng có thể đặt `$options['transport']` hoặc thay `$options['hooks']` trước khi chọn transport; quyền đó là toàn cục, không theo phạm vi request và không độc quyền.
9. `Curl::request()` gọi `setup_handle()` (`Curl.php:171` ở 6.9; `:172` ở 7.1.2), sau đó dispatch `curl.before_send` (`:173-174`; `:174-175`). Cầu nối WordPress chạy callback cha trước, rồi `http_api_curl`, rồi action chung `requests-curl.before_send` (`class-wp-http-requests-hooks.php:51-77`).
10. Chỉ sau hook đó `Curl` mới mở tệp đích (`Curl.php:175-182`; `:176-183`), khởi tạo `max_bytes` (`:184-189`; `:185-190`), áp dụng TLS `verify`/`verifyname` (`:191-202`; `:192-203`) và gọi `curl_exec()` (`:204`; `:205`).
11. Transport dispatch `curl.after_send` (`:207`; `:208`), thử lại một lần khi `CURLE_WRITE_ERROR` hoặc `CURLE_BAD_CONTENT_ENCODING` (`:209-217`; `:210-218`), rồi xử lý response.
12. `Curl::__construct()` đặt `CURLOPT_ENCODING` thành chuỗi rỗng (`Curl.php:102-122`, dòng 110, ở cả hai tag), tức bật giải nén response của cURL. Tham số `decompress` của `WP_Http` không được chuyển tiếp cho Requests trong mã nguồn đã kiểm.
13. `Curl::stream_body()` dispatch `request.progress` nhưng bỏ qua giá trị trả về, cắt dữ liệu lưu tại `max_bytes`, và trả về độ dài chunk gốc (`:533-559`; `:537-563`). Cắt bớt không phải là hủy truyền sớm.
14. Sau khi `Requests::request()` trả về, `WP_Http::request()` bắn action toàn cục `http_api_debug` (`class-wp-http.php:440` ở 6.9; `:441` ở 7.1.2) và trả response qua filter toàn cục `http_response` (`:467`; `:468`). Cả hai là writer phía response liên quan H-10 và H-12.

Transport riêng cho từng request hiện tại tránh vòng đời này: nó tự tạo `WpOrg\Requests\Hooks`, đăng ký `curl.before_send` trực tiếp, chọn rõ `WpOrg\Requests\Transport\Curl`, ghim `CURLOPT_RESOLVE` trước `curl_exec`, tắt proxy/auth/giải nén, và không đăng ký callback HTTP toàn cục nào của WordPress (`src/Support/Http/RequestsDownloadTransport.php:59-140`).

### So sánh phương án

| Phương án | Việc sẽ làm | Vì sao chưa giải quyết hợp đồng |
|---|---|---|
| A. Gọi thẳng `wp_safe_remote_get()` với guard toàn cục | Dùng hàm công khai và thêm filter `http_request_args`, `https_ssl_verify`, `http_api_curl` hoặc `pre_http_request` | `http_request_args` đã chạy trước khi hàm có thể khẳng định lại; `requests-requests.before_request` vẫn có thể đổi URL; callback toàn cục có thể đặt `$options['transport']` hoặc thay `$options['hooks']`, nhưng quyền đó là toàn cục và không theo phạm vi request; callback sau và cầu nối chung vẫn có thể đổi request; test zero-global-filter sẽ thất bại |
| B. Cầu nối cấp cao có phạm vi | Dùng `WP_Http` cộng ghim `http_api_curl` muộn và filter muộn | Cầu nối không chứng minh được quyền kiểm soát cuối cùng, không gắn được request với URL trùng lặp lồng nhau, chỉ thay được bộ hook ở phạm vi toàn cục mà không có danh tính request, và không ngăn được thay đổi `http_request_args` hoặc `requests-requests.before_request` trước đó; `http_api_curl` bị `requests-curl.before_send` chạy sau |
| C. Giữ transport riêng cho từng request | Giữ `RequestsDownloadTransport` và `SafeRemoteFetcher` đã chấp thuận | Giữ H-01 đến H-14 nhưng không đáp ứng yêu cầu chuyển sang API cấp cao; đây là baseline được chấp thuận, không phải migration |

Phương án A đơn giản nhất nhưng thất bại hợp đồng an toàn thực chất. Phương án B là cầu nối khả dĩ duy nhất, nhưng thất bại yêu cầu pre-send và sở hữu hook trong các phiên bản đã kiểm. Phương án C không phải migration.

### Ma trận bất biến

| ID | Thuộc tính bắt buộc | Cơ chế hiện tại | Kết quả của phương án cấp cao | Kết luận và bằng chứng | Hành vi khi lỗi và kiểm dự kiến |
|---|---|---|---|---|---|
| H-01 | Chặn site trước DNS và mỗi redirect; mở rộng chỉ theo hướng từ chối; kiểm lõi không mở rộng chính sách độc lập | `SafeRemoteFetcher` gọi `SiteHttpPolicy::allows()` trước `RemoteUrlPolicy::resolve()` và trước mỗi hop; `xnetvn_agent_hub_wp_remote_url_allowed` chỉ từ chối (`SafeRemoteFetcher.php:66-80`) | Chính sách chặn lõi chạy sau `http_request_args`; có thể gọi chính sách của ta trước hàm, nhưng `requests-requests.before_request` có thể đổi URL sau đó và `http_request_args` có thể đổi redirect cùng giá trị tùy chọn; `wp_http_validate_url()` của lõi không phải chính sách chỉ-từ-chối | **KHÔNG GIỮ ĐƯỢC.** Có thể áp chính sách độc lập, nhưng không có quyền kiểm soát cuối cùng trước truyền. | Từ chối trước DNS; unit test với chính sách site giả và vòng redirect. |
| H-02 | Chỉ tên DNS, HTTPS:443, không userinfo/local/own host, mọi câu trả lời A/AAAA công khai, không rỗng và có giới hạn, IDN chỉ khi hỗ trợ | `RemoteUrlPolicy::resolve()` kiểm scheme, cổng, userinfo, cú pháp host, hậu tố cục bộ, own host và mọi câu trả lời qua `PublicAddressGuard` (`RemoteUrlPolicy.php:29-68`) | `wp_http_validate_url()` phân giải một IPv4 bằng `gethostbyname()` và không có đường all-answer hoặc IPv6 (`http.php:563-653`/`:559-670`) | **KHÔNG GIỮ ĐƯỢC.** Kiểm lõi yếu hơn chính sách all-answer độc lập. | Từ chối host hoặc câu trả lời không hợp lệ; unit test A/AAAA hỗn hợp, IPv6, userinfo và cổng. |
| H-03 | Socket dùng IP đã kiểm trước khi truyền; hostname vẫn cho SNI/chứng chỉ; kiểm sau khi gửi là không đủ | `curl.before_send` đặt `CURLOPT_RESOLVE` trước `curl_exec` (`RequestsDownloadTransport.php:68-103`) | Không có tham số công khai `WP_Http` cho `CURLOPT_RESOLVE`; `http_api_curl` có thể đặt, nhưng action chung `requests-curl.before_send` chạy sau và transport có thể không phải cURL | **KHÔNG GIỮ ĐƯỢC.** Không bảo đảm ghim pre-send qua đường công khai. | Fail-closed khi thiếu ghim; network test với fixture TLS có kiểm soát. |
| H-04 | Năng lực transport rõ ràng và fail-closed; không tự chuyển sang transport không ghim | `available()` yêu cầu cURL, các hằng số cần thiết, `WpOrg\Requests\Transport\Curl`, `Hooks` và CA đọc được; `Requests::request()` nhận transport Curl rõ ràng (`RequestsDownloadTransport.php:47-49,106-126`) | `WP_Http` không chuyển tiếp `transport` của caller; callback toàn cục `requests-requests.before_request` có thể đặt `$options['transport']`, nhưng quyền đó là toàn cục và không theo phạm vi request; `http_api_curl` chỉ chạy với cURL | **KHÔNG GIỮ ĐƯỢC.** Có thể chọn transport không phải cURL mà không có điều khiển fail-closed theo phạm vi request. | Trả `remote_fetch_disabled`; unit test thiếu cURL, hằng số hoặc CA. |
| H-05 | Không proxy, CONNECT_TO override, HTTP auth, bearer/cookie đến, credential môi trường hoặc tùy chọn TLS yếu đến connection | Transport đặt proxy rỗng, `NOPROXY=*`, `HTTPAUTH=NONE`, không cookie, `SSL_VERIFYPEER=true`, `SSL_VERIFYHOST=2`, CA rõ ràng (`RequestsDownloadTransport.php:73-99`) | `WP_Http` có thể thêm `WP_HTTP_Proxy` (kể cả proxy auth), cookie và filter `https_ssl_verify`; `headers` bị filter hoặc tùy chọn cURL qua `http_api_curl` có thể thêm credential; `http_request_args` có thể đổi `headers`, `cookies`, `filename`, `redirection` và `sslverify` (`class-wp-http.php:252,327-337,355-409`) | **KHÔNG GIỮ ĐƯỢC.** Writer toàn cục có thể đưa lại credential, proxy hoặc TLS yếu. | Từ chối hoặc loại proxy, auth, cookie và TLS yếu; integration test với filter tạm. |
| H-06 | Redirect hiện cho `SafeRemoteFetcher`; chính sách đầy đủ mỗi hop; deadline tổng ban đầu | `follow_redirects=false`; vòng lặp thủ công với một deadline và chính sách đầy đủ mỗi hop (`SafeRemoteFetcher.php:64-103`; `RequestsDownloadTransport.php:118`) | Có thể đặt `redirection=0` và tự theo dõi, nhưng `http_request_args` có thể đổi số redirect và `requests-requests.before_request` có thể đổi URL; kiểm redirect lõi không phải chính sách độc lập mỗi hop | **MỘT PHẦN.** Wrapper giữ được redirect thủ công, nhưng không kiểm soát độc quyền tham số request cấp cao. | Từ chối hop tiếp theo khi chính sách lỗi; integration test chuỗi redirect và deadline tổng. |
| H-07 | Chỉ stream vào tệp tạm do lõi tạo và thuộc sở hữu; hook không ghi được tên tệp tùy ý; từ chối biến đổi trước khi mở tệp hoặc kết nối | `wp_tempnam()` tạo tệp; transport riêng nhận đúng đường dẫn; hook ghim riêng chạy trước `fopen` (`SafeRemoteFetcher.php:84-89`; `Curl.php:173-182`) | `WP_Http` nhận `filename` qua `http_request_args`; filter có thể đổi trước khi gọi; `http_api_curl` chạy trước `fopen`, nhưng action toàn cục và writer tham số sau không bị gỡ | **KHÔNG GIỮ ĐƯỢC.** Tên tệp có thể bị đổi trước khi request cấp cao tới `fopen`. | Xoá tệp tạm và từ chối trước khi mở; integration test filter đổi tên tệp. |
| H-08 | Giới hạn byte lưu và hủy truyền khi vượt; phân biệt đúng giới hạn với cắt bớt; không tự gửi lại sau hủy có chủ đích | `DownloadBudget` trả 1 từ callback tiến trình native; `max_bytes` cũng được đặt; mã hiện tại ánh xạ hủy thành `payload_too_large` (`RequestsDownloadTransport.php:92-99,136-138`; `DownloadBudget.php:33-44`) | `limit_response_size` thành `max_bytes`; `stream_body()` cắt và trả độ dài chunk gốc, còn giá trị trả của `request.progress` bị bỏ qua (`Curl.php:533-559`/`:537-563`); transport có thể thử lại khi lỗi ghi | **KHÔNG GIỮ ĐƯỢC.** Lưu trữ có giới hạn, nhưng hủy sớm và không-gửi-lại không được bảo đảm. | Trả `payload_too_large`; network test đúng giới hạn, vượt và đuôi chậm. |
| H-09 | Không giải nén body từ xa; từ chối response encoded; HTTP 200 không rỗng; `DownloadReply` an toàn | `Accept-Encoding: identity`, `CURLOPT_HTTP_CONTENT_DECODING=false`, và kiểm `content-encoding` rõ ràng (`RequestsDownloadTransport.php:87-90,131-134`) | `Curl::__construct()` đặt `CURLOPT_ENCODING` rỗng, bật giải nén (`Curl.php:102-122`, dòng 110); tham số `decompress` của `WP_Http` không được chuyển tiếp trong mã đã kiểm | **KHÔNG GIỮ ĐƯỢC.** Đường cấp cao có thể giải nén trước khi wrapper thấy body. | Trả `remote_fetch_failed`; network test body gzip, encoded và rỗng. |
| H-10 | Hook tham số, proxy, TLS, Requests, short-circuit và response toàn cục không vượt được biên đã duyệt; xác định mọi writer sau | Không đăng ký callback HTTP toàn cục; bộ `Hooks` riêng cho từng request (`RequestsDownloadTransport.php:65-104`) | `http_request_args`, `pre_http_request`, `https_ssl_verify`, `http_api_curl`, `requests-requests.before_request` (kể cả `$options['transport']` và `$options['hooks']`), `requests-curl.before_send`, `http_api_debug` và `http_response` đều chạy; `http_api_curl` bị `requests-curl.before_send` chạy sau (`class-wp-http-requests-hooks.php:51-77`; `class-wp-http.php:440,467`/`:441,468`) | **KHÔNG GIỮ ĐƯỢC.** Đường cấp cao được định nghĩa bởi các extension point toàn cục. | Từ chối trước truyền; integration test ma trận hook tham số, TLS, cURL và response. |
| H-11 | Gắn request sống qua lời gọi lồng nhau, kể cả URL trùng; chỉ gỡ callback sở hữu trong `finally`; giữ callback không liên quan | Closure giữ target đã kiểm và đối tượng `Hooks` riêng; không dùng khớp URL hay boolean chung (`RequestsDownloadTransport.php:65-104`) | Hook toàn cục không theo phạm vi request; khớp URL không phân biệt URL trùng lồng nhau; boolean chung là không đủ | **KHÔNG GIỮ ĐƯỢC.** API hook toàn cục không có danh tính request. | Chỉ gỡ callback sở hữu; integration test request lồng nhau cùng URL. |
| H-12 | Mọi lỗi dọn tệp trung gian và giữ từ vựng lý do công khai; không lộ URL/query/địa chỉ/đường dẫn/header/exception/payload | `SafeRemoteFetcher` dùng `finally` xoá tệp chưa chuyển; `FetchedFile` sở hữu cleanup; `ReasonMapper` làm sạch lý do (`SafeRemoteFetcher.php:84-113`; `FetchedFile.php:36-43`) | Wrapper có thể dọn tệp tạm của mình và ánh xạ lý do, nhưng không bảo đảm không có tệp trung gian hoặc text exception từ lõi/hook writer | **MỘT PHẦN.** Dọn dẹp và ánh xạ lý do của wrapper là khả thi; tương đương biên đầy đủ chưa được chứng minh. | Xoá tệp chưa chuyển; unit/integration test từ vựng lỗi và dọn dẹp. |
| H-13 | Nêu biên tin cậy đạt được; không tuyên bố miễn nhiễm với PHP cùng quyền tùy ý hoặc runtime WordPress bị xâm phạm | Quyết định đã chấp thuận nêu hook toàn cục có thể thay đổi là lý do dùng transport riêng (`ADR-0014:15-17`) | Đường cấp cao có nhiều extension point được ghi hơn; PHP cùng quyền vẫn ngoài mọi biên plugin | **ĐÃ GHI NHẬN.** Không plugin nào được tuyên bố miễn nhiễm với PHP cùng quyền tùy ý hoặc runtime WordPress bị xâm phạm. | Ghi giới hạn tin cậy; rà mô hình mối đe dọa với PHP cùng quyền. |
| H-14 | Giữ test không có filter HTTP toàn cục và quyết định ADR, hoặc có uỷ quyền rõ cho thay đổi tích hợp có chủ đích | `RemoteFetchTest::test_transport_is_private_from_global_http_filters()` khẳng định 0 lần gọi `http_request_args` (`tests/Integration/RemoteFetchTest.php:200-216`); transport riêng đáp ứng | Mọi lời gọi `WP_Http::request()` chạy `http_request_args` tại `class-wp-http.php:252`; khẳng định 0 lần gọi không thể sống sót với lời gọi cấp cao nếu không đổi hợp đồng test | **KHÔNG TƯƠNG THÍCH.** Migration cấp cao cần thay đổi hành vi tích hợp được uỷ quyền rõ; điều tra này không cấp quyền đó. | Integration test thất bại khi filter toàn cục chạy; thay đổi có chủ đích cần uỷ quyền rõ. |

### Quyết định

**KHÔNG TƯƠNG ĐƯƠNG.** Với WordPress 6.9 dùng Requests 2.0.11 và WordPress 7.1.2 dùng Requests 2.0.17, đường cấp cao công khai không giữ được H-01, H-02, H-03, H-04, H-05, H-07, H-08, H-09, H-10, H-11 và H-14. H-06 và H-12 chỉ phục hồi một phần; cả hai đều không khôi phục toàn bộ biên. Các khiếm khuyết chặn là ghim IP trước khi gửi, chọn transport theo phạm vi request và độc quyền, không giải nén, hủy sớm, danh tính request, kiểm soát hook toàn cục và hợp đồng không filter toàn cục.

Đây là kết quả phủ định, không phải báo cáo lỗ hổng. Nó không chứng minh mọi cầu nối tưởng tượng đều bất khả thi; nó chứng minh rằng không thiết kế nào khả dụng qua API công khai và hợp đồng hiện tại đã kiểm đáp ứng ma trận bất biến. Transport riêng cho từng request đã chấp thuận vẫn có hiệu lực. Phiếu phụ thuộc `REFACTOR_v1.0_2026-11-06_01_preserve-remote-fetch-safety-through-the-wordpress-http-api` không được triển khai từ kết quả này; reviewer được uỷ quyền nên hoãn hoặc huỷ phiếu đó.

### Tập ghi dự kiến và ma trận kiểm

Điều tra này chỉ thay đổi hai tài liệu ADR. Nếu một thiết kế được uỷ quyền trong tương lai được đề xuất, các kiểm dưới đây là bằng chứng tối thiểu; mọi mục là **dự kiến, chưa chạy** trong điều tra này.

| Kiểm | Bất biến | Môi trường |
|---|---|---|
| Chính sách site trước DNS và mỗi redirect thủ công; kết quả filter chỉ-từ-chối | H-01 | Unit với `SiteHttpPolicy` giả và resolver giả |
| Câu trả lời IPv4/IPv6 hỗn hợp, userinfo, cổng khác 443, hậu tố cục bộ, own host | H-02 | Unit với `DnsResolver` giả |
| Ghim pre-send với fixture TLS có kiểm soát; không phân giải DNS mới | H-03 | Network trên fixture lab sở hữu |
| Thiếu cURL, transport rõ ràng không khả dụng, CA không đọc được | H-04 | Unit cộng kiểm khả dụng runtime có kiểm soát |
| Nỗ lực đổi proxy, auth, cookie và `https_ssl_verify` | H-05 | Integration/network với filter tạm |
| Chuỗi redirect với câu trả lời DNS mới mỗi hop và một deadline tổng | H-06 | Integration với fixture sở hữu |
| Đổi tên tệp trước khi mở; hook cố đổi đích | H-07 | Integration với filter tạm |
| Đúng giới hạn byte, vượt với đuôi chậm, không gửi lại sau hủy | H-08 | Network với fixture sở hữu |
| Từ chối body gzip/encoded và yêu cầu HTTP 200 không rỗng | H-09 | Network với fixture sở hữu |
| Ma trận filter toàn cục: tham số, pre-request, TLS, cURL và response | H-10 | Integration |
| Request lồng nhau cùng URL và quyền sở hữu callback | H-11 | Integration |
| Lỗi, timeout, redirect, dọn dẹp và từ vựng lý do | H-12 | Integration/unit |
| Rà mô hình mối đe dọa cho extension point được ghi và PHP cùng quyền | H-13 | Rà tài liệu |
| Regression zero-global-filter và thẩm quyền ADR | H-14 | Integration cộng rà độc lập |

Migration cuối cùng cũng cần tập ghi riêng do planner duyệt, rollback khôi phục transport riêng và rà bảo mật T3 độc lập. Không tập ghi nào như vậy được duyệt ở đây.

### Rollback và bất định còn lại

Rollback của điều tra này là gỡ chỉ các mục điều tra được ghi nối khỏi hai tệp ADR; quyết định đã chấp thuận ngày 2026-10-08 và mọi nội dung khác giữ nguyên.

Bất định còn lại: điều tra là rà mã nguồn, không phải chiến dịch runtime. Nó không tuyên bố một bản WordPress tương lai không thể có cầu nối an toàn; nó ghi rằng các bề mặt công khai 6.9/7.1.2 đã kiểm thì không có. WordPress 7.1.4 và 6.9.11 hiện hành chưa được kiểm. Hook WordPress và hành vi Requests đi kèm có thể đổi giữa các bản phát hành, nên đề xuất tương lai phải lặp lại bản đồ nguồn và ma trận kiểm ở đúng phiên bản ghim khi đó.
