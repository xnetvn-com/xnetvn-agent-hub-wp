# Code Owners

> **Defines code review and change approval responsibilities for each architecture component of xNetVN Agent Hub WP.**

---

## 1. Ownership Assignment Principles
- Every code change in core areas (Core Domain, Auth, Security, Persistence) requires at least 1 approval from the corresponding Code Owner.
- Self-approval (self-merge) is not permitted on protected branches (`main`, `release/*`).

---

## 2. Responsibility Assignment by Directory

```
# Repository-wide default
* @xnetvn-com/core-maintainers

# Core architecture and startup Kernel
/src/Plugin.php @xnetvn-com/architects
/src/Bootstrap/ @xnetvn-com/architects
/src/Contracts/ @xnetvn-com/architects

# Authentication, Cryptography, Key Management, and Security Boundaries
/src/Auth/ @xnetvn-com/security-team
/src/Domain/ @xnetvn-com/architects @xnetvn-com/security-team
/src/Http/ @xnetvn-com/security-team
/src/Policy/ @xnetvn-com/security-team

# Database, Schema Migration, and Concurrency Locking
/src/Persistence/ @xnetvn-com/database-team
/src/RateLimit/ @xnetvn-com/backend-team

# MCP Adapter Interface and Ability Management
/src/Mcp/ @xnetvn-com/mcp-specialists
/src/Abilities/ @xnetvn-com/mcp-specialists

# Audit Logging and GDPR Privacy Compliance
/src/Audit/ @xnetvn-com/compliance-team

# REST Controllers and Administration UI
/src/Rest/ @xnetvn-com/backend-team
/src/Admin/ @xnetvn-com/frontend-team
/assets/ @xnetvn-com/frontend-team

# Automated Testing and Development Tools
/tests/ @xnetvn-com/qa-team
/bin/ @xnetvn-com/devops-team
/infra/ @xnetvn-com/devops-team

# Official Documentation (Single Source of Truth)
/docs/vi/ @xnetvn-com/docs-team
```
