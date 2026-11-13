# Mục Lục Hệ Thống Tài Liệu Dự Án (Documentation Index)

> **Cổng tra cứu tài liệu trung tâm của xNetVN Agent Hub WP (`xnetvn-agent-hub-wp`).**
> *Lưu ý: tài liệu chuẩn của dự án là bộ tiếng Anh trong `docs/en/`; `docs/vi/` giữ bản dịch tiếng Việt. Khi hai bản lệch nhau, tài liệu tiếng Anh là chuẩn và bản tiếng Việt được cập nhật theo.*

---

## 🧭 Điều Hướng Nhanh

```
docs/vi/
├── 1. Tài liệu căn bản: README.md | INSTALL.md | ARCHITECTURE.md | TESTING.md | DEPLOYMENT.md | development*.md
├── 2. Vận hành & Cấu hình: ENVIRONMENT.md | GLOSSARY.md | TROUBLESHOOTING.md | FAQ.md | SUPPORT.md
├── 3. Tiêu chuẩn kỹ thuật: standards/ (coding, api, ui-ux, testing, logging, issue-storage)
├── 4. Chính sách: policies/ (security, privacy, incident, access, data-retention)
├── 5. Hướng dẫn nghiệp vụ: guides/ (developer, operation, deployment, scaling, monitoring, agent-skill-pack)
├── 6. Tuân thủ quốc tế: compliance/ (gdpr, iso27001, pci-dss, audit)
├── 7. Kiến trúc & Thiết kế: architecture/ (overview, system-overview, DFD, ADR) | specs/
├── 8. An toàn thông tin: security/ (threat-modeling, threat-model, vulnerability, secure-coding, http-acceptance)
├── 9. Vận hành & Runbooks: operations/ (runbooks, incident-response, backup, disaster-recovery) | operations.md
├── 10. Nhân sự & Onboarding: onboarding/ (checklist, responsibilities, tooling)
├── 11. Pháp lý & Lịch sử: legal/ | changelogs/ (release notes) | reports/ | templates/
```

---

## 📋 Danh Mục Chi Tiết

### 1. Tài Liệu Căn Bản & Bắt Đầu Nhanh
- **[README.md](README.md):** Tổng quan dự án, tính năng nổi bật, kiến trúc tóm tắt và hướng dẫn bắt đầu.
- **[INSTALL.md](INSTALL.md):** Yêu cầu hệ thống (PHP 8.2+, WP 6.9+), các phương thức cài đặt, khởi tạo database.
- **[ARCHITECTURE.md](ARCHITECTURE.md):** Kiến trúc phân tầng, phân quyền giao thoa, các ranh giới bảo mật bất biến.
- **[TESTING.md](TESTING.md):** Kim tự tháp kiểm thử, Unit Test, Integration Test, E2E Test, đo lường độ bao phủ.
- **[DEPLOYMENT.md](DEPLOYMENT.md):** Đóng gói ZIP, cấu hình Nginx/Apache, cụm máy chủ, Redis Cache và rollback.
- **[ENVIRONMENT.md](ENVIRONMENT.md):** Danh mục hằng số wp-config, options bảng wp_options, cấu hình môi trường.
- **[GLOSSARY.md](GLOSSARY.md):** Bảng thuật ngữ chuyên ngành (MCP, Abilities, Pepper, Capability Intersection,...).
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md):** Bảng tra cứu mã lỗi (401, 403, 429, 503) và quy trình chẩn đoán.
- **[FAQ.md](FAQ.md):** Các câu hỏi thường gặp về tính năng và bảo mật.
- **[SUPPORT.md](SUPPORT.md):** Kênh hỗ trợ kỹ thuật, cam kết thời gian phản hồi (SLA).
- **[roadmap.md](roadmap.md):** Lộ trình phát triển các cột mốc M0–M7, từ nền tảng tới OAuth và chuẩn bị phát hành.
- **[CONTRIBUTING.md](CONTRIBUTING.md):** Hướng dẫn đóng góp mã nguồn, Git workflow, conventional commits.
- **[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md):** Quy tắc ứng xử cộng đồng văn minh, hòa nhập.
- **[LICENSE](LICENSE):** Giấy phép mã nguồn mở GNU General Public License v2.0 or later.
- **[CHANGELOG.md](CHANGELOG.md):** Lịch sử thay đổi và phát hành phiên bản theo SemVer.
- **[development.md](development.md):** Quy trình phát triển, kiểm cục bộ và ranh giới lab từ xa.
- **[development-coverage.md](development-coverage.md):** Baseline độ bao phủ, cách đo và bằng chứng số liệu.
- **[development-dependency-security.md](development-dependency-security.md):** Chính sách phụ thuộc, chuỗi cung ứng và khuyến nghị bảo mật.
- **[development-real-clients.md](development-real-clients.md):** Bộ kiểm MCP client công khai và giới hạn của nó.
- **[changelogs/release-notes/v0.1.0.md](changelogs/release-notes/v0.1.0.md):** Ghi chú phát hành 0.1.0.
- **[changelogs/release-notes/v1.0.0.md](changelogs/release-notes/v1.0.0.md):** Ghi chú phát hành 1.0.0.
- **[reports/mcp-specialized-skills-acceptance.md](reports/mcp-specialized-skills-acceptance.md):** Báo cáo nghiệm thu kỹ năng MCP chuyên biệt.
- **[reports/stab03-plugin-diagnosis-20261027.md](reports/stab03-plugin-diagnosis-20261027.md):** Chẩn đoán STAB-03 chỉ trong plugin và các số đo.

### 2. Tiêu Chuẩn Kỹ Thuật (`standards/`)
- **[standards/coding-standards.md](standards/coding-standards.md):** Tiêu chuẩn định dạng mã nguồn PHP, JS, CSS, Docblock.
- **[standards/api-standards.md](standards/api-standards.md):** Tiêu chuẩn thiết kế API (MCP JSON-RPC & Management REST).
- **[standards/ui-ux-standards.md](standards/ui-ux-standards.md):** Tiêu chuẩn giao diện người dùng WordPress Admin, WCAG 2.2 AA.
- **[standards/testing-standards.md](standards/testing-standards.md):** Tiêu chuẩn viết kiểm thử, nguyên tắc Given-When-Then, mocks.
- **[standards/logging-standards.md](standards/logging-standards.md):** Tiêu chuẩn ghi nhật ký kiểm toán, mask IP, không lưu secret.
- **[standards/issue-storage.md](standards/issue-storage.md):** Nơi lưu phiếu, lý do kho chỉ tồn tại cục bộ và cách chạy di chuyển trong tương lai.

### 3. Chính Sách Dự Án (`policies/`)
- **[policies/security-policy.md](policies/security-policy.md):** Chính sách bảo mật thông tin, xử lý lỗ hổng, ranh giới cấm.
- **[policies/privacy-policy.md](policies/privacy-policy.md):** Chính sách quyền riêng tư dữ liệu cá nhân theo GDPR/CCPA.
- **[policies/incident-policy.md](policies/incident-policy.md):** Quy trình phân loại mức độ và ứng phó sự cố P0 - P4.
- **[policies/access-policy.md](policies/access-policy.md):** Chính sách phân quyền truy cập, nguyên tắc đặc quyền tối thiểu.
- **[policies/data-retention-policy.md](policies/data-retention-policy.md):** Chính sách lưu trữ và hủy nhật ký kiểm toán.

### 4. Hướng Dẫn Thực Hành (`guides/`)
- **[guides/developer-guide.md](guides/developer-guide.md):** Hướng dẫn chi tiết cho lập trình viên phát triển thêm Ability Packs.
- **[guides/operation-guide.md](guides/operation-guide.md):** Hướng dẫn vận hành hệ thống hàng ngày, quản lý khóa và theo dõi tải.
- **[guides/deployment-guide.md](guides/deployment-guide.md):** Hướng dẫn thiết lập CI/CD pipeline tự động hóa.
- **[guides/scaling-guide.md](guides/scaling-guide.md):** Hướng dẫn mở rộng hệ thống theo chiều ngang phục vụ tải lớn.
- **[guides/monitoring-guide.md](guides/monitoring-guide.md):** Hướng dẫn giám sát hiệu năng, độ trễ p95/p99 và cảnh báo sự cố.
- **[guides/agent-skill-pack.md](guides/agent-skill-pack.md):** Hướng dẫn công khai cách kết nối AI agent tới máy chủ MCP và dùng các năng lực một cách an toàn.

### 5. Tuân Thủ & Pháp Lý (`compliance/` & `legal/`)
- **[compliance/gdpr-compliance.md](compliance/gdpr-compliance.md):** Đáp ứng các điều khoản GDPR (Quyền được biết, Quyền được quên).
- **[compliance/iso27001-compliance.md](compliance/iso27001-compliance.md):** Kiểm soát bảo mật theo khung tiêu chuẩn ISO/IEC 27001.
- **[compliance/pci-dss-compliance.md](compliance/pci-dss-compliance.md):** Các nguyên tắc an toàn dữ liệu liên quan.
- **[compliance/audit-process.md](compliance/audit-process.md):** Quy trình kiểm toán nội bộ và truy vết hành động.
- **[legal/terms-of-service.md](legal/terms-of-service.md):** Điều khoản dịch vụ và trách nhiệm pháp lý khi sử dụng.
- **[legal/third-party-notices.md](legal/third-party-notices.md):** Thông cáo bản quyền các thư viện bên thứ ba.

### 6. Kiến Trúc Chuyên Sâu & An Toàn (`architecture/` & `security/`)
- **[architecture/system-overview.md](architecture/system-overview.md):** Tổng quan kiến trúc hệ thống chi tiết.
- **[architecture/data-flow-diagrams/main-dfd.md](architecture/data-flow-diagrams/main-dfd.md):** Sơ đồ luồng dữ liệu chính qua các tầng.
- **[architecture/decision-records/DR-001-initial-design.md](architecture/decision-records/DR-001-initial-design.md):** Quyết định kiến trúc khởi đầu.
- **[architecture/overview.md](architecture/overview.md):** Kiến trúc cô đọng và các ranh giới bất biến.
- **[architecture/ability-catalog.md](architecture/ability-catalog.md):** Danh mục ability và mức rủi ro.
- **[architecture/adapter-notes.md](architecture/adapter-notes.md):** Ghi chú phiên bản adapter và tương thích.
- **[architecture/api-keys.md](architecture/api-keys.md):** Mô hình khóa và vòng đời.
- **[architecture/performance.md](architecture/performance.md):** Số đo hiệu năng và bối cảnh SLO.
- **[architecture/persistence.md](architecture/persistence.md):** Lược đồ lưu trữ và ranh giới dữ liệu.
- **[architecture/plugin-check-notes.md](architecture/plugin-check-notes.md):** Kết quả Plugin Check và cách xử lý.
- **[security/http-acceptance.md](security/http-acceptance.md):** Bằng chứng nghiệm thu HTTP.
- **[security/threat-model.md](security/threat-model.md):** Tóm tắt mô hình mối đe dọa.
- **[security/threat-modeling.md](security/threat-modeling.md):** Phân tích mối đe dọa theo mô hình STRIDE và OWASP API Top 10.
- **[security/vulnerability-management.md](security/vulnerability-management.md):** Quy trình quản lý và khắc phục lỗ hổng bảo mật.
- **[security/secure-coding-guide.md](security/secure-coding-guide.md):** Cẩm nang lập trình an toàn phòng chống lỗ hổng WordPress.

**Hồ sơ quyết định kiến trúc:**
- **[adr/0001-record-decisions.md](adr/0001-record-decisions.md):** Ghi nhận các quyết định sản phẩm đã đóng băng.
- **[adr/0002-abilities-and-adapter.md](adr/0002-abilities-and-adapter.md):** Abilities API và adapter chính thức.
- **[adr/0003-adapter-version-range.md](adr/0003-adapter-version-range.md):** Khoảng phiên bản adapter.
- **[adr/0004-key-model.md](adr/0004-key-model.md):** Mô hình khóa.
- **[adr/0005-capability-intersection.md](adr/0005-capability-intersection.md):** Giao thoa năng lực.
- **[adr/0006-runtime-floors.md](adr/0006-runtime-floors.md):** Sàn phiên bản runtime.
- **[adr/0007-oauth-deferred.md](adr/0007-oauth-deferred.md):** OAuth hoãn lại.
- **[adr/0008-core-rest-dispatch.md](adr/0008-core-rest-dispatch.md):** Điều phối REST lõi.
- **[adr/0009-origin-and-auth-route.md](adr/0009-origin-and-auth-route.md):** Origin và tuyến xác thực.
- **[adr/0010-minimal-audit.md](adr/0010-minimal-audit.md):** Kiểm toán tối thiểu.
- **[adr/0011-toolsets-and-discovery.md](adr/0011-toolsets-and-discovery.md):** Toolset và khám phá.
- **[adr/0012-textual-ip.md](adr/0012-textual-ip.md):** Lưu địa chỉ IP dạng văn bản.
- **[adr/0013-content-type-policy.md](adr/0013-content-type-policy.md):** Loại nội dung, DTO mở rộng và xung đột sửa.
- **[adr/0014-media-policy.md](adr/0014-media-policy.md):** Media có giới hạn và tải từ xa.
- **[adr/0015-safe-tool-errors.md](adr/0015-safe-tool-errors.md):** Lý do thực thi trong allowlist.
- **[adr/0016-comments-policy.md](adr/0016-comments-policy.md):** Chính sách bình luận và kiểm duyệt trực tiếp.
- **[adr/0017-taxonomy-policy.md](adr/0017-taxonomy-policy.md):** Chính sách taxonomy.
- **[adr/0018-users-policy.md](adr/0018-users-policy.md):** Chính sách người dùng.
- **[adr/0019-coverage-policy.md](adr/0019-coverage-policy.md):** Chính sách cưỡng chế độ bao phủ.

**Đặc tả cột mốc:**
- **[specs/M1-core.md](specs/M1-core.md):** M1 lõi xác thực, chính sách và facade MCP.
- **[specs/M2-content.md](specs/M2-content.md):** Gói nội dung M2.
- **[specs/M2-taxonomy.md](specs/M2-taxonomy.md):** Gói taxonomy M2.
- **[specs/M3-comments.md](specs/M3-comments.md):** Gói bình luận M3.
- **[specs/M3-media.md](specs/M3-media.md):** Gói media M3.
- **[specs/M5-users.md](specs/M5-users.md):** Gói người dùng M5.
- **[specs/content-media-implementation-plan.md](specs/content-media-implementation-plan.md):** Kế hoạch triển khai nội dung và media.

### 7. Vận Hành & Sự Cố (`operations/`)
- **[operations/runbooks/serviceA-runbook.md](operations/runbooks/serviceA-runbook.md):** Sổ tay hướng dẫn vận hành dịch vụ MCP Server.
- **[operations/incident-response.md](operations/incident-response.md):** Các bước phản hồi sự cố khẩn cấp.
- **[operations/backup-restore.md](operations/backup-restore.md):** Quy trình sao lưu và phục hồi cơ sở dữ liệu khóa và pepper.
- **[operations/disaster-recovery.md](operations/disaster-recovery.md):** Kế hoạch khôi phục sau thảm họa.
- **[operations/performance-slo.md](operations/performance-slo.md):** SLO hiệu năng MCP trên lab cô lập và phán quyết từng cổng benchmark.
- **[operations/performance-analysis.md](operations/performance-analysis.md):** Phân tích cổng hiệu năng wp69 cache-on, phân bổ chi phí một yêu cầu `tools/list` và các vòng sản phẩm.
- **[operations/mcp-behavior-evals.md](operations/mcp-behavior-evals.md):** Controller đánh giá hành vi MCP offline (98 ca FAKE, không gọi mô hình).
- **[operations.md](operations.md):** Tổng quan vận hành và các điểm vào xử lý sự cố.

### 8. Nhân Sự & Hội Nhập (`onboarding/`)
- **[onboarding/onboarding-checklist.md](onboarding/onboarding-checklist.md):** Danh mục kiểm tra từng bước cho thành viên mới tham gia dự án.
- **[onboarding/team-responsibilities.md](onboarding/team-responsibilities.md):** Phân chia trách nhiệm giữa các nhóm Core, Security, QA.
- **[onboarding/tooling-guide.md](onboarding/tooling-guide.md):** Hướng dẫn cài đặt và sử dụng công cụ nội bộ (Composer, PHPCS, RTL).

### 9. Biểu Mẫu Chuẩn (`templates/`)
- **[templates/bug_report.md](templates/bug_report.md):** Mẫu báo cáo lỗi kỹ thuật chuẩn.
- **[templates/feature_request.md](templates/feature_request.md):** Mẫu yêu cầu tính năng mới chuẩn.
- **[templates/refactor.md](templates/refactor.md):** Mẫu yêu cầu tái cấu trúc mã nguồn chuẩn.
- **[templates/pr_template.md](templates/pr_template.md):** Mẫu Pull Request chuẩn.
- **[templates/meeting_minutes_template.md](templates/meeting_minutes_template.md):** Mẫu biên bản họp kỹ thuật.
- **[templates/report_template.md](templates/report_template.md):** Mẫu báo cáo kỹ thuật định kỳ.
- **[templates/issue_template.md](templates/issue_template.md):** Mẫu phiếu chuẩn.
- **Phiếu:** phiếu của dự án không nằm trong cây này. Chúng ở kho cục bộ `.local/docs/en/issues/`, bị Git bỏ qua và không vào gói phát hành; liệt kê bằng `issue_tool.py board` và đọc quy tắc lưu trữ tại [standards/issue-storage.md](standards/issue-storage.md).
