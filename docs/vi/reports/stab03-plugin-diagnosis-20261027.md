# Chẩn đoán plugin-only cho STAB-03 — 2026-10-31

- **Phiếu:** `ISSUE_v0.1_2026-10-27_04_nguyen-nhan-va-thiet-ke-toi-uu-plugin-cho-stab-03-duoi-50-ms`
- **Người nhận:** `impl-stab03-diag-20261031` (T3, tiếp quản sau 85 giờ không có tiến độ)
- **Revision:** `536873f1ad73f774f4cf0417b640ebb8247caeb4` (base `a4b283f14e07a0d13572efe70b2fbb04c2fc18aa`)
- **Môi trường:** Remote Test Lab `xnetvn-agent-hub-wp`, wp69, WordPress 6.9, PHP 8.2, adapter 0.7.0, Redis cache-on; 3 lượt đo tuần tự.
- **Phạm vi:** chỉ chẩn đoán và bàn giao thiết kế; không sửa `src/`, dependency, runner, ngưỡng, toolset hay dữ liệu lưu giữ.

## 1. Kết quả đo

| Lượt | `http_ms` p50 | Khoảng cách tới 50 ms | `pre_dispatch_ms` | `dispatch_ms` | `adapter_init_ms` | Lỗi | Tool |
|---|---|---|---|---|---|---|---|
| 1 | 52.956692 ms | 2.956692 ms | 40.772915 | 8.766199 | 11.721398 | 0 | 60 |
| 2 | 53.112690 ms | 3.112690 ms | 40.898085 | 8.795498 | 11.729598 | 0 | 60 |
| 3 | 52.907789 ms | 2.907789 ms | 40.775061 | 8.808198 | 11.702498 | 0 | 60 |

Cả ba lượt đều `request_errors=0`, `visible_tools=60`, payload 33 191 logical
JSON bytes. Năm trong sáu gate đạt; `list_http_p50_lt_50_ms` trượt 3/3.

Hash tệp report:

- `benchmark-child-1.json` — `4e05f12fc046c15f4b1b256cb48e01ff226b27206c816a175fdf63a4f2443285`
- `benchmark-child-2.json` — `2cf503c713b318e700a670973907109ed5cadda47e0b74069df58ba6d6c7ac12`
- `benchmark-child-3.json` — `3a41689346aa19b9991de9f833cfb26d6d2e33d16ee5757b70b2897ab9aa28af`

Hash nguồn đo khớp local:

- `tests/E2E/benchmark.php` — `ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec`
- `infra/remote/run-benchmark.sh` — `1fb8f1df39a2c62a84e4019f2a7f26994a15e56334b680b11675a9d956f8a551`
- Adapter cài trên lab — `0.7.0`

## 2. Đường thời gian của lượt 1

Các số dưới đây là p50 aggregate, không phải phép chia từng mẫu; pha lồng
nhau không được cộng hai lần.

| Mốc | Giá trị |
|---|---|
| `mu_load_ms` (đầu request → MU-plugin) | 7.200003 ms |
| `bootstrap_ms` (đầu request → `plugins_loaded`) | 10.334969 ms |
| `init_ms` (đầu request → `init`) | 14.428139 ms |
| `pre_dispatch_ms` (đầu request → `rest_pre_dispatch`) | 40.772915 ms |
| `phase_rest_api_init_ms` | 15.224598 ms |
| `phase_mcp_adapter_init_ms` | 11.721398 ms |
| `phase_wp_abilities_api_init_ms` | 0.749500 ms |
| `phase_categories_init_ms` | 0.109600 ms |
| `dispatch_ms` | 8.766199 ms |
| `auth_ms` | 3.662600 ms |
| `policy_ms` | 0.659300 ms |
| `ttfb_ms` | 51.663000 ms |
| `http_ms` | 52.956692 ms |
| memory peak | 6 173 232 B |

## 3. Quy trách nhiệm

| Vùng | Chủ | Bằng chứng |
|---|---|---|
| Khởi động core + MU-plugin | WordPress core | `mu_load_ms` 7.20 ms; không chứa code Agent Hub |
| Nạp plugin + adapter | sản phẩm + dependency | `bootstrap_ms − mu_load_ms` ≈ 3.13 ms; `src/Bootstrap/Loader.php:39` → `src/Plugin.php:89,113-152` |
| Dựng toàn bộ pack definitions tại `init` 0 | sản phẩm | `src/Abilities/Registry.php:92-104`; `init_ms − bootstrap_ms` ≈ 4.09 ms, gồm cả core init |
| Đăng ký toàn bộ ability của các pack khả dụng + 6 category | sản phẩm | `src/Abilities/Registry.php:135-175`, `:119-133`; phase riêng 0.749500 + 0.109600 ms |
| Dựng server và chiếu 60 tool | dependency adapter 0.7.0 | `ServerGateway.php:88-117`; `phase_mcp_adapter_init_ms` 11.721398 ms; `McpAdapter.php:221,278`, `McpServer.php:90,141,237`, `McpComponentRegistry.php:134,182,585,673` |
| `scope_hint()` đọc key lần hai | sản phẩm | `ServerGateway.php:158-171` và `Authenticator.php:62-80` cùng `find_by_key_id()`; chưa đo riêng |
| Auth + rate limit + owner check | sản phẩm | `Authenticator.php:62-140`; `auth_ms` 3.662600 ms |
| Policy 60 tool | sản phẩm | `Registry.php:299-315`; `policy_ms` 0.659300 ms |
| Dispatch + output/audit/JSON | sản phẩm + adapter | `dispatch_ms` 8.766199 ms; phần còn lại sau auth/policy chưa tách |

**Chưa đo được:** phần plugin-only nằm trong `bootstrap_ms`, `init_ms` và
`pre_dispatch_ms − init_ms` (≈ 26.35 ms). Các timer riêng cho provider
construction, pack build và việc đăng ký ability chưa có timer riêng (số lượng chính xác do test catalog chốt). Vì vậy
không được ghi phần này là 0 hoặc gán hết cho plugin; đây là khoảng
`unavailable` lớn nhất.

**Biên và phần dư số học.** Ứng viên gộp category bị chặn trên bởi chính pha đã đo
`phase_categories_init_ms` 0.109600 ms, nên không thể kỳ vọng tiết kiệm quá 0,11 ms.
Phần dư giữa HTTPS p50 đầy đủ và tổng các cửa sổ đã đo (khoảng 3,4 ms) là chi phí
client, vận chuyển và core chưa được đo; ghi là **không quy cho plugin** và không
dùng để suy ra tốc độ cải thiện nào.

## 4. Ứng viên plugin-only

| # | Ứng viên | Bằng chứng | Giảm dự kiến (giả thuyết) | Điều bất biến / test | API công khai được pin | Rollback | Rủi ro |
|---|---|---|---|---|---|---|---|
| 1 | Chỉ dựng pack theo scope của key, sau scope hint, thay vì cả 6 pack tại `init` | `Registry.php:92-104` dựng mọi definition của các pack khả dụng; benchmark chỉ chiếu 60 tool; `names_for_scope()` ở `:216-240` đã lọc theo scope | 0.5–1.5 ms | Key thiếu/hỏng phải fallback toàn bộ; `allowed()` vẫn kiểm live từng tool; test catalog/scope/policy | `Registry::add_pack()`, `AbilityPack::definitions()`; tên/schema ability và capability map không đổi | Một commit; xoá nhánh chọn pack là trở lại dựng toàn bộ | Adapter cần tên tool tại `mcp_adapter_init`; scope hint chỉ thu hẹp, không cấp quyền |
| 2 | Đăng ký category một lần theo toolset thay vì một lần mỗi definition | `Registry.php:119-133` gọi `wp_has_ability_category()` cho từng definition; chỉ có 6 toolset | ≤ 0.11 ms, bị chặn trên bởi chính pha đo `phase_categories_init_ms` 0.109600 ms | Tập category/label không đổi; test contract `wp_has_ability_category` | `wp_has_ability_category()` / `wp_register_ability_category()` (WordPress 6.9), không đổi API plugin | Một commit; bỏ memo là trở lại gọi từng definition | Toolset động trong tương lai vẫn phải đăng ký |
| 3 | Chỉ đăng ký ability visible theo scope trên route MCP đã xác thực, fallback toàn bộ | `Registry.php:135-175` đăng ký mọi definition; scope chiếu 60 | 0.2–0.4 ms | Fallback khi key thiếu/hỏng; metadata private và output pipeline không đổi; ma trận role/scope | `wp_register_ability()` và các getter `Registry::all()` / `Registry::names()` không đổi; fallback giữ nguyên tên | Một commit; đường fallback chính là hành vi hiện tại nên bỏ filter là trở lại | Nếu key chưa xác thực, hint chỉ được thu hẹp |
| 4 | Hoãn dựng management controller và `Pages` trên request không quản trị | `Plugin.php:140-150` dựng `KeysController`, `AuditController`, `SettingsController`, `Pages` mọi request | < 0.2 ms | Route quản lý cookie+nonce vẫn đăng ký đúng; test REST route | Không đổi API công khai; controller và route giữ nguyên tên | Một commit; bỏ điều kiện là dựng lại mọi request | Bỏ sót hook quản lý trên route không quản trị |
| 5 | Gộp `find_by_key_id()` giữa `scope_hint()` và `authenticate()` | `ServerGateway.php:158-171`, `Authenticator.php:62-80` | không khuyến nghị | Cần cache request-local một stored key; thứ tự auth và xử lý secret phải giữ nguyên | Sẽ thêm cache theo request vào đường auth | Hoàn nguyên cache và thay đổi thứ tự cùng lúc | Cache đối tượng từ credential trước khi xác thực là rủi ro bảo mật |

Tổng lợi ích của #1–#4 có thể dưới 2 ms; chưa đủ chắc để đóng khoảng 2.91–
3.11 ms. Cần một phiếu sửa riêng triển khai #1–#3 (kèm fallback toàn bộ), đo
lại đúng ba lượt và chạy integration/HTTP. Nếu vẫn thiếu, phần còn lại nằm ở
adapter 0.7.0 và khoảng pre-dispatch chưa tách; khi đó cần phiếu
instrumentation riêng, không được tự viết profiler trong phiếu chẩn đoán.

## 5. Adapter 0.7.0

Các lựa chọn đã loại trừ (giữ nguyên kết luận mục 8.2):

- Reflection vào `$component_registry` hoặc `add_mcp_tool()` phá đóng gói dependency.
- Gọi `register_tools()` sau khi dựng server là không thể: registry private, không có getter.
- Thêm record trong `mcp_adapter_tools_list` không giúp: filter nhận record đã chiếu, chi phí dựng đã trả trước.
- Quảng cáo ít tool hơn làm đổi contract/payload 33 191 bytes.
- Cache tool object persistent lưu object gắn request (ability, schema, permission) ra ngoài request.
- `create_server()` từ chối ngoài `mcp_adapter_init` (`McpAdapter.php:278`); dựng server ở `tools/list` đầu tiên cũng bị từ chối.

## 6. Kết luận và đầu vào phiếu sửa

- Khoảng cách đo được: **2.907789–3.112690 ms**; nguyên nhân lớn nhất đã đo là
  adapter build **11.721398 ms**, nhưng phần plugin-only trong pre-dispatch
  **chưa được tách**.
- Ứng viên đề nghị: #1 (pack theo scope), #2 (category dedupe), #3 (đăng ký
  ability theo scope với fallback toàn bộ). #4 là tối ưu nhỏ; #5 không nên làm
  vì rủi ro bảo mật.
- Phiếu sửa phải giữ nguyên sáu ngưỡng, toolset, payload, auth/policy live,
  private ability metadata và output pipeline; tối đa 5 tệp runtime/test/docs.
- Sau sửa: chạy lại đúng ba lượt benchmark wp69 cache-on, `composer check`,
  `composer test:integration -- 6.9`, `git diff --check`; giao chủ gate
  `ISSUE_v0.1_2026-10-27_02` đóng hợp lệ nếu cả ba lượt `< 50 ms` và đủ sáu
  gate. Phiếu chẩn đoán này không tự đóng STAB-03.

## 7. Bằng chứng vận hành

- `rtl.py preflight` → exit 1, chỉ cảnh báo 4 bridge firewall cũ; không block.
- `rtl.py lease` trước lượt 1 → `nobody holds project xnetvn-agent-hub-wp`.
- `rtl.py sync --dry-run` → chỉ khác mtime; `sync` exit 0; `status` running, đủ 5 container healthy.
- `rtl.py plan` → exit 2 vì lỗi cộng trùng RAM đã biết (`2816 + 2816 > 5120`), không phải thiếu RAM thật; không redeploy theo quyết định của chủ dự án.
- Mỗi lượt: `flock -n 9` trên `/work/.local/role-seo/wp69.lock`, `cache-fixture.sh enable wp69`, `trap` disable, `run-benchmark.sh` giữ nguyên.
- Sau lượt 3: `object-cache.php` và `owned-dropin.sha256` đều vắng; cache fixture sạch.
- Không in/copy credential, token hay private state.

## 8. Giới hạn

- Số đo trên một container lab 1 CPU/512 MiB, không phải production hay cam kết dung lượng.
- Không có paired raw samples; không suy attribution từng request.
- Không sửa `src/`, dependency, benchmark, ngưỡng hay toolset trong phiếu này.
- Chưa chạy integration/HTTP sau sửa vì chưa có sửa runtime; đó là việc của phiếu fix.
