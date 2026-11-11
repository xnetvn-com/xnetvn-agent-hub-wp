# Internal Audit & Traceability Process (Audit Process)

> **Guidance for independent auditing, log review and user accountability tracing in xNetVN Agent Hub WP.**

---

## 1. Audit Objectives

1. **Non-repudiation:** Ensure every action performed by an AI Agent on a WordPress website is accurately traceable to a specific API key and user account.
2. **Transparency & Integrity:** Audit records cannot be edited or forged by ordinary users.
3. **Timely Anomaly Detection:** Identify abuse, vulnerability scanning or tool calls outside the permitted scope.

---

## 2. Quarterly Audit Workflow

```
[1. Extract audit data] ──► [2. Analyze traffic & error rates] ──► [3. Review active key inventory] ──► [4. Prepare audit report]
```

### Step 1: Extract Audit Data
- Administrators access **Agent Hub → Audit Logs**.
- Data can be filtered by date range or user, or the `wp_xnetvn_agent_hub_audit` table can be exported to CSV for advanced analysis.

### Step 2: Analyze Traffic & Error Rates
- Inspect records with `outcome = 'denied'`:
  - Analyze the cause: Key expiry, incorrect WordPress permissions or exceeding the site's risk ceiling?
  - Check for signs of password/token guessing attacks from a particular IP address.

### Step 3: Key Inventory Review
- Review all API keys with `active` status:
  - Which keys have not been used for more than 90 days? (Revocation recommended).
  - Which `destructive` keys are assigned to users no longer in the content management group? (Revoke or narrow permissions).

### Step 4: Prepare the Audit Report & Improvement Recommendations
- Aggregate statistics: Total MCP requests served, number of active keys, security incidents (if any).
- Record them in a technical report using the standard template at [templates/report_template.md](../templates/report_template.md).
