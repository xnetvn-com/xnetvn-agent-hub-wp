# Checkpoint hiệu năng và cache dùng chung

Đo ngày 2026-10-05 trên lab kiểm thử có phạm vi: WordPress 6.9, PHP 8.2, MCP Adapter chính thức 0.7.0, Redis 8.10.2 đã ghim và Redis Object Cache 3.0.0 dùng Predis. Đây là bằng chứng cột mốc, không phải nghiệm thu phát hành hay production. [Artifact số liệu](../../en/reports/M1-60-tools.json) công khai không chứa địa chỉ host, thông tin xác thực, giá trị đối số hoặc body phản hồi.

## Phương pháp và phép đo

Năm descriptor hệ thống thực tế, một fixture read có kiểm soát và 54 descriptor read riêng tư tổng hợp tạo đúng 60 công cụ hiển thị. Schema tổng hợp không đại diện độ phức tạp pack nghiệp vụ cuối. Mỗi trường hợp hợp lệ/không biết/sai bỏ mười yêu cầu làm nóng, rồi ghi 100 phản hồi HTTPS thực tế đã xác thực. Các lớp thất bại được xen kẽ. Giai đoạn mười worker riêng ghi mười yêu cầu mỗi worker. Không fixture hoặc benchmark khác chạy đồng thời với phép đo cuối. Service host dùng chung và kiểm tra sức khỏe thông thường vẫn hoạt động.

Client kiểm thử giữ cache kết nối/DNS và đặt lại mọi option yêu cầu, gồm Authorization, Cookie, control và body, trong `finally`. Không bật cookie engine hoặc cookie jar. Chi phí kết nối/DNS/TLS lạnh do đó nằm ngoài số liệu đã làm nóng bên dưới; artifact giữ thời gian tầng truyền tải và số kết nối. Phân vị dùng thứ hạng gần nhất. Thiếu đo lường trường hợp hợp lệ làm mẫu bị từ chối. Metric chính sách khi xác thực thất bại được bỏ vì giai đoạn đó không chạy; không ghi chúng bằng không.

| Phép đo | p50 | p95 | p99 |
|---|---:|---:|---:|
| Các giai đoạn hook xác thực | 3.771 ms | 3.948 ms | 4.016 ms |
| Lọc chính sách 60 công cụ | 3.714 ms | 3.930 ms | 4.007 ms |
| Xác thực và chính sách kết hợp | 7.494 ms | 7.763 ms | 7.831 ms |
| Điều phối REST đến lọc phản hồi | 12.600 ms | 12.956 ms | 13.165 ms |
| Yêu cầu HTTPS đầy đủ đã làm nóng | 55.868 ms | 57.826 ms | 61.506 ms |
| Yêu cầu HTTPS đầy đủ với mười worker đồng thời | 1101.586 ms | 1796.371 ms | 2146.004 ms |

Container WordPress giới hạn một CPU/512 MiB; Redis nửa CPU/256 MiB, với `maxmemory` 128 MiB và `noeviction`. Kết quả worker là giới hạn bão hòa đã đo, không phải tuyên bố năng lực production. Toàn bộ 430 yêu cầu benchmark hoàn tất với status và dạng kết quả dự kiến; gồm 30 lượt làm nóng, 300 mẫu tuần tự và 100 mẫu tải.

Cận bộ nhớ bảo thủ là 5,509,680 byte (5.254 MiB): bộ nhớ PHP đỉnh trừ mức nền MU-plugin. Nó gồm plugin thông thường, adapter, điều phối lõi và fixture tổng hợp sau mức nền; không phải phép đo cô lập riêng Agent Hub. Khi tải mười worker, tối đa quan sát được là 5,509,848 byte. JSON danh sách logic đã giải mã là 33,191 byte, không phải phép đo trên đường truyền/nén hoặc token LLM chính xác.

## Mục tiêu và cải thiện đã đo

Xác thực và chính sách riêng lẻ có trung vị đã làm nóng dưới 5 ms, và phần tăng bộ nhớ bảo thủ dưới 8 MiB. Trung vị kết hợp vẫn trên 5 ms. Điều phối 60 công cụ dưới 50 ms; HTTPS đầy đủ vẫn trên mục tiêu 55 ms đã sửa tại checkpoint này, nên mục tiêu yêu cầu đầy đủ nghiêm ngặt hơn **chưa đạt** ở checkpoint này. Độ trễ tải cũng lớn trên container cố ý giới hạn. Cần phân tích thêm với pack nghiệp vụ thực tế trước khi chấp nhận ngân sách phát hành.

Mục tiêu yêu cầu đầy đủ là `< 55 ms` từ 2026-11-09, khi chủ dự án cho phép hạ riêng yêu cầu đó từ `< 50 ms` (nguyên văn: "Bạn quyết STAB-03: hạ ngưỡng"). Đây là thay đổi yêu cầu, không phải cải thiện hiệu năng đo được: phương pháp đo, số mẫu và năm cổng còn lại không đổi, và mọi phép đo trước đó giữ phán quyết `historical, <50 ms>`. Các vòng 2026-10-22 đến 2026-11-07 đo 52,585–53,465 ms và nằm trong ngân sách mới; checkpoint 2026-10-05 ở trên thì không.

So sánh tương đương với kết nối mới giảm trung vị chính sách 60 công cụ từ 5.128 xuống 3.731 ms bằng cách memoize chỉ phép xác thực thuần của giá trị option giống hệt. Mỗi lượt đọc vẫn gọi `get_option()` cho site hiện tại. Giá trị đổi/hỏng được kiểm tra lại; capability hiện tại và quyết định mở rộng vẫn được đánh giá trực tiếp. Rà soát chỉ nguồn và kiểm thử tính mới/hỏng bao phủ ranh giới đó. So sánh tái sử dụng kết nối về sau đổi hành vi client và không được quy cho tối ưu production.

Trung vị xác thực khóa không biết là 2.668 ms và bí mật sai là 2.705 ms, tỷ lệ 1.014 giữa các mẫu xen kẽ. Cả hai phản hồi vẫn là 401 với cùng body chung. Điều này hỗ trợ N-03/N-04 cho cấu hình này; không phải bằng chứng thời gian hằng số phổ quát hoặc kênh bên.

## Bằng chứng lưu trữ chung và sự cố

Mười tiến trình WordPress độc lập mỗi tiến trình tăng bucket chung hai mươi lần: dự kiến 200, quan sát 200. Site WordPress 7.1.2 riêng giữ đối chứng dương do tiến trình khác ghi và đọc bằng không cho bucket site thứ nhất. Dọn dẹp chứng minh backend khả dụng và cưỡng bức vắng mặt trước xóa trạng thái. Đây là kiểm tra Redis thực tế, không phải mô phỏng bằng hệ thống tệp hoặc mock trong tiến trình.

Toàn bộ 35 ID HTTP phủ định gốc cũng đạt khi cache bền vững bật, gồm thu hồi, mất vai trò, giới hạn khóa/IP và danh tính cookie cache. Dừng backend có kiểm soát từ chối yêu cầu trước đó được phép với HTTP 500 và không có kết quả RPC; khởi động lại Redis khôi phục danh sách sáu mươi công cụ thành công. Object-cache drop-in toàn cục dừng WordPress trước khi Agent Hub tải nên không được báo 500 đó là phản hồi 503 không khả dụng của plugin này. Không bật chế độ cache graceful không được hỗ trợ. Drop-in/backend và thiết lập failover khác chưa xác minh.

Lab dùng timeout đọc số nguyên một giây. Triển khai flush nhóm Predis đã cài khôi phục timeout qua `stream_set_timeout(seconds)`; giá trị thập phân 0.25 bị cắt thành 0 và khiến lượt đọc sau thất bại. Chọn mặc định đã ghi trong tài liệu sửa tích hợp lab đó mà không vá mã vendor. Sentinel thuộc phạm vi quản lý ngăn hiểu nhầm kết quả flush nhóm rỗng thành thao tác không khả dụng. Flush nhóm cần hỗ trợ rõ ràng và không bao giờ chuyển sang flush toàn cache.

Cache drop-in đã tắt sau checkpoint; người dùng, khóa, option, mã đo lường và tệp thông tin xác thực riêng tạm thời đã xóa với kiểm tra quyền sở hữu/đọc lại. Redis vẫn là service lab cô lập. Không đổi cài đặt toàn máy chủ.

## Tái lập và các cửa còn lại

Chỉ dùng điểm vào remote-test-lab đã duyệt. Trong toolbox thuộc phạm vi quản lý, bật fixture cache ràng buộc checksum cho từng site, chạy `infra/remote/run-cache-concurrency.sh` và `infra/remote/run-benchmark.sh`, rồi tắt cả hai fixture cache. Benchmark khôi phục cài đặt site và xóa fixture HTTP khi thoát bình thường. Lượt bị gián đoạn cần đối soát trạng thái riêng trước thử lại. Không chạy bộ tích hợp chưa cấu hình khi drop-in site trình duyệt đang bật.

Chi phí một yêu cầu được tách bằng các mốc phân tích của MU-plugin benchmark (`Bootstrap-Ms`, `Init-Ms`, `Pre-Dispatch-Ms` và các mốc bộ nhớ) và bằng probe nền có giới hạn `tests/E2E/benchmark-cost-probe.php`, đọc một POST đăng nhập và một trang site thường qua cùng tầng truyền tải. Phương pháp, số liệu, so sánh với checkpoint 2026-10-05 và phán quyết từng cổng của các lượt 2026-10-21 nằm tại [operations/performance-analysis.md](../operations/performance-analysis.md). Checkpoint 2026-10-05 ở trên giữ nguyên; không đo lại.

Còn lại: mục tiêu hiệu năng yêu cầu đầy đủ, phép đo pack nghiệp vụ/công cụ/kích thước token cuối, bảo toàn ngưỡng độ bao phủ đo riêng, hai ứng dụng client thực tế, khả năng tiếp cận thủ công, tổ hợp backend/host khác và nghiệm thu cột mốc đầy đủ. Nội dung cơ sở dữ liệu N-02 theo nghĩa đen trong kế hoạch gốc cũng cần đối soát với yêu cầu dự phòng kiểm toán/tần suất chung; bằng chứng hiện tại chứng minh không tra cứu bảng thông tin xác thực, không phải không có SQL trên toàn ứng dụng.

Tham chiếu chính thức đã xác minh ngày 2026-10-05: [cấu hình Redis Object Cache và chế độ graceful không được hỗ trợ](https://github.com/rhubarbgroup/redis-cache/blob/develop/README.md), [bản phát hành Redis 8.10.2](https://github.com/redis/redis/releases/tag/8.10.2), [tăng cache nguyên tử WordPress](https://developer.wordpress.org/reference/functions/wp_cache_incr/), [ngữ nghĩa đặt lại libcurl](https://curl.se/libcurl/c/curl_easy_reset.html), [đối số timeout stream PHP](https://www.php.net/manual/en/function.stream-set-timeout.php).

## Descriptor Content/Media thật — 2026-10-08

Ma trận HTTPS đã liệt kê đúng 31 tool (5 System, 19 Content, 7 Media), kiểm tra 26 giá trị openWorldHint mới và hoàn thành vòng đời bài viết/media ngoài DIR_TESTDATA. Hai mươi lần tools/list tuần tự, tái sử dụng kết nối, có 53.515 byte descriptor logic; trung vị toàn bộ HTTPS là 58,62245 ms, p95 là 66,233643 ms trên fixture WordPress 6.9 dùng chung, giới hạn 1 CPU/512 MiB. Đây là thời gian phía client quan sát, không phải thời gian riêng của policy hoặc điều phối server. Chưa đạt mục tiêu 50 ms cho toàn request (historical, <50 ms>). Không suy ra năng lực production; tải đồng thời, TLS lạnh, token hóa và các ca SSRF đầy đủ vẫn cần bằng chứng riêng.

## Descriptor Users thật — 2026-10-10

Danh mục quản lý nay có sáu mươi hai tool (5 System, 19 Content, 7 Media, 10 Comments, 9 Taxonomy, 12 Users); số lượng từng pack được contract và inventory test khẳng định. Mười hai định nghĩa users chuẩn đã được thêm mà không chạy lại phép đo HTTPS tools/list đầy đủ; ước tính năm mươi tool ở trên cộng byte descriptor users vẫn là baseline lập kế hoạch cho tới khi UM-157 đo lại kích thước byte và p50/p95 trên HTTPS thật cho 62 tool. OpenWorldHint chỉ đúng cho create-user, update-user và send-password-reset (email của lõi rời khỏi site).

## Descriptor Taxonomy thật — 2026-10-09

Danh mục quản lý hiện có 50 tool (5 System, 19 Content, 7 Media, 10 Comments, 9 Taxonomy); số lượng từng pack được contract và inventory test khẳng định. Chín định nghĩa taxonomy đóng góp 2.190 byte mô tả và 10.586 byte schema vào/ra (12.776 byte logic, tính từ định nghĩa đã đăng ký). Cộng với payload 53.515 byte đã đo cho 31 tool, danh sách 50 tool ước tính gần 66 kilobyte trước framing; byte không phải số token. Thời gian tools/list 50 tool trên HTTPS thật vẫn chờ đo và nằm trong các cổng còn lại.

## Vòng sản phẩm 2026-10-21 — đo tại revision `20874a3`

Checkpoint 2026-10-05 ở trên **không** được đo lại và giữ nguyên; số liệu của nó đo tại `c98ca1c`. Vòng sản phẩm của `ISSUE_v0.1_2026-10-21_01` được đo trên cùng lab tại revision `20874a3` (nhánh `issue/perf-product-20261021`), cùng harness, cùng cách cô lập và cùng ngưỡng không đổi.

| Phép đo | Checkpoint 2026-10-05 (`c98ca1c`) | Lượt trước thay đổi 2026-10-21 | Vòng sản phẩm 2026-10-21 (`20874a3`) |
|---|---:|---:|---:|
| p50 các giai đoạn hook xác thực | 3.771 ms | 3.824 ms | 3.706 / 3.744 / 3.735 ms |
| p50 lọc chính sách các tool hiển thị | 3.714 ms | 5.598 ms | 3.346 / 3.363 / 3.347 ms |
| p50 xác thực và chính sách kết hợp | 7.494 ms | 9.400 ms | 7.059 / 7.097 / 7.081 ms |
| p50 điều phối REST đến lọc phản hồi | 12.600 ms | 13.427 ms | 11.502 / 11.518 / 11.500 ms |
| p50 yêu cầu HTTPS đầy đủ đã làm nóng | 55.868 ms | 79.049 ms | 55.784 / 55.915 / 55.865 ms |
| Cận bộ nhớ bảo thủ (đỉnh trừ nền MU-plugin) | 5 509 680 B | 8 705 720 B | 6 170 600 / 6 170 600 / 6 171 048 B |
| JSON danh sách logic đã giải mã | 33 191 B | 33 191 B | 33 191 B |
| Số tool hiển thị | 60 | 60 | 60 |

Nguyên nhân gốc đo được là việc tạo sẵn adapter MCP server với toàn bộ danh mục ability trong mỗi yêu cầu REST — 0.272 ms và 32 303 B cho mỗi tool được quảng bá — chứ không phải đăng ký ability, vốn chỉ tốn 0.87 ms cho 120 ability. Thay đổi chỉ dựng server cho đúng route MCP và chỉ với các tên mà phạm vi uỷ nhiệm được trình có thể dùng, nên payload hiển thị, số tool và kết quả từ chối không đổi. Hai trong bốn cổng đỏ nay đạt; hai cổng còn lại và lý do đo được nằm tại [operations/performance-analysis.md](../operations/performance-analysis.md) mục 3.1, 3.2 và 8.1.

## Vòng memo capability 2026-10-22 — đo tại revision `eac8f50`

Checkpoint 2026-10-05 ở trên **không** được đo lại và giữ nguyên; số liệu của nó đo tại `c98ca1c`. Memo capability của `ISSUE_v0.1_2026-10-21_04` được đo trên cùng lab tại revision `eac8f50` (nhánh `issue/perf2-20261022`), cùng harness, cùng cách cô lập và cùng ngưỡng không đổi. Bốn lượt trước thay đổi và ba lượt sau thay đổi trong cùng ngày được ghi tại [operations/performance-analysis.md](../operations/performance-analysis.md) mục 3.3 và 8.2.

| Phép đo | Checkpoint 2026-10-05 (`c98ca1c`) | Vòng sản phẩm 2026-10-21 (`20874a3`) | Các lượt trước thay đổi 2026-10-22 | Vòng memo capability 2026-10-22 (`eac8f50`) |
|---|---:|---:|---:|---:|
| p50 các giai đoạn hook xác thực | 3.771 ms | 3.706 / 3.744 / 3.735 ms | 3.701 / 3.697 / 3.740 / 3.724 ms | 3.718 / 3.708 / 3.718 ms |
| p50 lọc chính sách các tool hiển thị | 3.714 ms | 3.346 / 3.363 / 3.347 ms | 3.335 / 3.348 / 3.426 / 3.394 ms | 0.656 / 0.644 / 0.645 ms |
| p50 xác thực và chính sách kết hợp | 7.494 ms | 7.059 / 7.097 / 7.081 ms | 7.038 / 7.055 / 7.158 / 7.108 ms | 4.385 / 4.362 / 4.364 ms |
| p50 điều phối REST đến lọc phản hồi | 12.600 ms | 11.502 / 11.518 / 11.500 ms | 11.250 / 11.453 / 11.007 / 11.570 ms | 8.291 / 8.267 / 8.287 ms |
| p50 yêu cầu HTTPS đầy đủ đã làm nóng | 55.868 ms | 55.784 / 55.915 / 55.865 ms | 55.715 / 55.745 / 55.524 / 55.491 ms | 52.780 / 52.830 / 52.603 ms |
| Cận bộ nhớ bảo thủ (đỉnh trừ nền MU-plugin) | 5 509 680 B | 6 170 600 / 6 170 600 / 6 171 048 B | 6 173 448 / 6 173 448 / 6 173 448 / 6 170 824 B | 6 173 448 / 6 173 448 / 6 173 448 B |
| JSON danh sách logic đã giải mã | 33 191 B | 33 191 B | 33 191 B | 33 191 B |
| Số tool hiển thị | 60 | 60 | 60 | 60 |

Nguyên nhân gốc đo được của cửa sổ chính sách là phép kiểm capability trực tiếp lặp lại: 60 tool được quảng bá dùng chung một số ít cặp user, capability và object, và mỗi cặp bị kiểm trực tiếp một lần cho mỗi tool. Một quyết định capability theo từng cặp, trong phạm vi một yêu cầu và bên trong một lượt danh sách tool được quảng bá, loại bỏ phần lặp đó; memo sống trong object đã tạo ra nó, không bao giờ ghi vào transient, object cache, option hay thuộc tính dùng chung, và mọi nhánh quyết định khác vẫn kiểm capability trực tiếp. Trung vị xác thực và chính sách kết hợp nay đạt ngưỡng gốc lần đầu. Trung vị HTTPS đầy đủ vẫn cao hơn ngưỡng 2.603–2.830 ms: phần chặn còn lại là 11.689–11.729 ms mà adapter cài riêng cần để dựng server và chiếu 60 tool được quảng bá trước khi phục vụ yêu cầu, và phiên bản 0.7.0 của dependency đó không có điểm vào dựng trì hoãn hoặc lazy. Payload hiển thị, số tool, kết quả từ chối và tra cứu ability ngoài route MCP không đổi.
