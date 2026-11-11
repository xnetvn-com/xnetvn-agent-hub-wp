# Technical Support & Contact Center (Support Guide)

> **Contact information, support request process and service commitments (SLA) for xNetVN Agent Hub WP.**

---

## 1. Official Support Channels

| Support Channel | Address / Link | Purpose & Scope |
|---|---|---|
| **GitHub Issues** | [GitHub Repository Issues](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/issues) | Technical bug reports (Bugs), new Feature Requests and open architecture discussions. |
| **Technical Email** | `support@xnetvn.net` | In-depth technical support and enterprise integration questions. |
| **Licensing Email** | `license@xnetvn.net` | Questions about usage licenses, commercial licensing or copyright compliance. |
| **Private Security** | `security@xnetvn.net` | Sensitive vulnerability reports (Private Security Disclosures). |

---

## 2. Response Time Commitments (SLA)

| Severity | Definition | Target Response Time |
|---|---|---|
| **P1 - Blocker / Critical** | Critical security vulnerability, system outage, data leak or complete authentication failure. | Response within **2 hours**, updates every 4 hours until a patch is available. |
| **P2 - Major** | Core functionality error (cannot create keys, audit error, adapter error) with a workaround available. | Response within **12 hours**, resolution plan within 48 hours. |
| **P3 - Minor / Normal** | Minor interface or documentation errors, or usage guidance questions. | Response within **24 - 48 business hours**. |
| **P4 - Feature Request** | New feature suggestions or performance improvements. | Review and respond during the release planning cycle (Sprint Planning). |

---

## 3. Process for Receiving Prompt Support

To help the technical team support you as quickly as possible:
1. Read [TROUBLESHOOTING.md](TROUBLESHOOTING.md) and [FAQ.md](FAQ.md) carefully.
2. Prepare environment information:
   - WordPress and PHP versions in use.
   - Versions of the `xnetvn-agent-hub-wp` and `mcp-adapter` plugins.
   - Error logs from `wp-content/debug.log` (with sensitive information such as tokens, passwords and internal IPs removed).
3. Use the proper reporting template at [templates/bug_report.md](templates/bug_report.md).
