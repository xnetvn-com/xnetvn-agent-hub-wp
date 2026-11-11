# Báo cáo nghiệm thu skills MCP chuyên biệt

## 1. Thông tin tổng quan

- **Báo cáo:** nghiệm thu năm skill MCP, sáu pack ability và luồng bài SEO lưu giữ.
- **Dự án:** xNetVN Agent Hub for WordPress (`xnetvn-agent-hub-wp`), phiên bản mục tiêu 0.1.0.
- **Ngày:** 2026-10-27.
- **Người thực hiện:** `impl-s8-acceptance-20261027` (phiếu `ISSUE_v0.1_2026-10-21_12_nghiem-thu-skills-mcp-toan-dien-va-ban-giao-bai-seo-con`).
- **Revision nền:** `f6115574a7d8ebb1e2d2d83e989aa6290864f2dc` trên nhánh `issue/ISSUE_v0.1_2026-10-21_12_...`; campaign cuối chạy trên cùng cây sau khi sửa FX-08 và FX-06.
- **Namespace lab:** `xnetvn-agent-hub-wp`, single-site `compose.yaml`, WordPress 6.9 (`wp69`) và WordPress 7.1.3 (`wp71`, lab lệch nhãn 7.1.2).

## 2. Tóm tắt kết quả

| Tiêu chí | Kết quả | Căn cứ |
|---|---|---|
| ACCEPT-01 nền cấu trúc | **Đạt** | 22 tệp tài sản, checker `64/64`, `composer check` và `composer build` xanh |
| ACCEPT-02 hành vi model | **Bị chặn** | Không có harness Codex và không có quyền provider trong phiên này; 98 ca tồn tại nhưng không có trace model |
| ACCEPT-03 catalog, HTTP và pack | **Đạt theo yêu cầu đã sửa** | Cổng p50 HTTPS đầy đủ dùng ngân sách `< 55 ms` do chủ dự án uỷ quyền hiệu lực 2026-11-09; ngoại lệ STAB-03 đã được gỡ sau ba vòng hợp lệ (p50 52,834670 / 53,161070 / 53,275170 ms, 0 lỗi, 60 tool). Campaign `role-seo-20261109T180500Z` đạt `verify` với `problems=[]`, `open_gates.hit=[]`, pha single 423 đạt / 0 lỗi / 1 không áp dụng và pha network 416 đạt / 0 lỗi, mọi cờ cleanup đúng. Các hàng STAB-03 trước đây giữ làm lịch sử. |
| ACCEPT-04 read-back và browser | **Đạt** | Mỗi site một bài publish, ba ảnh, term và tác giả; browser RTL `15 passed` mỗi site |
| ACCEPT-05 lưu giữ, verify, reconcile | **Đạt** | `verify` và `reconcile` exit 0 cả hai site; giữ `post 1 / media 3 / terms 3 / users 2`; bài trial được liệt kê và giữ |
| ACCEPT-06 báo cáo EN/VI và review T3 | **Chờ** | Cặp báo cáo này đã có; review T3 độc lập và các mục mở bên dưới quyết định trạng thái cuối |

**Tổng kết:** nghiệm thu **không phải PASS đầy đủ**. Bốn tiêu chí đạt, ACCEPT-03 **đạt theo yêu cầu đã sửa** (ba vòng hợp lệ cộng campaign `role-seo-20261109T180500Z` với `problems=[]`), ACCEPT-02 bị chặn vì không có harness model, và ACCEPT-06 vẫn chờ review T3 độc lập. Báo cáo này không phải sign-off milestone bảo mật, không phải kết quả hosted CI, không phải kết quả real-client và không phải nghiệm thu production/phát hành.

**Cập nhật 2026-11-01:** lần chạy lại trên revision `cd60d68` **chưa đạt ACCEPT-03**: integration còn một ca stability đỏ và catalog wp71 có 57 ca đỏ trong một cửa sổ HTTP 503. Chi tiết ở mục 9; mục 3–8 giữ nguyên làm lịch sử campaign 2026-10-27.

**Cập nhật 2026-11-10:** chủ dự án đã cho phép hạ riêng yêu cầu p50 HTTPS đầy đủ từ `< 50 ms` xuống `< 55 ms`, hiệu lực 2026-11-09 (nguyên văn: "Bạn quyết STAB-03: hạ ngưỡng"). Ngoại lệ STAB-03 đã được gỡ sau ba vòng hợp lệ; mục 11 ghi bằng chứng và campaign đã hoàn tất. Mọi phép đo và phán quyết STAB-03 trước đây ở mục 3 và 10 giữ làm lịch sử với nhãn `historical, <50 ms>`.

## 3. Nội dung đã kiểm

### 3.1 Nền cấu trúc (ACCEPT-01)

| Phép kiểm | Lệnh | Kết quả |
|---|---|---|
| Cấu trúc và manifest tài sản | `python3 -I -B .agents/evals/check-test-debug-assets.py --stage all --self-test` | exit 0, `64/64 passed` |
| Manifest tài sản | `python3 -I -B .agents/evals/check-test-debug-assets.py --stage all --manifest` | exit 0, **22** tệp; checker sha256 `95bf0c9a34445c3b33447aeca82fd16e70efad0c371b5e8956d40bee876c9277` |
| Cổng dự án | `composer check` | exit 0: PHPCS sạch, cú pháp bootstrap PHP 7.2, PHPStan level 8 không baseline, **224 unit test / 50 962 khẳng định**, settings docs 30 khoá mỗi ngôn ngữ |
| Đóng gói | `composer build` | exit 0, `Release ZIP structure verified`, ZIP sha256 `14793ef800512b1e5b85f52f5d44c7da7a95b9ff5645cda81d3b600daf3bcf49` (theo lượt build: `zip` lưu mtime tệp nên hash không tái lập giữa các lượt) |

Kiểm cấu trúc chỉ chứng minh cấu trúc. Đây không phải bằng chứng runtime và không phải kết quả hành vi model.

### 3.2 Runtime single-site (ACCEPT-03)

| Phép kiểm | Lệnh | Kết quả |
|---|---|---|
| Integration WordPress 6.9 | `composer test:integration -- 6.9` | exit 0, **344 test / 8 051 khẳng định / 6 bỏ qua** |
| Integration WordPress 7.1.2 | `composer test:integration -- 7.1.2` | exit 0, **344 test / 8 051 khẳng định / 6 bỏ qua** |
| Integration multisite 7.1.2 | `XNETVN_TEST_MULTISITE=1 composer test:integration -- 7.1.2` | exit 0, **344 test / 8 077 khẳng định / 1 bỏ qua** |
| HTTP matrix | `composer test:http` | exit 0, **35/35 đạt, 0 lỗi** |
| Campaign nghiệm thu, phase single | `bash infra/remote/run-role-seo-acceptance.sh single role-seo-20261027T021126Z` | 422 pass, 0 fail, 0 skip, 1 không áp dụng, 1 gate mở |
| Campaign nghiệm thu, phase network | `bash infra/remote/run-role-seo-acceptance.sh network role-seo-20261027T021126Z` | 416 pass, 0 fail, 0 skip |
| Bộ verify campaign | `php tests/E2E/role-seo-acceptance.php verify --campaign role-seo-20261027T021126Z …` | exit 0, `problems=[]`, `open_gates.hit=["case:STAB-03@wp69"]` |

Tổng integration gồm 10 test mới từ hai tệp regression được merge trước campaign cuối (`RoleSeoAcceptanceGateTest`, `RoleSeoFixtureCronStabilityTest`); các số đo trước đó trong phiên là 334/7 990/6 và 334/8 016/1, không phải tổng cuối.

Sáu test bỏ qua là các skip phụ thuộc môi trường đã biết; nghiệm thu này không thêm skip nào. Lượt multisite giữ đúng một skip đã biết.

#### 3.2.1 Campaign nghiệm thu cuối

Campaign đầu `role-seo-20261026T205420Z` cho 424 dòng: **421 pass, 2 fail, 0 skip, 1 không áp dụng**. Hai ca đỏ là:

- `FX-08@wp71` — `internal_error` do runner so nguyên chuỗi counts (có `cron_digest`) thay vì counts sở hữu. Lỗi được ghi ở `BUG_v0.1_2026-10-27_01_...`; đây là lỗi runner test, không phải lỗi sản phẩm. Campaign được chạy lại sau khi sửa.
- `STAB-03@wp69` — chỉ gate `list_http_p50_lt_50_ms` (historical, <50 ms>) đỏ; năm gate benchmark còn lại đạt. Đây là vị trí lịch sử ngày 2026-10-27; mục 11 ghi yêu cầu đã sửa và bằng chứng của nó.

**Campaign cuối `role-seo-20261027T021126Z`:** phase single 424 dòng — **422 pass, 0 fail, 0 skip, 1 không áp dụng, 1 gate mở có khai**; cả 12 cleanup sở hữu true. Phase network **416/416 pass, 0 fail, 0 skip**; cả 9 cleanup sở hữu true. `verify` exit 0 với `problems=[]` và `open_gates.hit=["case:STAB-03@wp69"]`; gate được báo riêng và không tính là pass. Không required case nào bị skip và không ngưỡng nào bị nới.

Campaign đầu trên cùng cây lộ hai lỗi runner test, đều đã sửa trước lượt cuối và có phiếu riêng: FX-08 so nguyên chuỗi counts (`BUG_v0.1_2026-10-27_01_...`, sửa ở `089e25d`) và FX-06 cùng mẫu (`BUG_v0.1_2026-10-27_02_...`, sửa ở `7f77fa5`). Lượt thứ ba lộ flake của nhóm stability: một lượt `STAB-01`/`STAB-05` bị abort để lại hai ảnh đang giữ và ba bài trong trash, lượt sau kế thừa trạng thái đó. Hai attachment (5676–5677) và ba bài trash (5901–5903) đã được đối chiếu là tài nguyên nháp sở hữu và xoá; nhóm stability sau đó đạt 6/7 (chỉ `STAB-03` gate đỏ) và campaign cuối sạch. Điểm yếu cleanup-khi-lỗi được ghi là rủi ro còn lại, không phải lỗi sản phẩm.

### 3.3 Gate hiệu năng `STAB-03`

| Gate | Giá trị | Ngưỡng | Kết quả |
|---|---|---|---|
| `auth_median_lt_5_ms` | đạt | < 5 ms | Đạt |
| `policy_median_lt_5_ms` | đạt | < 5 ms | Đạt |
| `auth_policy_median_lt_5_ms` | đạt | < 5 ms | Đạt |
| `list_dispatch_p50_lt_50_ms` | đạt | < 50 ms | Đạt |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | 52,39–53,46 ms qua các lượt 2026-10-21/22 và 2026-10-27 | < 50 ms | **Không đạt** |
| `memory_max_lt_8_mib` | đạt | < 8 MiB | Đạt |

Khoảng trống còn lại là 2,39–3,46 ms. Các lượt 2026-10-27 đo trên stack ấm (điều kiện có lợi cho phép đo) và cho 53,464704 / 53,373806 / 53,358508 ms, đều false. Phân tích đo được ở `docs/en/operations/performance-analysis.md` mục 8.2 cho thấy phần lớn chi phí còn lại nằm ở dependency MCP adapter 0.7.0: adapter dựng 60 projection tool trước khi phục vụ và không có API dựng trễ; cửa sổ chính sách do plugin sở hữu đã chỉ còn 0,64–0,66 ms. Adapter 0.7.1 (2026-10-15) đã được kiểm chỉ đọc và cũng không thêm dựng trễ. Muốn đóng phải đổi dependency hoặc chủ dự án quyết định ngưỡng. Không ngưỡng và không phép đo nào bị đổi. Ngày 2026-10-27 chủ dự án trả lời câu hỏi mở bằng **phương án B**: giữ `< 50 ms` và để phiếu tiếp tục làm chủ gate tới khi upstream có dựng trễ; vì vậy phiếu vẫn mở và không được đánh `Hoàn thành`. Gate thuộc `ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`.

### 3.4 Catalog, network và pack

- **Ma trận catalog toàn bộ tool theo vai trò/blog (S5):** 62 tool, 595 dòng khai báo tool/vai trò/scope, 186 kết quả auth/target = 62×401 + 124×403 + 0×429, rate limit 11 → 429, nhóm A–H 11/11, tại revision `8bca2f6`. Mã plugin trên revision cuối giống hệt (`git diff 8bca2f6..HEAD -- src/` rỗng); vì vậy kết quả S5 được dùng lại và ghi rõ đây là giới hạn, không phải bằng chứng runtime mới.
- **Network (phase mới của campaign cuối):** 416/416 pass, 0 fail, 0 skip trên `network-main` và `network-child`, gồm bốn gate network và ma trận B–E; mọi cleanup sở hữu true. Stack được chuyển sang `compose.ui-network.yaml` (volume `ui_network_*` riêng), phase chạy trong cùng campaign, rồi chuyển lại `compose.yaml` và giữ volume.
- **HTTP matrix:** 35/35, gồm các probe method/route/auth/rate.

### 3.5 Bài lưu giữ và browser (ACCEPT-04)

| Site | Bài | URL | Tác giả | Term | Ảnh | Browser |
|---|---|---|---|---|---|---|
| wp69 | 4028 | `https://xnetvn-agent-hub-wp-wp69.192.168.2.215.sslip.io/huong-dan-xay-dung-bai-viet-wordpress-ro-rang-va-de-doc-sr20261026t153406z/` | 1015 (`author`) | category 597, tags 598–599 | featured 4025, inline 4026–4027 | `15 passed (14.2s)`; artifact đã fetch `.local/remote-test/20261026T214759Z` (`.last-run.json`: `passed`, không test lỗi) |
| wp71 | 1823 | `https://xnetvn-agent-hub-wp-wp71.192.168.2.215.sslip.io/huong-dan-xay-dung-bai-viet-wordpress-ro-rang-va-de-doc-sr20261026t153406z/` | 233 (`author`) | category 237, tags 238–239 | featured 1820, inline 1821–1822 | `15 passed (14.5s)`; artifact đã fetch `.local/remote-test/20261026T214834Z` (`.last-run.json`: `passed`, không test lỗi) |

Cả hai bài trả HTTP 200. Spec browser chỉ đọc: đọc handoff allowlist dưới `/work/.local/seo-review/`, mở permalink trên host lab, kiểm một H1, thứ tự H2/H3, đoạn văn, strong, list, link nội bộ, hai nguồn HTTPS, featured và hai ảnh inline có `naturalWidth > 0` kèm alt/caption, và taxonomy. Cả hai handoff ghi `revision: unknown` và `fingerprint: 6c448db8d1a72c3ce2689c1ef95b72d92b741c1d4fa6c4951e0a37f953beb8c2`; edit URL là `https://xnetvn-agent-hub-wp-wp69.192.168.2.215.sslip.io/wp-admin/post.php?post=4028&action=edit` và `https://xnetvn-agent-hub-wp-wp71.192.168.2.215.sslip.io/wp-admin/post.php?post=1823&action=edit`. Excerpt chỉ kiểm qua MCP vì theme không render. Không cài SEO plugin nên meta description, canonical, Open Graph và schema là **unsupported**, không phải PASS.

### 3.6 Lưu giữ và đối soát (ACCEPT-05)

| Phép kiểm | wp69 | wp71 |
|---|---|---|
| `run-seo-review.sh <site> review-20261026T153406Z verify` | exit 0, 13/13 check true | exit 0, 13/13 check true |
| `run-seo-review.sh <site> review-20261026T153406Z reconcile` | exit 0, reconciled true | exit 0, reconciled true |
| Trạng thái giữ | post 1, media 3, terms 3, users 2 | post 1, media 3, terms 3, users 2 |
| Dọn credential và temp | thu hồi 2 key, bỏ credential, bỏ temp | như trên |

Bài trial và bài lỗi được giữ và liệt kê, không xoá: wp69 4007, 4012, 4017, 4022, 4033, 4038; wp71 1818. Title do chủ dự án sửa trên 4022 và 4038 được giữ nguyên, không ghi đè. Luồng lưu giữ không dùng `wp_delete_post`, `wp_delete_attachment`, `wp_delete_user` hay `wp_delete_term`.

### 3.7 Hành vi model (ACCEPT-02)

`.agents/evals/test-debug-scenarios.json` định nghĩa **98** ca: I 11, J 27, K 24, R 12, M 12, P 12. Checker tĩnh chứng minh cấu trúc và bảng routing S7 (`J-16 → debugging`, `J-04/J-05/J-27 → none`) nhưng không chứng minh hành vi model. Phiên này không có client Codex, không có quyền provider cho lượt hành vi và không có trace recorder, nên ACCEPT-02 **bị chặn**. Phiếu yêu cầu trace model thật kèm review rubric độc lập; đọc thủ công hoặc checker xanh không thay thế được.

## 4. Chưa xác minh

- Hosted CI, MCP client thật, host production, phát hành gói và phân phối WordPress.org.
- Metadata SEO plugin (meta description, canonical, Open Graph, schema, thứ hạng). Lab không cài SEO plugin.
- Trace định tuyến/từ chối của model (ACCEPT-02).
- Ma trận catalog trên đúng revision cuối; dùng lại từ S5 trên mã plugin không đổi.
- Mức vá `wp71`: lab báo WordPress **7.1.3** trong khi nhãn compose và nhiều phiếu ghi 7.1.2. Bốn ca network và hành vi MCP không phụ thuộc mức vá, nhưng drift được ghi lại.
- Thay đổi `REVIEWED_FIXTURES` trong checkout dùng chung `private-local-udev` đang có trong working tree nhưng **chưa commit** vì kho đó cần root context của chủ dự án. Cổng dự án đọc working tree; bản clone mới sẽ không thấy thay đổi cho tới khi chủ dự án commit.
- `docs/vi/templates/*` vẫn lệch khối guide/gate hiện hành (drift có sẵn; `issue_tool.py templates` báo 4 mẫu cần cập nhật).

## 5. Rủi ro và giới hạn

1. **Cổng HTTPS đầy đủ đã sửa.** `list_http_p50_lt_50_ms` (historical, <50 ms>) còn cao hơn ngưỡng gốc 2,6–3,5 ms trên wp69 tại vị trí 2026-10-27; plugin không đóng được khi adapter 0.7.0 dựng tool projection sớm, và 0.7.1 không thay đổi điều đó. Lúc đó ngưỡng không bị nới và chủ dự án chọn giữ gate mở. Ngày 2026-11-09 chủ dự án cho phép yêu cầu `< 55 ms` đã sửa, và ngoại lệ được gỡ sau ba vòng hợp lệ; mục 11 ghi campaign hai pha đã hoàn tất `role-seo-20261109T180500Z` đáp ứng yêu cầu đã sửa.
2. **Runner test có flake.** FX-08 và FX-06 so chuỗi counts có `cron_digest`; cả hai đã sửa (`089e25d`, `7f77fa5`) và phiếu tương ứng `Hoàn thành`. Một lượt stability bị abort vẫn có thể để lại tài nguyên nháp sở hữu (một lượt để lại hai ảnh đang giữ và ba bài trong trash); lượt sau kế thừa trạng thái và đỏ. Các tài nguyên sở hữu đã được nhận theo marker và xoá; lượt sạch sau đó đạt. Điểm yếu cleanup-khi-lỗi là rủi ro harness còn lại, không phải lỗi sản phẩm. Tám lượt fixture trong tám phút cũng có thể bão hoà cửa sổ xác thực mỗi phút; nghiệm thu chạy từng phase một và không claim khả năng lặp sát nhau.
3. **Quy mô lab.** Mọi số đo đến từ một container giới hạn (1 CPU, 512 MiB) trên server dùng chung. Đây không phải hồ sơ tải production, không phải cam kết dung lượng hay uptime.
4. **Ma trận dùng lại.** Bằng chứng catalog và network đến từ campaign trước trên revision có mã không đổi. Đây là giới hạn đã ghi; nghiệm thu tương lai nên chạy lại cả hai trên đúng revision của nó.
5. **Dữ liệu lưu giữ.** Bài, ảnh, term và tác giả nằm trên volume lab. Chỉ chủ dự án mới được yêu cầu xoá; báo cáo và nghiệm thu này không xoá tài nguyên lưu giữ.

## 6. Cách tái lập

```bash
# Nền cấu trúc
python3 -I -B .agents/evals/check-test-debug-assets.py --stage all --self-test
python3 -I -B .agents/evals/check-test-debug-assets.py --stage all --manifest
composer check
composer build

# Suite local và lab
composer test:integration -- 6.9
composer test:integration -- 7.1.2
XNETVN_TEST_MULTISITE=1 composer test:integration -- 7.1.2
composer test:http

# Campaign nghiệm thu (lab transition sở hữu stack)
RTL_AGENT=<agent> python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec \
  --compose-file compose.yaml toolbox -- bash /work/infra/remote/run-role-seo-acceptance.sh single role-seo-YYYYMMDDTHHMMSSZ

# Kiểm bài lưu giữ
RTL_AGENT=<agent> python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec \
  --compose-file compose.yaml toolbox -- bash /work/infra/remote/run-seo-review.sh wp69 review-20261026T153406Z verify
RTL_AGENT=<agent> python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e \
  --base-url https://xnetvn-agent-hub-wp-wp69.192.168.2.215.sslip.io -- \
  sh -c 'export XNETVN_SEO_REVIEW_HANDOFF=/work/.local/seo-review/wp69/review-20261026T153406Z/handoff.json; npx playwright test tests/E2E/seo-review.spec.js --config=tests/E2E/playwright.remote.config.js'
```

Không chạy suite phá huỷ trên tài nguyên review đang giữ, và không dùng `--volumes`, purge hay prune trên lab dùng chung.

## 7. Bàn giao và lưu giữ

- Bài: wp69 post 4028, wp71 post 1823. Bài trial: wp69 4007, 4012, 4017, 4022, 4033, 4038; wp71 1818.
- Media: wp69 4025–4027, 4030–4032, 4035–4037; wp71 1820–1822. Term: wp69 597–605, wp71 237–239. Tác giả: wp69 1015, 1017, 1019; wp71 233.
- Journal: `/work/.local/seo-review/private/<target>/review-20261026T153406Z/journal.json` trên lab.
- Website vẫn mở cho chủ dự án xem; không tài nguyên lưu giữ nào bị xoá hay gán lại.

## 8. Việc nên làm tiếp

1. Sửa `BUG_v0.1_2026-10-27_03_...` (stability abort để lại tài nguyên nháp, lượt sau kế thừa) và chạy lại nhóm stability.
2. Đóng hoặc giữ `STAB-03` trung thực: tại vị trí 2026-10-27 chủ dự án chọn giữ gate mở và giữ ngưỡng `< 50 ms` (historical, <50 ms>) tới khi upstream có dựng trễ. Thay đổi yêu cầu ngày 2026-11-09 thay thế bước này; mục 11 ghi lại.
3. Cấp harness Codex/provider và chạy 98 ca hành vi kèm review trace độc lập (ACCEPT-02).
4. Chạy lại catalog và network trên đúng revision cuối trước mọi tuyên bố PASS đầy đủ.
5. Chủ dự án commit thay đổi `REVIEWED_FIXTURES` dùng chung trong `private-local-udev` khi thoả guard root context.

## 9. Lần chạy lại 2026-11-01 (revision `cd60d68`)

- **Người thực hiện:** `opencode-s8-acceptance-20261101`, nhánh `issue/ISSUE_v0.1_2026-10-21_12_nghiem-thu-skills-mcp-toan-dien-va-ban-giao-bai-seo-con`.
- **Revision nền:** `cd60d68fadcc3c7b791c17c8afcf1bc55b7a5b9e`; cây sạch trước khi sửa phiếu. `git diff 8bca2f6..HEAD -- src/` và `git diff 0ed7047..HEAD -- src/` đều rỗng, nên mã plugin bằng campaign S5/S6; thay đổi chỉ nằm ở `tests/`, `infra/` và tài liệu.
- **Môi trường:** RTL single-site `compose.yaml`, `wp69` WordPress 6.9, `wp71` WordPress 7.1.3 (nhãn compose vẫn 7.1.2). Preflight exit 1: chỉ cảnh báo bridge firewall toàn server, tài nguyên đủ (10 GiB RAM trống, 213 GiB đĩa). Lease của worktree `27-04-stab03-diagnosis-r2` đã hết hạn nên được tiếp quản khi sync.
- **Fingerprint đầu vào:** commit trên; `tests/E2E/catalog-role-matrix.php` sha256 `4eb728fa9d929ca72bfeaa8380f319a193e3f255054f79c759eda304951f62e0`; `infra/remote/run-catalog-role.sh` sha256 `f7ba04993110d4922507705b923235f26317deca8852709f686d44e665db6b8d`; manifest tài sản ignored (22 tệp) sha256 `35645abb838df60322c9304aea2c882a10c77437b5de01e01c72450b83e0df2b`.

| Phép kiểm | Lệnh / campaign | Kết quả |
|---|---|---|
| Cấu trúc tài sản | `check-test-debug-assets.py --stage all --self-test` | exit 0, `64/64 passed` |
| Cấu trúc client | `check-client-assets.py --self-test` | exit 0, `13/13 passed` |
| Cổng dự án | `composer check` | exit 0, **253 unit test / 51 029 khẳng định**, PHPStan L8, docs 30 khoá |
| Đóng gói | `composer test:packaging`, `composer build` | exit 0; ZIP sha256 `d1ea14e3e04d5bb1e8833234b5e4d2b9608cce0174a3d2ffa8f507a500a1b377` (theo lượt build) |
| Hành vi offline | `run-mcp-behavior-evals.py --run` + `--verify`, run `mcp-behavior-s8-20261101T031138Z` | 98/98 record `success`; verify `problems=[]`. Đây là controller/FAKE offline, **không phải** hành vi model; rubric T3 độc lập chưa chấm |
| HTTP matrix | `composer test:http` | 35/35 case true, exit 0 |
| Integration 6.9 (lệnh tài liệu) | `composer test:integration -- 6.9` | **exit 1**: 344 test, **11 fail**, 6 skip. Toàn bộ 11 ca `RoleSeoStabilityTest` fail ở `XNETVN_MATRIX_SITE`/`XNETVN_ROLE_SEO_RUN_ID` vì wrapper không truyền biến |
| Integration 6.9/7.1.2 (đặt biến trong toolbox) | `bash /work/infra/remote/run-integration.sh <version>` | cả hai **exit 1**: 344 test / 8 109 khẳng định / **1 fail** / 6 skip, cùng ca `test_stability_phase_leaves_nothing_behind_after_a_cron_drift` |
| Catalog wp69 | `run-catalog-role.sh wp69 cat-20261101T031743Z` | **0 fail**: 595 khai báo, 528 chạy, 67 N/A có lý do, 62 tool, 843 request, 10/10 gate true; cleanup + reconcile true; audit 646 dòng, `bad=0` |
| Catalog wp71 | `run-catalog-role.sh wp71 cat-20261101T040828Z` | **57 fail**: 530 chạy, 65 N/A, `matrix=false`, `counts_stable=false`; 50 `internal_error` HTTP 503 + 7 `unavailable`, tập trung ở index 28–87; cleanup + reconcile true (15 user/key, 64 post, 15 term, 11 media, 18 comment); audit 575 dòng, `bad=0` |
| Bài lưu giữ | `run-seo-review.sh <site> review-20261026T153406Z verify` và `reconcile` | cả hai site exit 0, **13/13 check true**, giữ `post 1 / media 3 / terms 3 / users 2`; key thu hồi, credential/temp đã xoá |
| Browser | `seo-review.spec.js` qua RTL, 3 engine | wp69 `15 passed` (artifact `20261101T050212Z`), wp71 `15 passed` (`20261101T050233Z`) |
| Network | `compose.ui-network.yaml` | **Không chạy**: campaign đã đỏ ở single-site nên dừng theo hợp đồng, không chuyển stack |

**Ca stability đỏ (tái hiện trực tiếp):** `role-seo-stability.php phase-cleanup` với run `s8pc1` trả `abort_observed=false`, `abort_ids_absent=false`, `workers_closed=false`, `barrier_idle=true`, `settings_restored=true`, `drift_case_clean=true`, `failed_case_clean=true`, mọi `leftover_* = 0`. Lượt trước để lại ba thư mục rỗng `s8ac69-a/b/c` (không có `state.json`, không có object WP); đã đối soát và `rmdir` đúng ba thư mục sở hữu. Lớp lỗi này từng được huỷ trong `BUG_v0.1_2026-10-27_03` vì “không tái hiện”; tại revision này nó **tái hiện được** trên cả 6.9 và 7.1.3.

**Ca wp71 HTTP 503:** container wp71 không restart (uptime 2 ngày), log không có fatal/OOM, `matrix.stderr` rỗng; cùng mã plugin và cùng ma trận đã xanh trên wp71 ngày 2026-10-26. Vì vậy bằng chứng nghiêng về một cửa sổ mất sẵn sàng tạm thời trong lúc seed/ma trận, **chưa kết luận nguyên nhân**. Theo hợp đồng, campaign giữ **chưa đạt** và không chạy lại để “cho xanh”.

**Kết luận lần chạy lại:** ACCEPT-01 đạt; ACCEPT-02 chỉ đạt phần controller offline, còn thiếu review rubric độc lập; ACCEPT-03 **chưa đạt**; ACCEPT-04 và ACCEPT-05 đạt lại trên đúng bài lưu giữ; ACCEPT-06 chờ reviewer T3. Bài, ảnh, term và tác giả vẫn nguyên trên volume lab.

**Việc cần làm tiếp của lần chạy lại:** (1) mở lại phiếu sửa cho `phase-cleanup`/abort của stability và chạy lại nhóm này; (2) bổ sung `XNETVN_MATRIX_SITE` và `XNETVN_ROLE_SEO_RUN_ID` vào wrapper hoặc tài liệu integration; (3) điều tra cửa sổ 503 trên wp71 rồi chạy lại đúng scope với campaign mới; (4) chạy network sau khi các đỏ trên đóng.

## 10. Nghiệm thu lại ngày 2026-11-09 (revision `037d289`)

**Vì sao có mục này.** Mục 9 kết thúc với ba điểm đỏ và một phần chưa chạy: ca stability drift đỏ trên cả hai suite, catalog wp71 có một khối ca `internal_error`, và ma trận network chưa từng chạy vì luồng dừng ở điểm đỏ đầu tiên. Mục này ghi một bộ campaign mới tại revision `037d289eb257ed77b9acf47d53725409048c505d` và việc đóng từng điểm đỏ.

- **Ca stability.** Lần chạy lại 2026-11-01 còn một ca `test_stability_phase_leaves_nothing_behind_after_a_cron_drift` đỏ, cộng mười một ca `RoleSeoStabilityTest` đỏ khi thiếu `XNETVN_MATRIX_SITE` và `XNETVN_ROLE_SEO_RUN_ID`, nên ca fail ngay trong `set_up()` trước mọi mutation. Sau khi sửa lab ngày 2026-11-07, cùng ca đó đạt khi cấp đủ hai biến: `run-integration.sh 6.9` và `7.1.2` mỗi bên trả 354 test / 8 232 khẳng định / **0 fail** / 6 bỏ qua. Không sửa sản phẩm, test, ngưỡng hay fixture nào, và `BUG_v0.1_2026-10-27_03` vẫn Cancelled.
- **`internal_error` trên wp71.** Nguyên nhân là xung đột định danh của harness, không phải lỗi sản phẩm: `tests/E2E/catalog-role-matrix.php` coi mọi số nguyên trong payload thuộc dải dành riêng 5000–5999 là định danh fixture logic rồi ghi lại qua ledger, trong khi định danh bài thật của wp71 đã lớn vào đúng dải đó (mục 9 ghi 57 ca; lượt chạy lại 2026-11-07 ghi 95 ca). Harness nay chỉ resolve marker logic tường minh và để mọi số nguyên khác đi qua nguyên vẹn; wp71 xanh trong campaign bên dưới.
- **Network.** Khi các campaign single-site đã xanh, luồng chuyển sang `compose.ui-network.yaml` và chạy phase network trên cả hai blog; `network-main` xanh trong bảng bên dưới và hàng blog con được ghi trong bảng bên dưới.

| Hạng mục | Campaign / lệnh | Kết quả |
|---|---|---|
| Catalog wp69 | `run-catalog-role.sh wp69 cat-20261109T031959Z` | **0 fail**: 595 khai báo, 528 chạy, 67 N/A có lý do, 62 tool, 843 request; gate 10/10 true; cleanup đã phục hồi, reconcile 0 |
| Catalog wp71 | `run-catalog-role.sh wp71 cat-20261109T040535Z` | **0 fail**: 595 khai báo, 530 chạy, 65 N/A có lý do, 849 request; gate 10/10 true |
| Network main | `run-catalog-role.sh network-main cat-20261109T044825Z` | **0 fail**: 595 khai báo, 594 chạy, 1 N/A có lý do, 983 request; `gates.matrix=true`, 10/10 gate true |
| Network child | `run-catalog-role.sh network-child cat-20261109T102043Z` | **0 fail**: 595 khai báo, 594 chạy, 1 N/A có lý do, 983 request; `gates.matrix=true`, 10/10 gate true; blog 2 |
| Integration 6.9 | `run-integration.sh 6.9` | **0 fail**: 354 test / 8 232 khẳng định / 6 bỏ qua |
| Integration 7.1.2 | `run-integration.sh 7.1.2` | **0 fail**: 354 test / 8 232 khẳng định / 6 bỏ qua |
| Controller offline | `run-mcp-behavior-evals.py --run` + `--verify` + `--review`, run `mcp-behavior-20261109T103826Z` | 98/98 bản ghi `success` (chỉ trạng thái thực thi); `--verify` `problems=[]`; records sha256 `b4a8ba79ce1e0fdf3e42566614f7341c62fe9d4dd6fae737bdb4e90c308f067b`; corpus sha256 `2660a3fa…`; rubric `7 PASS / 59 FAIL / 32 UNKNOWN`. Chỉ transport FAKE offline, **không phải** hành vi model |
| Nền cấu trúc | `check-test-debug-assets.py --stage all --self-test` và `--manifest`, `check-client-assets.py --self-test`, `composer check`, `composer build`, `bin/verify-zip.sh` | checker tài sản 64/64; manifest 22 tệp; client 20/20; `composer check` exit 0 (**269 unit test / 51 070 khẳng định**); `composer build` và `verify-zip` exit 0, ZIP sha256 `b0256c9e8ce87dd2f583d88edbcd63393441fee3dc128c62015749df42fe4cf3` (theo lượt build) |
| Bài lưu giữ | `run-seo-review.sh <site> review-20261026T153406Z verify` | wp69 post 4028 và wp71 post 1823: **13/13 check true**, `post 1 / media 3 / terms 3 / users 2`, tác giả không đổi, không xoá hay gán lại |

**Nguyên nhân các cửa sổ 503.** Các cửa sổ 503 làm gián đoạn bước seed và ma trận là do WordPress tự động cập nhật trong chế độ bảo trì. Debug log của site ghi `[08-Nov-2026 04:38:51]` và `[09-Nov-2026 03:14:41]` ở hai biên của các cửa sổ đó, và cùng revision cùng fixture đều xanh ngoài các cửa sổ ấy. Đây là sự kiện môi trường, không phải lỗi sản phẩm hay harness: không ngưỡng, test hay fixture nào bị đổi, và các campaign trên là lượt chạy mới, không phải chạy lại để phủ xanh một lượt đã đỏ.

**Nguồn gốc số liệu.** Mọi campaign của bộ này mang `XNETVN_MATRIX_REVISION=037d289…`, và cả bốn campaign báo cùng matrix hash `07d4d07ce9a0b312ea9fe65318fb3cb3241b3ae0ef1a33f97b1f9b905613d411`, nên bốn báo cáo campaign mô tả một cây và một harness, không phải trộn revision.

**Kết luận.** ACCEPT-01 đạt. ACCEPT-04 và ACCEPT-05 đạt trên bài lưu giữ. ACCEPT-06 được mục này cập nhật và vẫn chờ review T3 độc lập. ACCEPT-02 **không đạt**: rubric độc lập chấm 98/98 ca thành 7 PASS / 59 FAIL / 32 UNKNOWN (`--review` import với `problems=[]`; `review.json` sha256 `c499892d56b55c403fe999ded40279d66e1055402eefad2abf53bd1a0bba6a98`, `verdicts.json` sha256 `e68f6df2cfb9d820bced75b3948a7995174a5dcab4fbec595f7f63c720e074ca`). Nguyên nhân nằm ở chính phương pháp offline: transport FAKE chỉ route theo `mode`, nên toàn bộ 33 ca chuyên biệt R/M/P và bảy ca debugging nhận `xnetvn-mcp-testing` thay vì `role-security` / `media-testing` / `seo-publishing` / `debugging` của rubric; 10 ca thiếu một refusal bắt buộc, 6 ca đề xuất một lượt chạy bị cấm hoặc mâu thuẫn, và 32 trace là mẫu chung. Harness không được phát ra đáp án kỳ vọng, nên tự chấm không thể sửa được điều này; báo cáo không claim hành vi model và giới hạn FAKE vẫn giữ. ACCEPT-03 **vẫn chưa đạt toàn phần** dù catalog và network xanh, vì `list_http_p50_lt_50_ms` (historical, <50 ms>) còn mở ở 52,585 / 53,379 / 53,064 ms so với `< 50 ms`, thuộc `ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`. Đây là phán quyết ngày 2026-11-09 trước thay đổi yêu cầu; mục 11 ghi yêu cầu đã sửa. Vì vậy mục này không claim PASS đầy đủ.

## 11. Thay đổi yêu cầu và bằng chứng cổng mới (2026-11-10)

Chủ dự án đã cho phép hạ riêng yêu cầu p50 HTTPS đầy đủ từ `< 50 ms` xuống
`< 55 ms`, hiệu lực 2026-11-09. Nguyên văn uỷ quyền là: "Bạn quyết STAB-03: hạ
ngưỡng". Khoá cổng hiện tại là `list_http_p50_lt_55_ms`; năm cổng còn lại, 100
mẫu, 10 mẫu làm nóng, phương pháp đo và adapter pin không đổi. Đây là thay đổi
yêu cầu, không phải cải thiện hiệu năng đo được.

**Ba vòng wp69 cache-on hợp lệ** chạy tại revision nhánh `4921d66` (khoá benchmark
đổi ở `0389505`) với fixture cache thuộc sở hữu được bật:

| Vòng | p50 (ms) | p95 (ms) | `request_errors` | `visible_tools` | Cổng | Chênh vs 50 ms | Biên vs 55 ms |
|---|---:|---:|---:|---:|---|---:|---:|
| 1 | 52,834670 | 54,781869 | 0 | 60 | Đạt | +2,834670 | 2,165330 |
| 2 | 53,161070 | 55,193170 | 0 | 60 | Đạt | +3,161070 | 1,838930 |
| 3 | 53,275170 | 54,897070 | 0 | 60 | Đạt | +3,275170 | 1,724830 |

Mọi p50 đều từ 50 ms trở lên, nên mọi vòng mang cảnh báo không chặn
`p50 >= 50 ms`; cảnh báo không làm trượt cổng đã sửa. sha256 báo cáo:
`7bf53289ad46a7348428542f23e0328fd9821e9d52ad2ea21b20acf1fe1bab5a`,
`edc267b4fff8ba6cea9662811214a38d44fddc27a5632b9d87e26ee2060cec2c` và
`6fe0426b53c4354d368406d2ce0affc650f4e2b7c139e658e9a05906ba174aeb`.

**Gỡ ngoại lệ.** Mục `case`/`STAB-03`/`wp69` được gỡ khỏi
`xnetvn_agent_hub_wp_role_seo_acceptance_open_gates()` sau ba vòng đó.
`composer test:integration -- 6.9 --filter RoleSeoAcceptanceGateTest` đạt 5 test /
14 assertion, và `php tests/E2E/role-seo-acceptance.php selftest` đạt cả 23 kiểm
trong một bản sao sạch của cây; cùng lệnh trong worktree này trượt
`prepare-creates-the-manifest` vì `.local` là symlink tới kho dùng chung và guard
sở hữu từ chối thành phần symlink. Ca STAB-03 vẫn ở suite stability.

**Trạng thái campaign: đạt.** Campaign hai pha mới
`role-seo-20261109T180500Z` chạy dưới một campaign id trên fingerprint sau thay
đổi `1f383c341b5f41f55e7fc04fb9a4a48b82c2ffd492b89ae1805c794232fa8447`.
Pha single đạt 423/424 dòng (0 lỗi, 0 bỏ qua, 1 không áp dụng) và pha network đạt
416/416 dòng, với mọi cờ cleanup đúng ở cả hai pha. Bộ kiểm trả `problems=[]`,
`open_gates.hit=[]` và `ok=true`; `case:STAB-03@wp69` vắng mặt. Bằng chứng đối
soát cần thiết là các cờ cleanup đó cộng hai danh sách lỗi và cổng rỗng, vì CLI
không có chế độ `reconcile` riêng. Vì vậy ACCEPT-03 **đạt theo yêu cầu đã sửa**,
và mọi phép đo và phán quyết STAB-03 trước đây ở trên giữ làm lịch sử với
`historical, <50 ms>`.
