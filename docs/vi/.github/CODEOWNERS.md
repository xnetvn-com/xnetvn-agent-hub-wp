# Danh Sách Người Sở Hữu Mã Nguồn (Code Owners)

> **Quy định trách nhiệm rà soát (code review) và phê duyệt thay đổi theo từng thành phần kiến trúc của xNetVN Agent Hub WP.**

---

## 1. Nguyên Tắc Phân Quyền Sở Hữu
- Mỗi thay đổi mã nguồn trong các khu vực cốt lõi (Core Domain, Auth, Security, Persistence) bắt buộc phải có ít nhất 1 phê duyệt từ Code Owner tương ứng.
- Không cho phép tự phê duyệt (self-merge) trên các nhánh được bảo vệ (`main`, `release/*`).

---

## 2. Bảng Phân Công Trách Nhiệm Theo Thư Mục

```
# Toàn bộ kho lưu trữ mặc định
* @xnetvn-com/core-maintainers

# Kiến trúc cốt lõi và Kernel khởi động
/src/Plugin.php @xnetvn-com/architects
/src/Bootstrap/ @xnetvn-com/architects
/src/Contracts/ @xnetvn-com/architects

# Xác thực, Mật mã học, Quản lý Khóa và Ranh giới Bảo mật
/src/Auth/ @xnetvn-com/security-team
/src/Domain/ @xnetvn-com/architects @xnetvn-com/security-team
/src/Http/ @xnetvn-com/security-team
/src/Policy/ @xnetvn-com/security-team

# Cơ sở dữ liệu, Schema Migration và Khóa đồng thời
/src/Persistence/ @xnetvn-com/database-team
/src/RateLimit/ @xnetvn-com/backend-team

# Giao diện kết nối MCP Adapter và Quản lý Abilities
/src/Mcp/ @xnetvn-com/mcp-specialists
/src/Abilities/ @xnetvn-com/mcp-specialists

# Nhật ký kiểm toán và Tuân thủ quyền riêng tư GDPR
/src/Audit/ @xnetvn-com/compliance-team

# REST Controllers và Giao diện Quản trị
/src/Rest/ @xnetvn-com/backend-team
/src/Admin/ @xnetvn-com/frontend-team
/assets/ @xnetvn-com/frontend-team

# Kiểm thử tự động và Công cụ phát triển
/tests/ @xnetvn-com/qa-team
/bin/ @xnetvn-com/devops-team
/infra/ @xnetvn-com/devops-team

# Tài liệu chính thức (Single Source of Truth)
/docs/vi/ @xnetvn-com/docs-team
```
