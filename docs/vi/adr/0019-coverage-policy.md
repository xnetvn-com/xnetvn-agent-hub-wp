# ADR-0019: Chính sách cổng độ bao phủ

Trạng thái: Accepted (2026-10-31). Chủ dự án đã rà mô hình ngưỡng ngày 2026-10-31 và chọn chính sách 1.0 đã ghi trong tài liệu thay vì các con số chặt hơn mà cổng CI khai ngày 2026-10-28. Đã sửa đổi ngày 2026-11-05 (A1). Bản tiếng Anh (`docs/en/adr/0019-coverage-policy.md`) là nguồn chuẩn.

## Bối cảnh

Commit `1d58adb` đưa ngưỡng độ bao phủ dạng số vào `.github/scripts/CoverageGate.php`. Các con số bị áp là `combined.overall >= 95`, `combined.Domain|Auth|Policy >= 90`, `unit.overall >= 80`, bốn bộ integration `>= 70`, `unit.Domain|PureAuth >= 90`, kèm so sánh không giảm so với `.ecc/coverage/M1-lines.json` (nay là `.github/coverage/M1-lines.json`).

Lượt đo độ bao phủ gốc trên `0882134` cho `combined.overall 6533/7857 = 83.15%`, `combined.Auth 391/435 = 89.89%` và `unit.overall 1416/7841 = 18.06%`. Cổng chưa từng xanh trên `main`, nên mọi pull request đều thừa hưởng một job bắt buộc đỏ.

Hai nguồn của dự án không khớp với cổng:

- `docs/en/TESTING.md` §1 và `docs/vi/TESTING.md` §1 cam kết 90% cho `Domain`, `Auth`, `Policy` và **80% toàn dự án** cho bản phát hành, không phải 95% gộp và không phải 80% cho từng bộ unit.
- `.ecc/coverage/M1-lines.json` (nay là `.github/coverage/M1-lines.json`) ghi `unit.overall 572/2794 = 20.47%`. Bộ unit không phải bộ lọc toàn plugin: bốn bộ integration mới chạy trên runtime WordPress. Vì vậy sàn 80% cho unit mâu thuẫn với baseline đã ghi và với thiết kế đã tài liệu hoá.

Cổng độ bao phủ là tín hiệu chất lượng phát hành, không thay thế kiểm thử hành vi. Hạ một sàn mà bỏ quy tắc chống giảm sẽ che mất suy giảm thật.

## Quyết định

- D1 (chủ dự án, 2026-10-31): áp ngưỡng 1.0 đã ghi trong tài liệu cho từng pull request:
  - `combined.overall >= 80`
  - `combined.Domain >= 90`, `combined.Auth >= 90`, `combined.Policy >= 90`
  - mỗi bộ integration `>= 70`
  - `unit.Domain >= 90`, `unit.PureAuth >= 90`
- D2 (chủ dự án, 2026-10-31): bỏ sàn tuyệt đối `unit.overall >= 80`. Bộ unit giữ so sánh không giảm so với baseline `572/2794` thay vì một mục tiêu tuyệt đối mà bộ này chưa từng được thiết kế để đạt.
- D3: giữ quy tắc không giảm cho mọi chỉ số có trong baseline: `combined.overall`, `combined.Domain`, `combined.Auth`, `combined.Policy`, `unit.Domain`, `unit.PureAuth` và `unit.overall`.
- D4: giữ hợp đồng skip chấp nhận đúng 17 skip single-site và 12 skip multisite cho các ca chỉ chạy được trong lab, và từ chối mọi con số skip khác.
- D5: không hạ ngưỡng nào lần nữa nếu không có quyết định mới của chủ dự án và một ADR thay thế.

## Sửa đổi

- A1 (chủ dự án, 2026-11-05): pull request chỉ đổi tài liệu được miễn thu thập coverage. Việc miễn trừ dùng cùng danh sách cho phép với cổng nguồn (`docs/**`, `README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `SUPPORT.md`, `CODE_OF_CONDUCT.md`, `CHANGELOG.md` ở gốc). Mọi pull request khác giữ nguyên D1–D4. Check bắt buộc báo thành công kèm ghi chú rõ ràng và không bao giờ tuyên bố có số đo. Việc miễn trừ không hạ ngưỡng nào, không đổi yêu cầu coverage khi phát hành và không nới quy tắc chống hồi quy cho thay đổi mã nguồn.

## Hệ quả

- Cổng nay khớp với `docs/en/TESTING.md` và `docs/vi/TESTING.md`; tài liệu mô tả tường minh các con số đang bị áp.
- Khôi phục các chỉ số không giảm đòi hỏi kiểm thử hành vi mới thay vì sửa ngưỡng: `tests/Unit/Abilities/RegistryTest.php`, `tests/Unit/Abilities/OutputPipelineTest.php`, `tests/Unit/Auth/ServiceWiringTest.php`, `tests/Unit/Domain/ValueObjectGuardsTest.php` và fixture idempotent `tests/Support/StandaloneWordPressError.php`.
- Bằng chứng cục bộ tại lần thay đổi này: `unit.overall 1627/7846 = 20.74%` (trên baseline `20.47%`), `combined.overall 6574/7862 = 83.62%`, `combined.Auth 395/435 = 90.81%`, `combined.Domain 68/69 = 98.55%`, `unit.Domain 68/69 = 98.55%`.
- Nâng số về sau là bánh cóc bình thường: thêm test trước, rồi đổi ADR này và đổi cổng trong cùng một pull request đã review. Các con số 95/80 có thể trở lại làm cổng phát hành khi mã nguồn đạt tới chúng bằng bằng chứng đo được.
- Cổng vẫn là kiểm tra số trên số dòng đo được. Nó không thay thế nghiệm thu integration, runtime, client hay production.
