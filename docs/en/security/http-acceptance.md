# HTTP rejection matrix checkpoint

Verified2026-10-05 on the scoped WordPress6.9 test lab with official MCP Adapter0.7.0. `composer test:http` performs real HTTPS requests and owned-network plain HTTP. The current35 original-plan IDs passed with positive controls; this does not close full M1, client, cache, concurrency, coverage or performance acceptance.

Modern requests mirror the method/name and protocol version in HTTP headers and carry matching body metadata. Initial missing-header failures were fixture defects and were not accepted as policy proof. Independent cases reset only the owned lab's rate buckets; rate-limit scenarios then consume their own complete budget.

| IDs | Evidence scope |
|---|---|
| N-01–N-06 | Missing/malformed/unknown/wrong/revoked/expired credentials; challenge; equal unknown/wrong bodies. Malformed input performs zero credential-table lookups. WordPress/audit/rate storage remains active; this is not a claim of zero application-wide SQL. |
| N-07–N-09 | Deleted fixture owner, role loss/recovery, out-of-key-IP restriction. |
| N-10–N-14 | Actual internal plain HTTP, invalid/null/absent Origin, positive HTTPS list, failed-auth429/Retry-After. |
| N-15–N-19 | Proper management/core/default-adapter requests do not acquire key identity; valid cookie/nonce does not replace MCP key; key identity wins over a different valid cookie. |
| N-20–N-25 | Missing context denies; permitted read fixture is visible while higher tiers are absent; hidden/unknown envelopes and statuses equal; high risk/admin ceiling denied; key budget429. |
| N-26–N-30 |16MiB+1 body and depth40 reject; successful canary call omits its value from audit; bulk21 exceeds fixture schema20; complete hard-deny registry roster is absent. Business-pack-specific bulk behavior remains a later milestone. |
| N-31–N-35 | Unsupported protocol version; request-only unsupported adapter preserves base loading/notice and removes MCP route; valid-key Origin/plain-HTTP guards; observed early cached-cookie actor still yields the key actor, or401 without a key. |

Two defects were reproduced before correction: Apache's empty Authorization variables shadowed the enabled fallback, and modern unknown-toolHTTP400 differed from hidden-toolHTTP200. Empty strings alone now fall through; malformed nonempty primary headers stay authoritative. The sole adapter facade matches the installed error factory's translated tool-not-found envelope before normalizing; unrelated INVALID_PARAMS/validation/missing-parameter errors retain their original status/body.

The matrix also passed35/35 with the pinned persistent Redis cache active. Its reset requires supported group flushing, a deletable owned sentinel and forced absence; it never falls back to a whole-cache flush. Test instrumentation/state ownership is checked before setup and cleanup. The unsupported-adapter diagnostic loads core screen helpers before invoking administrative notice listeners.

The [performance checkpoint](../architecture/performance.md) records unknown/wrong-secret timing, the sixty-tool benchmark, shared-counter concurrency and scoped outage evidence. Complete-request latency remains above its target. Two real client applications and manual screen-reader acceptance remain pending. Coverage is measured separately in the [coverage checkpoint](../development-coverage.md). The literal N-02 database wording also requires reconciliation with audit/rate fallback; current instrumentation counts credential-table queries. A35-ID report alone does not close those requirements.

Official protocol sources, verified2026-10-05: [modern versioning](https://modelcontextprotocol.io/specification/2026-07-28/basic/versioning), [modern HTTP headers](https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http), [legacy HTTP](https://modelcontextprotocol.io/specification/2025-11-25/basic/transports).
