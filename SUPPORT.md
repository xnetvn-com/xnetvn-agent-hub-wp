# Support Guide

> **Contact information, support request process and service level commitments (SLA) for xNetVN Agent Hub WP.**

---

## 1. Official support channels

| Channel | Address / Link | Purpose and scope |
|---|---|---|
| **GitHub Issues** | [GitHub Repository Issues](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/issues) | Technical bug reports, feature requests and open architecture discussions. |
| **Technical email** | `support@xnetvn.net` | In-depth technical support and enterprise integration questions. |
| **Licensing email** | `license@xnetvn.net` | Licensing, commercial licensing and copyright compliance matters. |
| **Private security** | `security@xnetvn.net` | Sensitive security vulnerability disclosures (private reporting). |

---

## 2. Response time commitments (SLA)

| Severity | Definition | Target response time |
|---|---|---|
| **P1 - Blocker / Critical** | Severe security vulnerability, system outage, data leak or complete authentication failure. | Response within **2 hours**, updates every 4 hours until a fix is available. |
| **P2 - Major** | Core functionality failure (key creation, audit logging, adapter errors) with a workaround available. | Response within **12 hours**, remediation plan within 48 hours. |
| **P3 - Minor / Normal** | Small UI, documentation or usage questions. | Response within **24 - 48 business hours**. |
| **P4 - Feature request** | New feature proposals or performance improvements. | Reviewed and answered during release planning (sprint planning). |

---

## 3. Fast support process

To help the engineering team support you as quickly as possible:

1. Read the [troubleshooting guide](docs/vi/TROUBLESHOOTING.md) and the [FAQ](docs/vi/FAQ.md) first.
2. Prepare your environment information:
   - WordPress version and PHP version in use.
   - The `xnetvn-agent-hub-wp` plugin version and the `mcp-adapter` plugin version.
   - Error logs from `wp-content/debug.log` (with all sensitive information such as tokens, passwords and internal IPs removed).
3. Use the detailed bug report template at [`.github/ISSUE_TEMPLATE/bug_report.md`](.github/ISSUE_TEMPLATE/bug_report.md).