# Performance and shared-cache checkpoint

Measured 2026-10-05 on the scoped test lab: WordPress 6.9, PHP 8.2, official MCP Adapter 0.7.0, pinned Redis 8.10.2 and Redis Object Cache 3.0.0 using Predis. This is milestone evidence, not release or production acceptance. The public [numeric artifact](../reports/M1-60-tools.json) contains no host addresses, credentials, argument values or response bodies.

## Method and measurements

Five real system descriptors, one controlled read fixture and 54 synthetic private read descriptors produce exactly 60 visible tools. These synthetic schemas do not represent the final business-pack complexity. Each valid/unknown/wrong case discards ten warm-up requests, then records 100 validated real HTTPS responses. Failure classes are interleaved. A separate ten-worker phase records ten requests per worker. No other fixture or benchmark runs concurrently with the final measurement. Normal shared-host services and health checks remain active.

The test client keeps its connection/DNS caches and resets every request option, including Authorization, Cookie, controls and body, in `finally`. No cookie engine or cookie jar is enabled. Cold connection/DNS/TLS costs are therefore outside the warmed figures below; the artifact retains transport timing and connection counts. Percentiles use nearest rank. Missing valid-case instrumentation rejects the sample. Policy metrics for failed authentication are omitted because that phase does not run; they are not recorded as zero.

| Measurement | p50 | p95 | p99 |
|---|---:|---:|---:|
| Authentication hook phases | 3.771 ms | 3.948 ms | 4.016 ms |
| Policy filtering of 60 tools | 3.714 ms | 3.930 ms | 4.007 ms |
| Combined authentication and policy | 7.494 ms | 7.763 ms | 7.831 ms |
| REST dispatch through response filtering | 12.600 ms | 12.956 ms | 13.165 ms |
| Complete warmed HTTPS request | 55.868 ms | 57.826 ms | 61.506 ms |
| Complete HTTPS request with ten concurrent workers | 1101.586 ms | 1796.371 ms | 2146.004 ms |

The WordPress container is limited to one CPU/512 MiB; Redis to half a CPU/256 MiB, with 128 MiB `maxmemory` and `noeviction`. The worker result is a measured saturation limit, not a production capacity claim. All 430 benchmark requests completed with their expected status and result shape; this count includes 30 warm-ups, 300 sequential samples and 100 load samples.

The conservative memory bound is 5,509,680 bytes (5.254 MiB): peak PHP memory minus the MU-plugin baseline. It includes ordinary plugins, adapter, core dispatch and synthetic fixtures after that baseline; it is not an isolated measurement of Agent Hub alone. Under ten-worker load, the observed maximum was 5,509,848 bytes. The logical decoded list JSON is 33,191 bytes, not a wire/compression or exact LLM-token measurement.

## Targets and measured improvement

Authentication and policy each have warmed medians below 5 ms, and the conservative memory increment is below 8 MiB. The combined median remains above 5 ms. Dispatch for 60 tools is below 50 ms; complete HTTPS remains above the revised 55 ms target at this checkpoint, so the stricter complete-request target is **not met** by this checkpoint. Load latency is also substantial on the deliberately limited container. Further profiling with actual business packs is required before accepting the release budget.

The complete-request target is `< 55 ms` since 2026-11-09, when the project owner authorized relaxing only that requirement from `< 50 ms` (quoted verbatim: "Bạn quyết STAB-03: hạ ngưỡng"). This is a requirement change, not a measured performance gain: the measurement method, the sample count and the other five gates are unchanged, and every earlier measurement keeps its `historical, <50 ms>` verdict. The later rounds of 2026-10-22 through 2026-11-07 measured 52.585–53.465 ms and sit inside the revised budget; the 2026-10-05 checkpoint above did not.

A like-for-like fresh-connection comparison reduced the 60-tool policy median from 5.128 to 3.731 ms by memoizing only pure validation of an exactly identical option value. Every read still calls `get_option()` for the current site. Changed/corrupt values are rechecked; current capabilities and extension decisions remain live. Source-only review and freshness/corruption tests cover that boundary. The later connection-reuse comparison changes client behavior and is not attributed to the production optimization.

The unknown-key authentication median was 2.668 ms and wrong-secret median 2.705 ms, a ratio of 1.014 across interleaved samples. Both responses remain401 with the same generic body. This supports N-03/N-04 for this configuration; it is not universal constant-time or side-channel proof.

## Shared storage and outage evidence

Ten independent WordPress processes each incremented a shared bucket twenty times: expected200, observed200. A separate WordPress7.1.2 site retained a positive control written by a different process and read zero for the first site's bucket. Cleanup proves backend availability and forced absence before deleting its state. These are real Redis checks, not a filesystem imitation or an in-process mock.

All35 original negative HTTP IDs also passed with persistent cache active, including revocation, role loss, key/IP limits and cached-cookie identity. Controlled backend stop denied a previously permitted request with HTTP500 and no RPC result; restarting Redis restored a successful sixty-tool list. The global object-cache drop-in aborts WordPress before Agent Hub loads, so that500 must not be reported as this plugin's503 unavailable response. Unsupported graceful cache mode is not enabled. Other drop-ins/backends and failover setups are unverified.

The lab uses an integer one-second read timeout. The installed Predis group-flush implementation restores the timeout via `stream_set_timeout(seconds)`; a fractional0.25 value is truncated to0 and caused subsequent reads to fail. Choosing the documented default fixed that lab integration without patching vendor code. An owned sentinel prevents an empty-group flush result from being mistaken for an unavailable operation. Group flush requires explicit support and never falls back to flushing the entire cache.

Cache drop-ins were disabled after the checkpoint; temporary users, keys, options, instrumentation and private credential files were removed with ownership/readback checks. Redis itself remains an isolated lab service. No server-wide settings were changed.

## Reproduction and remaining gates

Only use the approved remote-test-lab entry point. In its owned toolbox, enable the checksum-bound cache fixture for each site, run `infra/remote/run-cache-concurrency.sh` and `infra/remote/run-benchmark.sh`, then disable both cache fixtures. The benchmark restores site settings and removes its HTTP fixture on normal exits. Interrupted runs require private-state reconciliation before retrying. Do not run an unconfigured integration harness while a browser-site drop-in is enabled.

The cost of one request is split with the analysis marks of the benchmark MU-plugin (`Bootstrap-Ms`, `Init-Ms`, `Pre-Dispatch-Ms` and the memory marks) and with the bounded background probe `tests/E2E/benchmark-cost-probe.php`, which reads one login POST and one plain site page over the same transport. The method, the numbers, the comparison with the checkpoint of 2026-10-05 and the per-gate verdict of the 2026-10-21 runs are in [operations/performance-analysis.md](../operations/performance-analysis.md). The checkpoint of 2026-10-05 above stays as it is; it was not re-measured.

Remaining: complete-request performance target, final business-pack/tool/token-size measurements, preservation of the separately measured coverage thresholds, two real client applications, manual accessibility, other backend/host combinations and full milestone acceptance. The original plan's literal N-02 database wording also needs reconciliation with its audit/shared-rate fallback requirements; current evidence proves zero credential-table lookups, not zero application-wide SQL.

Official references verified 2026-10-05: [Redis Object Cache configuration and unsupported graceful mode](https://github.com/rhubarbgroup/redis-cache/blob/develop/README.md), [Redis8.10.2 release](https://github.com/redis/redis/releases/tag/8.10.2), [WordPress atomic cache increment](https://developer.wordpress.org/reference/functions/wp_cache_incr/), [libcurl reset semantics](https://curl.se/libcurl/c/curl_easy_reset.html), [PHP stream timeout arguments](https://www.php.net/manual/en/function.stream-set-timeout.php).

## Real Content/Media descriptors — 2026-10-08

The real HTTPS Content/Media matrix listed exactly31 tools (5 System,19 Content,7 Media), validated all26 new openWorldHint values and completed the post/media lifecycle outside DIR_TESTDATA. Twenty sequential, bounded, connection-reused tools/list responses contained53,515 logical descriptor bytes; median complete HTTPS latency was58.62245ms and p95 was66.233643ms on the shared one-CPU/512MiB WordPress6.9 fixture. These are complete client-observed HTTP timings, not isolated policy or server dispatch timings. The complete-request 50 ms target was not met (historical, <50 ms>). Samples are not a production capacity claim, and controlled load, cold TLS, tokenizer size and expanded SSRF acceptance remain separate evidence.

## Real Users descriptors — 2026-10-10

The managed inventory is now sixty-two tools (5 System, 19 Content, 7 Media, 10 Comments, 9 Taxonomy, 12 Users); per-pack counts are asserted by contract and inventory tests. The twelve canonical users definitions were added without a new full HTTPS tools/list timing run; the fifty-tool estimate above plus the users descriptor bytes remain the planning baseline until UM-157 re-measures byte size and p50/p95 on real HTTPS for 62 tools. OpenWorldHint is true only for create-user, update-user and send-password-reset (core emails leave the site).

## Real Taxonomy descriptors — 2026-10-09

The managed inventory is now fifty tools (5 System,19 Content,7 Media,10 Comments,9 Taxonomy); per-pack counts are asserted by contract and inventory tests. The nine canonical taxonomy definitions contribute2,190 description bytes and10,586 input/output schema bytes (12,776 logical bytes, computed locally from the registered definitions). Added to the previously measured31-tool53,515-byte payload, a fifty-tool list is estimated near66 kilobytes before comments-payload delta and wire framing; bytes are not a tokenizer measurement. Full fifty-tool tools/list timing on real HTTPS remains pending and stays in the remaining gates below.

## Product round 2026-10-21 — measured at revision `20874a3`

The checkpoint of 2026-10-05 above is **not** re-measured and stays as it is;
its numbers were measured at `c98ca1c`. The product round of
`ISSUE_v0.1_2026-10-21_01` was measured on the same lab at revision
`20874a3` (branch `issue/perf-product-20261021`), with the same harness, the
same isolation and the same unchanged thresholds.

| Measurement | 2026-10-05 checkpoint (`c98ca1c`) | 2026-10-21 pre-change round | 2026-10-21 product round (`20874a3`) |
|---|---:|---:|---:|
| Authentication hook phases p50 | 3.771 ms | 3.824 ms | 3.706 / 3.744 / 3.735 ms |
| Policy filtering of the visible tools p50 | 3.714 ms | 5.598 ms | 3.346 / 3.363 / 3.347 ms |
| Combined authentication and policy p50 | 7.494 ms | 9.400 ms | 7.059 / 7.097 / 7.081 ms |
| REST dispatch through response filtering p50 | 12.600 ms | 13.427 ms | 11.502 / 11.518 / 11.500 ms |
| Complete warmed HTTPS request p50 | 55.868 ms | 79.049 ms | 55.784 / 55.915 / 55.865 ms |
| Conservative memory bound (peak minus MU-plugin baseline) | 5 509 680 B | 8 705 720 B | 6 170 600 / 6 170 600 / 6 171 048 B |
| Logical decoded list JSON | 33 191 B | 33 191 B | 33 191 B |
| Visible tools | 60 | 60 | 60 |

The measured root cause was the eager creation of the adapter MCP server
with the whole ability catalog on every REST request — 0.272 ms and 32 303 B
per advertised tool — and not the ability registration, which costs 0.87 ms
for 120 abilities. The change builds the server only for the exact MCP route
and only with the names the presented delegation scope can use, so the
visible payload, the tool count and the denial results are unchanged. Two of
the four failing gates now pass; the two that remain and their measured
reasons are in
[operations/performance-analysis.md](../operations/performance-analysis.md)
Sections 3.1, 3.2 and 8.1.

## Capability memo round 2026-10-22 — measured at revision `eac8f50`

The checkpoint of 2026-10-05 above is **not** re-measured and stays as it is;
its numbers were measured at `c98ca1c`. The capability memo of
`ISSUE_v0.1_2026-10-21_04` was measured on the same lab at revision
`eac8f50` (branch `issue/perf2-20261022`), with the same harness, the same
isolation and the same unchanged thresholds. The four pre-change rounds of
the same day and the three post-change rounds are documented in
[operations/performance-analysis.md](../operations/performance-analysis.md)
Sections 3.3 and 8.2.

| Measurement | 2026-10-05 checkpoint (`c98ca1c`) | 2026-10-21 product round (`20874a3`) | 2026-10-22 pre-change rounds | 2026-10-22 capability memo round (`eac8f50`) |
|---|---:|---:|---:|---:|
| Authentication hook phases p50 | 3.771 ms | 3.706 / 3.744 / 3.735 ms | 3.701 / 3.697 / 3.740 / 3.724 ms | 3.718 / 3.708 / 3.718 ms |
| Policy filtering of the visible tools p50 | 3.714 ms | 3.346 / 3.363 / 3.347 ms | 3.335 / 3.348 / 3.426 / 3.394 ms | 0.656 / 0.644 / 0.645 ms |
| Combined authentication and policy p50 | 7.494 ms | 7.059 / 7.097 / 7.081 ms | 7.038 / 7.055 / 7.158 / 7.108 ms | 4.385 / 4.362 / 4.364 ms |
| REST dispatch through response filtering p50 | 12.600 ms | 11.502 / 11.518 / 11.500 ms | 11.250 / 11.453 / 11.007 / 11.570 ms | 8.291 / 8.267 / 8.287 ms |
| Complete warmed HTTPS request p50 | 55.868 ms | 55.784 / 55.915 / 55.865 ms | 55.715 / 55.745 / 55.524 / 55.491 ms | 52.780 / 52.830 / 52.603 ms |
| Conservative memory bound (peak minus MU-plugin baseline) | 5 509 680 B | 6 170 600 / 6 170 600 / 6 171 048 B | 6 173 448 / 6 173 448 / 6 173 448 / 6 170 824 B | 6 173 448 / 6 173 448 / 6 173 448 B |
| Logical decoded list JSON | 33 191 B | 33 191 B | 33 191 B | 33 191 B |
| Visible tools | 60 | 60 | 60 | 60 |

The measured root cause of the policy window was the repeated live capability
check: the 60 advertised tools share a handful of user, capability and object
pairs, and each pair was checked live once per tool. One request-local
capability decision per pair inside a single advertised tool list round
removes that repetition; the memo lives in the object that created it, is
never written to a transient, the object cache, an option or a shared
property, and every other decision path still checks the capability live.
The combined authentication-and-policy median now meets its original
threshold for the first time. The complete-HTTPS median stays 2.603–2.830 ms
above its threshold: the remaining block is the 11.689–11.729 ms that the
separately installed adapter needs to build its server and project the 60
advertised tools before the request is served, and version 0.7.0 of that
dependency offers no deferred or lazy build entry point. The visible payload,
the tool count, the denial results and the ability lookup outside the MCP
route are unchanged.
