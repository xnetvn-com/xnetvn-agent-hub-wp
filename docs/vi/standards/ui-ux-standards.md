# Tiêu Chuẩn Giao Diện Người Dùng & Trải Nghiệm (UI/UX Standards)

> **Bộ tiêu chuẩn thiết kế giao diện trang quản trị WordPress, hướng tới tính thẩm mỹ, nhất quán và WCAG 2.2 Level AA. Mục tiêu và kết quả quan sát được ghi tách riêng ở §9; xem thêm [Hướng Dẫn Kiểm Thử](../TESTING.md).**

---

## 1. Nguyên Tắc Thiết Kế Giao Diện WordPress Admin

1. **Hòa nhập hoàn hảo với WordPress Core Design System:**
   - Sử dụng các lớp CSS chuẩn của WordPress (`wrap`, `button`, `button-primary`, `notice`, `form-table`, v.v.).
   - Tôn trọng màu sắc giao diện quản trị mà người dùng đã chọn trong Hồ sơ WordPress cá nhân.
2. **Minh bạch & An toàn Tuyệt Đối trong Xử Lý Khóa Bí Mật:**
   - Khóa API bí mật được hiển thị trong hộp thoại nổi (Modal Dialog) với cảnh báo rõ ràng.
   - Hộp thoại có nút sao chép nhanh sử dụng Clipboard API an toàn.
   - Khi người dùng đóng hộp thoại (bấm Hoàn tất hoặc nhấn phím `Escape`), khóa bí mật sẽ bị hủy vĩnh viễn khỏi bộ nhớ DOM của trình duyệt.
3. **Phản Hồi Trạng Thái Rõ Ràng (Clear Status Feedback):**
   - Mọi thao tác tạo, sửa, xóa hoặc luân chuyển khóa đều có thông báo trạng thái tức thì (Toast hoặc Admin Notice).
   - Sử dụng trạng thái tải (Loading state / Spinner) khi đang gửi yêu cầu qua API để tránh bấm nhiều lần (Debounce / Disable button).

---

## 2. Tiêu Chuẩn Tiếp Cận WCAG 2.2 Level AA (Accessibility Standards)

Mục tiêu: giao diện quản trị của xNetVN Agent Hub WP hướng tới tiêu chuẩn quốc tế **WCAG 2.2 Level AA**. Mức tuân thủ quan sát được ghi riêng ở §9. Kết quả axe tự động không đủ để kết luận tuân thủ WCAG; xem [Hướng Dẫn Kiểm Thử](../TESTING.md) và [architecture/overview.md](../architecture/overview.md).

### 2.1 Độ Tương Phản Màu Sắc (Color Contrast)

- Văn bản thông thường đạt tỷ lệ tương phản tối thiểu **4.5:1** so với màu nền.
- Văn bản lớn (từ 18pt trở lên) và các biểu tượng giao diện đạt tỷ lệ tối thiểu **3.0:1**.
- Không sử dụng màu sắc làm chỉ số duy nhất để truyền tải thông tin (ví dụ: trạng thái Hoạt động/Đã thu hồi phải có cả nhãn chữ và màu sắc).

### 2.2 Điều Hướng Bằng Bàn Phím (Keyboard Navigation)

- 100% các thành phần tương tác (nút bấm, liên kết, ô nhập liệu, modal) đều có thể truy cập và thao tác hoàn toàn bằng bàn phím (`Tab`, `Shift+Tab`, `Enter`, `Space`, `Esc`).
- Hộp thoại Modal phải có tính năng bẫy tiêu điểm (Focus Trap) khi đang mở và khôi phục tiêu điểm (Focus Restoration) về nút kích hoạt ban đầu sau khi đóng.
- Có chỉ báo tiêu điểm rõ ràng (Visible Focus Ring: `outline: 2px solid #2271b1`).

### 2.3 Nhãn Ngữ Nghĩa Cho Trình Đọc Màn Hình (Screen Readers & ARIA)

- Các biểu tượng hoặc nút chỉ có icon phải có thuộc tính `aria-label` mô tả rõ ràng hành động (ví dụ: `aria-label="Sao chép khóa API vào bộ nhớ tạm"`).
- Các thông báo lỗi và trạng thái sử dụng `role="alert"` hoặc `aria-live="polite"` để trình đọc màn hình thông báo cho người khiếm thị mà không làm gián đoạn trải nghiệm.
- Bảng danh sách khóa và nhật ký kiểm toán có tiêu đề cột (`<th>` với `scope="col"`) và chú thích bảng (`<caption>`).

---

## 3. Kiểm Thử Tự Động Giao Diện & Tiếp Cận

- Dự án tích hợp công cụ kiểm tra tự động **`@axe-core/playwright`** trong bộ kiểm thử E2E (`tests/E2E/admin.spec.js`).
- Mục tiêu cho mọi Pull Request làm thay đổi giao diện: kiểm thử Axe Core với **0 lỗi vi phạm WCAG 2.2 AA**. Kết quả axe đạt chỉ là tín hiệu, không tự nó chứng minh tuân thủ WCAG; vẫn cần kiểm thủ công, kiểm trình đọc màn hình và hồ sơ nghiệm thu ở §9.
- Các bộ UI chạy trên Remote Test Lab; xem [Hướng Dẫn Kiểm Thử](../TESTING.md) về thiết lập, `UI_BASE_URL` từ handoff của lab và ánh xạ `UI-FX/COM/OV/TL/KY/AC/ST/HP/NT`.

---

## 4. Các Trang Quản Trị (Sáu Giao Diện)

Triển khai tại `src/Admin/Pages.php` (`titles()`, `menu()`, `render()`, `navigation()`). Cả sáu giao diện đều yêu cầu quyền quản trị; tài khoản bị từ chối chỉ nhận thông báo từ chối, không thấy nội dung quản trị. Điều hướng đánh dấu trang hiện tại bằng `aria-current="page"` và mỗi giao diện giữ một tiêu đề chính.

| Giao diện | Đường dẫn quản trị | Luồng và điều khiển chính |
|---|---|---|
| Overview | `admin.php?page=xnetvn-agent-hub-wp` | Danh sách bắt đầu (3 bước với liên kết tới profile, Keys, Help); ô `MCP endpoint` chỉ đọc kèm nút `Copy endpoint`; danh sách định nghĩa `Connection` và `Site`; chẩn đoán `Check Authorization header` (chỉ báo có/không, một request); liên kết help khi connection chưa sẵn sàng. |
| Keys | `admin.php?page=xnetvn-agent-hub-wp-keys` | Bảng theo phạm vi (`Name`, `Key ID`, `Owner`, `Risk tier`, `Toolsets`, `Created`, `Last used`, `Expires`, `Status`, `Actions`); bộ lọc quản trị `state` (`all`/`active`/`expired`/`revoked`) và `owner`; nhóm tạo khóa (§7); hộp thoại bí mật và hộp thoại xác nhận; phân trang. |
| Activity | `admin.php?page=xnetvn-agent-hub-wp-activity` | Bộ lọc chỉ đọc `owner`, `key_id` (`[a-z2-7]{12}`), `ability`, `outcome` (`all`/`success`/`denied`/`error`/`invalid_input`/`rate_limited`/`auth_failed`), `after`/`before` (`datetime-local`, đổi sang UTC); bảng (`Time`, `User`, `Ability`, `Outcome`, `Transport`); phân trang. Bộ lọc chỉ thu hẹp danh sách. |
| Tools | `admin.php?page=xnetvn-agent-hub-wp-tools` | Tra cứu catalog: `search` (`Name, label or description`), `toolset`, `tier` (`all`/`read`/`write`/`destructive`/`admin`); đếm trực tiếp và trạng thái rỗng `No matching tools`. Không có hành động chạy và không gọi MCP từ màn hình này. |
| Settings | `admin.php?page=xnetvn-agent-hub-wp-settings` | Form Settings API chuẩn (`options.php`) với các nhóm `Access`, `Network`, `Limits`, `Audit and data`, `Notifications`, `Media`; mọi field giữ nguyên tên, kiểu và biên; help theo nhóm kèm cảnh báo tác động; một nút `Save settings`, không AJAX. |
| Help | `admin.php?page=xnetvn-agent-hub-wp-help` | Bốn bước hướng dẫn (tạo khóa, sao chép một lần, cấu hình endpoint với header `Authorization: Bearer` và token mẫu `<YOUR_TOKEN>`, kiểm connection); endpoint sao chép được; mẫu `MCP_ENDPOINT=https://example.invalid/wp-json/xnetvn-agent-hub-wp/mcp` với `REDACTED` cho giá trị chưa xác định; gỡ sự cố `401`/`403`/`429`/`503`; liên kết thu hồi khẩn cấp tới Keys. |

---

## 5. Hồ Sơ và User-Edit

Kết xuất bởi `src/Admin/Pages.php` (`profile()`) trên `profile.php` và `user-edit.php`, không lồng form trong form profile của WordPress. Mục này chỉ hiện khi chủ hồ sơ được phép dùng plugin và người xem là chủ sở hữu hoặc quản trị; người xem khác không thấy gì. Quản trị liệt kê mọi chủ sở hữu dùng giao diện Keys với `data-all="1"`; hồ sơ khóa cố định chủ sở hữu.

- Tiêu đề `Agent Hub API Keys` với cùng bảng Keys, trạng thái và phân trang như §4.
- Hồ sơ của chính mình có thêm ô chọn thông báo (`Email me about key creation, administrator revocation, expiry and unusual use.`) chỉ lưu dưới nonce hồ sơ tường minh của chính chủ.
- Các field tạo khóa chỉ hiện cho chủ hiện tại có quyền dùng.

---

## 6. Trạng Thái Request (Busy, Error, Empty, Unknown)

Mọi mục đọc (`overview`, `activity`, `tools`, danh sách Keys) đều kết xuất cùng ba vùng từ `src/Admin/Pages.php` (`read_state()`) và điều khiển qua `assets/admin.js`, không bao giờ ghi bí mật vào DOM trừ ô một lần ở §7.

- Busy: vùng chứa đặt `aria-busy="true"` và hiện `Loading…` (`data-loading`); cờ được xóa trong `finally` nên lượt đọc chậm vẫn thông báo tải mà không xóa hàng hiện tại.
- Error: `data-error` kèm nội dung lỗi và một nút `Try again` (`data-retry`) chỉ gửi đúng một GET đọc; mutation không bao giờ tự retry. Hết phiên (`401`/`403`) hướng dẫn tải lại và đăng nhập, không in lỗi thô.
- Empty: `data-empty` (`No keys found.`, `No activity found.`, `No tools available.`, `No status data available.`).
- Kết quả chưa xác định: response mutation bị mất thông báo `The key change finished with an uncertain result. The list will reload; check it before creating a replacement key.` Field thiếu hoặc không đọc được hiển thị `Unavailable`; giá trị đã thu hồi hoặc chưa xác định hiển thị `REDACTED`. Refresh lỗi vẫn giữ bí mật đang hiển thị; chỉ đóng tường minh mới xóa.

---

## 7. Bí Mật và Mật Khẩu (Hiển Thị Một Lần)

- Tạo thành công trả token đầy đủ một lần trong `dialog` chuẩn (`data-secret-dialog`): tiêu đề `Save your new key now`, help `This key is shown once. Closing this window clears it. Copy it now and store it securely.`, ô bí mật chỉ đọc (`data-secret`), nút `Copy key` và `I have saved the key`.
- Ô chỉ đọc là nơi duy nhất giữ bí mật; helper sao chép từ chối mọi input gắn `data-secret` ngoài hộp thoại đó và thông báo qua live region (`role="status"`, `aria-live="polite"`).
- Đóng (nút xác nhận, `Escape` hoặc đóng dialog) xóa giá trị khỏi DOM; assertion chỉ dùng boolean hoặc độ dài để trace không dính giá trị credential.
- Tier rủi ro cao yêu cầu mật khẩu hiện tại: form tạo chỉ hiện `Current password` cho `destructive`/`admin`, và hộp thoại xác nhận (`data-action-dialog`) hiện nhóm mật khẩu cho cùng tier. Help tier ghi `Choose the lowest tier needed. Your current WordPress permissions still apply. Destructive and Admin keys require your current password.`

---

## 8. Bàn Phím, Reflow và RTL

- Bàn phím: mọi điều khiển ở §4–§7 đều tới và thao tác được bằng bàn phím; các `details` gỡ sự cố mở được bằng bàn phím; field Settings sai giữ focus, nút Save vẫn bấm được và thông báo assertive (`Settings were not sent. Check the highlighted field.`).
- Focus: vòng focus `outline: 2px solid #2271b1` (`:focus-visible`); dialog bí mật và dialog hành động bẫy focus khi mở và trả focus về nút đã gọi khi đóng; bảng giữ focus bàn phím qua các lần kiểm reflow.
- Reflow: UI kiểm viewport `390px`, `768px`, `1440px` và reflow `320px` không tràn ngang cấp trang (`document.documentElement.scrollWidth - clientWidth <= 1`); tên dài, Unicode và endpoint tự xuống dòng (`overflow-wrap: anywhere`); bảng cuộn trong `.xnetvn-table-wrap` (`tabindex="0"`, `role="region"`).
- RTL: kiểm bố cục `right-to-left` giữ nội dung trong viewport; không cần stylesheet RTL riêng vì bố cục dùng luồng logic và selector `.xnetvn-` có phạm vi.
- Chuyển động: `prefers-reduced-motion: reduce` tắt chuyển động không cần thiết.

---

## 9. Mục Tiêu Đối Chiếu Kết Quả Quan Sát (Hồ Sơ Nghiệm Thu)

Không đọc một kiểm tự động xanh thành claim phát hành, hosted-CI, real-client, host hay production. Nghiệm thu toàn diện P13 (ba browser, hai single-site và multisite) chưa có evidence trong revision này và giữ trạng thái pending.

| Claim | Mục tiêu | Kết quả quan sát trong revision này |
|---|---|---|
| Tuân thủ WCAG 2.2 AA | Tuân thủ WCAG 2.2 Level AA theo §2 | Chưa xác lập. Axe chỉ là tín hiệu; kiểm thủ công và kiểm trình đọc màn hình đang pending. |
| Kiểm axe tự động | 0 lỗi axe trên UI thay đổi | Kết quả theo từng lượt chạy; ghi revision, site, browser và role. Không claim pass cố định ở đây. |
| Flow/field/error sáu giao diện (§4–§7) | Khớp `src/Admin/Pages.php` và `assets/admin.js` | Khớp revision `45d9e36` như mô tả trên; bằng chứng runtime thuộc hồ sơ chạy trong [Hướng Dẫn Kiểm Thử](../TESTING.md). |
| Bàn phím, reflow (`390`/`768`/`1440`, `320`), RTL (§8) | Không tràn, giữ focus, RTL trong viewport | Kết quả theo từng lượt chạy; xem hồ sơ kiểm. |
| Nghiệm thu P13 (ba browser, hai site, multisite) | Ma trận xanh đầy đủ | Pending: chưa có evidence trong revision này. |
| Thủ công, trình đọc màn hình, real-client, hosted CI, host, production | Có evidence riêng | Chưa xác minh trong revision này; không suy ra từ `git diff --check` hay lượt chạy focused. |

Mỗi lượt chạy ghi thời điểm, revision, site (`wp69`/`wp71`), browser, role và lệnh; xem [Hướng Dẫn Kiểm Thử](../TESTING.md).
