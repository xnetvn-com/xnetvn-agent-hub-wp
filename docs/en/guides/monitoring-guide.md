# Monitoring and Alerting Guide

> **A guide to setting up performance monitoring, tracking technical metrics, and proactive alerting for xNetVN Agent Hub WP.**

---

## 1. Key Metrics to Monitor

The system provides important technical metrics for integration with Prometheus, Grafana, Datadog, or the ELK Stack:

| Metric Group | Metric Name | Normal Threshold | Danger Threshold (Alert) |
|---|---|---|---|
| **MCP traffic** | Requests Per Second - RPS | 10 - 200 RPS | Spike > 500 RPS within 1 minute |
| **Error rate** | 401 Unauthorized error rate | < 1% | > 5% (Risk of token brute-force attacks) |
| **Denial rate** | 403 Forbidden error rate | < 0.5% | > 2% (Incorrect Origin configuration or permissions) |
| **Rate limiting** | Number of 429 Too Many Requests responses | < 0.1% | > 2% (Client congestion or DoS) |
| **Processing time** | Response Latency p95 | < 100ms | > 300ms continuously for 5 minutes |
| **Database** | `*_audit` table size | < 1,000,000 rows | > 5,000,000 rows (Cleanup cron process failure) |

---

## 2. Request tracing

- The audit logger assigns each request a short identifier (`request_id`, 16 hex characters from 8 random bytes) when it writes the row (`src/Audit/AuditLogger.php`).
- The identifier is stored in the audit table `{prefix}xnetvn_agent_hub_wp_audit` and is visible in **Agent Hub > Activity**.
- It is not emitted as an HTTP response header, so record the approximate time, the key ID and the ability name when you report an issue, then locate the row in Activity or in the table.

---

## 3. Health checks and probes

The plugin does not register a dedicated health endpoint. Use standard WordPress signals together with the plugin's own status data:

- **Liveness:** a request to the site's REST index (`/wp-json/`) returns `200` when PHP and WordPress accept requests.
- **Readiness:** the database answers, the `{prefix}xnetvn_agent_hub_wp_keys` and `{prefix}xnetvn_agent_hub_wp_audit` tables are queryable, and the official MCP Adapter is active. **Agent Hub > Overview** reports the adapter version and whether the server was created.
- The plugin returns no `503` for dependency failures; a missing or out-of-range adapter disables the MCP server while the rest of the plugin keeps working (`src/Bootstrap/Requirements.php`).

