# Disaster Recovery Plan

> **Disaster response strategy, recovery objectives (RTO/RPO), and failover procedures for xNetVN Agent Hub WP.**

---

## 1. Disaster Recovery Objectives

- **Recovery Time Objective - RTO:**
  - Restore MCP server service operation in **under 30 minutes**.
- **Recovery Point Objective - RPO:**
  - API key data loss: **RPO = 0** (no loss of valid key information is permitted).
  - Audit log data: **RPO <= 1 hour** (up to 1 hour of audit log loss is accepted in a physical data center disaster).

---

## 2. Failover Scenarios and Procedures

### 2.1 Web Server Node Failure
- **Situation:** A Web Node running PHP-FPM suffers a hardware failure.
- **Procedure:**
  1. The Load Balancer automatically removes the failed node from the cluster through a Health Check Probe.
  2. MCP traffic is automatically forwarded to the remaining Web Nodes in the cluster.
  3. Create a new Web Node from a Golden AMI / Container Image and add it to the cluster.

### 2.2 Database Primary Failure
- **Situation:** The MySQL Primary server suffers an unrecoverable failure.
- **Procedure:**
  1. Trigger automatic Failover: promote a Read-Replica to the new Primary Master.
  2. Update the connection configuration in `wp-config.php` or the DNS endpoint to point to the new Master.
  3. Ensure that the InnoDB table configuration and data write permissions function normally.
  4. Run integrity checks on the `*_keys` and `*_audit` tables.
  5. Clear the system-wide cache: `wp cache flush`.

---

## 3. Periodic Disaster Recovery Drills

- The engineering team conducts disaster recovery drills **every 6 months** in Staging/Remote Test Lab:
  - Simulate deleting the database and restoring it from the latest backup.
  - Check that AI Agents can continue connecting successfully with the restored API keys.
  - Evaluate actual RTO and RPO against the stated targets.
