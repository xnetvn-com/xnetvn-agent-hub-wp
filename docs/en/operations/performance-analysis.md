# MCP Performance Gate Analysis (wp69 cache-on)

> **Outcome (2026-10-21):** the four failing gates of
> `tests/E2E/benchmark.php` were reproduced in three isolated runs on the
> pinned baseline, and every miss was attributed to a **product** cost with a
> number. The shared-lab noise hypothesis is refuted. **None of the six
> original gates, and none of their measurement rules, was changed** by this
> analysis.
>
> **Follow-up (2026-10-21, Section 3.1 and 8.1):** the product change of
> `ISSUE_v0.1_2026-10-21_01` was measured with the same harness. The
> measured root cause was **not** the ability registration (0.87 ms for 120
> abilities) but the eager creation of the adapter MCP server with the whole
> catalog on every REST request (33.89 ms and 3 876 432 B for 120 tools).
> Building the server only for the MCP route and only with the names the
> presented scope can use makes `policy_median_lt_5_ms` and
> `memory_max_lt_8_mib` pass in all three runs; the visible payload, the
> tool count and the denial results are unchanged. The two remaining misses
> are measured and attributed in Section 8.1.
>
> **Follow-up (2026-10-22, Section 3.3 and 8.2):** the capability memo of
> `ISSUE_v0.1_2026-10-21_04` was measured with the same harness and the same
> unchanged thresholds. One live capability decision per user, capability and
> object inside one advertised tool list round brings the policy window from
> 3.34–3.43 ms to 0.64–0.66 ms, so
> `auth_policy_median_lt_5_ms` **passes in all three runs** for the first
> time. `list_http_p50_lt_50_ms` (historical, <50 ms>) still misses by 2.60–2.83 ms; Section 8.2
> records the measured distance, the `path:line` evidence that adapter 0.7.0
> has no deferred-build API, every excluded alternative and the fact that no
> threshold was moved.
>
> | Gate | Verdict of this analysis | Where the cost sits |
> |---|---|---|
> | `policy_median_lt_5_ms` | Product | the plugin's own per-tool policy check inside `mcp_adapter_tools_list` |
> | `auth_policy_median_lt_5_ms` | Product | the same two product windows, summed |
> | `list_http_p50_lt_50_ms` (historical, <50 ms>) | Product | 59.9 % of the request sits between `init` and `rest_pre_dispatch`, where the plugin registers 117 abilities and the adapter builds the server |
> | `memory_max_lt_8_mib` | Product | 81.5 % of the peak is allocated while the request is handled, not while WordPress starts |
>
> The report is evidence for a product change, not a threshold change. A child
> ticket carries the product work; this document keeps the original gates.

---

## 1. Scope and method

### 1.1 What was measured

One `tools/list` MCP request against wp69 with the persistent cache on, the
same request the benchmark has always measured, plus new **analysis-only**
marks that split that request into phases. The six gate thresholds, their
units, their names and the meaning of
`X-Xnetvn-Benchmark-Memory-Bytes` are unchanged
(`tests/E2E/benchmark.php:260-265`).

The new marks are written by the lab MU-plugin
(`tests/E2E/benchmark-mu-plugin.php`) and only read by the benchmark
(`tests/E2E/benchmark.php`); no gate reads them:

| Mark | Meaning |
|---|---|
| `X-Xnetvn-Benchmark-Mu-Load-Ms` | request start to the MU-plugin load (the memory baseline) |
| `X-Xnetvn-Benchmark-Bootstrap-Ms` | request start to `plugins_loaded` |
| `X-Xnetvn-Benchmark-Init-Ms` | request start to the `init` mark (priority 1, right after the ability packs load) |
| `X-Xnetvn-Benchmark-Pre-Dispatch-Ms` | request start to `rest_pre_dispatch` (priority 0) |
| `X-Xnetvn-Benchmark-Memory-Baseline-Bytes` | the absolute MU-plugin baseline (`memory_get_usage()` at the MU-plugin load) |
| `X-Xnetvn-Benchmark-Memory-Plugins-Bytes` | current memory at `plugins_loaded` minus the baseline |
| `X-Xnetvn-Benchmark-Peak-Plugins-Bytes` | the absolute peak (`memory_get_peak_usage()`) at `plugins_loaded` |
| `X-Xnetvn-Benchmark-Memory-Init-Bytes` | current memory at the `init` mark minus the current memory at `plugins_loaded` |
| `X-Xnetvn-Benchmark-Peak-Init-Bytes` | the absolute peak at the `init` mark |
| `X-Xnetvn-Benchmark-Memory-Request-Bytes` | request peak minus the current memory at the `init` mark |
| `X-Xnetvn-Benchmark-Peak-Request-Bytes` | request peak minus the peak at the `init` mark |
| `X-Xnetvn-Benchmark-Declared-Classes*` | declared classes at `plugins_loaded`, at `init` and at the response |
| `X-Xnetvn-Benchmark-Phase-Rest-Api-Init-Ms` | the `rest_api_init` action, the window in which the adapter creates its servers |
| `X-Xnetvn-Benchmark-Phase-Mcp-Adapter-Init-Ms` | the `mcp_adapter_init` action, the window in which the plugin creates its own MCP server |
| `X-Xnetvn-Benchmark-Phase-Wp-Abilities-Api-Init-Ms` | the `wp_abilities_api_init` action, the window in which the plugin registers its abilities with core |
| `X-Xnetvn-Benchmark-Phase-Wp-Abilities-Api-Categories-Init-Ms` | the `wp_abilities_api_categories_init` action |
| `X-Xnetvn-Benchmark-Probe-Abilities-Total` | the number of abilities the WordPress abilities API knows |
| `X-Xnetvn-Benchmark-Probe-Abilities-Ours` | the number of those abilities that belong to this plugin |
| `X-Xnetvn-Benchmark-Probe-User-During-Rest-Init` | `1` when the current user was already resolved inside `rest_api_init`, `0` otherwise |

### 1.2 Environment

| Item | Value |
|---|---|
| Revision | `4bbadba` on branch `issue/perf-investigation-20261021` (integration base `ca9454d`, which already contains the first round of this work, merged at `9032d1f`); the commits after it touch only this document and the ticket file, so the measured code is unchanged |
| WordPress / PHP / adapter | 6.9 / 8.2.34 / 0.7.0 |
| Persistent cache | Redis 8.10.2 / Redis Object Cache 3.0.0 / Predis, owned drop-in enabled around every run |
| Visible tools | 60 (5 system + 1 read fixture + 54 synthetic benchmark descriptors) |
| Lab | `rtl-xnetvn-agent-hub-wp`, one namespace, one running project during the runs |
| Isolation | `flock` on `/work/.local/role-seo/wp69.lock` (the lock used by `infra/remote/run-role-seo.sh`), so no suite of this project ran in parallel |
| Container limits | unchanged: wp69 `mem_limit: 512m`, `cpus: 1.0`; toolbox `1024m`, `1.0` CPU |

### 1.3 Method of the three runs

Each run is one `rtl.py exec` command that, in order: takes the wp69 lock,
enables the owned cache fixture, runs `infra/remote/run-benchmark.sh`
unmodified, runs the background probe, and disables the cache fixture again
through an `EXIT` trap. The report JSON is written on the server at
`/work/.local/perf-analysis-2026-10-20/benchmark-run-{1,2,3}.json` and read
back with `rtl.py exec … cat`.

After every run the site was verified clean: no `xnetvn-benchmark.php`
drop-in, no `object-cache.php` drop-in, no HTTP fixture state, `redis-cache`
deactivated.

---

## 2. Run register

| Run | Run id | Window (UTC) | Load average before → after | `request_errors` |
|---|---|---|---|---|
| 1 | `perf-20261020-200808-7993` | 2026-10-20T20:08:08Z → 2026-10-20T20:09:57Z | 0.21 / 0.39 / 0.36 → 1.52 / 0.91 / 0.56 | 0 |
| 2 | `perf-20261020-200959-4554` | 2026-10-20T20:09:59Z → 2026-10-20T20:11:48Z | 1.47 / 0.91 / 0.56 → 0.74 / 0.87 / 0.59 | 0 |
| 3 | `perf-20261020-201150-1592` | 2026-10-20T20:11:50Z → 2026-10-20T20:13:38Z | 0.74 / 0.87 / 0.59 → 0.72 / 0.81 / 0.60 | 0 |

The run ids are built from the **UTC** clock (`date -u +%Y%m%d-%H%M%S`), so they
carry the same date as the `started_utc` metadata of the round. The load
average stayed between 0.21 and 1.52 on a six-CPU host, and no other project
ran on the lab during the three rounds.

---

## 3. Gate results

The thresholds are the original ones and were not touched.

| Gate | Threshold | Run 1 | Run 2 | Run 3 | Verdict |
|---|---|---|---|---|---|
| `auth_median_lt_5_ms` | < 5 ms | 3.813396 | 3.852197 | 3.782997 | Pass (3/3) |
| `policy_median_lt_5_ms` | < 5 ms | 5.720994 | 5.703695 | 5.674195 | **Miss (3/3)** |
| `auth_policy_median_lt_5_ms` | < 5 ms | 9.535290 | 9.570592 | 9.473492 | **Miss (3/3)** |
| `list_dispatch_p50_lt_50_ms` | < 50 ms | 14.376987 | 14.361687 | 14.314487 | Pass (3/3) |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | < 50 ms | 80.399927 | 79.727528 | 79.585628 | **Miss (3/3)** |
| `memory_max_lt_8_mib` | < 8 MiB | 8690448 B (8.2879 MiB) | 8687824 B (8.2854 MiB) | 8688272 B (8.2858 MiB) | **Miss (3/3)** |

The three runs agree to 0.047 ms on the policy median, 0.097 ms on the
auth+policy median, 0.814 ms on the HTTP median and 2624 bytes on the memory
peak. They reproduce the isolated runs of 2026-10-20 (policy 5.68/5.67,
HTTP 79.42/79.31, memory 8.2836/8.2840 MiB) to within 0.05 ms, 0.99 ms and
0.004 MiB. The shared-lab noise hypothesis is therefore **refuted**: the
misses are reproducible across sessions and load conditions. (The
multi-day evidence is the shared-lab run of 2026-10-18 recorded in
`ISSUE_v0.1_2026-10-16_06`; the runs of this analysis all fall on
2026-10-20 UTC.)

### 3.1 Product round of 2026-10-21

The product change of `ISSUE_v0.1_2026-10-21_01` was measured on the same lab
with the same harness, the same isolated procedure and the **unchanged**
thresholds of the table above. One baseline round was measured on the
pre-change code of the same day, so the two columns below differ only by the
change.

| Run id | Code | `request_errors` | `visible_tools` | logical JSON bytes |
|---|---|---|---|---|
| `perf-base-20261021-0400z` | pre-change | 0 | 60 | 33191 |
| `perf-f1-20261021-0445z` | post-change | 0 | 60 | 33191 |
| `perf-f2-20261021-0445z` | post-change | 0 | 60 | 33191 |
| `perf-f3-20261021-0445z` | post-change | 0 | 60 | 33191 |

| Gate | Baseline | Run 1 | Run 2 | Run 3 | Verdict |
|---|---|---|---|---|---|
| `auth_median_lt_5_ms` | 3.824 | 3.7055 | 3.7442 | 3.7353 | Pass (3/3) |
| `policy_median_lt_5_ms` | 5.5983 | 3.3462 | 3.3626 | 3.3468 | **Pass now (3/3)** |
| `auth_policy_median_lt_5_ms` | 9.4001 | 7.0587 | 7.0973 | 7.0808 | **Miss (3/3)** |
| `list_dispatch_p50_lt_50_ms` | 13.4269 | 11.5021 | 11.5175 | 11.4999 | Pass (3/3) |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | 79.0488 | 55.7839 | 55.9147 | 55.8650 | **Miss (3/3)** |
| `memory_max_lt_8_mib` | 8705720 B | 6170600 B | 6170600 B | 6171048 B | **Pass now (3/3)** |

The visible payload, the tool count and the number of failing requests are
unchanged; two of the four failing gates now pass and the two that remain
miss by a smaller margin than before (Section 3.2 and Section 8).

### 3.2 The measured root cause of the pre-dispatch cost

The hypothesis of the parent ticket — "every request re-registers all 117
abilities" — was measured instead of assumed, by instrumenting `src/` and by
varying the number of tool names handed to the adapter. The measurements are
medians of twelve warmed requests on the same lab, cache on.

| Measured part of one request | Baseline | After the change |
|---|---|---|
| `wp_abilities_api_init` (registering the abilities with core) | 0.99 ms | 0.78 ms |
| adapter `create_server()` with 120 tool names | **33.89 ms / 3 876 432 B** | 11.79 ms / 1 444 984 B |
| the plugin's own `mcp_adapter_tools_list` handler | 5.64 ms | 3.35 ms |
| `mcp_adapter_init` (whole action) | 34.84 ms | 11.81 ms |
| `rest_api_init` (whole action) | 39.19 ms | 15.34 ms |
| `pre_dispatch_ms` | 67.21 ms | 40.95 ms |
| `memory_request_bytes` | 7 091 336 B | 4 557 584 B |

The per-tool cost of the adapter is linear and was measured by advertising
0, 30, 60 and 120 tool names in the same session: 0.165 ms and 4 936 B for
the server itself, then **0.272 ms and 32 303 B per advertised tool**
(0.205 ms per tool for the small synthetic schemas, 0.34 ms for the real
business schemas). The adapter projects every advertised tool into every
supported MCP protocol revision before the request is served, so the cost is
paid for every name it is given, not only for the tools a client may use.

**The ability registration was never the dominant cost.** Registering 120
abilities with core costs 0.87 ms and 365 560 B; building the 120 adapter
tool objects costs 33.89 ms and 3 876 432 B, that is 39 times the time and
10.6 times the memory. The parent ticket's hypothesis is therefore refuted by
measurement, and the product change follows the measurement: the server is
built with no tool outside the exact MCP route and, on that route, with only
the names the presented delegation scope can use.

The remaining cost of the policy window was measured the same way, with
probes inside `Policy::evaluate()` and `Registry::allowed()`: of the 3.35 ms
that the window still costs for the 60 advertised tools, **2.62 ms are the 60
live capability checks** (`user_can()`), 0.08 ms are the extension filter and
0.45 ms are the rest of the decision path. The capability check must stay
live, because `tests/Unit/Policy/ToolVisibilityTest.php:44` requires a
capability that is lost mid-request to hide the tool immediately.

### 3.3 Product round of 2026-10-22 (capability memo)

The change of `ISSUE_v0.1_2026-10-21_04` was measured on the same lab with
the same harness, the same isolated procedure and the **unchanged**
thresholds of Section 3. The four rounds of 2026-10-22 04:59–05:13 UTC
(`benchmark-child-1.json` … `benchmark-child-4.json` of
`/work/.local/perf-analysis-2026-10-21/`) measured the code **before** the
change on the same stack; the three rounds below measured the code **after**
it.

| Run id (report file) | Code | `request_errors` | `visible_tools` | logical JSON bytes |
|---|---|---|---|---|
| `local-20261022T045956Z` (`benchmark-child-1.json`) | pre-change | 0 | 60 | 33191 |
| `local-20261022T050059Z` (`benchmark-child-2.json`) | pre-change | 0 | 60 | 33191 |
| `local-20261022T050300Z` (`benchmark-child-3.json`) | pre-change | 0 | 60 | 33191 |
| `local-20261022T051346Z` (`benchmark-child-4.json`) | pre-change | 0 | 60 | 33191 |
| `local-20261022T055048Z` (`benchmark-child-1.json`) | post-change | 0 | 60 | 33191 |
| `local-20261022T055256Z` (`benchmark-child-2.json`) | post-change | 0 | 60 | 33191 |
| `local-20261022T055457Z` (`benchmark-child-3.json`) | post-change | 0 | 60 | 33191 |

| Gate | Threshold | Pre-change (4 rounds) | Post-change run 1 | Post-change run 2 | Post-change run 3 | Verdict |
|---|---|---|---|---|---|---|
| `auth_median_lt_5_ms` | `< 5 ms` | 3.701 / 3.697 / 3.740 / 3.724 ms | 3.718497 ms | 3.708496 ms | 3.717896 ms | Pass (3/3) |
| `policy_median_lt_5_ms` | `< 5 ms` | 3.335 / 3.348 / 3.426 / 3.394 ms | 0.655599 ms | 0.644300 ms | 0.645499 ms | Pass (3/3) |
| `auth_policy_median_lt_5_ms` | `< 5 ms` | 7.038 / 7.055 / 7.158 / 7.108 ms | 4.384696 ms | 4.362197 ms | 4.364095 ms | **Pass now (3/3)** |
| `list_dispatch_p50_lt_50_ms` | `< 50 ms` | 11.250 / 11.453 / 11.007 / 11.570 ms | 8.290991 ms | 8.267292 ms | 8.286591 ms | Pass (3/3) |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | `< 50 ms` | 55.715 / 55.745 / 55.524 / 55.491 ms | 52.779746 ms | 52.829945 ms | 52.603445 ms | **Miss (3/3)** |
| `memory_max_lt_8_mib` | `< 8 MiB` | 6 173 448 / 6 173 448 / 6 173 448 / 6 170 824 B | 6 173 448 B | 6 173 448 B | 6 173 448 B | Pass (3/3) |

The advertised payload, the tool count, the number of failing requests and the
memory peak are unchanged; the policy window drops from 3.34–3.43 ms to
0.64–0.66 ms, which is the 2.62 ms of repeated live capability checks measured
in Section 3.2. The combined window therefore passes its original threshold
for the first time, with 0.62–0.64 ms of headroom.

The remaining miss is the complete HTTPS request. Its distance to the
original threshold is **2.779746 / 2.829945 / 2.603445 ms** in the three
rounds (52.779746, 52.829945 and 52.603445 ms against 50 ms). The request
still spends 40.95–41.22 ms before `rest_pre_dispatch` and 8.27–8.29 ms in
the dispatch window; the adapter's own `mcp_adapter_init` phase is
11.688588–11.729188 ms of that total and did not change (11.640–11.754 ms
before the change).

---

## 4. Cost allocation of one `tools/list` request

### 4.1 The timeline

Percentiles are the p50 of 100 valid samples; the phase values are
differences of those p50 values, so they are an approximation of the
per-sample split. The eight phases add up to the measured `http_ms`.

| Component | Source | Run 1 | Run 2 | Run 3 | Share of `http_ms` (run 2) |
|---|---|---|---|---|---|
| `tls_connect_ms` | `connect_ms + tls_ready_ms` | 0.000000 | 0.000000 | 0.000000 | 0.0 % |
| `server_think_ms` | `ttfb_ms` − `tls_connect_ms` | 78.888000 | 78.555000 | 78.417000 | 98.5 % |
| `phase_a1_core_startup_ms` | `mu_load_ms` (request start → MU-plugin load) | 7.294893 | 7.313013 | 7.286072 | 9.2 % |
| `phase_a2_plugin_load_ms` | `bootstrap_ms` − `mu_load_ms` (MU-plugin load → `plugins_loaded`) | 3.164053 | 2.887964 | 2.869844 | 3.6 % |
| `phase_b_init_ms` | `init_ms` − `bootstrap_ms` (`plugins_loaded` → `init`) | 4.173279 | 4.160881 | 4.153013 | 5.2 % |
| `phase_c_pre_dispatch_ms` | `pre_dispatch_ms` − `init_ms` (`init` → `rest_pre_dispatch`) | 47.685862 | 47.782183 | 47.646046 | **59.9 %** |
| `phase_d_dispatch_ms` | `dispatch_ms` (`rest_pre_dispatch` → `rest_post_dispatch`) | 14.376987 | 14.361687 | 14.314487 | 18.0 % |
| ↳ `auth_ms` | `X-Xnetvn-Benchmark-Auth-Ms` | 3.813396 | 3.852197 | 3.782997 | 4.8 % |
| ↳ `policy_ms` | `X-Xnetvn-Benchmark-Policy-Ms` | 5.720994 | 5.703695 | 5.674195 | 7.2 % |
| `residual_ms` | `server_think_ms` − `bootstrap_ms` − `dispatch_ms` | 54.052067 | 53.992336 | 53.946597 | 67.7 % |
| `phase_e_response_flush_ms` | `ttfb_ms` − (phases A1…D) | 2.192926 | 2.049272 | 2.147538 | 2.6 % |
| `phase_f_response_transfer_ms` | `curl_total_ms` − `ttfb_ms` | 1.152000 | 0.816000 | 0.808000 | 1.0 % |
| `phase_g_client_overhead_ms` | `http_ms` − `curl_total_ms` | 0.359927 | 0.356528 | 0.360628 | 0.4 % |
| `http_ms` (client total) | client `hrtime` around the request | 80.399927 | 79.727528 | 79.585628 | 100 % |

For run 2 the phases add up to 79.727528 ms, which is the measured `http_ms`
of 79.727528 ms; runs 1 and 3 close the same way (80.399927 and 79.585628).

**The part that is not attributed to a named phase is `phase_e`, not 0 ms.**
`phase_e_response_flush_ms` (2.192926 / 2.049272 / 2.147538 ms) is the time
between the last measurement mark (`rest_post_dispatch`) and the first byte the
client receives: the response serialisation in PHP, the flush through Apache
and TLS, and the transfer to the client. It is an **interpretation** of a
measured difference, not a directly measured phase, so it is reported as the
remainder of the request rather than as a named cost. The ticket's own
formula `residual_ms = server_think_ms − bootstrap_ms − dispatch_ms`
(54.052067 / 53.992336 / 53.946597 ms) is the sum of `phase_b`, `phase_c` and
`phase_e`; it is listed because the ticket asks for it, and it is not an
additional cost.

### 4.2 What belongs to whom

| Part of `http_ms` | Owner | Evidence |
|---|---|---|
| TLS handshake, DNS, new connections (0.000 ms) | infrastructure | `connect_ms`, `tls_ready_ms`, `new_connections` are 0 in every run: the client keeps the connection, so the transport adds nothing |
| Response transfer and client overhead | infrastructure and test client | `curl_total_ms − ttfb_ms` and `http_ms − curl_total_ms` |
| Phase A1, core start-up (request start → MU-plugin load) (7.313 ms) | WordPress core | the benchmark MU-plugin is the last MU-plugin of the site, so this window is the core start-up (`wp-config.php`, the database connection, `wp-settings.php` up to the MU-plugin loader) plus any MU-plugin that the site loads before it; it contains **no** code of the Agent Hub plugin |
| Phase A2, plugin load (MU-plugin load → `plugins_loaded`) (2.888 ms) | **product**, with the dependency | the Agent Hub plugin file is loaded and its kernel boots in this window, before `plugins_loaded` fires: `xnetvn-agent-hub-wp.php:32` → `src/Bootstrap/Loader.php:39` → `src/Plugin.php:89`, `:96`, `:113-152` (the providers and the registry are built here); the separately installed adapter plugin is loaded in the same window |
| Phase B, ability packs (4.161 ms) | product | the plugin builds all pack definitions at `init` priority 0 (`src/Abilities/Registry.php:91-104`) |
| Phase C, abilities API and adapter server (47.782 ms) | product, with the dependency | between `init` and `rest_pre_dispatch` WordPress initialises the abilities API, the plugin registers its 117 abilities with their schemas (`src/Abilities/Registry.php:132-175`) and the adapter creates the MCP server (`src/Mcp/ServerGateway.php:81-117`) |
| Phase D, dispatch (14.362 ms) | product and dependency | adapter dispatch plus the plugin's policy window (5.704 ms) and authentication window (3.852 ms) |

The **network is not the cause**: the TLS/connection cost is 0 ms and the
response transfer is 0.816 ms, together 1 % of the request.

---

## 5. Background probe P3 and the plain-request reference

### 5.1 The prescribed probe

The ticket prescribes an empty POST to `https://$RTL_ALIAS_WP69/wp-login.php`
through the same client transport, run right after the MCP measurement with
10 warm-up and 100 samples. It was implemented in
`tests/E2E/benchmark-cost-probe.php` (the file allowed by the ticket) because
`McpClient::request()` throws `UnexpectedValueException('Malformed test
response.')` for an HTML body **before** it returns the transport array
(`tests/E2E/McpClient.php:104-105`, `:142-150`), so the transport numbers of
a login page cannot be read through that client.

| Probe metric | Run 1 | Run 2 | Run 3 |
|---|---|---|---|
| `http_ms` p50 | 499.910348 | 500.094847 | 500.291448 |
| `ttfb_ms` p50 | 499.721000 | 499.989000 | 500.182000 |
| `curl_total_ms` p50 | 499.883000 | 500.066000 | 500.263000 |
| `dns_ms`, `connect_ms`, `tls_ready_ms`, `new_connections` p50 | 0 | 0 | 0 | 0 |
| HTTP status | 200 (100/100) | 200 (100/100) | 200 (100/100) |

### 5.2 Why this probe cannot be used as a lower bound

The prescribed probe is **6.3 times slower than the MCP request it is meant
to bound** (500.1 ms against 79.7 ms), so the ticket's formula "the part of
`http_ms` above P3 is the marginal cost of the MCP route" cannot be applied:
P3 is not a lower bound for a plain request on this lab.

A diagnostic run in the toolbox (one request per endpoint, persistent cache
off at that moment) isolates where the cost is:

| Request | Code | `time_starttransfer` | `time_total` |
|---|---|---|---|
| `GET /wp-login.php` | 200 | 0.121212 | 0.121315 |
| `POST /wp-login.php` | 200 | 0.476178 | 0.476354 |
| `GET /` | 200 | 0.160118 | 0.162082 |
| `GET /wp-json/` | 200 | 0.198916 | 0.201670 |
| `POST /wp-json/` | 404 | 0.366690 | 0.366763 |

The empty POST costs about 355 ms more than the same page with GET, and the
POST to a REST path costs about 168 ms more than the GET to the same path.
The probe therefore measures a **login-path cost**, not the cost of a plain
WordPress request. This is a property of the prescribed probe target, not of
the MCP route.

### 5.3 The plain site read as the usable reference

The probe therefore also reads the site root with GET over the same
connection, in the same run and under the same cache state. That read is the
usable "cost outside the MCP route" reference:

| Reference metric (`GET /`) | Run 1 | Run 2 | Run 3 |
|---|---|---|---|
| `http_ms` p50 | 59.171947 | 59.148946 | 59.398546 |
| `ttfb_ms` p50 | 57.231000 | 57.224000 | 57.499000 |
| `curl_total_ms` p50 | 59.145000 | 59.120000 | 59.372000 |
| `dns_ms`, `connect_ms`, `tls_ready_ms`, `new_connections` p50 | 0 | 0 | 0 | 0 |
| HTTP status | 200 (100/100) | 200 (100/100) | 200 (100/100) |

A plain read of the same site on the same transport costs 59.15 ms (run 2),
of which the transport contributes 0 ms and the response transfer 1.896 ms.
The MCP request costs 79.73 ms, so the MCP route adds **20.58 ms** over a
plain page read of the same site — that is the dispatch window (14.36 ms)
plus the larger response body. The remaining 59.15 ms is the shared cost of
every request on this site: WordPress core start-up, the plugin boot, the
ability registration and the adapter server.

### 5.4 Limits of the probe

- The probe still loads the Agent Hub plugin (every plugin loads on every
  request), so it cannot separate the plugin's bootstrap from the core's.
- The probe runs **after** the MCP measurement in the same round, so it sees
  the same cache state and the same host load, but not the same PHP process.
- Because of 5.2 the ticket's subtraction formula is replaced by the phase
  marks of Section 4, which attribute every millisecond of `http_ms` without
  a subtraction.

---

## 6. The memory peak

The baseline is the MU-plugin load, the same baseline the gate has always
used. The report publishes the **absolute** marks next to the derived ones, so
that every part below can be recomputed by the reader.

### 6.1 The absolute marks

| Mark | Run 1 | Run 2 | Run 3 |
|---|---|---|---|
| `memory_baseline_bytes` — the MU-plugin baseline (absolute) | 1479312 | 1481872 | 1481872 |
| `memory_bytes` — the gate metric (request peak − baseline) | 8690448 | 8687824 | 8688272 |
| `memory_plugins_bytes` — current at `plugins_loaded` − baseline | 808264 | 805640 | 805640 |
| `peak_plugins_bytes` — absolute peak at `plugins_loaded` | 2287576 | 2287512 | 2287512 |
| `memory_init_bytes` — current at `init` − current at `plugins_loaded` | 793496 | 793496 | 793496 |
| `peak_init_bytes` — absolute peak at `init` | 3086336 | 3086272 | 3086272 |
| `memory_request_bytes` — request peak − current at `init` | 7088688 | 7088688 | 7089136 |
| `peak_request_bytes` — request peak − peak at `init` | 7083424 | 7083424 | 7083872 |

### 6.2 Two splits of the same peak

| Split | Part | Run 1 | Run 2 | Run 3 | Share of the peak (run 2) |
|---|---|---|---|---|---|
| current-based | `memory_plugins_bytes` — current at `plugins_loaded` − baseline | 808264 B | 805640 B | 805640 B | 9.3 % |
| current-based | `memory_init_bytes` — current at `init` − current at `plugins_loaded` | 793496 B | 793496 B | 793496 B | 9.1 % |
| current-based | `memory_request_bytes` — request peak − current at `init` | 7088688 B | 7088688 B | 7089136 B | **81.6 %** |
| peak-based | startup: `peak_plugins_bytes` − `memory_baseline_bytes` | 808264 B | 805640 B | 805640 B | 9.3 % |
| peak-based | pack load: `peak_init_bytes` − `peak_plugins_bytes` | 798760 B | 798760 B | 798760 B | 9.2 % |
| peak-based | request: request peak − `peak_init_bytes` | 7083424 B | 7083424 B | 7083872 B | **81.5 %** |
| — | `memory_bytes` (the gate metric) | 8690448 B | 8687824 B | 8688272 B | 100 % |

**The "sum = `memory_bytes`" row is an identity, not a verification.** Both
splits are differences of the same three marks, so each of them adds up to the
gate metric by construction; the earlier wording ("the sum matches the gate
metric exactly in every run") was therefore **not** evidence. What the
absolute marks do show is where the current value and the peak value differ:
at `plugins_loaded` the gap is **0 B** (the process is still at its maximum),
while at `init` the peak is **5264 B** above the retained usage, which is why
the current-based request part (7088688 B) is 5264 B larger than the
peak-based request part (7083424 B). The **peak** split attributes the growth
of the peak itself; the current split attributes the memory still held at the
end of the request. Both point at the same phase.

### 6.3 Mechanism

The peak is reached while the request is handled, not while WordPress starts.
Only 1.50 MiB of the 8.29 MiB peak (18.5 %) is startup cost (the kernel boot
plus the ability packs); 6.76 MiB (81.5 % by the peak split) is allocated
after the `init` mark, in the window where the WordPress abilities API
registers the plugin's 117 abilities with their schemas, the adapter builds
the server and the 60-tool list is assembled and filtered. The declared-class
count shows the same shape: 539 classes at `plugins_loaded`, 563 at `init`,
631 at the response.

**Consequence for open question 1.** The evidence does **not** support
changing the measurement of `memory_max_lt_8_mib`: the peak is a per-request
cost, not a startup cost, so the measurement already points at the product
path. The measurement and the `< 8 MiB` threshold both stay unchanged, and no
separate measurement-change ticket is opened. The product work belongs to the
child ticket.

---

## 7. Checkpoint 2026-10-05 versus the new runs

The checkpoint is the numeric artifact `docs/en/reports/M1-60-tools.json`
(`source_base_commit` = `c98ca1c`, `measurement_date_utc` = 2026-10-05). Per
the decision of the coordinator (2026-10-21) it was **not** re-measured at the
old revision; the remainder that cannot be attributed is written down as
"not yet explained", with its number.

| Row | Checkpoint 2026-10-05 | New runs (mean of 3) | Delta | Explained by the measurement | Not yet explained |
|---|---|---|---|---|---|
| auth p50 | 3.771296 ms | 3.816197 ms | +0.044901 ms (+1.19 %) | the window is unchanged product code; the delta is inside the noise of two different days | 0.044901 ms |
| policy p50 | 3.714196 ms | 5.699628 ms | +1.985432 ms (+53.46 %) | the whole delta sits inside the product's policy window; the visible payload did not change (33 191 logical JSON bytes in both) | the internal split of the 1.985 ms inside the window (not measured) |
| auth+policy p50 | 7.494492 ms | 9.526458 ms | +2.031966 ms (+27.11 %) | the sum of the two product windows above | 0.001633 ms of the sum is not attributable to either single window (percentiles of a sum are not the sum of percentiles) |
| dispatch p50 | 12.599588 ms | 14.351054 ms | +1.751466 ms (+13.90 %) | inside the REST dispatch window | 1.751466 ms of internal split (not measured) |
| HTTP p50 | 55.867646 ms | 79.904361 ms | +24.036715 ms (+43.02 %) | +1.751 ms in the dispatch window and +22.285 ms **before** `rest_pre_dispatch` | the split of the 22.285 ms at the checkpoint (the artifact has no phase marks) |
| HTTP p50 − dispatch p50 | 43.268058 ms | 65.553307 ms | +22.285249 ms (+51.51 %) | the new measurement splits it: 7.313 ms core start-up + 2.888 ms plugin load + 4.161 ms pack load + 47.782 ms abilities/adapter window | the checkpoint's split of the same 43.268 ms |
| memory max | 5 509 680 B (5.2544 MiB) | 8 688 848 B (8.2863 MiB) | +3 179 168 B (+57.70 %) | the new measurement places 81.5 % of the peak in the request phase and 18.5 % in startup | 3 179 168 B (the checkpoint artifact has no memory split) |
| `concurrent_10` HTTP p50 | 1101.586029 ms | 1562.495519 ms | +460.909490 ms (+41.84 %) | the same single-request phases, amplified by ten concurrent workers on one CPU | 460.909490 ms (no concurrent phase marks) |

**What the code delta says.** `git diff --stat c98ca1c..HEAD -- src` reports
**59 files changed, 9017 insertions, 65 deletions**. The new namespaces are
`src/Abilities/Packs/{Content,Media,Comments,Taxonomy,Users}` (the five real
packs, 57 additional abilities), `src/Support/Http`, `src/RateLimit` and
`src/Contracts`. The repository's own architecture note records that the real
Content/Media, Taxonomy and Users packs were measured on 2026-10-08,
2026-10-09 and 2026-10-10 — **after** the 2026-10-05 checkpoint, which
measured a site that registered only the 5 system abilities, the read fixture
and the 54 synthetic descriptors (60 in total). The site now registers 117
abilities (62 real ones plus the 54 synthetic descriptors and the read
fixture) on every request, while the visible MCP payload is byte-identical
(33 191 logical JSON bytes in both the checkpoint and the new runs).

That is the most likely cause of the +3 179 168 B of memory and of the
+22.285 ms that now sits before `rest_pre_dispatch`: the product loads and
registers roughly twice as many ability definitions on every request. It is
still a **hypothesis with documentary support, not a measured attribution**:
the checkpoint artifact has no phase marks, and the ticket's decision forbids
re-measuring at `c98ca1c`, so the split of the checkpoint's own 43.268 ms
cannot be recovered. The remaining numbers are listed above as "not yet
explained".

---

## 8. Conclusion per gate and proposed action

| Gate | Classification | Evidence | Proposed action |
|---|---|---|---|
| `policy_median_lt_5_ms` (5.700 ms) | **Product issue** | the measured window is the plugin's own `mcp_adapter_tools_list` handler (`src/Mcp/ServerGateway.php:145-162`), which re-evaluates the policy for every listed tool; the visible payload did not change, so the extra 1.985 ms is per-tool work, not payload work | child ticket: cache the per-tool decision inside one request and stop re-reading the site settings for every tool |
| `auth_policy_median_lt_5_ms` (9.526 ms) | **Product issue** | the sum of two product windows (3.816 ms authentication + 5.700 ms policy); both are inside the plugin's code | same child ticket; the two windows are the same path |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) (79.904 ms) | **Product issue** | 59.9 % of the request (47.782 ms) sits between `init` and `rest_pre_dispatch`, the window where the plugin registers 117 abilities and the adapter builds the server; a plain page read of the same site costs 59.149 ms, so the transport is not the cause | child ticket: register the abilities lazily, only for the requests that need them, and keep the payload identical |
| `memory_max_lt_8_mib` (8.2863 MiB, mean of three runs) | **Product issue** | 81.5 % of the peak (6.76 MiB) is allocated while the request is handled; only 18.5 % (1.50 MiB) is startup cost | same child ticket: the same lazy registration removes the duplicated definitions; **the measurement and the threshold stay unchanged** |

No gate is classified as an environment problem: the three isolated runs
reproduce the misses within 0.05 ms on the policy median while the load
average stayed between 0.21 and 1.52 on a six-CPU host, and the transport
cost is 0 ms. No gate is classified as a measurement problem: the memory peak
is a per-request cost, so the existing measurement already points at the
product, and the coordinator's decision of 2026-10-21 keeps both the
measurement and the threshold.

**Thresholds: unchanged in this analysis.** At the time of this analysis the
six original gates kept their values, units and names in
`tests/E2E/benchmark.php:260-265`, in
`docs/en/operations/performance-slo.md` and in the referenced tickets. A
separate owner-authorized requirement change of 2026-11-09 revises only the
complete-HTTPS p50 gate; Section 8.6 records it, and every earlier verdict
here stays `historical, <50 ms>`.

### 8.1 Verdict after the product change of 2026-10-21

The change measured in Section 3.1 removes the eager server build on every
REST request and keeps the per-request site setting reads out of the
per-tool decision path. The visible payload, the tool count, the permission
scope and the denial results are unchanged.

| Gate | Before | After | Verdict after the change |
|---|---|---|---|
| `policy_median_lt_5_ms` | 5.5983 ms | 3.3462 / 3.3626 / 3.3468 ms | **Pass (3/3)** |
| `auth_policy_median_lt_5_ms` | 9.4001 ms | 7.0587 / 7.0973 / 7.0808 ms | **Miss (3/3)** — the window is 3.71–3.74 ms of authentication plus 3.35 ms of policy; the 60 live `user_can()` checks alone cost 2.62 ms and must stay live |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | 79.0488 ms | 55.7839 / 55.9147 / 55.8650 ms | **Miss (3/3)** — 40.95 ms is spent before `rest_pre_dispatch` (7.3 ms core start-up, 2.9 ms plugin load, 4.2 ms pack load, 11.8 ms adapter server build for the 60 advertised tools, the rest is core REST dispatch) and 11.50 ms in the dispatch window |
| `memory_max_lt_8_mib` | 8 705 720 B | 6 170 600 / 6 170 600 / 6 171 048 B | **Pass (3/3)** |

**Why the two remaining gates still miss.**

- `auth_policy_median_lt_5_ms` needs a policy window below about 1.25 ms,
  because the authentication window of this lab is 3.71–3.74 ms on its own.
  The window costs 3.35 ms for 60 advertised tools, of which 2.62 ms are the
  live capability checks. Reaching the gate therefore needs either a smaller
  advertised tool set, which the `tools/list` contract forbids, or a
  cheaper capability check, which `tests/Unit/Policy/ToolVisibilityTest.php:44`
  forbids, or a cheaper authentication window, which lives in
  `src/Auth/Authenticator.php` and `src/RateLimit/`, outside the scope of
  the product ticket.
- `list_http_p50_lt_50_ms` (historical, <50 ms>) needs about 6 ms more. The largest remaining
  product-owned block is the 11.8 ms the adapter needs to build the 60
  advertised tools of the MCP route, a cost inside the separately installed
  dependency (`McpComponentRegistry::add_mcp_tool()` projects every tool
  into every supported protocol revision). The rest of the request is
  WordPress core start-up, the plugin boot and the core REST dispatch.

Both remaining misses are product costs, not measurement or environment
problems, and both are outside the files that
`ISSUE_v0.1_2026-10-21_01` was allowed to change.

### 8.2 Verdict after the capability memo of 2026-10-22

> The figures in this section are the **pre-change baseline** of 2026-10-22. They stay for comparability; the current attribution and the latest measured
> ranges are Section 8.4 (2026-10-31) and Section 8.5 (2026-11-07).

The change measured in Section 3.3 resolves one capability decision per user,
capability and object inside one advertised tool list round. The tool list,
the payload, the permission scope, the denial results and the ability lookup
outside the MCP route are unchanged, and the capability check of every other
path stays live.

| Gate | Before (2026-10-21) | After (2026-10-22, three rounds) | Verdict after the change |
|---|---|---|---|
| `policy_median_lt_5_ms` | 3.3462 / 3.3626 / 3.3468 ms | 0.655599 / 0.644300 / 0.645499 ms | Pass (3/3) |
| `auth_policy_median_lt_5_ms` | 7.0587 / 7.0973 / 7.0808 ms | 4.384696 / 4.362197 / 4.364095 ms | **Pass (3/3)** — the window is 3.71 ms of authentication plus 0.64–0.66 ms of policy |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | 55.7839 / 55.9147 / 55.8650 ms | 52.779746 / 52.829945 / 52.603445 ms | **Miss (3/3)** — 2.779746 / 2.829945 / 2.603445 ms above the original threshold |
| `memory_max_lt_8_mib` | 6 170 600 / 6 170 600 / 6 171 048 B | 6 173 448 / 6 173 448 / 6 173 448 B | Pass (3/3) |

**Why `list_http_p50_lt_50_ms` (historical, <50 ms>) cannot be met inside the plugin.** The three
rounds agree on the conclusion, and every number below comes from the same
three report files.

1. **The measured distance to the threshold.** The complete warmed HTTPS
   request is 52.779746 ms, 52.829945 ms and 52.603445 ms, that is
   **2.779746 ms, 2.829945 ms and 2.603445 ms above** the original threshold
   of 50 ms. The only plugin-owned window timed separately in that request is the
   policy window, which now costs 0.644300–0.655599 ms in total; the
   plugin-only share of the pre-dispatch window is not separated
   (Section 8.4), so it is not claimed to be zero here. Subtracting the
   policy p50 from the HTTP p50 of each pair gives 52.124147, 52.185645 and
   51.957946 ms, that is 2.124147, 2.185645 and 1.957946 ms above the
   threshold. That subtraction is only an arithmetic illustration of the
   medians, not a counterfactual measurement: a zero policy window was never
   run and the p50 of a difference is not the difference of p50 values.
2. **The largest remaining block belongs to the dependency.** Of the
   40.946006–41.217089 ms spent before `rest_pre_dispatch`,
   `phase_mcp_adapter_init_ms` alone is 11.688588–11.729188 ms, the phase in
   which the adapter builds its server and projects the 60 advertised tools
   into every supported protocol revision. That phase did not change with
   this work (11.639893–11.754187 ms before the change).
3. **Adapter 0.7.0 has no deferred-build API.** The read-only copy of the
   dependency on this machine is `.local/evidence/mcp-adapter/`, and its
   sha256 of `mcp-adapter.php`, `includes/Core/McpAdapter.php`,
   `includes/Core/McpServer.php`, `includes/Core/McpComponentRegistry.php`,
   `includes/Domain/Tools/McpTool.php`,
   `includes/Handlers/Tools/ToolsHandler.php` and
   `includes/Core/McpVersionNegotiator.php` is identical to the copy
   installed in the lab. The API evidence is:
   - `mcp-adapter.php:14` — the version is 0.7.0.
   - `includes/Core/McpAdapter.php:81` — the adapter hooks `init()` onto
     `rest_api_init` with priority 15; `:93` is `init()`; `:113` fires
     `mcp_adapter_init`.
   - `includes/Core/McpAdapter.php:221` — `create_server()` is the only
     public entry point, and `:278` refuses every call outside
     `doing_action( 'mcp_adapter_init' )` with `invalid_timing`.
   - `includes/Core/McpServer.php:141` — the constructor; `:237` calls
     `register_tools( $tools )` inside it, so the tool objects exist before
     any request is served.
   - `includes/Core/McpServer.php:90` — `private McpComponentRegistry
     $component_registry`, without a public getter; `:371` `count_tools()` and
     `:403` `get_mcp_tool()` only read.
   - `includes/Core/McpComponentRegistry.php:134` — `register_tools()`;
     `:147` `register_single_tool()`, `:182` `add_mcp_tool()` and `:238`
     `register_ability_tool()` are private; `:585` `get_tools()` rebuilds the
     records for the schema of the request; `:673` `has_any_projection()`
     walks every supported protocol revision.
   - `includes/Core/McpVersionNegotiator.php:35-38` — two supported protocol
     revisions, so every tool is projected twice.
   - `includes/Domain/Tools/McpTool.php:196` — `McpTool::fromAbility()`.
   - `includes/Handlers/Tools/ToolsHandler.php:66` — `list_tools()`, and
     `:84` applies the `mcp_adapter_tools_list` filter to the **already
     projected** records, not to `McpTool` objects.
   - A search of the whole `includes/` tree of the dependency for a
     deferred or lazy build entry point returns nothing.
4. **Every alternative was excluded with its reason.**
   - Reflection on `$component_registry` or on `add_mcp_tool()` breaks the
     encapsulation of the dependency and depends on its private details.
   - Calling `register_tools()` after the build is impossible: the registry
     is private and has no public getter, so it needs reflection.
   - Adding records in `mcp_adapter_tools_list` cannot help: the filter
     receives the already projected records, and the initial build cost is
     paid before the filter runs.
   - Advertising fewer tools changes the `tools/list` contract and the
     payload of 33 191 logical bytes.
   - Caching the tool objects persistently would store request-bound objects
     (abilities, schemas, permissions) outside their request.
   - Patching or upgrading the dependency is outside the plugin and outside
     the scope of this ticket; the dependency was only read.
   - Building the server later than `mcp_adapter_init` is refused by
     `create_server()` with `invalid_timing`, and the route would not be
     registered.
   - Building the server only at the first `tools/list` is refused by the
     same guard: the route has to exist from `rest_api_init`.
5. **No threshold was moved.** The six gate names, their units and their
   values stay exactly as they are in `tests/E2E/benchmark.php:267-272` and
   in Section 2 of `docs/en/operations/performance-slo.md`; the measurement
   rules and the meaning of `X-Xnetvn-Benchmark-Memory-Bytes` are unchanged.

**What remains open.** `list_http_p50_lt_50_ms` (historical, <50 ms>) is a real gap of about
2.6–2.8 ms that the plugin cannot close while adapter 0.7.0 builds its tool
objects before the request is served. Closing it needs a change in the
separately installed dependency (a deferred or projected-on-demand build) or
an explicit owner decision on the threshold. Both are outside this ticket.

### 8.3 Ownership round of 2026-10-27

A third isolated measurement re-ran the wp69 cache-on benchmark three times at
revision `53b272e96f36b85cea4734d9b4cb53a9821fe469`, with
`tests/E2E/benchmark.php` sha256
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec`, the same
`flock`/`trap` procedure and the same unchanged thresholds; the run ids are
`local-20261026T225819Z`, `local-20261026T225910Z` and
`local-20261026T225952Z`. Every round reported `request_errors: 0`, 60
visible tools and 33 191 logical JSON bytes. The complete-HTTPS median is
53.465 / 53.374 / 53.359 ms, i.e. 3.465 / 3.374 / 3.359 ms above the original
`< 50 ms` threshold, while the other five gates pass in all three rounds, so
the gap is unchanged in nature and slightly larger than the 2.603–2.830 ms of
Section 8.2. Because a round misses, the threshold stays where it is and the
red case stays covered: while it is open,
`ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`
owns the `list_http_p50_lt_50_ms` (historical, <50 ms>) gate. The three rounds reused the already
running isolated stack instead of restarting it before each round, which keeps
the PHP caches warm and therefore favours the measured value; a warm stack
that still misses cannot hide a colder failure. The measured distance, the
adapter `path:line` evidence and every excluded alternative of Section 8.2 are
unchanged.

### 8.4 Plugin-only diagnosis of 2026-10-31

`ISSUE_v0.1_2026-10-27_04` asked a narrower question than Section 8.3: after
the adapter's own build, which remaining cost is still inside the plugin and
could be removed without changing the six thresholds, the toolset or the
advertised payload? Three more wp69 cache-on rounds were measured at revision
`536873f1ad73f774f4cf0417b640ebb8247caeb4` (base `a4b283f14e07a0d13572efe70b2fbb04c2fc18aa`),
with `tests/E2E/benchmark.php` sha256
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec` and
adapter `0.7.0`. The run ids are `20261031T0602Z`; the three report files are
`benchmark-child-1.json` (sha256 `4e05f12fc046c15f4b1b256cb48e01ff226b27206c816a175fdf63a4f2443285`),
`benchmark-child-2.json` (sha256 `2cf503c713b318e700a670973907109ed5cadda47e0b74069df58ba6d6c7ac12`)
and `benchmark-child-3.json` (sha256 `3a41689346aa19b9991de9f833cfb26d6d2e33d16ee5757b70b2897ab9aa28af`).

The stack was already running and the owner's answer of 2026-10-27 allowed
sequential measurement without `down`/`up`. `rtl.py plan` returned exit 2 on
the known memory double-count (`2816 MB running + 2816 MB for this project >
5120 MB`), not on a real shortage; the stack was therefore not redeployed.
`rtl.py preflight` returned only the existing firewall warning. The owned
cache fixture was enabled before each round and disabled by the `trap`; after
the last round both `/sites/wp69/wp-content/object-cache.php` and
`/work/.local/cache-fixture/wp69/owned-dropin.sha256` were absent.

| Gate | Threshold | Run 1 | Run 2 | Run 3 | Verdict |
|---|---|---|---|---|---|
| `auth_median_lt_5_ms` | `< 5 ms` | pass | pass | pass | Pass (3/3) |
| `policy_median_lt_5_ms` | `< 5 ms` | pass | pass | pass | Pass (3/3) |
| `auth_policy_median_lt_5_ms` | `< 5 ms` | pass | pass | pass | Pass (3/3) |
| `list_dispatch_p50_lt_50_ms` | `< 50 ms` | 8.766199 ms | 8.795498 ms | 8.808198 ms | Pass (3/3) |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | `< 50 ms` | 52.956692 ms | 53.112690 ms | 52.907789 ms | **Miss (3/3)** |
| `memory_max_lt_8_mib` | `< 8 MiB` | pass | pass | pass | Pass (3/3) |

Every round reported `request_errors: 0`, `visible_tools: 60` and 33 191
logical JSON bytes. The distance to the HTTP threshold is 2.956692, 3.112690
and 2.907789 ms. The p50 timeline of run 1 is: `bootstrap_ms` 10.334969,
`init_ms` 14.428139, `pre_dispatch_ms` 40.772915, `dispatch_ms` 8.766199,
`phase_mcp_adapter_init_ms` 11.721398, `phase_wp_abilities_api_init_ms`
0.749500, `phase_categories_init_ms` 0.109600, `auth_ms` 3.662600,
`policy_ms` 0.659300, `mu_load_ms` 7.200003 and memory 6 173 232 B. These are
aggregate phase values, not a per-sample split; nested phases are not added
twice.

**What is measured and what is not.** The plugin's own provider construction
(`src/Plugin.php:113-152`), the pack definition build
(`src/Abilities/Registry.php:92-104`) and the ability registrations of every
available pack (`src/Abilities/Registry.php:135-175`; the catalog test pins the
exact count, so no number is hard-coded here) have no dedicated timer. They sit
inside the aggregate `bootstrap_ms`, `init_ms` and the difference between
`pre_dispatch_ms` and `init_ms`, together with WordPress core and the adapter.
The adapter build is separately timed at 11.72 ms; the abilities API and
category phases are separately timed at 0.75 ms and 0.11 ms. The auth and
policy windows are separately timed. The remaining plugin-only share of the
40.77 ms pre-dispatch window is therefore **unavailable**, not zero.

**Candidate plugin-only changes.** Each candidate is a hypothesis for a
separate fix ticket; none was implemented here.

The arithmetic residual between the complete HTTPS p50 and the sum of the
timed windows (about 3.4 ms) is the untimed client, transport and core
overhead of the request. It is recorded as **unattributed**, not assigned to
the plugin, and no measured speedup is derived from it.

| Candidate | Evidence | Expected saving (hypothesis) | Invariant and test | Public API pinned | Rollback | Risk |
|---|---|---|---|---|---|---|
| Build only the packs visible to the presented scope, after the scope hint, instead of all six packs at `init` | `src/Abilities/Registry.php:92-104` builds every definition of the available packs; the benchmark exposes 60 tools; `names_for_scope()` at `:216-240` already applies the scope terms | 0.5–1.5 ms | Unknown/malformed key must fall back to the full catalog; `allowed()` still checks every tool live; ability-catalog, scope and policy tests | `Registry::add_pack()`/`AbilityPack::definitions()` and the ability names, schemas and capability map stay unchanged | One commit; deleting the selection branch restores the current full-catalog build | The adapter needs the names at `mcp_adapter_init`; the scope hint is unauthenticated narrowing, never a grant |
| Register categories once per toolset instead of once per definition | `src/Abilities/Registry.php:119-133` calls `wp_has_ability_category()` for every definition; there are only six toolset slugs | ≤ 0.11 ms, bounded by the measured `phase_categories_init_ms` 0.109600 ms | Category set and labels unchanged; `wp_has_ability_category` contract test | `wp_has_ability_category()` / `wp_register_ability_category()` (WordPress 6.9), no plugin API change | One commit; reverting the memo restores the per-definition call | A future dynamic toolset must still be registered |
| Register only the scope-visible abilities on an authenticated MCP route, with a full-catalog fallback | `src/Abilities/Registry.php:135-175` registers every definition; the scope exposes 60 | 0.2–0.4 ms | Fallback on missing/unknown key; private ability metadata and output pipeline unchanged; role/scope matrix | `wp_register_ability()` and the registry getters `Registry::all()` / `Registry::names()` unchanged; the fallback keeps the same names | One commit; the fallback path is the current behaviour, so reverting the filter restores it | If the key is not yet authenticated, the hint must narrow only |
| Defer the management controllers and `Pages` construction on a non-management request | `src/Plugin.php:147-150` constructs `KeysController`, `AuditController`, `SettingsController` and `Pages` for every request | < 0.2 ms | Management cookie+nonce routes still register on management requests; REST route tests | No public API change; the controllers and their routes keep their names | One commit; removing the condition constructs them on every request again | Missing a management hook on a non-management route |
| Remove the duplicate `find_by_key_id()` between `ServerGateway::scope_hint()` and `Authenticator::authenticate()` | `src/Mcp/ServerGateway.php:158-171` and `src/Auth/Authenticator.php:62-80` both read the same key | not recommended | Would need a request-local cache of a stored key; secret handling and auth ordering must stay exact | Would add a request-scoped cache to the auth path | Revert the cache and the ordering change together | Caching a credential-derived object before authorization is a security regression |

**The adapter block.** The 11.72 ms `phase_mcp_adapter_init_ms` is the same
boundary documented in Section 8.2: adapter `0.7.0` has no public
deferred-build API, `create_server()` refuses calls outside
`mcp_adapter_init`, and the `mcp_adapter_tools_list` filter receives already
projected records. Fewer advertised tools would change the payload; a
persistent object cache would store request-bound ability objects. Those
alternatives remain excluded for the reasons in Section 8.2, not because the
plugin is "waiting for upstream".

**Conclusion.** The measured gap is 2.91–3.11 ms. The plugin-only candidates
above are plausible but each is small; together they may not close the gap.
The unmeasured split of the 40.77 ms pre-dispatch window is the largest
uncertainty. A fix ticket should implement the safe candidates (packs by
scope, category dedupe, scope-visible registration) with the full-catalog
fallback, then re-run the same three-round benchmark and integration/HTTP
checks. If the gap remains, the evidence points to the adapter build and to
the unmeasured pre-dispatch split; a separate instrumentation ticket is then
required. STAB-03 stays unmet and the gate stays owned by
`ISSUE_v0.1_2026-10-27_02`.

### 8.5 Ownership round of 2026-11-07

`ISSUE_v0.1_2026-10-27_02` re-measured the same wp69 cache-on path three times
at revision `f4535c36a55391138682cc9942e783a9b0befde5`, with
`tests/E2E/benchmark.php` sha256
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec`, adapter
`0.7.0` and the unchanged thresholds. The run ids are
`local-20261107T110959Z`, `local-20261107T111037Z` and
`local-20261107T111111Z`; the report files are `benchmark-child-1.json`
(sha256 `175a09e41cf19060fe7674eb80af6e74959835f781ff1d5579e80b3e2add37ef`),
`benchmark-child-2.json`
(sha256 `3318b022a24506e9c3d6b9286d9649beaf3917623ba3e531d555d37cb8bce8d8`)
and `benchmark-child-3.json`
(sha256 `7d3a4edea4910d1b0d1ea934a04241d29a70ebc10f4aabb6a1cce41d84d28f65`).

Every round reported `request_errors: 0` and 60 visible tools. The
complete-HTTPS median is 52.585 / 53.379 / 53.064 ms, i.e. 2.585 / 3.379 /
3.064 ms above the original `< 50 ms` threshold, while the other five gates
pass in all three rounds. The pre-dispatch median is 40.787 / 41.085 /
40.756 ms, the dispatch median 8.378 / 8.898 / 8.851 ms and the adapter-init
median 11.840 / 11.851 / 11.839 ms, so the split recorded in Section 8.4
reproduces and the gap is not an artefact of one round.

The stack was stopped and redeployed before the round instead of being reused,
and `rtl.py preflight` reported `stale_firewall_bridges=0` after the host
firewall residue was removed. `rtl.py plan` first refused with the known
memory double-count (`2816 MB running + 2816 MB for this project > 5120 MB`)
because the project's own stack was running; stopping that stack made the plan
pass, so the deployment was legal and the round is a cold start. A round
misses, so Branch B applies: the threshold stays where it is and
`ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`
keeps the `list_http_p50_lt_50_ms` (historical, <50 ms>) gate.

### 8.6 Requirement change of 2026-11-09

The project owner authorized relaxing only the complete-HTTPS p50 requirement
from `< 50 ms` to `< 55 ms`, effective 2026-11-09. The authorization, quoted
verbatim, is: "Bạn quyết STAB-03: hạ ngưỡng". The current gate key is
`list_http_p50_lt_55_ms`; the other five gates, the 100-sample count, the
10-sample warmup and the measurement method are unchanged. This is a
requirement change, not a measured performance gain, and no earlier verdict is
rewritten.

The five campaigns between 2026-10-21 and 2026-11-07 measured the complete-HTTPS
p50 at 52.39–53.465 ms with `request_errors: 0` and 60 visible tools. The three
most recent campaigns with full per-round values are 53.465 / 53.374 / 53.359 ms
(2026-10-27), 52.957 / 53.113 / 52.908 ms (2026-10-31) and 52.585 / 53.379 /
53.064 ms (2026-11-07); every earlier row in this document keeps its
`historical, <50 ms>` verdict.

Three fresh wp69 cache-on rounds at branch revision `4921d66` (benchmark key
changed in `0389505`) measured 52.834670 / 53.161070 / 53.275170 ms p50 and
54.781869 / 55.193170 / 54.897070 ms p95, with `request_errors: 0` and 60
visible tools in every round. The three rounds are 2.165330 / 1.838930 /
1.724830 ms below the revised 55 ms budget and 2.834670 / 3.161070 / 3.275170 ms
above the historical 50 ms budget; every p50 at or above 50 ms is recorded as a
nonblocking warning, not a failure. The round report files are
`benchmark-child-1.json` (sha256
`7bf53289ad46a7348428542f23e0328fd9821e9d52ad2ea21b20acf1fe1bab5a`),
`benchmark-child-2.json` (sha256
`edc267b4fff8ba6cea9662811214a38d44fddc27a5632b9d87e26ee2060cec2c`) and
`benchmark-child-3.json` (sha256
`6fe0426b53c4354d368406d2ce0affc650f4e2b7c139e658e9a05906ba174aeb`).

The STAB-03 exception was retired from the acceptance verifier after those
three rounds. The fresh two-phase acceptance campaign
`role-seo-20261109T180500Z` completed on 2026-11-10 at fingerprint
`1f383c341b5f41f55e7fc04fb9a4a48b82c2ffd492b89ae1805c794232fa8447`: the
single phase passed 423 of 424 rows (0 fail, 0 skip, 1 not applicable) and the
network phase passed 416 of 416 rows, with every cleanup flag true in both
phases. The verifier returned `problems=[]` and an empty `open_gates.hit`, so
ACCEPT-03 is met against the revised requirement. The acceptance report records
the campaign evidence; no earlier verdict is rewritten.

---

## 9. Limits of this analysis

- The split between "product" and "dependency" inside phase C and inside the
  request-phase memory is not measured: separating the plugin's 117 ability
  registrations from the WordPress abilities API and the adapter would need
  instrumentation inside `src/`, which this ticket may not touch.
- The checkpoint of 2026-10-05 has no phase marks, so its 43.268 ms outside
  the dispatch window cannot be split; the remaining numbers are reported as
  "not yet explained" with their exact value.
- The prescribed P3 probe could not serve as a lower bound (Section 5); the
  phase marks of Section 4 were used instead.
- The numbers are lab numbers on one deliberately limited container (1 CPU,
  512 MiB). They are not a production load profile and not a capacity claim.
- The concurrent figures are a measured saturation limit of the ten-worker
  phase, not a throughput promise.
