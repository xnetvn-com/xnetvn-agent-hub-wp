# Hướng Dẫn Tự Động Hóa Triển Khai (CI/CD Deployment Guide)

> **Hướng dẫn đường ống tích hợp và triển khai liên tục (CI/CD Pipeline) với GitHub Actions cho xNetVN Agent Hub WP.**

---

## 1. Cấu Trúc Pipeline

Pipeline được chia thành các workflow độc lập để lỗi ở một vùng không che lấp
vùng khác. Cổng nguồn là đồ thị fan-out/fan-in; coverage, bảo mật, phụ thuộc,
SBOM, tài liệu, phát hành, tuân thủ và hiệu năng chạy bằng workflow riêng với
điều kiện kích hoạt riêng.

```text
pull request hoặc push lên main
  ├──► cổng nguồn (ci.yml)
  ├──► cổng coverage (coverage-check.yml)
  ├──► cổng bảo mật và phụ thuộc
  ├──► workflow lint
  └──► plugin check, SBOM và triển khai tài liệu (lọc theo đường dẫn)

tag v* ──► kiểm tra changelog + kiểm tra tuân thủ + draft release (SBOM + ZIP)
```

---

## 2. Bố Cục Workflow

Nguồn chuẩn của workflow là các tệp YAML trong `.github/workflows/`. Hướng dẫn
này cố ý không chép lại YAML mẫu, vì bản chép sẽ lệch khỏi nguồn đã được gia cố.

| Workflow | Điều kiện kích hoạt | Mục đích |
|---|---|---|
| `ci.yml` | pull request, push lên `main`, thủ công | Cổng nguồn: chuẩn mã, phân tích cú pháp bootstrap PHP 7.2, PHPStan, các ràng buộc bất biến của kho, đồng bộ tài liệu, ma trận unit, ma trận integration, đóng gói và hợp đồng tooling. `ci-result` là kiểm bắt buộc ổn định cho workflow này. |
| `coverage-check.yml` | pull request, push lên `main`, thủ công | Coverage unit và integration gốc cùng cổng số học chống giảm. Pull request chỉ đổi tài liệu khớp danh sách cho phép báo thành công rõ ràng không kèm artifact thay vì thu thập coverage. |
| `workflow-lint.yml` | pull request, push lên `main`, thủ công | actionlint, zizmor và hợp đồng workflow; luôn báo kiểm ổn định `workflow-lint`. |
| `security-scan.yml` | pull request, push lên `main`, hằng tuần, thủ công | Gitleaks phát hiện bí mật, Semgrep phân tích tĩnh và Trivy cảnh báo hệ thống tệp. |
| `dependency-audit.yml` | pull request, push lên `main`, hằng tuần, thủ công | Cảnh báo Composer và npm theo lockfile. |
| `sbom.yml` | phụ thuộc đổi, thủ công, được release gọi | SBOM CycloneDX và SPDX cho Composer và npm. |
| `plugin-check.yml` | mã nguồn đóng gói đổi, thủ công | WordPress Plugin Check trên ZIP đã dựng. |
| `docs-deploy.yml` | tài liệu đổi trên `main`, thủ công | Gói tài liệu; chỉ triển khai Pages khi `XNETVN_PAGES_ENABLED=true`. |
| `changelog.yml` | tag `v*`, thủ công | Kiểm tra nhất quán phiên bản và trích release notes. |
| `compliance-check.yml` | hằng tuần, tag `v*`, thủ công | Các ràng buộc bất biến của kho qua `bin/check-repo-invariants.sh` và kiểm tra nhất quán phiên bản. |
| `release.yml` | tag `v*` | ZIP đã xác minh và đủ tệp SBOM trong GitHub Release nháp. |
| `performance-test.yml` | thủ công | Hợp đồng benchmark và ngân sách thời gian đóng gói. |
| `pr-labeler.yml` | pull request target | Gắn nhãn theo đường dẫn trên runner self-hosted; chỉ pull request cùng kho và không bao giờ checkout mã pull request. |

Cổng nguồn dùng đồ thị fan-out/fan-in: `quality` chạy trước để dừng sớm trước
các ma trận unit và integration; các job build và tooling độc lập chạy song
song; `ci-result` gộp toàn bộ kết quả. Luật nhánh chỉ nên yêu cầu các ngữ cảnh
ổn định trong `.github/settings.yml`, không bao giờ yêu cầu tên một chân ma trận.

Mọi job thực thi khai báo `timeout-minutes` và quyền tối thiểu; job gọi
reusable workflow kế thừa timeout của các job được gọi. Action bên thứ ba được
ghim vào commit đầy đủ. Biến kho có thể chọn runner do chủ kho quản lý; nếu
không, job dùng `ubuntu-24.04`. Job giữ token ghi hoặc danh tính OIDC luôn chạy
trên hạ tầng GitHub-hosted.

---

## 3. Quy Tắc Gắn Thẻ Phát Hành (Release Tagging Rules)

- Tuân thủ nghiêm ngặt Semantic Versioning: `v0.1.0`, `v0.2.0`, `v1.0.0`.
- Tag phải khớp header plugin, stable tag trong `readme.txt` và mục tương ứng
  trong `CHANGELOG.md`; `changelog.yml` và `release.yml` thực thi điều này, và
  `release.yml` từ chối tag sai định dạng hoặc không khớp trước khi job nháp
  cài đặt phụ thuộc.
- Một tag khởi động `release.yml`: xác minh gói, tạo đầy đủ SBOM Composer và npm,
  rồi tạo GitHub Release **nháp**.
- Phát hành bản nháp là việc thủ công của chủ kho sau khi xem cả lượt chạy
  release và lượt chạy changelog.
- Không bao giờ ghi đè, di chuyển hay force-push tag đã phát hành. Mỗi tag phải
  gắn với một bản build bất biến kèm ZIP và release notes.
