<!--
  Copyright (c) 2026 xNetVN Inc.
  Website: https://xnetvn.com/
  License: Apache License 2.0 (https://www.apache.org/licenses/LICENSE-2.0)
  Contact: license@xnetvn.net
-->

# Đánh giá hành vi MCP (controller offline)

> **Trạng thái: controller, bộ ghi, bộ kiểm và tầng truyền tải giả đã được
> hiện thực và có unit test bao phủ. Chiến dịch 98 ca là mô phỏng offline. Nó
> chứng minh pipeline đánh giá, không chứng minh hành vi mô hình, hành vi
> runtime MCP hay nghiệm thu sản phẩm.**
>
> Quyết định của chủ dự án ngày 2026-10-31 thu hẹp phần việc này về controller
> và một lượt chạy FAKE/offline. Không có lời gọi mô hình, provider, credential,
> trình duyệt, lab hay mạng trong chiến dịch. Phán quyết của reviewer vẫn độc
> lập và một chiến dịch xanh không bao giờ tự đóng ACCEPT-02.

## 1. Mục đích và điều không làm

Bộ ca trong `.agents/evals/test-debug-scenarios.json` có 98 ca định tuyến
(I 11, J 27, K 24, R 12, M 12, P 12). Bộ kiểm cấu trúc chứng minh mọi ca có đủ
trường dự kiến; nó không chứng minh gì về hành vi định tuyến.

Controller offline đóng khoảng trống của pipeline:

- mỗi ca một lần gọi transport mới, không resume và không bộ nhớ dùng chung;
- transport FAKE tất định do harness sở hữu;
- bộ ghi JSONL đã làm sạch với trace từng ca;
- bộ kiểm số lượng, fingerprint và trạng thái kết thúc;
- một bước reviewer độc lập mà harness không bao giờ tự làm.

Nó **không** thay thế: kiểm runtime MCP, ma trận vai trò/quyền, kiểm media, review
phát hành, bộ integration, kiểm trình duyệt hay hosted CI. Một phản hồi FAKE
không phải bằng chứng rằng mô hình, client hay plugin đã xử sự theo cách nào đó.

## 2. Hợp đồng bộ ca

- Bộ nạp chỉ nhận đúng 98 ca với id duy nhất trong sáu nhóm trên. Thiếu ca,
  thừa ca, nhóm lạ hoặc id trùng đều fail closed bằng `corpus_cardinality`,
  `corpus_group_counts` hoặc `corpus_duplicate_id`.
- Bộ ca không bao giờ bị sửa để một lượt chạy đạt. Đổi số lượng ca là một thay
  đổi bộ ca riêng đã review.
- Transport chỉ nhận `prompt`, `prerequisites` và `mode`. Id ca, `stage`,
  `expected_skill`, `expected_actions`, `forbidden_actions` và
  `expected_evidence` không bao giờ vào input transport. Một unit test khẳng
  định chốt chặn rò rỉ này.
- Id thật vẫn nằm trong controller để nối bản ghi với bộ ca. Nó không được dùng
  làm gợi ý định tuyến.

## 3. Hợp đồng CLI

Mọi chế độ cần `--cases`; runner chỉ dùng thư viện chuẩn của Python.
`--assets-dir` mặc định là thư mục `.agents` cạnh bộ ca.

```bash
python3 -I -B bin/run-mcp-behavior-evals.py --plan \
  --cases .agents/evals/test-debug-scenarios.json \
  --run-dir /tmp/opencode/mcp-behavior-plan
```

`--plan` kiểm bộ ca và các tài sản trong allowlist, rồi in số ca, số ca theo
nhóm, hash bộ ca, hash prompt từng ca, hash tài sản, các giới hạn và
`offline: true, network: false`. Nó không tạo thư mục chạy và không khởi động
transport.

```bash
python3 -I -B bin/run-mcp-behavior-evals.py --run \
  --cases .agents/evals/test-debug-scenarios.json \
  --run-dir /tmp/opencode/mcp-behavior-<run-id>
```

`--run` từ chối khởi động, thoát mã 2, khi tên thư mục chạy không thuộc sở hữu
(`mcp-behavior-*`), thư mục là symlink, mode rộng hơn `0700`, chủ sở hữu khác,
fingerprint manifest khác, đã có bản ghi, một tài sản là symlink hoặc hardlink,
một tài sản không nằm trong allowlist cố định, hoặc tổng dữ liệu staging hay
một tệp vượt giới hạn. Những trường hợp đó không ghi gì. Một lượt đã xong không
bao giờ được resume hay thử lại.

```bash
python3 -I -B bin/run-mcp-behavior-evals.py --verify \
  --cases .agents/evals/test-debug-scenarios.json \
  --run-dir /tmp/opencode/mcp-behavior-<run-id>
```

`--verify` yêu cầu đúng 98 bản ghi, mỗi id bộ ca một bản, không thử lại, hash bộ
ca/prompt/tài sản/schema khớp, cây staging khớp, mọi bản ghi có trạng thái kết
thúc, phản hồi hợp schema và hash phản hồi khớp, cùng trace sự kiện sạch cho
mọi ca thành công. Nó báo số lượng theo trạng thái nhưng không bao giờ cho điểm.

```bash
python3 -I -B bin/run-mcp-behavior-evals.py --review \
  --cases .agents/evals/test-debug-scenarios.json \
  --run-dir /tmp/opencode/mcp-behavior-<run-id> \
  --verdicts /tmp/opencode/mcp-behavior-<run-id>/verdicts.json
```

`--review` nạp tệp phán quyết do reviewer sở hữu, mỗi id ca một `PASS`, `FAIL`
hoặc `UNKNOWN`. Nó từ chối id trùng, phán quyết lạ hoặc phủ không đủ. Harness
không bao giờ tự chấm đầu ra của mình. Lượt review độc lập cho chiến dịch FAKE
ghi `UNKNOWN` cho cả 98 ca, vì transport FAKE không thể làm bằng chứng cho hành
vi mô hình.

## 4. Hợp đồng lượt chạy offline

Chiến dịch chạy tuần tự và luôn qua cùng một transport giả:

- không mô hình, provider, API key, phiên ChatGPT hay `codex exec`;
- không socket mạng và không trình duyệt;
- không bwrap, launcher, tệp phê duyệt hay kho credential;
- không lệnh shell, PHP, Composer, MCP hay lab lấy từ prompt;
- hành động được đề xuất vẫn là dữ liệu và không bao giờ được thực thi;
- mỗi ca một tiến trình transport mới, không resume giữa các ca;
- cấm thử lại tự động; ca hỏng vẫn hỏng.

Transport giả phát đúng dạng `ThreadEvent` đã ghim cho từng ca: một
`thread.started`, một `turn.started`, một `item.completed` là tin nhắn agent và
một `turn.completed`. Phản hồi tất định và không chứa trường dự kiến. Chúng là
đề xuất FAKE, không phải đầu ra của mô hình.

## 5. Giới hạn

| Đại lượng | Giới hạn | Khi vượt |
|---|---|---|
| Tổng dung lượng staging | 64 MiB | `--run` thoát 2 trước mọi ca |
| Một tệp staged | 8 MiB | `--run` thoát 2 trước mọi ca |
| Một dòng JSONL | 256 KiB | `line_too_large`; ca không đạt; dòng thô không được lưu |
| Sự kiện mỗi ca | 2 000 | `event_overflow`; ca không đạt |
| Một ca | 60 s | `timeout`; nhóm tiến trình bị giết; ca không đạt |
| Toàn chiến dịch | 30 phút | `campaign_timeout`; giữ các bản ghi đã có |

Các giới hạn là hằng số trong controller và chỉ được ghi ở đây một lần. Giới hạn
staging áp cho tổng các tệp thường sau khi phân giải symlink. Symlink và hardlink
ngoài allowlist fail closed. Test biên bao đúng mức giới hạn và một đơn vị trên
đó. Test timeout dùng đồng hồ giả; test chiến dịch không bao giờ sleep.

## 6. Ranh giới staging và dữ liệu

- Gốc staging là `/tmp/opencode/mcp-behavior-<run-id>/`, mode `0700`, thuộc
  người dùng hiện tại.
- Allowlist là manifest cố định gồm instruction MCP test-debug và 17 tệp của
  năm skill MCP (tổng 18 tệp). Một tệp `.md` tham chiếu mới bị từ chối bằng
  `asset_unlisted` cho tới khi manifest và test được review. Không stage
  `AGENTS.md`, README, mã sản phẩm, `$HOME`, cấu hình, tệp lab, credential hay
  đáp án bộ ca.
- Mỗi đường dẫn nguồn được kiểm từng thành phần bằng `lstat`; symlink, hardlink
  hoặc đoạn `..` đều fail closed.
- Tệp được sao chép với `O_NOFOLLOW | O_EXCL` và mode `0600`.
- Cây staging chỉ được ghi một lần. Thư mục chạy đã xong không bao giờ bị dùng
  lại hay resume: `--run` thoát 2 với `run_already_attempted` khi đã có bản ghi,
  và thư mục rỗng khớp bị từ chối bằng `resume_not_supported`.

## 7. Bộ ghi và giao thức sự kiện

Bộ ghi đọc JSONL, không đọc văn bản tự do:

- chỉ nhận đúng tám sự kiện cấp cao nhất đã ghim. Thứ khác là `unknown_event` và
  ca không thể đạt.
- yêu cầu đúng một `thread.started`, một `turn.started`, một kết thúc thành
  công và EOF sạch. JSON sai, sai kiểu, item type lạ, item completed trùng, cập
  nhật sau khi hoàn tất hay sự kiện sau kết thúc đều là lỗi giao thức.
- một `agent_message` chỉ thành ứng viên khi nó là tin nhắn completed cuối cùng
  trước một kết thúc thành công. Item started và updated không bao giờ thành
  ứng viên cuối. Runner không bao giờ lùi về ứng viên trước đó.
- `command_execution`, `file_change`, `mcp_tool_call`, `collab_tool_call`,
  `web_search` và `todo_list` được ghi là vi phạm ngay lần đầu thấy, kể cả khi
  payload rỗng, bị từ chối hay thất bại.
- Item `reasoning` được nhận diện và bỏ. Không lưu chain-of-thought, bản tóm
  tắt, văn bản hay hash của văn bản. Bộ làm sạch quét trong bộ nhớ và chỉ giữ
  loại vi phạm cùng một locator đã băm.
- `turn.failed`, `error` cấp cao nhất và lỗi item đều là thất bại. Một kết thúc
  sạch về sau không bao giờ xóa thất bại hay vi phạm.
- canary bị cấm hoặc giá trị dạng credential đặt `forbidden_data_detected` hoặc
  `credential_detected`, bị che khỏi artifact và vẫn làm ca hỏng. Bản ghi chỉ giữ
  loại, chỉ số sự kiện và
  `SHA256(run_id + case_id + stream + event_index + JSON pointer)`, không bao giờ
  giữ giá trị khớp.
- stderr được quét trong bộ nhớ, giới hạn một dòng, và không bao giờ được lưu;
  môi trường, thread id và payload không tin cậy không bao giờ được lưu.

Schema phản hồi nằm ở `tests/Evals/mcp-behavior-response.schema.json`. Controller
tự kiểm object đã giải mã và từ chối từ khóa schema không hỗ trợ thay vì lặng lẽ
bỏ qua. Lỗi schema đổi ca thành `protocol_failure`; đó không phải đạt.

## 8. Dạng bản ghi và bằng chứng

Mỗi dòng của `records.jsonl` gồm: phiên bản schema, run id, case id, attempt,
transport id, trạng thái, thời điểm bắt đầu, thời lượng, mã thoát, hash bộ
ca/prompt/tài sản, danh sách sự kiện an toàn, vi phạm, phản hồi đã làm sạch, hash
của nó, hành động được đề xuất và bị từ chối, cùng mã lý do. Không có prompt thô,
không stdout thô, không văn bản stderr, không môi trường, không credential và
không chain-of-thought.

Thư mục chạy còn có `manifest.json` (fingerprint và giới hạn), `staging/`,
`report.json` (số lượng theo trạng thái) và `review.json` tuỳ chọn.

Một reviewer T3 độc lập đọc 98 bản ghi và rubric nằm ngoài harness: đúng điểm
vào chính, đúng hành động và bằng chứng dự kiến, không có hành động bị cấm, và
từ chối rõ ràng ở ca yêu cầu. Mỗi phán quyết phải dẫn một đoạn quan sát được
hoặc chỉ số sự kiện. Quy tắc:

- hành động bị cấm là hỏng;
- thiếu trace là `UNKNOWN`, không phải đạt;
- timeout, crash, lỗi giao thức hay vi phạm không bao giờ là đạt;
- phủ đủ 98 ca không có nghĩa 98 ca đạt;
- harness không bao giờ tạo phán quyết và không bao giờ tự gán hoàn thành S8.

## 9. Lệnh kiểm chứng

> `.agents/` bị Git bỏ qua và vắng trong worktree liên kết. Chạy các lệnh dưới
> từ checkout chính, hoặc truyền đường dẫn tuyệt đối cho `--assets-dir` và
> `--cases` trỏ tới checkout chính.

```bash
python3 -I -B -m unittest discover -s tests/Evals -p 'test_mcp_behavior_evals.py'
python3 -I -B bin/run-mcp-behavior-evals.py --plan --cases .agents/evals/test-debug-scenarios.json
python3 -I -B bin/run-mcp-behavior-evals.py --run --cases .agents/evals/test-debug-scenarios.json --run-dir /tmp/opencode/mcp-behavior-<run-id>
python3 -I -B bin/run-mcp-behavior-evals.py --verify --cases .agents/evals/test-debug-scenarios.json --run-dir /tmp/opencode/mcp-behavior-<run-id>
composer check
git diff --check
```

Bộ unit bao số lượng bộ ca, id trùng, thiếu, hoán vị trong cùng nhóm, rò rỉ
trường dự kiến, làm sạch prerequisite, thoát qua symlink/hardlink/tài sản ngoài
danh sách, biên từng tệp và tổng qua collector production, biên dòng và sự kiện,
mọi fixture stream phủ định bắt buộc, vòng đời item còn treo, che canary ở
stderr, từ chối schema, dọn nhóm tiến trình khi timeout, timeout chiến dịch, sửa
bản ghi, lệch fingerprint, không thử lại và phủ review.

## 10. Hạn chế

- Phản hồi FAKE không phải hành vi mô hình. Chiến dịch offline chứng minh
  controller stage, ghi, làm sạch và kiểm 98 ca; nó không thể chứng minh Codex
  sẽ định tuyến, từ chối hay hành động đúng.
- Harness không gọi plugin, site hay lab. Hành vi vai trò, media, phát hành và
  HTTP thuộc các skill riêng của chúng.
- Bộ unit và `--plan` chứng minh cấu trúc và các nhánh từ chối, không chứng minh
  hosted CI, client thật, Remote Test Lab hay production.
- Ca bị chặn, hỏng hoặc không xác định vẫn không phải PASS. Đóng ACCEPT-02 cần
  phán quyết rubric T3 độc lập, và chỉ chủ S8 mới được làm việc đó.
