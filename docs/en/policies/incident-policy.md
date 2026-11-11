# Incident Management & Response Process (Incident Response Policy)

> **Severity classification, escalation steps and response scenarios for technical and information security incidents in xNetVN Agent Hub WP.**

---

## 1. Incident Severity Levels

| Level | Name | Classification Criteria | Notification Channel & Response Time |
|---|---|---|---|
| **P0** | **Critical** | - A publicly exploited security vulnerability (RCE, SQLi, secret/pepper leakage).<br>- The entire MCP system or WordPress website is unavailable.<br>- Database corruption or loss of key integrity. | Activate 24/7 alerting.<br>Respond within **15 minutes**.<br>Update status every 30 minutes. |
| **P1** | **High** | - Widespread API key authentication failures.<br>- Key rotation or key management failures.<br>- Brute-force or DDoS attacks exhaust resources. | Respond within **1 hour**.<br>Update status every 2 hours. |
| **P2** | **Medium** | - A specific tool pack (e.g. Content Pack) fails.<br>- Rate limit counters display incorrect numbers.<br>- Administration UI display errors that do not affect the main flow. | Respond within **4 hours**.<br>Update status daily. |
| **P3** | **Low** | - Minor interface errors, documentation mistakes or non-critical linter warnings. | Address through the regular maintenance sprint plan. |

---

## 2. Standard 5-Step Response Process

```
[1. Detect & Record] ──► [2. Contain & Mitigate] ──► [3. Analyze Root Cause] ──► [4. Remediate & Deploy] ──► [5. Report & Learn]
```

### Step 1: Detection & Identification
- Receive alerts from real-time monitoring, unusual audit logs, or user/security expert reports via `security@xnetvn.net`.
- Immediately create a dedicated Incident Channel and assign an Incident Commander.

### Step 2: Containment
- For exposed or suspected stolen keys:
  - Immediately execute emergency key revocation through WP-CLI or the administration interface:
    ```bash
    wp eval 'xnetvn_revoke_key("key_id");'
    ```
- For Pepper secret leakage:
  - Generate a new Pepper and revoke all existing API keys to require users to create new keys.
- For DDoS / Brute-force attacks:
  - Block malicious IP ranges immediately at the Web Server (Nginx) or CDN (Cloudflare) layer.

### Step 3: Root Cause Analysis (RCA)
- Inspect all audit logs in `wp_xnetvn_agent_hub_audit` and the system log file `debug.log`.
- Reproduce the failure in an isolated environment (Sandbox / Remote Test Lab).
- Identify weaknesses in logic, configuration or authorization boundaries.

### Step 4: Remediation & Patching
- Write a Regression Test that precisely reproduces the failure.
- Fix the source code and ensure `composer check` passes 100%.
- Package and release a Hotfix Release (e.g. bump the PATCH version to `v0.1.1`).

### Step 5: Post-Mortem
- Create a technical incident record using the standard template at [templates/report_template.md](../templates/report_template.md).
- Record lessons learned, update operational documentation and add automated tests to prevent recurrence.
