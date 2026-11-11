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

## 2. Distributed Request Tracing with Request ID

- Each MCP request sent to the system is assigned a unique **`request_id`** (UUID v4 format).
- This identifier appears in:
  1. The MCP JSON-RPC response.
  2. The audit log table `wp_xnetvn_agent_hub_audit.request_id`.
  3. The HTTP response header: `X-Request-ID: <uuid>`.
- When a user or AI Agent encounters an issue, providing the `request_id` to the administrator enables immediate lookup of the request's full processing context.

---

## 3. Health Check and Probe Configuration

The system provides a health check endpoint for Load Balancers and Kubernetes/Docker:

- **Liveness Probe:**
  - Send `GET /wp-json/xnetvn-agent-hub-wp/v1/health` (or check a basic REST API route).
  - HTTP `200 OK` indicates that PHP-FPM and WordPress are accepting requests normally.
- **Readiness Probe:**
  - Check that the MySQL database connection works and that the `*_keys` table can be queried.
  - Check that the `mcp-adapter` plugin is active.
  - If any component fails, return HTTP `503 Service Unavailable` so that the Load Balancer temporarily isolates that node.
