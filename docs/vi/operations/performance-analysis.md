# Phân tích cổng hiệu năng MCP (wp69 cache-on)

> **Kết luận (2026-10-21):** bốn cổng trượt của `tests/E2E/benchmark.php` được
> tái hiện trong ba lượt cô lập trên baseline đã ghim, và mọi lần trượt đều được
> quy về một chi phí **sản phẩm** kèm con số. Giả thuyết nhiễu từ lab dùng chung
> bị bác. **Không cổng nào trong sáu cổng gốc, và không quy tắc đo nào của
> chúng, bị thay đổi** bởi phân tích này.
>
> **Tiếp theo (2026-10-21, mục 3.1 và 8.1):** thay đổi sản phẩm của
> `ISSUE_v0.1_2026-10-21_01` được đo bằng cùng harness. Nguyên nhân gốc đo được
> **không phải** đăng ký ability (0.87 ms cho 120 ability) mà là việc tạo sẵn
> adapter MCP server với toàn bộ danh mục trong mỗi yêu cầu REST (33.89 ms và
> 3 876 432 B cho 120 tool). Chỉ dựng server cho route MCP và chỉ với các tên mà
> phạm vi được trình có thể dùng làm `policy_median_lt_5_ms` và
> `memory_max_lt_8_mib` đạt ở cả ba lượt; payload hiển thị, số tool và kết quả
> từ chối không đổi. Hai lần trượt còn lại được đo và quy nguyên nhân ở mục 8.1.
>
> **Tiếp theo (2026-10-22, mục 3.3 và 8.2):** memo capability của
> `ISSUE_v0.1_2026-10-21_04` được đo bằng cùng harness và cùng ngưỡng không đổi.
> Một quyết định capability trực tiếp theo từng cặp user, capability và object
> trong một lượt danh sách tool được quảng bá đưa cửa sổ chính sách từ
> 3.34–3.43 ms về 0.64–0.66 ms, nên `auth_policy_median_lt_5_ms` **đạt ở cả ba
> lượt** lần đầu tiên. `list_http_p50_lt_50_ms` (historical, <50 ms>) vẫn trượt 2.60–2.83 ms; mục 8.2
> ghi khoảng cách đo được, bằng chứng `path:line` rằng adapter 0.7.0 không có API
> dựng trì hoãn, mọi phương án bị loại và việc không ngưỡng nào bị dời.
>
> | Cổng | Phán quyết của phân tích này | Chi phí nằm ở đâu |
> |---|---|---|
> | `policy_median_lt_5_ms` | Sản phẩm | phép kiểm chính sách theo từng tool của chính plugin bên trong `mcp_adapter_tools_list` |
> | `auth_policy_median_lt_5_ms` | Sản phẩm | chính hai cửa sổ sản phẩm đó, cộng lại |
> | `list_http_p50_lt_50_ms` (historical, <50 ms>) | Sản phẩm | 59.9 % yêu cầu nằm giữa `init` và `rest_pre_dispatch`, nơi plugin đăng ký 117 ability và adapter dựng server |
> | `memory_max_lt_8_mib` | Sản phẩm | 81.5 % đỉnh được cấp phát trong lúc xử lý yêu cầu, không phải lúc WordPress khởi động |
>
> Báo cáo là bằng chứng cho một thay đổi sản phẩm, không phải thay đổi ngưỡng.
> Một phiếu con mang phần việc sản phẩm; tài liệu này giữ nguyên các cổng gốc.

---

## 1. Phạm vi và phương pháp

### 1.1 Đo cái gì

Một yêu cầu `tools/list` MCP nhắm wp69 với cache bền vững bật, đúng yêu cầu mà
benchmark vẫn luôn đo, cộng các mốc **chỉ để phân tích** chia yêu cầu đó thành
các pha. Sáu ngưỡng cổng, đơn vị, tên và ý nghĩa của
`X-Xnetvn-Benchmark-Memory-Bytes` không đổi (`tests/E2E/benchmark.php:260-265`).

Các mốc mới do MU-plugin của lab ghi (`tests/E2E/benchmark-mu-plugin.php`) và chỉ
benchmark đọc (`tests/E2E/benchmark.php`); không cổng nào đọc chúng:

| Mốc | Ý nghĩa |
|---|---|
| `X-Xnetvn-Benchmark-Mu-Load-Ms` | bắt đầu yêu cầu đến lúc MU-plugin tải (mốc nền bộ nhớ) |
| `X-Xnetvn-Benchmark-Bootstrap-Ms` | bắt đầu yêu cầu đến `plugins_loaded` |
| `X-Xnetvn-Benchmark-Init-Ms` | bắt đầu yêu cầu đến mốc `init` (ưu tiên 1, ngay sau khi các ability pack tải) |
| `X-Xnetvn-Benchmark-Pre-Dispatch-Ms` | bắt đầu yêu cầu đến `rest_pre_dispatch` (ưu tiên 0) |
| `X-Xnetvn-Benchmark-Memory-Baseline-Bytes` | nền tuyệt đối của MU-plugin (`memory_get_usage()` lúc MU-plugin tải) |
| `X-Xnetvn-Benchmark-Memory-Plugins-Bytes` | bộ nhớ hiện tại ở `plugins_loaded` trừ nền |
| `X-Xnetvn-Benchmark-Peak-Plugins-Bytes` | đỉnh tuyệt đối (`memory_get_peak_usage()`) tại `plugins_loaded` |
| `X-Xnetvn-Benchmark-Memory-Init-Bytes` | bộ nhớ hiện tại ở mốc `init` trừ bộ nhớ hiện tại ở `plugins_loaded` |
| `X-Xnetvn-Benchmark-Peak-Init-Bytes` | đỉnh tuyệt đối tại mốc `init` |
| `X-Xnetvn-Benchmark-Memory-Request-Bytes` | đỉnh yêu cầu trừ bộ nhớ hiện tại ở mốc `init` |
| `X-Xnetvn-Benchmark-Peak-Request-Bytes` | đỉnh yêu cầu trừ đỉnh tại mốc `init` |
| `X-Xnetvn-Benchmark-Declared-Classes*` | số lớp đã khai báo tại `plugins_loaded`, tại `init` và tại phản hồi |
| `X-Xnetvn-Benchmark-Phase-Rest-Api-Init-Ms` | action `rest_api_init`, cửa sổ adapter tạo server của nó |
| `X-Xnetvn-Benchmark-Phase-Mcp-Adapter-Init-Ms` | action `mcp_adapter_init`, cửa sổ plugin tạo MCP server riêng |
| `X-Xnetvn-Benchmark-Phase-Wp-Abilities-Api-Init-Ms` | action `wp_abilities_api_init`, cửa sổ plugin đăng ký ability với core |
| `X-Xnetvn-Benchmark-Phase-Wp-Abilities-Api-Categories-Init-Ms` | action `wp_abilities_api_categories_init` |
| `X-Xnetvn-Benchmark-Probe-Abilities-Total` | số ability mà abilities API của WordPress biết |
| `X-Xnetvn-Benchmark-Probe-Abilities-Ours` | số ability trong đó thuộc plugin này |
| `X-Xnetvn-Benchmark-Probe-User-During-Rest-Init` | `1` khi người dùng hiện tại đã được phân giải bên trong `rest_api_init`, ngược lại `0` |

### 1.2 Môi trường

| Hạng mục | Giá trị |
|---|---|
| Revision | `4bbadba` trên nhánh `issue/perf-investigation-20261021` (nền tích hợp `ca9454d`, đã chứa vòng đầu của phần việc này, merge tại `9032d1f`); các commit sau đó chỉ chạm tài liệu này và tệp phiếu, nên mã được đo không đổi |
| WordPress / PHP / adapter | 6.9 / 8.2.34 / 0.7.0 |
| Cache bền vững | Redis 8.10.2 / Redis Object Cache 3.0.0 / Predis, drop-in thuộc sở hữu được bật quanh mỗi lượt |
| Tool hiển thị | 60 (5 system + 1 fixture read + 54 descriptor benchmark tổng hợp) |
| Lab | `rtl-xnetvn-agent-hub-wp`, một namespace, một dự án chạy trong lúc đo |
| Cách ly | `flock` trên `/work/.local/role-seo/wp69.lock` (khóa mà `infra/remote/run-role-seo.sh` dùng), nên không suite nào của dự án này chạy song song |
| Giới hạn container | không đổi: wp69 `mem_limit: 512m`, `cpus: 1.0`; toolbox `1024m`, `1.0` CPU |

### 1.3 Phương pháp ba lượt

Mỗi lượt là một lệnh `rtl.py exec` lần lượt: lấy khóa wp69, bật fixture cache
thuộc sở hữu, chạy `infra/remote/run-benchmark.sh` nguyên bản, chạy probe nền, và
tắt lại fixture cache qua trap `EXIT`. JSON báo cáo được ghi trên server tại
`/work/.local/perf-analysis-2026-10-20/benchmark-run-{1,2,3}.json` và đọc lại
bằng `rtl.py exec … cat`.

Sau mỗi lượt, site được xác minh sạch: không còn drop-in
`xnetvn-benchmark.php`, không còn drop-in `object-cache.php`, không còn trạng
thái HTTP fixture, `redis-cache` đã tắt.

---

## 2. Sổ lượt chạy

| Lượt | Run id | Cửa sổ (UTC) | Load average trước → sau | `request_errors` |
|---|---|---|---|---|
| 1 | `perf-20261020-200808-7993` | 2026-10-20T20:08:08Z → 2026-10-20T20:09:57Z | 0.21 / 0.39 / 0.36 → 1.52 / 0.91 / 0.56 | 0 |
| 2 | `perf-20261020-200959-4554` | 2026-10-20T20:09:59Z → 2026-10-20T20:11:48Z | 1.47 / 0.91 / 0.56 → 0.74 / 0.87 / 0.59 | 0 |
| 3 | `perf-20261020-201150-1592` | 2026-10-20T20:11:50Z → 2026-10-20T20:13:38Z | 0.74 / 0.87 / 0.59 → 0.72 / 0.81 / 0.60 | 0 |

Run id dựng từ đồng hồ **UTC** (`date -u +%Y%m%d-%H%M%S`), nên mang cùng ngày
với metadata `started_utc` của vòng. Load average nằm trong khoảng 0.21 đến 1.52
trên host sáu CPU, và không dự án nào khác chạy trên lab trong ba vòng.

---

## 3. Kết quả cổng

Các ngưỡng là ngưỡng gốc và không bị chạm.

| Cổng | Ngưỡng | Lượt 1 | Lượt 2 | Lượt 3 | Phán quyết |
|---|---|---|---|---|---|
| `auth_median_lt_5_ms` | < 5 ms | 3.813396 | 3.852197 | 3.782997 | Đạt (3/3) |
| `policy_median_lt_5_ms` | < 5 ms | 5.720994 | 5.703695 | 5.674195 | **Trượt (3/3)** |
| `auth_policy_median_lt_5_ms` | < 5 ms | 9.535290 | 9.570592 | 9.473492 | **Trượt (3/3)** |
| `list_dispatch_p50_lt_50_ms` | < 50 ms | 14.376987 | 14.361687 | 14.314487 | Đạt (3/3) |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | < 50 ms | 80.399927 | 79.727528 | 79.585628 | **Trượt (3/3)** |
| `memory_max_lt_8_mib` | < 8 MiB | 8690448 B (8.2879 MiB) | 8687824 B (8.2854 MiB) | 8688272 B (8.2858 MiB) | **Trượt (3/3)** |

Ba lượt khớp nhau trong 0.047 ms ở trung vị chính sách, 0.097 ms ở trung vị
xác thực cộng chính sách, 0.814 ms ở trung vị HTTP và 2624 byte ở đỉnh bộ nhớ.
Chúng tái hiện các lượt cô lập ngày 2026-10-20 (policy 5.68/5.67, HTTP 79.42/79.31,
bộ nhớ 8.2836/8.2840 MiB) trong 0.05 ms, 0.99 ms và 0.004 MiB. Giả thuyết nhiễu
từ lab dùng chung do đó **bị bác**: các lần trượt tái lập được qua nhiều phiên và
điều kiện tải. (Bằng chứng nhiều ngày là lượt lab dùng chung ngày 2026-10-18 ghi
trong `ISSUE_v0.1_2026-10-16_06`; các lượt của phân tích này đều rơi vào
2026-10-20 UTC.)

### 3.1 Vòng sản phẩm ngày 2026-10-21

Thay đổi sản phẩm của `ISSUE_v0.1_2026-10-21_01` được đo trên cùng lab với cùng
harness, cùng quy trình cô lập và các ngưỡng **không đổi** của bảng trên. Một
vòng baseline được đo trên mã trước thay đổi cùng ngày, nên hai cột dưới chỉ khác
nhau ở thay đổi.

| Run id | Mã | `request_errors` | `visible_tools` | byte JSON logic |
|---|---|---|---|---|
| `perf-base-20261021-0400z` | trước thay đổi | 0 | 60 | 33191 |
| `perf-f1-20261021-0445z` | sau thay đổi | 0 | 60 | 33191 |
| `perf-f2-20261021-0445z` | sau thay đổi | 0 | 60 | 33191 |
| `perf-f3-20261021-0445z` | sau thay đổi | 0 | 60 | 33191 |

| Cổng | Baseline | Lượt 1 | Lượt 2 | Lượt 3 | Phán quyết |
|---|---|---|---|---|---|
| `auth_median_lt_5_ms` | 3.824 | 3.7055 | 3.7442 | 3.7353 | Đạt (3/3) |
| `policy_median_lt_5_ms` | 5.5983 | 3.3462 | 3.3626 | 3.3468 | **Nay đạt (3/3)** |
| `auth_policy_median_lt_5_ms` | 9.4001 | 7.0587 | 7.0973 | 7.0808 | **Trượt (3/3)** |
| `list_dispatch_p50_lt_50_ms` | 13.4269 | 11.5021 | 11.5175 | 11.4999 | Đạt (3/3) |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | 79.0488 | 55.7839 | 55.9147 | 55.8650 | **Trượt (3/3)** |
| `memory_max_lt_8_mib` | 8705720 B | 6170600 B | 6170600 B | 6171048 B | **Nay đạt (3/3)** |

Payload hiển thị, số tool và số yêu cầu lỗi không đổi; hai trong bốn cổng trượt
nay đạt và hai cổng còn lại trượt với biên nhỏ hơn trước (mục 3.2 và mục 8).

### 3.2 Nguyên nhân gốc đo được của chi phí trước điều phối

Giả thuyết của phiếu cha — "mỗi yêu cầu đăng ký lại toàn bộ 117 ability" — được
đo thay vì giả định, bằng cách gắn mã đo trong `src/` và thay đổi số tên tool đưa
cho adapter. Các phép đo là trung vị của mười hai yêu cầu đã làm nóng trên cùng
lab, cache bật.

| Phần đo được của một yêu cầu | Baseline | Sau thay đổi |
|---|---|---|
| `wp_abilities_api_init` (đăng ký ability với core) | 0.99 ms | 0.78 ms |
| adapter `create_server()` với 120 tên tool | **33.89 ms / 3 876 432 B** | 11.79 ms / 1 444 984 B |
| handler `mcp_adapter_tools_list` của chính plugin | 5.64 ms | 3.35 ms |
| `mcp_adapter_init` (toàn action) | 34.84 ms | 11.81 ms |
| `rest_api_init` (toàn action) | 39.19 ms | 15.34 ms |
| `pre_dispatch_ms` | 67.21 ms | 40.95 ms |
| `memory_request_bytes` | 7 091 336 B | 4 557 584 B |

Chi phí theo từng tool của adapter là tuyến tính và được đo bằng cách quảng bá 0,
30, 60 và 120 tên tool trong cùng phiên: 0.165 ms và 4 936 B cho chính server,
sau đó **0.272 ms và 32 303 B cho mỗi tool được quảng bá** (0.205 ms mỗi tool với
schema tổng hợp nhỏ, 0.34 ms với schema nghiệp vụ thật). Adapter chiếu mọi tool
được quảng bá vào mọi bản protocol MCP được hỗ trợ trước khi phục vụ yêu cầu, nên
chi phí trả cho mọi tên được đưa, không chỉ cho tool mà client có thể dùng.

**Đăng ký ability chưa bao giờ là chi phí trội.** Đăng ký 120 ability với core tốn
0.87 ms và 365 560 B; dựng 120 object tool của adapter tốn 33.89 ms và 3 876 432
B, tức 39 lần thời gian và 10,6 lần bộ nhớ. Giả thuyết của phiếu cha do đó bị phép
đo bác, và thay đổi sản phẩm đi theo phép đo: server được dựng không có tool nào
ngoài đúng route MCP và, trên route đó, chỉ với các tên mà phạm vi uỷ nhiệm được
trình có thể dùng.

Chi phí còn lại của cửa sổ chính sách được đo cùng cách, với probe bên trong
`Policy::evaluate()` và `Registry::allowed()`: trong 3.35 ms mà cửa sổ vẫn tốn cho
60 tool được quảng bá, **2.62 ms là 60 phép kiểm capability trực tiếp**
(`user_can()`), 0.08 ms là filter mở rộng và 0.45 ms là phần còn lại của đường
quyết định. Phép kiểm capability phải giữ trực tiếp, vì
`tests/Unit/Policy/ToolVisibilityTest.php:44` yêu cầu một capability bị mất giữa
yêu cầu phải ẩn tool ngay.

### 3.3 Vòng sản phẩm ngày 2026-10-22 (memo capability)

Thay đổi của `ISSUE_v0.1_2026-10-21_04` được đo trên cùng lab với cùng harness,
cùng quy trình cô lập và các ngưỡng **không đổi** của mục 3. Bốn vòng ngày
2026-10-22 04:59–05:13 UTC (`benchmark-child-1.json` … `benchmark-child-4.json`
của `/work/.local/perf-analysis-2026-10-21/`) đo mã **trước** thay đổi trên cùng
stack; ba vòng dưới đo mã **sau** thay đổi.

| Run id (tệp báo cáo) | Mã | `request_errors` | `visible_tools` | byte JSON logic |
|---|---|---|---|---|
| `local-20261022T045956Z` (`benchmark-child-1.json`) | trước thay đổi | 0 | 60 | 33191 |
| `local-20261022T050059Z` (`benchmark-child-2.json`) | trước thay đổi | 0 | 60 | 33191 |
| `local-20261022T050300Z` (`benchmark-child-3.json`) | trước thay đổi | 0 | 60 | 33191 |
| `local-20261022T051346Z` (`benchmark-child-4.json`) | trước thay đổi | 0 | 60 | 33191 |
| `local-20261022T055048Z` (`benchmark-child-1.json`) | sau thay đổi | 0 | 60 | 33191 |
| `local-20261022T055256Z` (`benchmark-child-2.json`) | sau thay đổi | 0 | 60 | 33191 |
| `local-20261022T055457Z` (`benchmark-child-3.json`) | sau thay đổi | 0 | 60 | 33191 |

| Cổng | Ngưỡng | Trước thay đổi (4 vòng) | Sau thay đổi lượt 1 | Sau thay đổi lượt 2 | Sau thay đổi lượt 3 | Phán quyết |
|---|---|---|---|---|---|---|
| `auth_median_lt_5_ms` | `< 5 ms` | 3.701 / 3.697 / 3.740 / 3.724 ms | 3.718497 ms | 3.708496 ms | 3.717896 ms | Đạt (3/3) |
| `policy_median_lt_5_ms` | `< 5 ms` | 3.335 / 3.348 / 3.426 / 3.394 ms | 0.655599 ms | 0.644300 ms | 0.645499 ms | Đạt (3/3) |
| `auth_policy_median_lt_5_ms` | `< 5 ms` | 7.038 / 7.055 / 7.158 / 7.108 ms | 4.384696 ms | 4.362197 ms | 4.364095 ms | **Nay đạt (3/3)** |
| `list_dispatch_p50_lt_50_ms` | `< 50 ms` | 11.250 / 11.453 / 11.007 / 11.570 ms | 8.290991 ms | 8.267292 ms | 8.286591 ms | Đạt (3/3) |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | `< 50 ms` | 55.715 / 55.745 / 55.524 / 55.491 ms | 52.779746 ms | 52.829945 ms | 52.603445 ms | **Trượt (3/3)** |
| `memory_max_lt_8_mib` | `< 8 MiB` | 6 173 448 / 6 173 448 / 6 173 448 / 6 170 824 B | 6 173 448 B | 6 173 448 B | 6 173 448 B | Đạt (3/3) |

Payload được quảng bá, số tool, số yêu cầu lỗi và đỉnh bộ nhớ không đổi; cửa sổ
chính sách giảm từ 3.34–3.43 ms về 0.64–0.66 ms, đúng 2.62 ms của các phép kiểm
capability trực tiếp lặp lại đã đo ở mục 3.2. Cửa sổ kết hợp do đó đạt ngưỡng gốc
lần đầu, với biên 0.62–0.64 ms.

Lần trượt còn lại là yêu cầu HTTPS đầy đủ. Khoảng cách tới ngưỡng gốc là
**2.779746 / 2.829945 / 2.603445 ms** ở ba vòng (52.779746, 52.829945 và
52.603445 ms so với 50 ms). Yêu cầu vẫn tốn 40.95–41.22 ms trước
`rest_pre_dispatch` và 8.27–8.29 ms trong cửa sổ điều phối; pha
`mcp_adapter_init` của adapter là 11.688588–11.729188 ms trong tổng đó và không
đổi (11.640–11.754 ms trước thay đổi).

---

## 4. Phân bổ chi phí một yêu cầu `tools/list`

### 4.1 Dòng thời gian

Phân vị là p50 của 100 mẫu hợp lệ; giá trị từng pha là hiệu của các p50 đó, nên
chúng là xấp xỉ của cách chia theo từng mẫu. Tám pha cộng lại bằng `http_ms` đo
được.

| Thành phần | Nguồn | Lượt 1 | Lượt 2 | Lượt 3 | Tỷ lệ trong `http_ms` (lượt 2) |
|---|---|---|---|---|---|
| `tls_connect_ms` | `connect_ms + tls_ready_ms` | 0.000000 | 0.000000 | 0.000000 | 0.0 % |
| `server_think_ms` | `ttfb_ms` − `tls_connect_ms` | 78.888000 | 78.555000 | 78.417000 | 98.5 % |
| `phase_a1_core_startup_ms` | `mu_load_ms` (bắt đầu yêu cầu → MU-plugin tải) | 7.294893 | 7.313013 | 7.286072 | 9.2 % |
| `phase_a2_plugin_load_ms` | `bootstrap_ms` − `mu_load_ms` (MU-plugin tải → `plugins_loaded`) | 3.164053 | 2.887964 | 2.869844 | 3.6 % |
| `phase_b_init_ms` | `init_ms` − `bootstrap_ms` (`plugins_loaded` → `init`) | 4.173279 | 4.160881 | 4.153013 | 5.2 % |
| `phase_c_pre_dispatch_ms` | `pre_dispatch_ms` − `init_ms` (`init` → `rest_pre_dispatch`) | 47.685862 | 47.782183 | 47.646046 | **59.9 %** |
| `phase_d_dispatch_ms` | `dispatch_ms` (`rest_pre_dispatch` → `rest_post_dispatch`) | 14.376987 | 14.361687 | 14.314487 | 18.0 % |
| ↳ `auth_ms` | `X-Xnetvn-Benchmark-Auth-Ms` | 3.813396 | 3.852197 | 3.782997 | 4.8 % |
| ↳ `policy_ms` | `X-Xnetvn-Benchmark-Policy-Ms` | 5.720994 | 5.703695 | 5.674195 | 7.2 % |
| `residual_ms` | `server_think_ms` − `bootstrap_ms` − `dispatch_ms` | 54.052067 | 53.992336 | 53.946597 | 67.7 % |
| `phase_e_response_flush_ms` | `ttfb_ms` − (pha A1…D) | 2.192926 | 2.049272 | 2.147538 | 2.6 % |
| `phase_f_response_transfer_ms` | `curl_total_ms` − `ttfb_ms` | 1.152000 | 0.816000 | 0.808000 | 1.0 % |
| `phase_g_client_overhead_ms` | `http_ms` − `curl_total_ms` | 0.359927 | 0.356528 | 0.360628 | 0.4 % |
| `http_ms` (tổng phía client) | `hrtime` của client quanh yêu cầu | 80.399927 | 79.727528 | 79.585628 | 100 % |

Với lượt 2, các pha cộng lại bằng 79.727528 ms, đúng `http_ms` đo được của
79.727528 ms; lượt 1 và lượt 3 khép lại cùng cách (80.399927 và 79.585628).

**Phần không được quy cho một pha có tên là `phase_e`, không phải 0 ms.**
`phase_e_response_flush_ms` (2.192926 / 2.049272 / 2.147538 ms) là thời gian
giữa mốc đo cuối (`rest_post_dispatch`) và byte đầu tiên client nhận: tuần tự hóa
phản hồi trong PHP, xả qua Apache và TLS, và truyền tới client. Đó là một cách
**diễn giải** một hiệu đã đo, không phải pha được đo trực tiếp, nên được báo là
phần dư của yêu cầu thay vì một chi phí có tên. Công thức của chính phiếu
`residual_ms = server_think_ms − bootstrap_ms − dispatch_ms`
(54.052067 / 53.992336 / 53.946597 ms) là tổng của `phase_b`, `phase_c` và
`phase_e`; nó được liệt kê vì phiếu yêu cầu, và không phải chi phí thêm.

### 4.2 Phần nào thuộc ai

| Phần của `http_ms` | Chủ sở hữu | Bằng chứng |
|---|---|---|
| Bắt tay TLS, DNS, kết nối mới (0.000 ms) | hạ tầng | `connect_ms`, `tls_ready_ms`, `new_connections` đều bằng 0 ở mọi lượt: client giữ kết nối, nên tầng truyền tải không thêm gì |
| Truyền phản hồi và chi phí client | hạ tầng và client kiểm thử | `curl_total_ms − ttfb_ms` và `http_ms − curl_total_ms` |
| Pha A1, khởi động core (bắt đầu yêu cầu → MU-plugin tải) (7.313 ms) | WordPress core | MU-plugin benchmark là MU-plugin cuối của site, nên cửa sổ này là khởi động core (`wp-config.php`, kết nối database, `wp-settings.php` tới loader MU-plugin) cộng mọi MU-plugin mà site tải trước nó; nó **không** chứa mã của plugin Agent Hub |
| Pha A2, tải plugin (MU-plugin tải → `plugins_loaded`) (2.888 ms) | **sản phẩm**, cùng dependency | tệp plugin Agent Hub được tải và kernel của nó khởi động trong cửa sổ này, trước khi `plugins_loaded` phát: `xnetvn-agent-hub-wp.php:32` → `src/Bootstrap/Loader.php:39` → `src/Plugin.php:89`, `:96`, `:113-152` (provider và registry được dựng ở đây); plugin adapter cài riêng được tải trong cùng cửa sổ |
| Pha B, ability pack (4.161 ms) | sản phẩm | plugin dựng toàn bộ định nghĩa pack tại `init` ưu tiên 0 (`src/Abilities/Registry.php:91-104`) |
| Pha C, abilities API và server adapter (47.782 ms) | sản phẩm, cùng dependency | giữa `init` và `rest_pre_dispatch`, WordPress khởi tạo abilities API, plugin đăng ký 117 ability cùng schema (`src/Abilities/Registry.php:132-175`) và adapter tạo MCP server (`src/Mcp/ServerGateway.php:81-117`) |
| Pha D, điều phối (14.362 ms) | sản phẩm và dependency | điều phối của adapter cộng cửa sổ chính sách (5.704 ms) và cửa sổ xác thực (3.852 ms) của plugin |

**Mạng không phải nguyên nhân:** chi phí TLS/kết nối là 0 ms và truyền phản hồi là
0.816 ms, tổng cộng 1 % của yêu cầu.

---

## 5. Probe nền P3 và tham chiếu yêu cầu thường

### 5.1 Probe được quy định

Phiếu quy định một POST rỗng tới `https://$RTL_ALIAS_WP69/wp-login.php` qua cùng
tầng truyền tải client, chạy ngay sau phép đo MCP với 10 lượt làm nóng và 100
mẫu. Nó được hiện thực trong `tests/E2E/benchmark-cost-probe.php` (tệp mà phiếu
cho phép) vì `McpClient::request()` ném
`UnexpectedValueException('Malformed test response.')` với body HTML **trước
khi** trả mảng transport (`tests/E2E/McpClient.php:104-105`, `:142-150`), nên
không đọc được số liệu transport của trang đăng nhập qua client đó.

| Chỉ số probe | Lượt 1 | Lượt 2 | Lượt 3 |
|---|---|---|---|
| `http_ms` p50 | 499.910348 | 500.094847 | 500.291448 |
| `ttfb_ms` p50 | 499.721000 | 499.989000 | 500.182000 |
| `curl_total_ms` p50 | 499.883000 | 500.066000 | 500.263000 |
| `dns_ms`, `connect_ms`, `tls_ready_ms`, `new_connections` p50 | 0 | 0 | 0 | 0 |
| HTTP status | 200 (100/100) | 200 (100/100) | 200 (100/100) |

### 5.2 Vì sao probe này không dùng được làm cận dưới

Probe được quy định **chậm gấp 6,3 lần yêu cầu MCP mà nó định làm cận**
(500.1 ms so với 79.7 ms), nên công thức của phiếu "phần `http_ms` trên P3 là chi
phí biên của route MCP" không áp dụng được: P3 không phải cận dưới cho một yêu
cầu thường trên lab này.

Một lượt chẩn đoán trong toolbox (mỗi endpoint một yêu cầu, cache bền vững tắt
lúc đó) cô lập chỗ phát sinh chi phí:

| Yêu cầu | Mã | `time_starttransfer` | `time_total` |
|---|---|---|---|
| `GET /wp-login.php` | 200 | 0.121212 | 0.121315 |
| `POST /wp-login.php` | 200 | 0.476178 | 0.476354 |
| `GET /` | 200 | 0.160118 | 0.162082 |
| `GET /wp-json/` | 200 | 0.198916 | 0.201670 |
| `POST /wp-json/` | 404 | 0.366690 | 0.366763 |

POST rỗng tốn thêm khoảng 355 ms so với cùng trang bằng GET, và POST tới một
đường REST tốn thêm khoảng 168 ms so với GET cùng đường. Probe do đó đo **chi phí
của đường đăng nhập**, không phải chi phí của một yêu cầu WordPress thường. Đó là
thuộc tính của đích probe được quy định, không phải của route MCP.

### 5.3 Đọc trang site thường làm tham chiếu dùng được

Vì vậy probe cũng đọc gốc site bằng GET trên cùng kết nối, trong cùng lượt và
cùng trạng thái cache. Lượt đọc đó là tham chiếu dùng được cho "chi phí ngoài
route MCP":

| Chỉ số tham chiếu (`GET /`) | Lượt 1 | Lượt 2 | Lượt 3 |
|---|---|---|---|
| `http_ms` p50 | 59.171947 | 59.148946 | 59.398546 |
| `ttfb_ms` p50 | 57.231000 | 57.224000 | 57.499000 |
| `curl_total_ms` p50 | 59.145000 | 59.120000 | 59.372000 |
| `dns_ms`, `connect_ms`, `tls_ready_ms`, `new_connections` p50 | 0 | 0 | 0 | 0 |
| HTTP status | 200 (100/100) | 200 (100/100) | 200 (100/100) |

Một lượt đọc thường cùng site trên cùng tầng truyền tải tốn 59.15 ms (lượt 2),
trong đó tầng truyền tải đóng góp 0 ms và truyền phản hồi 1.896 ms. Yêu cầu MCP
tốn 79.73 ms, nên route MCP thêm **20.58 ms** so với một lần đọc trang thường của
cùng site — đó là cửa sổ điều phối (14.36 ms) cộng body phản hồi lớn hơn. 59.15
ms còn lại là chi phí dùng chung của mọi yêu cầu trên site này: khởi động
WordPress core, khởi động plugin, đăng ký ability và server adapter.

### 5.4 Hạn chế của probe

- Probe vẫn tải plugin Agent Hub (mọi plugin đều tải ở mọi yêu cầu), nên nó
  không tách được bootstrap của plugin khỏi bootstrap của core.
- Probe chạy **sau** phép đo MCP trong cùng vòng, nên nó thấy cùng trạng thái
  cache và cùng tải host, nhưng không cùng tiến trình PHP.
- Vì 5.2, công thức trừ của phiếu được thay bằng các mốc pha ở mục 4, vốn quy
  mọi mili-giây của `http_ms` mà không cần phép trừ.

---

## 6. Đỉnh bộ nhớ

Baseline là lúc MU-plugin tải, đúng baseline mà cổng vẫn luôn dùng. Báo cáo công
bố các mốc **tuyệt đối** bên cạnh các mốc dẫn xuất, để người đọc tính lại được
mọi phần dưới đây.

### 6.1 Các mốc tuyệt đối

| Mốc | Lượt 1 | Lượt 2 | Lượt 3 |
|---|---|---|---|
| `memory_baseline_bytes` — nền MU-plugin (tuyệt đối) | 1479312 | 1481872 | 1481872 |
| `memory_bytes` — chỉ số của cổng (đỉnh yêu cầu − nền) | 8690448 | 8687824 | 8688272 |
| `memory_plugins_bytes` — hiện tại ở `plugins_loaded` − nền | 808264 | 805640 | 805640 |
| `peak_plugins_bytes` — đỉnh tuyệt đối tại `plugins_loaded` | 2287576 | 2287512 | 2287512 |
| `memory_init_bytes` — hiện tại ở `init` − hiện tại ở `plugins_loaded` | 793496 | 793496 | 793496 |
| `peak_init_bytes` — đỉnh tuyệt đối tại `init` | 3086336 | 3086272 | 3086272 |
| `memory_request_bytes` — đỉnh yêu cầu − hiện tại ở `init` | 7088688 | 7088688 | 7089136 |
| `peak_request_bytes` — đỉnh yêu cầu − đỉnh tại `init` | 7083424 | 7083424 | 7083872 |

### 6.2 Hai cách chia cùng một đỉnh

| Cách chia | Phần | Lượt 1 | Lượt 2 | Lượt 3 | Tỷ lệ trong đỉnh (lượt 2) |
|---|---|---|---|---|---|
| theo hiện tại | `memory_plugins_bytes` — hiện tại ở `plugins_loaded` − nền | 808264 B | 805640 B | 805640 B | 9.3 % |
| theo hiện tại | `memory_init_bytes` — hiện tại ở `init` − hiện tại ở `plugins_loaded` | 793496 B | 793496 B | 793496 B | 9.1 % |
| theo hiện tại | `memory_request_bytes` — đỉnh yêu cầu − hiện tại ở `init` | 7088688 B | 7088688 B | 7089136 B | **81.6 %** |
| theo đỉnh | khởi động: `peak_plugins_bytes` − `memory_baseline_bytes` | 808264 B | 805640 B | 805640 B | 9.3 % |
| theo đỉnh | tải pack: `peak_init_bytes` − `peak_plugins_bytes` | 798760 B | 798760 B | 798760 B | 9.2 % |
| theo đỉnh | yêu cầu: đỉnh yêu cầu − `peak_init_bytes` | 7083424 B | 7083424 B | 7083872 B | **81.5 %** |
| — | `memory_bytes` (chỉ số của cổng) | 8690448 B | 8687824 B | 8688272 B | 100 % |

**Dòng "tổng = `memory_bytes`" là một đồng nhất thức, không phải phép kiểm.**
Cả hai cách chia đều là hiệu của cùng ba mốc, nên mỗi cách cộng lại bằng chỉ số
cổng theo cấu trúc; cách viết trước đây ("tổng khớp chính xác chỉ số cổng ở mọi
lượt") do đó **không** phải bằng chứng. Điều các mốc tuyệt đối cho thấy là chỗ
giá trị hiện tại và giá trị đỉnh khác nhau: tại `plugins_loaded` khoảng cách là
**0 B** (tiến trình vẫn ở mức tối đa), còn tại `init` đỉnh cao hơn mức đang giữ
**5264 B**, đó là lý do phần yêu cầu theo hiện tại (7088688 B) lớn hơn phần yêu
cầu theo đỉnh (7083424 B) đúng 5264 B. Cách chia theo **đỉnh** quy phần tăng của
chính đỉnh; cách chia theo hiện tại quy phần bộ nhớ còn giữ ở cuối yêu cầu. Cả
hai chỉ cùng một pha.

### 6.3 Cơ chế

Đỉnh đạt được trong lúc xử lý yêu cầu, không phải lúc WordPress khởi động. Chỉ
1.50 MiB trong 8.29 MiB đỉnh (18.5 %) là chi phí khởi động (khởi động kernel cộng
các ability pack); 6.76 MiB (81.5 % theo cách chia đỉnh) được cấp phát sau mốc
`init`, trong cửa sổ nơi abilities API của WordPress đăng ký 117 ability cùng
schema, adapter dựng server và danh sách 60 tool được lắp và lọc. Số lớp đã khai
báo cho cùng hình dạng: 539 lớp tại `plugins_loaded`, 563 tại `init`, 631 tại
phản hồi.

**Hệ quả cho câu hỏi mở 1.** Bằng chứng **không** ủng hộ việc đổi cách đo
`memory_max_lt_8_mib`: đỉnh là chi phí theo từng yêu cầu, không phải chi phí khởi
động, nên phép đo đã chỉ đúng vào đường sản phẩm. Cả phép đo và ngưỡng `< 8 MiB`
đều giữ nguyên, và không mở phiếu đổi phép đo riêng. Phần việc sản phẩm thuộc
phiếu con.

---

## 7. Checkpoint 2026-10-05 so với các lượt mới

Checkpoint là artifact số liệu `docs/en/reports/M1-60-tools.json`
(`source_base_commit` = `c98ca1c`, `measurement_date_utc` = 2026-10-05). Theo
quyết định của điều phối viên (2026-10-21), nó **không** được đo lại ở revision
cũ; phần dư không quy được nguyên nhân được ghi là "chưa giải thích", kèm con số.

| Dòng | Checkpoint 2026-10-05 | Lượt mới (trung bình 3) | Chênh lệch | Được phép đo giải thích | Chưa giải thích |
|---|---|---|---|---|---|
| auth p50 | 3.771296 ms | 3.816197 ms | +0.044901 ms (+1.19 %) | cửa sổ là mã sản phẩm không đổi; chênh lệch nằm trong nhiễu của hai ngày khác nhau | 0.044901 ms |
| policy p50 | 3.714196 ms | 5.699628 ms | +1.985432 ms (+53.46 %) | toàn bộ chênh lệch nằm trong cửa sổ chính sách của sản phẩm; payload hiển thị không đổi (33 191 byte JSON logic ở cả hai) | cách chia bên trong 1.985 ms trong cửa sổ (chưa đo) |
| auth+policy p50 | 7.494492 ms | 9.526458 ms | +2.031966 ms (+27.11 %) | tổng của hai cửa sổ sản phẩm trên | 0.001633 ms của tổng không quy được cho cửa sổ nào (phân vị của tổng không phải tổng các phân vị) |
| dispatch p50 | 12.599588 ms | 14.351054 ms | +1.751466 ms (+13.90 %) | nằm trong cửa sổ điều phối REST | cách chia bên trong 1.751466 ms (chưa đo) |
| HTTP p50 | 55.867646 ms | 79.904361 ms | +24.036715 ms (+43.02 %) | +1.751 ms trong cửa sổ điều phối và +22.285 ms **trước** `rest_pre_dispatch` | cách chia 22.285 ms tại checkpoint (artifact không có mốc pha) |
| HTTP p50 − dispatch p50 | 43.268058 ms | 65.553307 ms | +22.285249 ms (+51.51 %) | phép đo mới chia được: 7.313 ms khởi động core + 2.888 ms tải plugin + 4.161 ms tải pack + 47.782 ms cửa sổ ability/adapter | cách chia của checkpoint cho cùng 43.268 ms |
| bộ nhớ đỉnh | 5 509 680 B (5.2544 MiB) | 8 688 848 B (8.2863 MiB) | +3 179 168 B (+57.70 %) | phép đo mới đặt 81.5 % đỉnh vào pha yêu cầu và 18.5 % vào khởi động | 3 179 168 B (artifact checkpoint không có cách chia bộ nhớ) |
| `concurrent_10` HTTP p50 | 1101.586029 ms | 1562.495519 ms | +460.909490 ms (+41.84 %) | cùng các pha của một yêu cầu, khuếch đại bởi mười worker đồng thời trên một CPU | 460.909490 ms (không có mốc pha đồng thời) |

**Chênh lệch mã nói gì.** `git diff --stat c98ca1c..HEAD -- src` báo **59 tệp
thay đổi, 9017 dòng thêm, 65 dòng xóa**. Các namespace mới là
`src/Abilities/Packs/{Content,Media,Comments,Taxonomy,Users}` (năm pack thật, 57
ability thêm), `src/Support/Http`, `src/RateLimit` và `src/Contracts`. Ghi chú
kiến trúc của chính kho ghi rằng các pack thật Content/Media, Taxonomy và Users
được đo ngày 2026-10-08, 2026-10-09 và 2026-10-10 — **sau** checkpoint
2026-10-05, vốn đo một site chỉ đăng ký 5 ability hệ thống, fixture read và 54
descriptor tổng hợp (tổng 60). Site nay đăng ký 117 ability (62 thật cộng 54
descriptor tổng hợp và fixture read) ở mọi yêu cầu, trong khi payload MCP hiển
thị giống nhau từng byte (33 191 byte JSON logic ở cả checkpoint và các lượt mới).

Đó là nguyên nhân khả dĩ nhất của +3 179 168 B bộ nhớ và +22.285 ms nay nằm trước
`rest_pre_dispatch`: sản phẩm tải và đăng ký số định nghĩa ability gần gấp đôi ở
mỗi yêu cầu. Đây vẫn là **giả thuyết có tài liệu ủng hộ, không phải quy nguyên
nhân đã đo**: artifact checkpoint không có mốc pha, và quyết định của phiếu cấm đo
lại ở `c98ca1c`, nên không lấy lại được cách chia 43.268 ms của chính checkpoint.
Các con số còn lại được liệt kê ở trên là "chưa giải thích".

---

## 8. Kết luận theo từng cổng và hành động đề xuất

| Cổng | Phân loại | Bằng chứng | Hành động đề xuất |
|---|---|---|---|
| `policy_median_lt_5_ms` (5.700 ms) | **Lỗi sản phẩm** | cửa sổ đo được là handler `mcp_adapter_tools_list` của chính plugin (`src/Mcp/ServerGateway.php:145-162`), vốn đánh giá lại chính sách cho từng tool được liệt kê; payload hiển thị không đổi, nên 1.985 ms thêm là việc theo từng tool, không phải việc của payload | phiếu con: cache quyết định theo từng tool trong một yêu cầu và ngừng đọc lại cài đặt site cho mỗi tool |
| `auth_policy_median_lt_5_ms` (9.526 ms) | **Lỗi sản phẩm** | tổng của hai cửa sổ sản phẩm (3.816 ms xác thực + 5.700 ms chính sách); cả hai nằm trong mã plugin | cùng phiếu con; hai cửa sổ là cùng một đường |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) (79.904 ms) | **Lỗi sản phẩm** | 59.9 % yêu cầu (47.782 ms) nằm giữa `init` và `rest_pre_dispatch`, cửa sổ nơi plugin đăng ký 117 ability và adapter dựng server; một lần đọc trang thường của cùng site tốn 59.149 ms, nên tầng truyền tải không phải nguyên nhân | phiếu con: đăng ký ability lười, chỉ cho yêu cầu cần, và giữ payload không đổi |
| `memory_max_lt_8_mib` (8.2863 MiB, trung bình ba lượt) | **Lỗi sản phẩm** | 81.5 % đỉnh (6.76 MiB) được cấp phát trong lúc xử lý yêu cầu; chỉ 18.5 % (1.50 MiB) là chi phí khởi động | cùng phiếu con: cùng cách đăng ký lười loại bỏ định nghĩa trùng; **phép đo và ngưỡng giữ nguyên** |

Không cổng nào bị phân loại là lỗi môi trường: ba lượt cô lập tái hiện các lần
trượt trong 0.05 ms ở trung vị chính sách trong khi load average nằm trong 0.21
đến 1.52 trên host sáu CPU, và chi phí tầng truyền tải là 0 ms. Không cổng nào bị
phân loại là lỗi phép đo: đỉnh bộ nhớ là chi phí theo từng yêu cầu, nên phép đo
hiện có đã chỉ đúng vào sản phẩm, và quyết định của điều phối viên ngày 2026-10-21
giữ cả phép đo lẫn ngưỡng.

**Ngưỡng: không đổi trong phân tích này.** Tại thời điểm phân tích, sáu cổng gốc
giữ nguyên giá trị, đơn vị và tên trong `tests/E2E/benchmark.php:260-265`, trong
`docs/en/operations/performance-slo.md` và trong các phiếu được tham chiếu. Một
thay đổi yêu cầu riêng do chủ dự án uỷ quyền ngày 2026-11-09 chỉ hạ cổng p50
HTTPS đầy đủ; mục 8.6 ghi lại thay đổi đó, và mọi phán quyết trước đây trong tài
liệu này giữ nhãn `historical, <50 ms>`.

### 8.1 Phán quyết sau thay đổi sản phẩm ngày 2026-10-21

Thay đổi được đo ở mục 3.1 loại bỏ việc dựng sẵn server trong mỗi yêu cầu REST và
giữ việc đọc cài đặt site theo từng yêu cầu ra khỏi đường quyết định theo từng
tool. Payload hiển thị, số tool, phạm vi quyền và kết quả từ chối không đổi.

| Cổng | Trước | Sau | Phán quyết sau thay đổi |
|---|---|---|---|
| `policy_median_lt_5_ms` | 5.5983 ms | 3.3462 / 3.3626 / 3.3468 ms | **Đạt (3/3)** |
| `auth_policy_median_lt_5_ms` | 9.4001 ms | 7.0587 / 7.0973 / 7.0808 ms | **Trượt (3/3)** — cửa sổ là 3.71–3.74 ms xác thực cộng 3.35 ms chính sách; riêng 60 phép kiểm `user_can()` trực tiếp tốn 2.62 ms và phải giữ trực tiếp |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | 79.0488 ms | 55.7839 / 55.9147 / 55.8650 ms | **Trượt (3/3)** — 40.95 ms tốn trước `rest_pre_dispatch` (7.3 ms khởi động core, 2.9 ms tải plugin, 4.2 ms tải pack, 11.8 ms dựng server adapter cho 60 tool được quảng bá, phần còn lại là điều phối REST của core) và 11.50 ms trong cửa sổ điều phối |
| `memory_max_lt_8_mib` | 8 705 720 B | 6 170 600 / 6 170 600 / 6 171 048 B | **Đạt (3/3)** |

**Vì sao hai cổng còn lại vẫn trượt.**

- `auth_policy_median_lt_5_ms` cần cửa sổ chính sách dưới khoảng 1.25 ms, vì cửa
  sổ xác thực của lab này đã là 3.71–3.74 ms. Cửa sổ tốn 3.35 ms cho 60 tool được
  quảng bá, trong đó 2.62 ms là các phép kiểm capability trực tiếp. Đạt cổng do đó
  cần hoặc tập tool được quảng bá nhỏ hơn, điều hợp đồng `tools/list` cấm, hoặc
  phép kiểm capability rẻ hơn, điều
  `tests/Unit/Policy/ToolVisibilityTest.php:44` cấm, hoặc cửa sổ xác thực rẻ hơn,
  vốn nằm ở `src/Auth/Authenticator.php` và `src/RateLimit/`, ngoài phạm vi phiếu
  sản phẩm.
- `list_http_p50_lt_50_ms` (historical, <50 ms>) cần thêm khoảng 6 ms. Khối sản phẩm còn lại lớn nhất là
  11.8 ms adapter cần để dựng 60 tool được quảng bá của route MCP, một chi phí
  nằm trong dependency cài riêng (`McpComponentRegistry::add_mcp_tool()` chiếu mọi
  tool vào mọi bản protocol được hỗ trợ). Phần còn lại của yêu cầu là khởi động
  WordPress core, khởi động plugin và điều phối REST của core.

Cả hai lần trượt còn lại đều là chi phí sản phẩm, không phải lỗi phép đo hay môi
trường, và cả hai đều nằm ngoài các tệp mà `ISSUE_v0.1_2026-10-21_01` được phép
sửa.

### 8.2 Phán quyết sau memo capability ngày 2026-10-22

Thay đổi được đo ở mục 3.3 giải quyết một quyết định capability cho mỗi cặp user,
capability và object trong một lượt danh sách tool được quảng bá. Danh sách tool,
payload, phạm vi quyền, kết quả từ chối và tra cứu ability ngoài route MCP không
đổi, và phép kiểm capability của mọi đường khác vẫn trực tiếp.

| Cổng | Trước (2026-10-21) | Sau (2026-10-22, ba vòng) | Phán quyết sau thay đổi |
|---|---|---|---|
| `policy_median_lt_5_ms` | 3.3462 / 3.3626 / 3.3468 ms | 0.655599 / 0.644300 / 0.645499 ms | Đạt (3/3) |
| `auth_policy_median_lt_5_ms` | 7.0587 / 7.0973 / 7.0808 ms | 4.384696 / 4.362197 / 4.364095 ms | **Đạt (3/3)** — cửa sổ là 3.71 ms xác thực cộng 0.64–0.66 ms chính sách |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | 55.7839 / 55.9147 / 55.8650 ms | 52.779746 / 52.829945 / 52.603445 ms | **Trượt (3/3)** — cao hơn ngưỡng gốc 2.779746 / 2.829945 / 2.603445 ms |
| `memory_max_lt_8_mib` | 6 170 600 / 6 170 600 / 6 171 048 B | 6 173 448 / 6 173 448 / 6 173 448 B | Đạt (3/3) |

**Vì sao `list_http_p50_lt_50_ms` (historical, <50 ms>) không thể đạt trong phạm vi plugin.** Ba vòng
đồng ý về kết luận, và mọi con số dưới đây lấy từ cùng ba tệp báo cáo.

1. **Khoảng cách đo được tới ngưỡng.** Yêu cầu HTTPS đầy đủ đã làm nóng là
   52.779746 ms, 52.829945 ms và 52.603445 ms, tức **cao hơn ngưỡng gốc 50 ms là
   2.779746 ms, 2.829945 ms và 2.603445 ms**. Phần còn lại thuộc plugin trong yêu
   cầu đó là cửa sổ chính sách, nay tốn tổng 0.644300–0.655599 ms. Trừ policy p50
   khỏi HTTP p50 của từng cặp cho 52.124147, 52.185645 và 51.957946 ms, tức cao
   hơn ngưỡng 2.124147, 2.185645 và 1.957946 ms. Phép trừ đó chỉ là minh họa số
   học của các trung vị, không phải phép đo phản thực: cửa sổ chính sách bằng
   không chưa từng được chạy và p50 của một hiệu không phải hiệu của các p50.
2. **Khối còn lại lớn nhất thuộc dependency.** Trong 40.946006–41.217089 ms tốn
   trước `rest_pre_dispatch`, riêng `phase_mcp_adapter_init_ms` là
   11.688588–11.729188 ms, pha mà adapter dựng server và chiếu 60 tool được quảng
   bá vào mọi bản protocol được hỗ trợ. Pha đó không đổi qua phần việc này
   (11.639893–11.754187 ms trước thay đổi).
3. **Adapter 0.7.0 không có API dựng trì hoãn.** Bản sao chỉ đọc của dependency
   trên máy này là `.local/evidence/mcp-adapter/`, và sha256 của `mcp-adapter.php`,
   `includes/Core/McpAdapter.php`, `includes/Core/McpServer.php`,
   `includes/Core/McpComponentRegistry.php`, `includes/Domain/Tools/McpTool.php`,
   `includes/Handlers/Tools/ToolsHandler.php` và
   `includes/Core/McpVersionNegotiator.php` giống hệt bản cài trong lab. Bằng
   chứng API là:
   - `mcp-adapter.php:14` — phiên bản là 0.7.0.
   - `includes/Core/McpAdapter.php:81` — adapter gắn `init()` vào `rest_api_init`
     với ưu tiên 15; `:93` là `init()`; `:113` phát `mcp_adapter_init`.
   - `includes/Core/McpAdapter.php:221` — `create_server()` là điểm vào công khai
     duy nhất, và `:278` từ chối mọi lời gọi ngoài
     `doing_action( 'mcp_adapter_init' )` bằng `invalid_timing`.
   - `includes/Core/McpServer.php:141` — hàm dựng; `:237` gọi
     `register_tools( $tools )` bên trong nó, nên các object tool tồn tại trước
     khi bất kỳ yêu cầu nào được phục vụ.
   - `includes/Core/McpServer.php:90` — `private McpComponentRegistry
     $component_registry`, không có getter công khai; `:371` `count_tools()` và
     `:403` `get_mcp_tool()` chỉ đọc.
   - `includes/Core/McpComponentRegistry.php:134` — `register_tools()`;
     `:147` `register_single_tool()`, `:182` `add_mcp_tool()` và `:238`
     `register_ability_tool()` là private; `:585` `get_tools()` dựng lại bản ghi
     cho schema của yêu cầu; `:673` `has_any_projection()` đi qua mọi bản
     protocol được hỗ trợ.
   - `includes/Core/McpVersionNegotiator.php:35-38` — hai bản protocol được hỗ
     trợ, nên mọi tool được chiếu hai lần.
   - `includes/Domain/Tools/McpTool.php:196` — `McpTool::fromAbility()`.
   - `includes/Handlers/Tools/ToolsHandler.php:66` — `list_tools()`, và `:84` áp
     filter `mcp_adapter_tools_list` lên các bản ghi **đã được chiếu**, không
     phải lên object `McpTool`.
   - Tìm toàn bộ cây `includes/` của dependency để tìm điểm vào dựng trì hoãn
     hoặc lười không trả về kết quả nào.
4. **Mọi phương án đều bị loại kèm lý do.**
   - Reflection lên `$component_registry` hoặc `add_mcp_tool()` phá đóng gói của
     dependency và phụ thuộc chi tiết private của nó.
   - Gọi `register_tools()` sau khi dựng là không thể: registry là private và
     không có getter công khai, nên cần reflection.
   - Thêm bản ghi trong `mcp_adapter_tools_list` không giúp được: filter nhận các
     bản ghi đã chiếu, và chi phí dựng ban đầu đã trả trước khi filter chạy.
   - Quảng bá ít tool hơn làm đổi hợp đồng `tools/list` và payload 33 191 byte
     logic.
   - Cache object tool bền vững sẽ lưu các object gắn với yêu cầu (ability,
     schema, quyền) ra ngoài yêu cầu của chúng.
   - Vá hoặc nâng cấp dependency nằm ngoài plugin và ngoài phạm vi phiếu này;
     dependency chỉ được đọc.
   - Dựng server muộn hơn `mcp_adapter_init` bị `create_server()` từ chối bằng
     `invalid_timing`, và route sẽ không được đăng ký.
   - Chỉ dựng server ở lần `tools/list` đầu bị cùng chốt chặn từ chối: route phải
     tồn tại từ `rest_api_init`.
5. **Không ngưỡng nào bị dời.** Sáu tên cổng, đơn vị và giá trị giữ đúng như
   trong `tests/E2E/benchmark.php:267-272` và trong mục 2 của
   `docs/en/operations/performance-slo.md`; quy tắc đo và ý nghĩa của
   `X-Xnetvn-Benchmark-Memory-Bytes` không đổi.

**Còn mở.** `list_http_p50_lt_50_ms` (historical, <50 ms>) là khoảng cách thật khoảng 2.6–2.8 ms mà
plugin không thể đóng trong khi adapter 0.7.0 dựng object tool trước khi phục vụ
yêu cầu. Đóng nó cần một thay đổi trong dependency cài riêng (dựng trì hoãn hoặc
chiếu theo nhu cầu) hoặc một quyết định rõ ràng của chủ dự án về ngưỡng. Cả hai
nằm ngoài phiếu này.

### 8.3 Vòng sở hữu ngày 2026-10-27

Phép đo cô lập thứ ba chạy lại benchmark wp69 cache-on ba lần tại revision
`53b272e96f36b85cea4734d9b4cb53a9821fe469`, với `tests/E2E/benchmark.php` sha256
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec`, cùng quy
trình `flock`/`trap` và cùng ngưỡng không đổi; run id là
`local-20261026T225819Z`, `local-20261026T225910Z` và `local-20261026T225952Z`.
Mọi vòng báo `request_errors: 0`, 60 tool hiển thị và 33 191 byte JSON logic.
Trung vị HTTPS đầy đủ là 53.465 / 53.374 / 53.359 ms, tức cao hơn ngưỡng gốc
`< 50 ms` là 3.465 / 3.374 / 3.359 ms, trong khi năm cổng còn lại đạt ở cả ba
vòng, nên bản chất khoảng cách không đổi và hơi lớn hơn 2.603–2.830 ms của mục
8.2. Vì một vòng trượt, ngưỡng giữ nguyên và ca đỏ vẫn được theo dõi: trong lúc
mở, `ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`
sở hữu cổng `list_http_p50_lt_50_ms` (historical, <50 ms>). Ba vòng dùng lại stack cô lập đang chạy
thay vì khởi động lại trước mỗi vòng, nhờ đó giữ cache PHP ấm và do đó có lợi cho
giá trị đo; một stack ấm mà vẫn trượt thì không thể che một lỗi khi nguội hơn.
Khoảng cách đo được, bằng chứng `path:line` của adapter và mọi phương án bị loại
ở mục 8.2 không đổi.

### 8.4 Chẩn đoán chỉ plugin ngày 2026-10-31

`ISSUE_v0.1_2026-10-27_04` hỏi một câu hẹp hơn mục 8.3: sau bước dựng của chính
adapter, chi phí nào còn nằm trong plugin và có thể gỡ mà không đổi sáu ngưỡng,
toolset hay payload được quảng bá? Ba vòng wp69 cache-on nữa được đo tại revision
`536873f1ad73f774f4cf0417b640ebb8247caeb4` (nền
`a4b283f14e07a0d13572efe70b2fbb04c2fc18aa`), với `tests/E2E/benchmark.php` sha256
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec` và adapter
`0.7.0`. Run id là `20261031T0602Z`; ba tệp báo cáo là `benchmark-child-1.json`
(sha256 `4e05f12fc046c15f4b1b256cb48e01ff226b27206c816a175fdf63a4f2443285`),
`benchmark-child-2.json` (sha256
`2cf503c713b318e700a670973907109ed5cadda47e0b74069df58ba6d6c7ac12`) và
`benchmark-child-3.json` (sha256
`3a41689346aa19b9991de9f833cfb26d6d2e33d16ee5757b70b2897ab9aa28af`).

Stack đã chạy sẵn và trả lời của chủ dự án ngày 2026-10-27 cho phép đo tuần tự mà
không cần `down`/`up`. `rtl.py plan` trả mã 2 vì lỗi đếm trùng bộ nhớ đã biết
(`2816 MB đang chạy + 2816 MB cho dự án này > 5120 MB`), không phải vì thiếu thật;
vì vậy stack không được triển khai lại. `rtl.py preflight` chỉ trả cảnh báo
firewall vốn có. Fixture cache thuộc sở hữu được bật trước mỗi vòng và tắt bởi
trap; sau vòng cuối cả `/sites/wp69/wp-content/object-cache.php` và
`/work/.local/cache-fixture/wp69/owned-dropin.sha256` đều vắng.

| Cổng | Ngưỡng | Lượt 1 | Lượt 2 | Lượt 3 | Phán quyết |
|---|---|---|---|---|---|
| `auth_median_lt_5_ms` | `< 5 ms` | đạt | đạt | đạt | Đạt (3/3) |
| `policy_median_lt_5_ms` | `< 5 ms` | đạt | đạt | đạt | Đạt (3/3) |
| `auth_policy_median_lt_5_ms` | `< 5 ms` | đạt | đạt | đạt | Đạt (3/3) |
| `list_dispatch_p50_lt_50_ms` | `< 50 ms` | 8.766199 ms | 8.795498 ms | 8.808198 ms | Đạt (3/3) |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | `< 50 ms` | 52.956692 ms | 53.112690 ms | 52.907789 ms | **Trượt (3/3)** |
| `memory_max_lt_8_mib` | `< 8 MiB` | đạt | đạt | đạt | Đạt (3/3) |

Mọi vòng báo `request_errors: 0`, `visible_tools: 60` và 33 191 byte JSON logic.
Khoảng cách tới ngưỡng HTTP là 2.956692, 3.112690 và 2.907789 ms. Dòng thời gian
p50 của lượt 1 là: `bootstrap_ms` 10.334969, `init_ms` 14.428139,
`pre_dispatch_ms` 40.772915, `dispatch_ms` 8.766199,
`phase_mcp_adapter_init_ms` 11.721398, `phase_wp_abilities_api_init_ms` 0.749500,
`phase_categories_init_ms` 0.109600, `auth_ms` 3.662600, `policy_ms` 0.659300,
`mu_load_ms` 7.200003 và bộ nhớ 6 173 232 B. Đây là giá trị pha tổng hợp, không
phải cách chia theo từng mẫu; các pha lồng nhau không bị cộng hai lần.

**Cái gì đã đo và cái gì chưa.** Việc dựng provider của chính plugin
(`src/Plugin.php:113-152`), việc dựng định nghĩa pack
(`src/Abilities/Registry.php:92-104`) và 120 lần đăng ký ability
(`src/Abilities/Registry.php:135-175`) không có timer riêng. Chúng nằm trong
`bootstrap_ms`, `init_ms` tổng hợp và trong hiệu giữa `pre_dispatch_ms` và
`init_ms`, cùng với WordPress core và adapter. Bước dựng adapter được đo riêng ở
11.72 ms; các pha abilities API và category được đo riêng ở 0.75 ms và 0.11 ms.
Các cửa sổ xác thực và chính sách được đo riêng. Phần còn lại thuộc plugin trong
cửa sổ 40.77 ms trước điều phối do đó là **không khả dụng**, không phải bằng không.

**Các thay đổi chỉ-plugin ứng viên.** Mỗi ứng viên là một giả thuyết cho một
phiếu sửa riêng; không ứng viên nào được hiện thực ở đây.

| Ứng viên | Bằng chứng | Tiết kiệm kỳ vọng (giả thuyết) | Bất biến và kiểm thử | Rủi ro |
|---|---|---|---|---|
| Chỉ dựng các pack mà phạm vi được trình thấy, sau khi có gợi ý phạm vi, thay vì dựng cả sáu pack ở `init` | `src/Abilities/Registry.php:92-104` dựng 120 định nghĩa; benchmark phơi 60 tool; `names_for_scope()` ở `:216-240` đã áp các điều khoản phạm vi | 0.5–1.5 ms | Khóa lạ/hỏng phải lùi về toàn danh mục; `allowed()` vẫn kiểm mọi tool trực tiếp; test danh mục ability, phạm vi và chính sách | Adapter cần các tên ở `mcp_adapter_init`; gợi ý phạm vi là thu hẹp chưa xác thực, không bao giờ là cấp quyền |
| Đăng ký category một lần cho mỗi toolset thay vì một lần cho mỗi định nghĩa | `src/Abilities/Registry.php:119-133` gọi `wp_has_ability_category()` cho mọi định nghĩa; chỉ có sáu slug toolset | 0.1–0.3 ms | Tập category và nhãn không đổi; contract test `wp_has_ability_category` | Một toolset động trong tương lai vẫn phải được đăng ký |
| Chỉ đăng ký các ability mà phạm vi thấy trên route MCP đã xác thực, có lùi về toàn danh mục | `src/Abilities/Registry.php:135-175` đăng ký 120; phạm vi phơi 60 | 0.2–0.4 ms | Lùi khi thiếu khóa/khóa lạ; metadata ability riêng tư và pipeline đầu ra không đổi; ma trận vai trò/phạm vi | Nếu khóa chưa được xác thực, gợi ý chỉ được thu hẹp |
| Trì hoãn việc dựng controller quản trị và `Pages` khi yêu cầu không phải quản trị | `src/Plugin.php:147-150` dựng `KeysController`, `AuditController`, `SettingsController` và `Pages` ở mọi yêu cầu | < 0.2 ms | Route cookie+nonce quản trị vẫn đăng ký trên yêu cầu quản trị; test route REST | Bỏ sót một hook quản trị trên route không quản trị |
| Gỡ `find_by_key_id()` trùng giữa `ServerGateway::scope_hint()` và `Authenticator::authenticate()` | `src/Mcp/ServerGateway.php:158-171` và `src/Auth/Authenticator.php:62-80` cùng đọc một khóa | không khuyến nghị | Sẽ cần cache theo yêu cầu cho khóa đã lưu; xử lý bí mật và thứ tự xác thực phải giữ chính xác | Cache một object dẫn xuất từ credential trước khi uỷ quyền là suy giảm bảo mật |

**Khối adapter.** 11.72 ms `phase_mcp_adapter_init_ms` là cùng ranh giới đã ghi ở
mục 8.2: adapter `0.7.0` không có API dựng trì hoãn công khai, `create_server()`
từ chối lời gọi ngoài `mcp_adapter_init`, và filter `mcp_adapter_tools_list` nhận
bản ghi đã được chiếu. Quảng bá ít tool hơn sẽ đổi payload; cache object bền vững
sẽ lưu object ability gắn với yêu cầu. Các phương án đó vẫn bị loại vì lý do ở
mục 8.2, không phải vì plugin "đang chờ thượng nguồn".

**Kết luận.** Khoảng cách đo được là 2.91–3.11 ms. Các ứng viên chỉ-plugin ở trên
khả dĩ nhưng đều nhỏ; cộng lại có thể vẫn không đóng được khoảng cách. Phần chưa
đo của cửa sổ 40.77 ms trước điều phối là bất định lớn nhất. Một phiếu sửa nên
hiện thực các ứng viên an toàn (pack theo phạm vi, khử trùng category, đăng ký
theo phạm vi thấy) cùng lúc lùi về toàn danh mục, rồi chạy lại đúng phép đo ba
vòng và các kiểm integration/HTTP. Nếu khoảng cách còn, bằng chứng chỉ vào bước
dựng của adapter và phần chưa đo trước điều phối; khi đó cần một phiếu gắn mã đo
riêng. STAB-03 vẫn chưa đạt và cổng vẫn do `ISSUE_v0.1_2026-10-27_02` sở hữu.

### 8.5 Vòng sở hữu ngày 2026-11-07

`ISSUE_v0.1_2026-10-27_02` đo lại cùng đường dẫn wp69 cache-on ba lần tại revision
`f4535c36a55391138682cc9942e783a9b0befde5`, với `tests/E2E/benchmark.php` sha256
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec`, adapter
`0.7.0` và các ngưỡng không đổi. Run id là `local-20261107T110959Z`,
`local-20261107T111037Z` và `local-20261107T111111Z`; ba tệp báo cáo có sha256
`175a09e41cf19060fe7674eb80af6e74959835f781ff1d5579e80b3e2add37ef`,
`3318b022a24506e9c3d6b9286d9649beaf3917623ba3e531d555d37cb8bce8d8` và
`7d3a4edea4910d1b0d1ea934a04241d29a70ebc10f4aabb6a1cce41d84d28f65`.

Mọi vòng đều `request_errors: 0` và 60 tool hiển thị. Trung vị HTTPS đầy đủ là
52.585 / 53.379 / 53.064 ms, tức 2.585 / 3.379 / 3.064 ms trên ngưỡng gốc
`< 50 ms`, trong khi năm cổng còn lại đạt ở cả ba vòng. Trung vị trước điều phối
là 40.787 / 41.085 / 40.756 ms, trung vị điều phối 8.378 / 8.898 / 8.851 ms và
trung vị khởi tạo adapter 11.840 / 11.851 / 11.839 ms, nên cách chia đã ghi ở mục
8.4 lặp lại được và khoảng cách không phải hiện tượng của một vòng.

Stack được dừng và triển khai lại trước các vòng thay vì dùng lại, và
`rtl.py preflight` báo `stale_firewall_bridges=0` sau khi phần dư firewall trên
host được dọn. `rtl.py plan` thoạt từ chối vì lỗi đếm trùng bộ nhớ đã biết
(`2816 MB running + 2816 MB for this project > 5120 MB`) do stack của chính dự án
đang chạy; dừng stack đó thì plan qua, nên lần triển khai hợp lệ và vòng đo là
khởi động nguội. Một vòng trượt nên nhánh B áp dụng: ngưỡng giữ nguyên và
`ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69` tiếp tục
sở hữu cổng `list_http_p50_lt_50_ms` (historical, <50 ms>).

### 8.6 Thay đổi yêu cầu ngày 2026-11-09

Chủ dự án đã cho phép hạ riêng yêu cầu p50 của HTTPS đầy đủ từ `< 50 ms` xuống
`< 55 ms`, hiệu lực 2026-11-09. Nguyên văn uỷ quyền là: "Bạn quyết STAB-03: hạ
ngưỡng". Khoá cổng hiện tại là `list_http_p50_lt_55_ms`; năm cổng còn lại, 100
mẫu, 10 mẫu làm nóng và phương pháp đo không đổi. Đây là thay đổi yêu cầu, không
phải cải thiện hiệu năng đo được, và không phán quyết cũ nào bị viết lại.

Năm campaign từ 2026-10-21 đến 2026-11-07 đo p50 HTTPS đầy đủ trong khoảng
52,39–53,465 ms với `request_errors: 0` và 60 tool hiển thị. Ba campaign gần nhất
có đủ giá trị từng vòng là 53,465 / 53,374 / 53,359 ms (2026-10-27),
52,957 / 53,113 / 52,908 ms (2026-10-31) và 52,585 / 53,379 / 53,064 ms
(2026-11-07); mọi hàng trước đây trong tài liệu này giữ phán quyết
`historical, <50 ms>`.

Ba vòng wp69 cache-on mới tại revision nhánh `4921d66` (khoá benchmark đổi ở
`0389505`) đo p50 52,834670 / 53,161070 / 53,275170 ms và p95 54,781869 /
55,193170 / 54,897070 ms, với `request_errors: 0` và 60 tool hiển thị ở mọi
vòng. Ba vòng thấp hơn ngân sách 55 ms mới là 2,165330 / 1,838930 / 1,724830 ms
và cao hơn ngân sách 50 ms lịch sử là 2,834670 / 3,161070 / 3,275170 ms; mọi p50
từ 50 ms trở lên được ghi là cảnh báo không chặn, không phải lỗi. Ba tệp báo cáo
vòng là `benchmark-child-1.json` (sha256
`7bf53289ad46a7348428542f23e0328fd9821e9d52ad2ea21b20acf1fe1bab5a`),
`benchmark-child-2.json` (sha256
`edc267b4fff8ba6cea9662811214a38d44fddc27a5632b9d87e26ee2060cec2c`) và
`benchmark-child-3.json` (sha256
`6fe0426b53c4354d368406d2ce0affc650f4e2b7c139e658e9a05906ba174aeb`).

Ngoại lệ STAB-03 đã được gỡ khỏi bộ kiểm nghiệm thu sau ba vòng đó. Campaign
nghiệm thu hai pha mới `role-seo-20261109T180500Z` hoàn tất ngày 2026-11-10 tại
fingerprint `1f383c341b5f41f55e7fc04fb9a4a48b82c2ffd492b89ae1805c794232fa8447`:
pha single đạt 423/424 dòng (0 lỗi, 0 bỏ qua, 1 không áp dụng) và pha network đạt
416/416 dòng, với mọi cờ cleanup đúng ở cả hai pha. Bộ kiểm trả `problems=[]` và
`open_gates.hit` rỗng, nên ACCEPT-03 đạt theo yêu cầu đã sửa. Báo cáo nghiệm thu
ghi bằng chứng campaign; không phán quyết cũ nào bị viết lại.

---

## 9. Hạn chế của phân tích này

- Cách chia giữa "sản phẩm" và "dependency" bên trong pha C và bên trong phần bộ
  nhớ của pha yêu cầu không được đo: tách 117 lần đăng ký ability của plugin khỏi
  abilities API của WordPress và adapter sẽ cần gắn mã đo trong `src/`, điều phiếu
  này không được chạm.
- Checkpoint 2026-10-05 không có mốc pha, nên không chia được 43.268 ms của nó
  ngoài cửa sổ điều phối; các con số còn lại được báo là "chưa giải thích" kèm
  giá trị chính xác.
- Probe P3 được quy định không dùng được làm cận dưới (mục 5); các mốc pha của
  mục 4 được dùng thay thế.
- Các con số là số của lab trên một container cố ý giới hạn (1 CPU, 512 MiB).
  Chúng không phải hồ sơ tải production và không phải tuyên bố năng lực.
- Các số liệu đồng thời là giới hạn bão hòa đo được của pha mười worker, không
  phải cam kết thông lượng.
