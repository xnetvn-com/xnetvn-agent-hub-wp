# Phân Công Trách Nhiệm Đội Ngũ (Team Responsibilities)

> **Cơ cấu tổ chức, vai trò và ma trận trách nhiệm RACI trong dự án xNetVN Agent Hub WP.**

---

## 1. Cơ Cấu Các Nhóm Chuyên Trách

```
┌────────────────────────────────────────────────────────┐
│                   Ban Quản Trị Dự Án                   │
│             (Lead Architect & Project Leads)           │
└───────────────────────────┬────────────────────────────┘
                            │
       ┌────────────────────┼────────────────────┐
       ▼                    ▼                    ▼
[ Nhóm Core & MCP ]   [ Nhóm An Toàn & DB ] [ Nhóm QA & Vận Hành ]
- Xây dựng Kernel     - Mật mã học, Pepper  - Kiểm thử tự động
- Quản lý Abilities   - Schema, Giao dịch   - CI/CD, Đóng gói
- REST Controllers    - Quyền hạn Policy    - SRE, Giám sát
```

---

## 2. Ma Trận Trách Nhiệm RACI Theo Hạng Mục Công Việc

*(R - Responsible: Người trực tiếp làm | A - Accountable: Người chịu trách nhiệm phê duyệt | C - Consulted: Người được tham vấn | I - Informed: Người được thông báo)*

| Hạng Mục Kỹ Thuật | Nhóm Kiến Trúc (Architecture) | Nhóm Bảo Mật (Security) | Nhóm Backend / Core | Nhóm QA / DevOps |
|---|---|---|---|---|
| **Định nghĩa ranh giới an toàn & Cấp độ rủi ro** | A | R | C | I |
| **Phát triển Kernel & Service Providers** | A | C | R | I |
| **Xây dựng Ability Packs mới (Content, Taxonomy)** | C | C | R | R |
| **Mật mã học, Khóa API & Xoay vòng khóa nguyên tử** | A | R | R | C |
| **Kiểm thử tự động & Đo lường Coverage** | I | C | R | A / R |
| **Thiết lập CI/CD & Đóng gói phát hành ZIP** | I | C | C | A / R |
| **Phản hồi sự cố khẩn cấp (P0 / P1)** | A | R | R | R |
| **Duy trì tài liệu tiếng Việt (Single Source of Truth)** | A | C | R | C |

---

## 3. Quy Tắc Giao Tiếp & Báo Cáo

- **Trao đổi hàng ngày (Daily Sync):** Báo cáo 3 câu hỏi (Hôm qua làm gì? Hôm nay làm gì? Có vướng mắc gì không?) trên kênh Slack/Discord nội bộ.
- **Rà soát kiến trúc (Architecture Review):** Bắt buộc tổ chức họp rà soát trước khi thêm bất kỳ Ability nào có cấp độ `destructive` hoặc `admin`.
