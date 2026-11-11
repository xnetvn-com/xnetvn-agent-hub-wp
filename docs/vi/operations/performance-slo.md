<!--
  Copyright (c) 2026 xNetVN Inc.
  Website: https://xnetvn.com/
  License: Apache License 2.0 (https://www.apache.org/licenses/LICENSE-2.0)
  Contact: license@xnetvn.net
-->

# SLO hiệu năng MCP (lab cô lập)

> **Quyết định (tạm thời, chỉ để báo cáo): các cổng benchmark gốc vẫn là ngưỡng
> tham chiếu cho wp69 cache-on, và cả số liệu lab dùng chung lẫn lab cô lập đều
> được ghi lại để so sánh.**
>
> Quyết định này **không giải quyết** cảnh báo STAB-03 nêu trong
> `ISSUE_v0.1_2026-10-16_06`. Lượt cô lập ngày 2026-10-20 tái hiện đúng bốn
> cổng trượt (2/6 cổng đạt trên wp69), nên giả thuyết nhiễu từ lab dùng chung
> **không** được bằng chứng ủng hộ và STAB-03 vẫn mở. Số liệu lab dùng chung là
> số gián tiếp lấy từ dòng kết quả STAB-03 của phiếu đó và không được đo lại ở
> đây (mục 3); bằng chứng độc lập là lượt chạy cô lập.
>
> Không ngưỡng nào trong phiếu đó hay trong `tests/E2E/benchmark.php` bị đổi ở đây.
>
> **Cập nhật 2026-10-20:** một điều tra cô lập trên cùng baseline lặp lại phép
> đo wp69 cache-on ba lần, quy mọi lần trượt về một chi phí sản phẩm kèm con số,
> và **giữ nguyên cả sáu cổng, ngưỡng và quy tắc đo của chúng**. Phương pháp,
> cách chia pha một yêu cầu, cơ chế bộ nhớ, so sánh với checkpoint 2026-10-05 và
> phán quyết từng cổng nằm ở [performance-analysis.md](performance-analysis.md).
> STAB-03 vẫn mở vì bốn cổng vẫn trượt.
>
> **Thay đổi yêu cầu (hiệu lực 2026-11-09):** chủ dự án đã cho phép hạ riêng
> yêu cầu p50 của HTTPS đầy đủ từ `< 50 ms` xuống `< 55 ms`; nguyên văn uỷ
> quyền là "Bạn quyết STAB-03: hạ ngưỡng". Khoá cổng hiện tại là
> `list_http_p50_lt_55_ms`. Đây là thay đổi yêu cầu, không phải cải thiện hiệu
> năng: phương pháp đo, 100 mẫu, 10 mẫu làm nóng và năm cổng còn lại không
> đổi. Mọi phép đo và phán quyết trước đó giữ nhãn `historical, <50 ms>`.
> Ngoại lệ STAB-03 đã được gỡ khỏi bộ kiểm nghiệm thu sau khi ba vòng hợp lệ
> đạt cổng mới ngày 2026-11-10.

---

## 1. Phạm vi

Các SLO này áp cho luồng MCP/HTTPS thật trên **wp69 cache-on** tại baseline đã ghim:

| Hạng mục baseline | Giá trị đã ghim |
|---|---|
| WordPress | 6.9 |
| PHP | 8.2.x |
| Adapter | 0.7.0 |
| Cache bền vững | Bật (Redis 8.10.2 / Redis Object Cache 3.0.0 / Predis); người gọi bật fixture cache thuộc sở hữu bằng `infra/remote/cache-fixture.sh enable wp69` trước khi chạy, vì `infra/remote/run-benchmark.sh` không tự bật cache |
| Runner | `infra/remote/run-benchmark.sh` nguyên bản, không chạy suite song song trong lúc đo |

Tài liệu này chỉ bao phép đo wp69 cache-on ngày **2026-10-20**. Checkpoint baseline
đã đóng băng ngày **2026-10-05** trong
[architecture/performance.md](../architecture/performance.md) (artifact số liệu
[docs/en/reports/M1-60-tools.json](../../en/reports/M1-60-tools.json)) là
**một lượt đo khác** trên cùng baseline đã ghim, và tài liệu này **không** thay
thế nó. Hai lượt khác nhau ở phán quyết: checkpoint 2026-10-05 đạt 4/6 cổng gốc
(policy p50 3.714 ms, HTTP p50 55.868 ms, bộ nhớ đỉnh 5.254 MiB), còn lượt
2026-10-20 đạt 2/6 (mục 3). Cả hai được giữ nguyên: không bên nào thay bên kia
cho tới khi giải thích được khoảng cách giữa chúng.

## 2. Bảng SLO theo đích

Các ngưỡng dưới đây là ngân sách nghiệm thu hiện tại của
`tests/E2E/benchmark.php`. Năm cổng gốc không đổi. Cổng p50 HTTPS đầy đủ dùng
yêu cầu `< 55 ms` đã được chủ dự án cho phép ngày 2026-11-09 (nguyên văn ở đầu
tài liệu). Giá trị `< 55 ms` là biên kỹ thuật trên khoảng đo được
52,39–53,465 ms, không phải cải thiện hiệu năng. Mọi phép đo và phán quyết lịch
sử ở mục 3 giữ nhãn `historical, <50 ms>`.

| Cổng (khóa benchmark) | Chỉ số | Ngưỡng | Áp cho | Cách đo |
|---|---|---|---|---|
| `auth_median_lt_5_ms` | p50 của `auth_ms` | < 5 ms | wp69 cache-on | Mục 4 |
| `policy_median_lt_5_ms` | p50 của `policy_ms` | < 5 ms | wp69 cache-on | Mục 4 |
| `auth_policy_median_lt_5_ms` | p50 của `auth_policy_ms` | < 5 ms | wp69 cache-on | Mục 4 |
| `list_dispatch_p50_lt_50_ms` | p50 của `dispatch_ms` | < 50 ms | wp69 cache-on | Mục 4 |
| `list_http_p50_lt_55_ms` | p50 của `http_ms` | < 55 ms | wp69 cache-on | Mục 4 |
| `memory_max_lt_8_mib` | max của `memory_bytes` | < 8 MiB | wp69 cache-on | Mục 4 |

Các đích khác ngoài wp69 cache-on chỉ được đo; chúng **chưa có SLO**:

| Đích | Trạng thái |
|---|---|
| role-flow | Chỉ đo, chưa có SLO |
| multisite | Chỉ đo, chưa có SLO |
| cache-off | Chỉ đo, chưa có SLO |

Ví dụ cách đọc: `list_http_p50_lt_55_ms` áp cho wp69 cache-on; role-flow chỉ được đo và chưa có SLO.

## 3. Độ trễ quan sát: lab dùng chung so với lab cô lập (chỉ báo cáo)

Cả hai cột đều là **quan sát chỉ để báo cáo, không phải cam kết**.

Cột lab dùng chung là số **gián tiếp**: lấy từ dòng kết quả STAB-03 của
`ISSUE_v0.1_2026-10-16_06` tại commit `75598bf` (và `bc1eb13` trước đó), dòng mà
lần sửa `ccec893` ngày 2026-10-20 đã thay bằng cổng boolean và phán quyết 2/6.
Dòng đó ghi lượt wp69 trên lab dùng chung `run-20261018010657-21233` với
`request_errors: 0`, auth p50 3.82 ms, policy p50 5.62 ms, auth+policy p50
9.43 ms, dispatch p50 14.13 ms, HTTP valid p50 78.71 ms và bộ nhớ đỉnh 8 685 952
byte (8.28 MiB); cột trên là dòng đó đã làm tròn. Phiếu này **không** đo lại các
số đó, nên chúng chỉ để báo cáo và không được đọc như phép đo của phiếu này.

Cột lab cô lập lấy từ hai lượt cô lập ngày 2026-10-20 ghi ở mục 4.1, kèm run id
và đường dẫn artifact trên server.

| Chỉ số | Đơn vị | Cách đo | Lab dùng chung (gián tiếp, từ _06 tại `75598bf`) | Lab cô lập (2026-10-20) | Lab cô lập (vòng sản phẩm 2026-10-21) | Lab cô lập (vòng memo capability 2026-10-22) | Cổng gốc | Kết quả cổng cô lập |
|---|---|---|---|---|---|---|---|---|
| policy p50 | ms | p50 của `policy_ms`, 100 mẫu hợp lệ | 5.6 | 5.68 / 5.67 | 3.35 / 3.36 / 3.35 | 0.656 / 0.644 / 0.645 | < 5 ms | Trượt (2026-10-20), **Đạt (2026-10-21)** |
| auth+policy p50 | ms | p50 của `auth_ms + policy_ms`, 100 mẫu hợp lệ | 9.4 | 9.51 / 9.50 | 7.06 / 7.10 / 7.08 | 4.385 / 4.362 / 4.364 | < 5 ms | Trượt (2026-10-20, 2026-10-21), **Đạt (2026-10-22)** |
| HTTP p50 (hợp lệ) | ms | p50 của `http_ms`, 100 mẫu hợp lệ | 78.7 | 79.42 / 79.31 | 55.78 / 55.91 / 55.87 | 52.780 / 52.830 / 52.603 | < 50 ms (historical, <50 ms>) | Trượt (mọi vòng) |
| Bộ nhớ đỉnh (hợp lệ) | MiB | max của `memory_bytes` (đỉnh trừ nền MU-plugin) | 8.28 | 8.284 / 8.284 | 5.884 / 5.884 / 5.885 | 5.884 / 5.884 / 5.884 | < 8 MiB | Trượt (2026-10-20), **Đạt (2026-10-21)** |

Giá trị hỗ trợ của cùng các lượt cô lập (không phải cổng riêng):

| Chỉ số | Đơn vị | Lượt cô lập 1 | Lượt cô lập 2 | Lab cô lập (vòng memo capability 2026-10-22) | Cổng gốc | Kết quả cổng |
|---|---|---|---|---|---|---|
| auth p50 | ms | 3.84 | 3.83 | 3.718 / 3.708 / 3.718 | < 5 ms | Đạt (cả hai lượt) |
| dispatch p50 | ms | 14.22 | 14.21 | 8.291 / 8.267 / 8.287 | < 50 ms | Đạt (cả hai lượt) |
| `request_errors` | số lượng | 0 | 0 | 0 / 0 / 0 | = 0 | Đạt (cả hai lượt) |
| `wrong_unknown_p50_ratio` | tỷ lệ | 1.037 | 1.029 | 0.994 / 1.013 / 1.002 | không | Chỉ để tham khảo |

**Vòng sản phẩm 2026-10-21.** Các phép đo cô lập của
`ISSUE_v0.1_2026-10-21_01` chạy cùng harness, cùng quy trình cô lập và cùng
ngưỡng không đổi trên cùng lab; run id là `perf-f1-20261021-0445z`,
`perf-f2-20261021-0445z` và `perf-f3-20261021-0445z`, kèm một vòng baseline
trước thay đổi `perf-base-20261021-0400z` đo cùng ngày. Mọi lượt đều báo
`request_errors: 0`, 60 tool hiển thị và 33 191 byte JSON logic, nên payload
quảng bá, số tool và kết quả từ chối không đổi. Thay đổi loại bỏ việc dựng sẵn
adapter MCP server trong mỗi yêu cầu REST và việc đọc lặp cài đặt site theo từng
tool; nguyên nhân gốc đo được và hai lần trượt còn lại nằm ở
[operations/performance-analysis.md](performance-analysis.md) mục 3.1, 3.2 và 8.1.

**Vòng memo capability 2026-10-22.** Các phép đo cô lập của
`ISSUE_v0.1_2026-10-21_04` chạy cùng harness, cùng quy trình cô lập và cùng
ngưỡng không đổi trên cùng lab; run id là `local-20261022T055048Z`,
`local-20261022T055256Z` và `local-20261022T055457Z`, kèm bốn vòng trước thay
đổi trong cùng ngày (`local-20261022T045956Z` … `local-20261022T051346Z`) đo trên
cùng stack. Mọi lượt đều báo `request_errors: 0`, 60 tool hiển thị và 33 191
byte JSON logic, nên payload quảng bá, số tool và kết quả từ chối không đổi. Một
quyết định capability trực tiếp theo từng cặp user, capability và object trong
một lượt danh sách tool được quảng bá đưa trung vị chính sách về 0.644–0.656 ms
và trung vị xác thực cộng chính sách về 4.362–4.385 ms, nên trung vị kết hợp đạt
ngưỡng gốc lần đầu. Trung vị HTTPS đầy đủ vẫn ở 52.603–52.830 ms, cao hơn ngưỡng
gốc 2.603–2.830 ms; khoảng cách đo được, bằng chứng `path:line` rằng adapter
0.7.0 không có API dựng trì hoãn và mọi phương án bị loại đều ở
[operations/performance-analysis.md](performance-analysis.md) mục 3.3 và 8.2.

**Vòng sở hữu STAB-03 ngày 2026-10-27.** Phép đo cô lập thứ ba trên cùng lab
lặp lại lượt wp69 cache-on ba lần tại revision
`53b272e96f36b85cea4734d9b4cb53a9821fe469`, với `tests/E2E/benchmark.php` sha256
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec`, cùng quy
trình `flock`/`trap` và cùng ngưỡng không đổi; run id là
`local-20261026T225819Z`, `local-20261026T225910Z` và `local-20261026T225952Z`.
Mọi vòng đều báo `request_errors: 0`, 60 tool hiển thị và 33 191 byte JSON logic,
nên payload quảng bá, số tool và kết quả từ chối không đổi. Trung vị HTTPS đầy
đủ là 53.465 / 53.374 / 53.359 ms, tức cao hơn ngưỡng gốc `< 50 ms` là
3.465 / 3.374 / 3.359 ms, trong khi năm cổng còn lại đạt ở cả ba vòng: bản chất
khoảng cách không đổi và hơi lớn hơn 2.603–2.830 ms của vòng memo capability. Vì
một vòng trượt, ngưỡng giữ nguyên và ca đỏ vẫn được theo dõi: trong lúc mở,
`ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`
sở hữu cổng `list_http_p50_lt_50_ms` (`historical, <50 ms>`). Ba vòng dùng lại stack cô lập đang chạy
thay vì khởi động lại trước mỗi vòng, nhờ đó giữ cache PHP ấm và do đó có lợi cho
giá trị đo; một stack ấm mà vẫn trượt thì không thể che một lỗi khi nguội hơn.
Sáu ngưỡng của mục 2 và phương pháp của mục 4 không đổi.

**Chẩn đoán chỉ plugin ngày 2026-10-31.** Phép đo cô lập thứ tư chạy cùng quy
trình wp69 cache-on ba lần tại revision `536873f1ad73f774f4cf0417b640ebb8247caeb4`,
với cùng sha256 benchmark
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec` và adapter
`0.7.0`; run id là `20261031T0602Z`. Mọi vòng đều báo `request_errors: 0`, 60
tool hiển thị và 33 191 byte JSON logic. Trung vị HTTPS đầy đủ là
52.957 / 53.113 / 52.908 ms, tức cao hơn ngưỡng gốc 2.957 / 3.113 / 2.908 ms,
trong khi năm cổng còn lại đạt ở cả ba vòng. Riêng bước dựng adapter tốn
11.72 ms; các phương án chỉ-plugin (pack theo scope, khử trùng lặp category,
đăng ký theo scope hiển thị, trì hoãn provider quản trị) và phần chia
pre-dispatch chưa đo nằm ở [performance-analysis.md](performance-analysis.md)
mục 8.4. Không ngưỡng, phương pháp, toolset hay runtime nào bị đổi. Cổng vẫn do
`ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`
sở hữu, và việc sửa cần một phiếu riêng.

**Lượt sở hữu ngày 2026-11-07.** Phép đo cô lập thứ năm chạy lại cùng quy
trình wp69 cache-on ba lần tại revision
`f4535c36a55391138682cc9942e783a9b0befde5`, với cùng sha256 benchmark
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec` và adapter
`0.7.0`; run id là `local-20261107T110959Z`, `local-20261107T111037Z` và
`local-20261107T111111Z`. Mọi vòng báo `request_errors: 0` và 60 tool hiển thị.
Trung vị HTTPS đầy đủ là 52.585 / 53.379 / 53.064 ms, tức 2.585 / 3.379 /
3.064 ms trên ngưỡng gốc `< 50 ms` (historical, <50 ms>), trong khi năm cổng
còn lại đạt ở cả ba vòng. Stack được dừng và triển khai lại trước các vòng thay
vì dùng lại, nên số liệu là khởi động nguội; `rtl.py preflight` báo
`stale_firewall_bridges=0`. Không ngưỡng, phương pháp, toolset hay runtime nào
bị đổi. Cổng vẫn do
`ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69` sở
hữu cho tới khi một thay đổi riêng đóng khoảng cách.

**Lượt cô lập cho thấy gì.** Phép đo cô lập nằm trong **≤1,5%** so với số lab
dùng chung (chênh lệch tương đối lớn nhất: 1,49% ở policy p50) và cho cùng phán
quyết: 2/6 cổng đạt (`auth_median_lt_5_ms`, `list_dispatch_p50_lt_50_ms`), còn
`policy_median_lt_5_ms`, `auth_policy_median_lt_5_ms`, `list_http_p50_lt_50_ms` (`historical, <50 ms>`)
và `memory_max_lt_8_mib` trượt. Hai lượt cô lập liên tiếp khớp nhau trong 0,02 ms
ở policy và auth+policy p50 và trong 0,11 ms ở HTTP p50, nên khoảng cách tái lập
được và không phải mẫu một lần.

Do đó:

- Giả thuyết lab dùng chung gây ra các lần trượt STAB-03 **không** được lượt cô
  lập xác nhận.
- STAB-03 **không** được đóng bởi tài liệu này. Các cổng trượt là khoảng cách
  thật giữa bản build hiện tại và ngưỡng gốc, và đóng nó cần hoặc một thay đổi
  sản phẩm hoặc một quyết định rõ ràng của chủ dự án về ngưỡng. Cả hai nằm ngoài
  phạm vi tài liệu này.
- Vì lượt cô lập không đạt ngưỡng gốc, các ngưỡng ở mục 2 là **bộ tham chiếu mà
  bản build hiện tại chưa đáp ứng**, và quyết định trên vẫn tạm thời cho tới khi
  khoảng cách đó được đóng.
- Vòng sản phẩm 2026-10-21 đóng hai trong bốn khoảng cách (trung vị chính sách và
  đỉnh bộ nhớ) mà không dời ngưỡng; hai khoảng cách còn lại đã được đo và quy
  nguyên nhân ở [performance-analysis.md](performance-analysis.md) mục 8.1.
- Vòng memo capability 2026-10-22 đóng thêm một khoảng cách (trung vị xác thực
  cộng chính sách) mà không dời ngưỡng, nên năm trong sáu cổng đạt trong lab.
  Khoảng cách cuối, trung vị HTTPS đầy đủ, cao hơn ngưỡng 2.603–2.830 ms và được
  quy cho adapter cài riêng ở [performance-analysis.md](performance-analysis.md)
  mục 8.2; vì vậy STAB-03 vẫn mở.

### 3.1 Xác nhận ngày 2026-10-20 (thêm ba lượt cô lập)

Phép đo cô lập thứ hai trên cùng baseline, ngày 2026-10-20 UTC, lặp lại lượt
wp69 cache-on ba lần với cùng quy trình và cùng cách cô lập, và tái hiện đúng
phán quyết: trung vị xác thực và dispatch vẫn dưới ngưỡng, trong khi trung vị
chính sách, trung vị xác thực cộng chính sách, trung vị HTTPS đầy đủ và đỉnh bộ
nhớ vẫn trên ngưỡng. Ba lượt khớp nhau trong 0,047 ms ở trung vị chính sách,
0,814 ms ở trung vị HTTPS đầy đủ và 2 624 byte ở đỉnh bộ nhớ, và khớp hai lượt
ngày 2026-10-20 trong 0,05 ms, 0,99 ms và 0,004 MiB. Giả thuyết nhiễu từ lab
dùng chung do đó bị bác.

Điều tra đó cũng chia một yêu cầu thành các pha (khởi động core, tải plugin, tải
ability pack, cửa sổ giữa `init` và `rest_pre_dispatch`, điều phối REST, xả và
truyền phản hồi) và chia đỉnh bộ nhớ thành phần khởi động và phần yêu cầu, một
lần theo bộ nhớ giữ lại và một lần theo đỉnh đo được. 59,9 % yêu cầu nằm trong
cửa sổ nơi plugin đăng ký ability và adapter dựng server, và 81,5 % đỉnh bộ nhớ
được cấp phát trong lúc xử lý yêu cầu; tầng truyền tải đóng góp 0 ms thời gian
kết nối và 0,816 ms truyền phản hồi. Cách chia pha, cơ chế bộ nhớ, so sánh với
checkpoint 2026-10-05 và phán quyết từng cổng nằm ở
[performance-analysis.md](performance-analysis.md). Không cổng, ngưỡng hay quy
tắc đo nào bị đổi.

Nguồn nhiễu mà lượt cô lập đã loại, và nguồn chưa loại:

| Nguồn nhiễu nghi ngờ | Trạng thái sau lượt cô lập |
|---|---|
| Suite song song của dự án này | Đã loại: không suite nào khác giữ khóa wp69 hay lease lab trong lúc đo, và chỉ một dự án chạy trên lab |
| Tranh chấp cache dùng chung giữa các lượt | Đã loại: fixture cache thuộc sở hữu được bật và tắt quanh mỗi lượt, không lượt nào khác đang chạy |
| Cron ghi lại `option` bởi core ngoài suite | Chưa loại: fixture giữ `settings_digest` và `cron_digest` để so drift, và bản thân drift không được lượt này cô lập |
| Tải kiểu production | Không có: lab là một stack test duy nhất, nên các số này không phải hồ sơ tải production |

## 4. Phương pháp đo

1. **Người gọi** bật fixture cache thuộc sở hữu bằng
   `infra/remote/cache-fixture.sh enable wp69` —
   `infra/remote/run-benchmark.sh` không bật cache, nó chỉ từ chối chạy khi thiếu
   cache bền vững — rồi chạy `infra/remote/run-benchmark.sh` nguyên bản trên
   wp69, và tắt lại bằng `infra/remote/cache-fixture.sh disable wp69`; không chạy
   suite nào khác trong cùng namespace lab trong lúc đo.
2. Benchmark làm nóng 10 mẫu mỗi ca, rồi ghi 100 mẫu cho mỗi loại `valid`,
   `unknown`, `wrong`, cộng 10 worker đồng thời (`tests/E2E/benchmark.php`).
3. Báo p50 của từng chuỗi độ trễ, max của `memory_bytes` (đỉnh trừ nền MU-plugin),
   `wrong_unknown_p50_ratio` và `request_errors`.
4. Phán quyết SLO hợp lệ yêu cầu `request_errors` bằng 0 và đủ sáu cổng trong
   JSON báo cáo; chỉ số thiếu được báo là không khả dụng, không bao giờ điền 0.

### 4.1 Nguồn gốc phép đo cô lập ngày 2026-10-20

| Hạng mục | Giá trị |
|---|---|
| Đo lúc (UTC) | lượt 1: 2026-10-20T03:04:26Z → 03:05:13Z; lượt 2: 2026-10-20T03:05:35Z → 03:06:22Z |
| Run id | `slo-20261020T030426-7294`, `slo-20261020T030535-7419` |
| Lệnh | `RTL_AGENT=opencode python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --compose-file compose.yaml toolbox -- bash -c "<cache-enable → run-benchmark.sh → cache-disable wrapper>"` |
| Hành vi wrapper | **Người gọi** (không phải script benchmark) bật fixture cache thuộc sở hữu: `infra/remote/cache-fixture.sh enable wp69` → `infra/remote/run-benchmark.sh` (nguyên bản) → `infra/remote/cache-fixture.sh disable wp69`, với trap `EXIT` tắt fixture cache, và `flock` độc quyền trên khóa wp69 mà `infra/remote/run-role-seo.sh` dùng để không suite nào của dự án này chạy song song — đúng bước người gọi ở mục 4, bước 1 |
| Mã thoát | `rtl.py exec` thoát 0; script benchmark thoát 0; bật cache thoát 0; tắt cache thoát 0 |
| Artifact báo cáo (server) | `/work/.local/slo-20261020/slo-20261020T030426-7294/benchmark.json` và `/work/.local/slo-20261020/slo-20261020T030535-7419/benchmark.json` (vùng bị git bỏ qua) |
| Namespace lab | `rtl-xnetvn-agent-hub-wp` (một namespace cho dự án này; 5/5 container khoẻ) |
| Môi trường | Ubuntu 24.04.4 LTS, 6 CPU, tổng 11 908 MB RAM, còn khoảng 10 044 MB, 217 GB đĩa trống, Docker 29.4.1, Compose 5.1.3; load average 0,30 trước lượt 1 và 0,70 sau đó; load average 0,90 trước lượt 2 và 3,68 sau đó |
| Bằng chứng cô lập | `rtl.py preflight` báo `running_projects=1`; `rtl.py projects` liệt kê dự án này là namespace đang chạy duy nhất; `rtl.py lease` do worktree này giữ suốt phép đo; không suite nào khác đang chạy |
| Runtime được đo | WordPress 6.9, PHP 8.2.34, adapter 0.7.0, Redis 8.10.2 / Redis Object Cache 3.0.0 / Predis, 60 tool hiển thị |
| Revision | `caebb65` (`merge(issue-06): stability follow-up fixes and campaign evidence`); sha256 của `tests/E2E/benchmark.php`, `tests/E2E/benchmark-mu-plugin.php`, `tests/E2E/http-fixture.php`, `infra/remote/run-benchmark.sh`, `infra/remote/run-wp.sh`, `infra/remote/cache-fixture.sh`, `xnetvn-agent-hub-wp.php` và mọi tệp trong `src/` được xác minh giống nhau giữa checkout đo và server trước khi chạy |

## 5. Quay lui

Vị trí quay lui là: **giữ các cổng gốc và chỉ ghi số liệu lab dùng chung**. Không
ngưỡng mới nào thay một cổng trong `tests/E2E/benchmark.php` (dòng 260-265) vì
nhiễu từ lab dùng chung. Vị trí này là lịch sử: thay đổi yêu cầu riêng ngày
2026-11-09 ở đầu tài liệu chỉ hạ ngân sách p50 HTTPS đầy đủ và không phải phản
ứng với nhiễu từ lab dùng chung.

Các việc bị cấm:

- Sửa bất kỳ ngưỡng nào trong `ISSUE_v0.1_2026-10-16_06`.
- Sửa bất kỳ cổng nào trong `tests/E2E/benchmark.php` (kể cả dòng 260-265) để
  một cổng đạt.
- Đọc số liệu lab dùng chung như cam kết tải production.
- Đọc số liệu lab dùng chung ở mục 3 như phép đo của phiếu này: chúng là số gián
  tiếp từ `_06` và **không** được đo lại ở đây.
- Đọc số liệu lab cô lập ở mục 3 như bằng chứng STAB-03 đã đóng, hoặc như hồ sơ
  tải production.
