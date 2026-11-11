# Lưu trữ phiếu

Trạng thái: chấp thuận 2026-11-03. Quyết định của chủ dự án: tài liệu tiếng Anh trong `docs/en/` là chuẩn và `docs/vi/` là bản dịch. Phiếu của dự án không phải tài liệu: chúng nằm trong một kho bị Git bỏ qua trên máy này.

## Nơi lưu phiếu

| Kho | Nội dung | Ngôn ngữ | Trong Git |
|---|---|---|---|
| `.local/docs/en/issues/` | toàn bộ phiếu của dự án | Lược đồ tiếng Anh; phần chữ của phiếu lịch sử đã chuyển có thể còn tiếng Việt | Không, bị Git bỏ qua |

Kho thuộc checkout chính (`git rev-parse --path-format=absolute --git-common-dir` cho thư mục Git ở đó). Hãy chạy verifier nghiệm thu từ checkout đó; chạy từ worktree thì tra cứu mặc định không thấy kho và gate fail closed.

`docs/vi/issues/` và `docs/en/issues/` không còn tồn tại. 70 phiếu đã được chuyển vào kho ngày 2026-11-03 và hai cây được gỡ trong cùng thay đổi; lịch sử của chúng vẫn nằm trong Git.

## Bố cục

```
.local/docs/en/issues/
├── v0.1/
│   ├── bugs/
│   ├── feature_requests/
│   ├── others/
│   └── refactor/
├── .journal.jsonl        sổ nhật ký ghi (mỗi lần công cụ ghi một dòng JSON)
├── .migrated-from        nguồn gốc chuyển đổi
├── .migration-report.md  kết quả chuyển từng phiếu
└── .translation-report.md kết quả dịch từng phiếu
```

## Quy tắc

- Kho không bao giờ được commit, không vào gói phát hành và không bao giờ bị force-add. `.local/` bị `.gitignore` gốc bỏ qua.
- Phiếu chỉ được tạo và sửa qua `issue_tool.py`: `new`, `status`, `claim`, `block`, `progress`, `answer` và `migrate`. Sửa văn bản trực tiếp vẫn được nhưng để lại cảnh báo kiểm toán (`IS41`).
- Sao lưu kho ra ngoài kho trước mọi thao tác ghi hàng loạt, ví dụ `tar -C <checkout> -czf <ngoài-kho>/tickets-<ngày>.tgz .local/docs/en/issues`.
- Không dùng `git clean -x` mà thiếu `-e .local/`: cờ đó xoá cả tệp bị bỏ qua, tức xoá kho.
- Tìm bằng `rg --no-ignore` hoặc `issue_tool.py board`; `rg`, Grep, Glob mặc định bỏ qua đường dẫn bị ignore nên phiếu trông như không tồn tại.
- Máy khác hoặc worktree trên lab không đọc được kho. Hãy gửi bản giao việc kèm nội dung phiếu, và giữ máy này là nơi ghi duy nhất.

## Ngôn ngữ

Mọi phiếu đều viết bằng tiếng Anh chuyên nghiệp. Toàn bộ 70 phiếu đã chuyển được dịch ngày 2026-11-03: `issue_tool.py check` không còn cảnh báo `IS42`, và không còn chữ tiếng Việt nào ngoài khối mã. Tiếng Việt chỉ còn là dữ liệu được trích trong 47 giá trị mã nội tuyến khác nhau (67 lần xuất hiện) và 5 dòng trong khối mã: thuật ngữ glossary mà tài liệu tiếng Việt buộc phải dùng, tiêu đề bài mẫu cố định, tiêu đề bài viết trong một lệnh, các dòng nhật ký trạng thái lịch sử, tên tiêu đề mẫu tiếng Việt, một mẫu `rg` khớp các tiêu đề đó, một khối output kiểm thử lịch sử được trích, và một chú thích mốc báo cáo chiến dịch. Đó là bằng chứng hoặc đầu vào nên phải giữ nguyên; bản dịch không bao giờ được sửa định danh, lệnh được trích, con số đo hay giá trị đã lưu.

## Chủ sở hữu gate nghiệm thu

Verifier nghiệm thu đọc trạng thái chủ sở hữu gate từ kho khi kho có mặt. Worktree trên lab không mang theo kho, nên gate không tìm được chủ sở hữu sẽ không được áp dụng và ca đỏ của nó vẫn đỏ: quy tắc fail closed và không bao giờ che lỗi. Unit và integration test dùng fixture đã commit trong `tests/Fixtures/tickets/`, nên bản clone mới vẫn kiểm thử được mà không cần kho.

## Hồ sơ chuyển đổi

- Công cụ: `issue_tool.py migrate` của skill `lc-udev-writing-project-issues`, phiên bản 2.0.0.
- Các lượt: `docs/vi/issues` với `--prefer-en docs/en/issues` (67 phiếu), rồi `docs/en/issues` (3 phiếu chỉ có tiếng Anh).
- Ngày và người thực hiện: 2026-11-03, `opencode-docs-sync-20261103`.
- Kết quả: 70 phiếu được ghi, 0 lỗi sau khi bổ sung mục metadata và nhật ký trạng thái mà ba phiếu tiếng Anh cũ còn thiếu; còn 25 cảnh báo về khả năng nhận việc của phiếu đã đóng hoặc còn câu hỏi mở.
- Gỡ cây cũ: `git rm -r -- docs/en/issues docs/vi/issues`, trong cùng thay đổi đã review.
- Sao lưu trước khi chuyển: một tệp nén 961 KB của cả hai cây cũ, giữ ngoài kho.
- Sao lưu kho sau khi dịch: `$HOME/.local/share/xnetvn-agent-hub-wp/backups/ticket-store-20261103.tgz` (987 KB, 70 phiếu và báo cáo), cũng nằm ngoài checkout.

## Kiểm chứng

`composer check:docs` chạy `bin/check-docs-parity.py`, kiểm đối xứng đường dẫn Anh/Việt, cấu trúc tiêu đề và mọi liên kết tài liệu tương đối. Công cụ không quét kho phiếu, vì kho không phải tài liệu và không được phát hành; `issue_tool.py board` và `check` là cổng dành cho phiếu.
