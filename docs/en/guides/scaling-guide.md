# Horizontal Scaling Guide

> **Horizontal Scaling strategies, performance optimization, and high-load architecture for xNetVN Agent Hub WP.**

---

## 1. Scalability Targets

- Support millions of MCP requests per day from distributed AI Agent systems.
- Ensure response Latency:
  - **p50 < 30ms** for session checks and tool discovery (`discover-abilities`).
  - **p95 < 100ms** for ordinary tool execution (`execute-ability`).
- Stateless Application Tier: Every WordPress web server can scale independently behind a Load Balancer.

---

## 2. Enterprise Cluster Architecture

```
                       [ AI Agent Network ]
                                  │
                                  ▼ (HTTPS)
                      [ Cloudflare / AWS CloudFront ]
                                  │
                                  ▼
                         [ Load Balancer ]
                                  │
             ┌────────────────────┼────────────────────┐
             ▼                    ▼                    ▼
     [ Web Node 1 ]       [ Web Node 2 ]       [ Web Node N ]
     (PHP-FPM WP)         (PHP-FPM WP)         (PHP-FPM WP)
             │                    │                    │
             └────────────────────┼────────────────────┘
                                  │
                     ┌────────────┴────────────┐
                     ▼                         ▼
             [ Redis Cluster ]        [ MySQL Primary / Replica ]
           (Rate Limit & Cache)       - Writes: Master (InnoDB)
                                      - Reads: Read-Replicas
```

---

## 3. Essential Scaling Solutions

### 3.1 Using Redis Cluster for Rate Counters
- By default, without Redis, the plugin uses the `wp_options` table with SQL statements to increment rate counters. This can create an I/O Bottleneck under heavy traffic.
- By enabling Redis Persistent Object Cache:
  - Counter increments (`INCR`) are handled entirely in Redis RAM with microsecond latency.
  - MySQL database I/O load is eliminated entirely for read-only requests or requests denied by rate limits.

### 3.2 Write Transaction Optimization and Database Throttling
- **Throttling Last Used updates (`last_used_at`):**
  - The system uses throttling: it writes `last_used_at` to the database at most **1 time every 5 minutes** for each API key.
  - This prevents millions of continuous `UPDATE` statements when an AI Agent calls tools frequently.
- **Batch Audit Inserts:**
  - Collect all audit events within a request and execute exactly 1 INSERT statement, `INSERT INTO ... VALUES (...), (...)`, at the shutdown hook.

### 3.3 Read/Write Splitting
- For high-traffic systems, use solutions such as AWS Aurora MySQL or ProxySQL:
  - Key authentication queries (`SELECT ... FROM *_keys WHERE key_id = ...`) can be distributed to Read Replicas (with minimal replication lag).
  - Key creation and rotation transactions, and audit writes, are routed to the Primary Master.
