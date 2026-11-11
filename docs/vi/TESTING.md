# Hướng Dẫn Kiểm Thử & Đảm Bảo Chất Lượng (Testing Guide)

> **Tài liệu hướng dẫn quy trình kiểm thử toàn diện, phương pháp TDD, ma trận kiểm thử và công cụ tự động hóa của xNetVN Agent Hub WP.**

---

## 1. Chiến Lược Kiểm Thử (Testing Strategy & Pyramid)

Dự án áp dụng mô hình Kim Tự Tháp Kiểm Thử (Test Pyramid) nghiêm ngặt với phương châm: **Kiểm thử là công dân hạng nhất (First-Class Citizen) của mã nguồn**.

```
          /\
         /  \     End-to-End Tests (10%)
        /    \    - Playwright Browser Tests (Admin UI, WCAG 2.2 AA)
       /──────\   - Real MCP Client Execution (Claude Desktop, etc.)
      /        \
     /          \  Integration Tests (20%)
    /            \ - WP-PHPUnit với WordPress 6.9 & 7.1.2
   /──────────────\ - Multisite, MySQL Transaction, REST Gate
  /                \
 /                  \ Unit Tests (70%)
/                    \- PHPUnit 9.6, Brain Monkey, Mockery
/──────────────────────\- Pure Domain, Crypto, Policy, Contracts
```

- **Mục tiêu Code Coverage (được ép bởi `.github/scripts/CoverageGate.php`, ADR-0019):**
  - Tối thiểu **90%** coverage cho các tầng cốt lõi `Domain`, `Auth`, `Policy`.
  - Tối thiểu **80%** coverage gộp toàn dự án cho mọi pull request, trừ pull request chỉ đổi tài liệu khớp danh sách cho phép rõ ràng; các pull request đó được miễn thu thập coverage và không bao giờ tuyên bố có số đo.
  - Tối thiểu **70%** cho từng suite integration và **90%** cho `unit.Domain` và `unit.PureAuth`.
  - Không số đo nào được thấp hơn baseline đã ghi tại `.github/coverage/M1-lines.json`.
  - Suite unit không có sàn tổng tuyệt đối; đây là một trong năm suite, còn các suite integration mới chạy runtime WordPress.
- **Tiêu chuẩn kiểm thử:**
  - Không có cảnh báo hoặc lỗi nào bị bỏ qua.
  - Phân tích tĩnh: **PHPStan Level 8 không baseline** (0 lỗi).
  - Định dạng chuẩn WordPress: **PHPCS WordPress-Extra + WordPress-Core + WordPress-Docs**.
  - Tương thích phiên bản: **PHPCompatibilityWP** (kiểm tra PHP 7.2 cho tệp Bootstrap, PHP 8.2 cho toàn bộ plugin).

---

## 2. Các Bộ Kiểm Thử (Test Suites)

### 2.1 Unit Tests (Kiểm Thử Đơn Vị)

- **Vị trí:** `tests/Unit/`
- **Công cụ:** PHPUnit 9.6 + Brain Monkey + Mockery.
- **Mục đích:** Kiểm tra logic nghiệp vụ thuần túy, tính toán mật mã (KeyGenerator, KeyHasher, Pepper), phân giải quyền hạn (TierResolver, Policy), xác thực định dạng token và mô phỏng lỗi.
- **Lệnh chạy:**
  ```bash
  composer test:unit
  ```

### 2.2 Integration Tests (Kiểm Thử Tích Hợp)

- **Vị trí:** `tests/Integration/`
- **Công cụ:** `wp-phpunit/wp-phpunit` trên cơ sở dữ liệu WordPress thực tế.
- **Mục đích:**
  - Kiểm tra vòng đời khởi động của plugin (`PluginBootTest.php`).
  - Kiểm tra tương tác cơ sở dữ liệu thực với bảng `$wpdb` (`PersistenceTest.php`).
  - Kiểm tra giao dịch luân chuyển khóa nguyên tử trên MySQL InnoDB (`RotationAtomicityTest.php`).
  - Kiểm tra cơ chế khóa chống xung đột (`WpOwnerLock`).
  - Kiểm tra môi trường đơn site (Single site) và đa site (Multisite Network, `MultisiteTest.php`).
  - Kiểm tra xuất và xóa dữ liệu cá nhân theo GDPR (`AuditPrivacyTest.php`).
- **Lệnh chạy (Remote Test Lab `toolbox`; chạy đơn site rồi multisite):**
  ```bash
  composer test:integration
  XNETVN_TEST_MULTISITE=0 composer test:integration
  XNETVN_TEST_MULTISITE=1 composer test:integration
  ```
  `composer test:integration` chạy `bin/test-integration.sh`, ủy thác cho `toolbox` của lab ngoài CI. Xem thiết lập lab ở §4.

### 2.3 Contract Tests (Kiểm Thử Hợp Đồng)

- **Vị trí:** `tests/Contract/`
- **Mục đích:** Đảm bảo các gói chức năng (Ability Packs) luôn tuân thủ nghiêm ngặt giao ước `AbilityPack` và `AbilitySpec`.
- **Lớp kiểm tra mẫu:** `PackContractTestCase.php`.

### 2.4 End-to-End & Accessibility Tests (E2E & WCAG)

- **Vị trí:** `tests/E2E/`
- **Công cụ:** Playwright Test Runner + `@axe-core/playwright`.
- **Mục đích:**
  - Kiểm tra giao diện người dùng trên trình duyệt thật: Quản lý khóa API tại trang Profile và Admin Dashboard.
  - Mục tiêu **WCAG 2.2 Level AA**: Tương phản màu, điều hướng bàn phím, nhãn form cho trình đọc màn hình. Kết quả axe chỉ là tín hiệu, không tự nó chứng minh tuân thủ WCAG (xem §5).
  - Kiểm tra kết nối thực tế từ các MCP Client mẫu (`McpClient.php`, `real-clients.py`).
- **Lệnh chạy:**
  ```bash
  npm run test:e2e
  ```
  Dạng lab của lệnh này ở §4. Chạy từng site một với `--base-url "$UI_BASE_URL"`; lặp cho site thứ hai. `UI_BASE_URL` là địa chỉ HTTPS từ handoff của lab (không phải URL production, không commit).

### 2.5 Packaging & Plugin Check Tests

- **Vị trí:** `tests/Packaging/` & `bin/`
- **Mục đích:**
  - Đóng gói file `.zip` phát hành sản phẩm và xác minh danh sách tệp không chứa mã thừa, development dependencies hoặc file rác.
  - Chạy công cụ kiểm tra tiêu chuẩn WordPress chính thức (`Plugin Check`).
- **Lệnh chạy:**
  ```bash
  composer test:packaging
  ```

### 2.6 Network and HTTP Matrix Tests (Kiểm Thử Mạng và Ma Trận HTTP)

- **Vị trí:** `bin/test-network.sh`, `infra/remote/run-network.sh`, `infra/remote/run-http-matrix.sh`, `tests/E2E/http-matrix.php`.
- **Mục đích:** Chạy nhánh multisite/network và ma trận HTTP bảo mật (Origin, TLS, auth, rate limit) trên stack lab.
- **Lệnh chạy (Remote Test Lab `toolbox`):**
  ```bash
  composer test:network
  composer test:http
  ```

### 2.6.1 Kiểm Thử Vòng Đời Bình Luận (Giữ Lại)

- **Vị trí:** `infra/remote/run-comments-review.sh`, `tests/E2E/comments-review.php` và `tests/E2E/comments-review-fixture.php`.
- **Mục đích:** Chạy mười ability bình luận qua MCP HTTPS thật: tạo, đọc, liệt kê, đếm, trả lời, sửa, duyệt, hold, spam, unspam, trash, untrash, bulk moderation, xóa và purge.
- **Giữ lại:** `wp69` giữ bình luận review ở approved, hold, spam và trash và không gọi purge; pha CMT-09 chỉ audit read-only và xóa sentinel đích danh. `wp71` khôi phục mọi review spam/trash, audit lại ngay trước từng lệnh purge và chặn purge khi còn review, sentinel hoặc ứng viên lạ. Chỉ bình luận disposable được đánh dấu rõ mới được xóa; journal ghi từng lần xóa có chủ đích.
- **Lệnh chạy (Remote Test Lab `toolbox`):**
  ```bash
  bash /work/infra/remote/run-comments-review.sh wp69
  bash /work/infra/remote/run-comments-review.sh wp71
  ```
  Chạy `wp69` trước `wp71`. Trong toolbox lab, báo cáo nằm ở `/work/.local/comments-review/<site>/<run_id>/`; bản trên máy host ở `.local/comments-review/<site>/<run_id>/`.
- **Giới hạn:** Bộ này không thay thế các cổng nghiệm thu multisite, rate-limit, Origin/TLS, real-client hoặc production.

### 2.7 Ánh Xạ Bộ UI (Bao Phủ Luồng)

Mỗi hành vi UI trong [Tiêu Chuẩn UI/UX](standards/ui-ux-standards.md) ánh xạ tới một file Playwright focused. Lọc theo tag đã nêu; pass focused không thay thế nghiệm thu P13 đầy đủ ở §5.

| Bộ | File spec | Bao phủ |
|---|---|---|
| UI-FX | `tests/E2E/ui-fixture.spec.js` | Đăng nhập fixture, ownership/quota, khôi phục settings, reflow `390px`, khớp dataset. |
| UI-COM | `tests/E2E/ui-common.spec.js` | Điều hướng thống nhất, một heading, loading/error/retry, hướng dẫn phiên, reflow/`320px`, RTL, style có phạm vi, an toàn artifact. |
| UI-OV | `tests/E2E/ui-overview.spec.js` | Bắt đầu, sao chép endpoint, field `Unavailable`, retry lỗi, chẩn đoán header (chỉ có/không), reflow/focus/contrast. |
| UI-TL | `tests/E2E/ui-tools.spec.js` | Tìm catalog theo tên, lọc toolset/tier kết hợp AND, trạng thái rỗng, liệt kê metadata (không có hành động chạy). |
| UI-KY | `tests/E2E/ui-keys.spec.js` | Lọc theo phạm vi, từ chối chéo chủ, chặn mutation trùng, xử lý kết quả chưa xác định, focus/xóa dialog bí mật, mật khẩu cho tier destructive/admin. |
| UI-AC | `tests/E2E/ui-activity.spec.js` | Phạm vi owner/field, đổi giờ địa phương sang UTC, chặn khoảng ngược, phân trang, render text-only, từ chối quản trị. |
| UI-ST | `tests/E2E/ui-settings.spec.js` | Field nhóm kèm biên, mode ghi đè role, chặn input sai, rejected-save vẫn đọc được, reflow/focus, từ chối quản trị. |
| UI-HP | `tests/E2E/ui-help.spec.js` | Bốn bước hướng dẫn, sao chép endpoint, fallback khi clipboard bị từ chối, gỡ sự cố bằng bàn phím, reflow/focus/contrast. |
| UI-NT | `tests/E2E/ui-notices.spec.js` | Thông báo môi trường kèm nguyên nhân và bước tiếp theo, chặn theo capability, reflow/focus/contrast. |

Hai file hỗ trợ `tests/E2E/admin.spec.js` (smoke admin và axe) và `tests/E2E/ui-artifacts.spec.js` (quét artifact dạng credential) không tính trong chín bộ UI trên.

### 2.8 Chiến Dịch Nghiệm Thu MCP Theo Vai Trò Và Nội Dung SEO

- **Vị trí:** `tests/E2E/role-seo-acceptance.php` (manifest, verifier và quét artifact), `infra/remote/run-role-seo-acceptance.sh` (runner chiến dịch) và các runner nhóm `infra/remote/run-role-seo.sh`, `infra/remote/run-role-seo-network.sh` và `infra/remote/run-role-seo-network-matrix.sh`.
- **Công cụ:** harness MCP bằng PHP do dự án sở hữu (`tests/E2E/RoleSeoHarness.php`, `tests/E2E/McpClient.php`) qua HTTPS thật với adapter đã cài. WP-CLI chỉ seed fixture, đo hậu điều kiện và dọn dẹp; nó không bao giờ thay một lệnh MCP end-to-end.
- **Mục đích:** gom ca của mọi nhóm vai trò và SEO của một chiến dịch (fixture, permissions, content, media, lifecycle, stability, network) vào một report an toàn rồi đối chiếu với manifest kỳ vọng của chiến dịch đó.
- **Chỉ MCP:** chiến dịch không điều khiển trình duyệt. Admin UI, theme frontend, indexing của Google và phát hành không bao giờ được đánh dấu hoàn thành nhờ nó, và một lượt chỉ pass integration không bao giờ được đọc là MCP end-to-end.
- **Ngữ nghĩa vai trò:** các actor `administrator`, `editor`, `author`, `contributor`, `subscriber` và `banned` mang scope khoá read, write, destructive và admin. Scope chỉ thu hẹp quyền của user hiện tại, không bao giờ cấp quyền mới. Một lượt từ chối chỉ tính là pass khi lớp từ chối khớp kỳ vọng và không có ghi ngoài ý muốn; lỗi schema hoặc lỗi wire không bao giờ được đọc là từ chối phân quyền.
- **Target:** `wp69` (WordPress 6.9), `wp71` (WordPress 7.1.2) và multisite `network-main` cùng `network-child` (WordPress 7.1.2).
- **URL network:** phase network dùng domain canonical của `rtl.py plan` và `rtl.py handoff` mới nhất (ví dụ `https://xnetvn-agent-hub-wp-wpnetwork.vm-2-215.local`). Alias `.sslip.io` không phải target: WordPress multisite chuyển hướng nó về home của mạng và làm mất path của blog, nên site con `/ui-second/` sẽ không tới được. Mọi lệnh network chạy bằng domain canonical.
- **Khôi phục có số đo:** mọi report của nhóm mang bộ đếm của site thuộc sở hữu trước và sau lượt chạy, kèm băm settings và cron, để verifier phân biệt rò owned với lệch `cron_digest`. Report gộp của nhóm `network` mang bộ đếm của **cả hai blog** và **cả hai phần**; một khối counts rỗng sẽ để lại việc khôi phục owned của nhóm đó không có số đo.
- **Cron và quota có kiểm soát:** fixture đã sở hữu chụp settings, cron digest và upload quota của site thuộc sở hữu trước khi seed và khôi phục sau nhóm. Chiến dịch không nâng quota, cron hay trần của site lab, và chỉ tắt đúng drop-in cache mà chính nó bật.
- **Metric:** report đếm ca theo vai trò, target, chế độ scope và biến thể, kèm p50, p95, p99, giá trị lớn nhất và số mẫu của từng bucket độ trễ, delta bộ nhớ đỉnh theo actor, số vòng ổn định và cờ không rò dữ liệu. Metric chưa đo được giữ nguyên là `unavailable`; không bao giờ thay bằng 0 và không suy ra tải production.
- **An toàn report:** report chỉ giữ id ca, vai trò, target, trạng thái và reason trong allowlist, số lượng, thời gian và kiểm boolean. Nó không giữ giá trị argument, body response, body bài, cookie, bearer token, hash credential hay đối số thô. Verifier quét mọi artifact của chiến dịch bất kể đuôi tệp, và một lượt từ chối không bao giờ in đoạn khớp.
- **Bao phủ trên hai blog của network:** cả hai phase đều chạy đúng các tệp ma trận B–E. Phase network chạy chúng **một lần cho mỗi blog** qua `infra/remote/run-role-seo-network-matrix.sh`, **từng blog một lượt** vì hai blog dùng chung bảng network, rồi chạy ma trận network của nhóm network. Vì vậy mỗi blog có đúng số ca B–E như site single (permissions 53, content 48, media 53, lifecycle 35, tổng 189 ca mỗi blog) cộng thêm các ca của nhóm network.
- **Gate mở:** một ca đỏ, hoặc một cờ cleanup đỏ mà nguyên nhân đã được đo, có thể được khai là gate mở của một phiếu khác. Danh sách gate **luôn** lấy từ mã của verifier, không bao giờ từ manifest, nên một chiến dịch không thể làm im tiếng ca đỏ của chính nó; mỗi gate trỏ tới **một phiếu có thật** trong kho phiếu cục bộ (`.local/docs/en/issues/`) và một khóa hẹp; một cờ cleanup chỉ là gate khi các bộ đếm owned và `settings_digest` không đổi, chỉ `cron_digest` đổi, và nhóm đó nằm trong danh sách của phiếu đó. Chủ sở hữu của một gate còn phải **đang mở**: verifier đọc trạng thái trong tệp phiếu và chỉ áp dụng gate khi phiếu đó còn mở, nên gate của một phiếu đã đóng không bao giờ che ca đỏ của chiến dịch. Verifier báo từng gate riêng kèm chủ sở hữu và không bao giờ tính nó là đạt của chiến dịch này, còn ca đỏ không được khai vẫn là lỗi của chiến dịch này và lệnh kiểm tra thoát khác 0.
- **Report root:** `prepare` và `verify` từ chối report root nằm trong thư mục mà mọi người đều ghi được mà không có bit sticky (ví dụ `/tmp` trên máy không có sticky bit), vì người khác có thể thay thế artifact. Hãy dùng thư mục trong dự án, ví dụ `.local/remote-test/role-seo/<campaign>`.
- **Runbook lab cho phase network:** domain canonical của network không có bản ghi DNS trong container toolbox, nên runner nghiệm thu thêm một dòng cho domain đó vào hosts file của container đó, trỏ về host của lab theo plan mới. Thay đổi này chỉ nằm trong container của dự án này, không đụng server, firewall, Traefik hay Swarm, và mất khi container được dựng lại. Runner trình duyệt của lab làm đúng việc đó bằng `--add-host`.
- **Lệnh (offline, không cần lab):**
  ```bash
  php tests/E2E/role-seo-acceptance.php selftest
  php tests/E2E/role-seo-acceptance.php fingerprint --root .
  ```

---

## 3. Lệnh Chạy Toàn Diện Trước Khi Cam Kết (Pre-commit Quality Gate)

Để đảm bảo toàn bộ mã nguồn đạt chuẩn 100% trước khi tạo commit hoặc Pull Request:

```bash
# Chạy toàn bộ kiểm tra chất lượng (Lint, Analyse, Unit Test)
composer check
```

Lệnh `composer check` tương đương với việc thực thi tuần tự:

1. `composer lint`: Kiểm tra PSR/WordPress coding standards.
2. `composer lint:bootstrap`: Kiểm tra tính tương thích cú pháp PHP 7.2 cho các file bootstrap.
3. `composer analyse`: Phân tích tĩnh cấp độ cao nhất với PHPStan Level 8.
4. `composer test:unit`: Chạy toàn bộ Unit Tests.

`composer check` còn chạy `composer check:docs`: đối chiếu mặc định cài đặt đã ghi với `WpSiteSettings::DEFAULTS` (`bin/check-settings-docs.py`) và kiểm đối xứng tài liệu Anh/Việt cùng mọi liên kết tài liệu tương đối (`bin/check-docs-parity.py`).

---

## 4. Kiểm Thử Trên Phòng Thí Nghiệm Từ Xa (Remote Test Lab)

Kiểm thử nặng, kiểm thử tải đa luồng, kiểm tra tương thích trình duyệt E2E và ma trận HTTP bảo mật chạy trên server test dùng chung qua skill `lc-udev-remote-test-lab` (`rtl.py`). Không chạy dịch vụ, database hay browser cục bộ cho các bộ này.

### 4.1 Thiết Lập và Preflight

```bash
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" preflight
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" plan --compose-file compose.yaml --site wp69:80 --site wp71:80
```

Đọc output plan (RAM trống, đĩa, limits, projects). Preflight hoặc plan bị chặn thì dừng lượt chạy; chẩn đoán bằng `rtl.py diagnose --fetch` và phân biệt lỗi môi trường với bug dự án.

### 4.2 Khởi Động Lab (Hai Site)

```bash
npm run remote:plan
npm run remote:up
```

`npm run remote:plan` kết xuất và lint plan hai site (`wp69:80`, `wp71:80`). `npm run remote:up` đồng bộ, khởi động stack và tạo cả hai site WordPress. Giữ volume test khi stop; không purge hay xóa dữ liệu thật.

### 4.3 Địa Chỉ Kiểm (`UI_BASE_URL` và `--base-url`)

```bash
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" handoff --dir "$PWD"
UI_BASE_URL="<địa chỉ HTTPS từ handoff cho một site>"
```

Đặt `UI_BASE_URL` trong shell từ `rtl.py handoff` của namespace site tương ứng. Đây là địa chỉ thử, không phải credential; không commit và không tự chế. Mọi lệnh E2E dưới đây đọc biến đó qua `--base-url "$UI_BASE_URL"`. Chạy các bộ đã chọn lần lượt cho từng site (`wp69`, rồi `wp71`).

### 4.4 Chạy Các Bộ

```bash
# Ma trận HTTP bảo mật
composer test:http

# Bộ UI focused, từng site một
RTL_AGENT="$USER" python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir "$PWD" --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js --grep UI-OV'
```

Thay tag `--grep` bằng bộ ở §2.7 (`UI-FX`, `UI-COM`, `UI-OV`, `UI-TL`, `UI-KY`, `UI-AC`, `UI-ST`, `UI-HP`, `UI-NT`). Bộ integration và network dùng §2.2 và §2.6. Kiểm UI bao phủ viewport `390px`/`768px`/`1440px` và reflow `320px`, tiếng Anh và RTL, Unicode và tên dài, bàn phím, focus, nhãn, contrast, loading, empty, error, success và ca hết phiên hoặc mất quyền khi áp dụng.

Bộ nghiệm thu P13 là `tests/E2E/admin.spec.js` cộng mọi file `tests/E2E/ui-*.spec.js`. Chạy không lọc tag, mỗi single-site một lượt:

```bash
RTL_AGENT="$USER" python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir "$PWD" --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test tests/E2E/admin.spec.js tests/E2E/ui-*.spec.js --config=tests/E2E/playwright.remote.config.js'
```

Trên multisite network, dùng domain canonical `.local` (alias làm mất blog path), thêm `/ui-second/` cho target con, và chỉ chạy nhóm nghiệm thu network:

```bash
RTL_AGENT="$USER" python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir "$PWD" --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test tests/E2E/ui-acceptance.spec.js --grep "@ui-acc-net" --config=tests/E2E/playwright.remote.config.js'
```

`tests/E2E/seo-review.spec.js` thuộc luồng bài viết S6 được giữ lại, cần handoff S6 và nằm ngoài P13; không thêm nó vào bộ trên.

#### 4.4.1 Đo tương phản và chuyển động bằng máy (`UI-MANUAL`)

Bộ `tests/E2E/ui-manual-equivalence.spec.js` đo bằng máy những mục mà danh sách manual của UI-04 yêu cầu. Bộ này chỉ đọc: nó không tạo, thu hồi hay sửa khóa nào.

```bash
# Mỗi site một lượt: địa chỉ của wp69 trước, rồi địa chỉ của wp71.
RTL_AGENT="$USER" python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir "$PWD" --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js --grep UI-MANUAL'
```

Bằng chứng của một lượt nằm ở `.local/remote-test/<dấu-thời-gian>/`: `ui-manual-equivalence.json` và một tệp `ui-manual/<biến-thể>--<engine>.json` cho mỗi biến thể và mỗi engine. Ngưỡng của bộ đo là cố định và không bao giờ được hạ để ca đỏ thành xanh:

| Ngưỡng | Giá trị |
|---|---|
| Tương phản của chữ thường (WCAG 2.2 SC 1.4.3) | 4.5:1 |
| Tương phản của chữ lớn | 3:1 |
| Vi phạm axe trong `.xnetvn-agent-hub` (`wcag2a`, `wcag2aa`) | 0 ở mọi biến thể được đo |
| `forced-colors: active` | 0 vi phạm axe và tỉ lệ nhỏ nhất 4.5:1 |
| `prefers-reduced-motion: reduce` | 0 phần tử có animation và 0 phần tử có transition bên trong hub |

Các biến thể gồm viewport tham chiếu, mức zoom 200 % và 400 %, locale Ả Rập kèm `dir="rtl"`, màu quản trị `fresh`, `light` và `midnight`, màu trình duyệt và `forced-colors: active`, và giảm chuyển động. Màu quản trị được mô phỏng bằng stylesheet của bộ chọn ở trang hồ sơ và body class tương ứng; stylesheet của màu quản trị được in sau stylesheet của plugin, nên một quy tắc của plugin phải thắng quy tắc của core thì cần độ đặc hiệu cao hơn quy tắc của core (`.wp-core-ui .button` là 0-2-0). Plugin khai báo màu của chính nó cho các nút của hub và nêu màu của bảng màu hệ thống trong khối `@media (forced-colors: active)`; nó không sửa tệp nào của core và không đặt `forced-color-adjust: none`.

### 4.5 Chạy Chiến Dịch Nghiệm Thu MCP

Chiến dịch chạy hai phase tuần tự của cùng namespace: `single` trước, rồi chuyển chế độ single sang network theo runbook nền đã duyệt, rồi `network`, rồi quay lui về `single` theo runbook. Runner không tự khởi động, dừng hay chuyển đổi stack, và hai phase không bao giờ chạy liền nhau.

```bash
set -o pipefail
ROLE_SEO_CAMPAIGN="role-seo-$(date -u +%Y%m%dT%H%M%SZ)"
php tests/E2E/role-seo-acceptance.php prepare --campaign "$ROLE_SEO_CAMPAIGN" --report-dir ".local/remote-test/role-seo/$ROLE_SEO_CAMPAIGN"
composer check
RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" preflight
RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" sync --compose-file compose.yaml --dry-run
RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --compose-file compose.yaml toolbox -- bash /work/infra/remote/run-role-seo-acceptance.sh single "$ROLE_SEO_CAMPAIGN" | tee ".local/remote-test/role-seo/$ROLE_SEO_CAMPAIGN/single.log"
# Chuyển chế độ single sang network theo runbook nền; phase dưới cần stack network đang active.
RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --compose-file compose.ui-network.yaml toolbox -- bash /work/infra/remote/run-role-seo-acceptance.sh network "$ROLE_SEO_CAMPAIGN" | tee ".local/remote-test/role-seo/$ROLE_SEO_CAMPAIGN/network.log"
php tests/E2E/role-seo-acceptance.php verify --campaign "$ROLE_SEO_CAMPAIGN" --report-dir ".local/remote-test/role-seo/$ROLE_SEO_CAMPAIGN"
```

`prepare` tạo report root với quyền `0700` sau khi kiểm không thành phần đường dẫn nào là symlink hay thuộc sở hữu người khác, và từ chối một chiến dịch còn dữ liệu. Mỗi phase in đúng một khối JSON giữa `ROLE_SEO_REPORT_BEGIN` và `ROLE_SEO_REPORT_END`; verifier đọc hai marker đó, so tập ca quan sát với manifest kỳ vọng và từ chối khối bị thiếu hoặc bị lặp, JSON hỏng, ca thiếu, ca lạ, ca trùng, fingerprint khác manifest, cờ cleanup sai và artifact có nội dung giống credential.

Fingerprint mã là SHA-256 trên danh sách đường dẫn tương đối đã sắp xếp và bytes của `src/`, tệp văn bản trong `tests/`, `infra/remote/`, `composer.lock`, `xnetvn-agent-hub-wp.php` và `uninstall.php`. Cùng mã đó tính nó trên máy nhận và trên bản sync của lab, nên hai phase phải mang cùng một fingerprint; đổi mã giữa hai phase làm phase trước mất hiệu lực.

Private state của chiến dịch (report gốc của nhóm và ledger của fixture) nằm lại trên lab. Chỉ khối phase đã mask mới đi về máy nhận, và verifier quét thư mục report trước khi bàn giao.

Giới hạn của **một** lệnh `rtl.py exec` là 600 s, nên một phase dài hơn (ví dụ phase `single` có nhóm `stability` 5 vòng trên hai site) phải chạy nền **trong** container toolbox và lấy nguyên văn report do phase tự sinh: khởi động `nohup … "$ROLE_SEO_CAMPAIGN" > <report-dir>/<phase>.console.log 2>&1 &`, chờ khối `ROLE_SEO_REPORT_END`, rồi ghi nguyên văn khối đó vào `<phase>.log` cho `verify`. Chạy ma trận network bằng runner thô cần thêm domain canonical vào `/etc/hosts` của container toolbox (acceptance runner tự làm việc này ở `ensure_canonical_dns`). Không tăng giới hạn 600 s, không chạy song song hai lượt và không đổi thứ tự `single` trước `network`.

---

## 5. Mục Tiêu Đối Chiếu Kết Quả Quan Sát (Hồ Sơ Nghiệm Thu)

`git diff --check` chỉ chứng minh whitespace; không bao giờ chứng minh runtime, hosted CI, real-client, host hay sẵn sàng production. Nghiệm thu toàn diện P13 đã chạy ngày 2026-11-04: 198/198 trên `wp69` và `wp71`, 6/6 trên network main và child, Chromium/Firefox/WebKit, không bỏ qua ca nào. Evidence: `.local/remote-test/20261104T102449Z` (`wp69`), `.local/remote-test/20261104T103736Z` (`wp71`), `.local/remote-test/20261104T105310Z` (network main), `.local/remote-test/20261104T105421Z` (network child).

| Claim | Mục tiêu | Kết quả quan sát trong revision này |
|---|---|---|
| Cổng chất lượng PHP | `composer check` thoát 0 | Kết quả theo từng lượt chạy; ghi lệnh, revision và môi trường. |
| Integration (`XNETVN_TEST_MULTISITE=0/1`), network, ma trận HTTP | Xanh trên cả hai site | Kết quả theo từng lượt chạy; xem hồ sơ chạy. |
| Bộ UI `UI-FX/COM/OV/TL/KY/AC/ST/HP/NT` | Xanh từng bộ trên `wp69` và `wp71` | Kết quả theo từng lượt chạy; focused không thay nghiệm thu đầy đủ. |
| Chiến dịch nghiệm thu MCP theo vai trò và SEO (§2.8, §4.5) | Manifest kỳ vọng bằng tập ca quan sát trên `wp69`, `wp71`, `network-main` và `network-child`; không thiếu, không lạ, không trùng; `fail=0`, `skip=0`; cleanup thuộc sở hữu đã xác minh | Kết quả theo từng lượt chạy; report của chiến dịch và output của verifier là bằng chứng. |
| Metric của chiến dịch MCP (§2.8) | p50, p95, số mẫu, delta bộ nhớ và số vòng là số thật | Kết quả theo từng lượt chạy; metric chưa đo được giữ là `unavailable` và không bao giờ thành 0. |
| TLS được tin cậy, real-client MCP (Claude, ChatGPT và khác), hosted CI, host và production | Có evidence riêng | Chưa xác minh trong revision này; ngoại lệ chứng thư tự ký chỉ áp cho client của lab. |
| Kiểm axe tự động | 0 lỗi axe trên UI thay đổi | Chỉ là tín hiệu; không đọc thành tuân thủ WCAG. |
| Tuân thủ WCAG 2.2 AA | Tuân thủ theo [Tiêu Chuẩn UI/UX](standards/ui-ux-standards.md) | Chưa xác lập; kiểm thủ công WCAG, bàn phím và trình đọc màn hình vẫn thuộc phần nghiệm thu riêng. |
| Nghiệm thu P13 | Ba browser, hai site, multisite xanh | PASS — 198/198 trên `wp69` và `wp71`; 6/6 trên network main và child; Chromium, Firefox và WebKit; 0 fail, 0 skip (đường dẫn evidence ngày 2026-11-04 ở trên). |
| Thủ công, trình đọc màn hình, real-client, hosted CI, host, production | Có evidence riêng | Chưa xác minh trong revision này. |

Mỗi lượt chạy ghi thời điểm, revision, site (`wp69`/`wp71`), browser, role, lệnh và nguồn `UI_BASE_URL` (`rtl.py handoff`); không ghi credential, hash hay giá trị argument vào log và artifact.
