<!--
  Copyright (c) 2026 xNetVN Inc.
  Website: https://xnetvn.com/
  License: Apache License 2.0 (https://www.apache.org/licenses/LICENSE-2.0)
  Contact: license@xnetvn.net
-->

# MCP Performance SLO (Isolated Lab)

> **Decision (provisional, report-only): the original benchmark gates stay the
> reference thresholds for wp69 cache-on, and both the shared-lab and the
> isolated-lab numbers are recorded for comparison.**
>
> This decision **does not resolve** the STAB-03 WARNING reported in
> `ISSUE_v0.1_2026-10-16_06`. The isolated run of 2026-10-20 reproduces the same
> four failing gates (2 of 6 gates pass at wp69), so the shared-lab noise
> hypothesis is **not** supported by the evidence and STAB-03 stays open. The
> shared-lab numbers are second-hand from the STAB-03 result row of that issue
> and were not re-measured here (Section 3); the independent evidence is the
> isolated run.
>
> No threshold in that issue or in `tests/E2E/benchmark.php` is changed here.
>
> **Update (2026-10-20):** an isolated investigation on the same baseline
> repeated the wp69 cache-on measurement three times, attributed every miss
> to a product cost with a number, and **kept all six gates, their
> thresholds and their measurement rules unchanged**. The method, the phase
> split of one request, the memory mechanism, the comparison with the
> 2026-10-05 checkpoint and the per-gate verdict are in
> [performance-analysis.md](performance-analysis.md). STAB-03 stays open
> because the four gates still fail.
>
> **Requirement change (effective 2026-11-09):** the project owner authorized
> relaxing only the complete-HTTPS p50 requirement from `< 50 ms` to
> `< 55 ms`; the authorization, quoted verbatim, is
> "Bạn quyết STAB-03: hạ ngưỡng". The current gate key is
> `list_http_p50_lt_55_ms`. This is a requirement change, not a measured
> performance gain: the measurement method, the 100-sample count, the 10-sample
> warmup and the other five gates are unchanged. Every earlier measurement
> and verdict keeps its `historical, <50 ms>` label. The STAB-03 exception
> was retired from the acceptance verifier after three valid rounds passed
> the revised gate on 2026-11-10.

---

## 1. Scope

These SLOs apply to the real MCP/HTTPS flow on **wp69 cache-on** at the pinned baseline:

| Baseline item | Pinned value |
|---|---|
| WordPress | 6.9 |
| PHP | 8.2.x |
| Adapter | 0.7.0 |
| Persistent cache | Enabled (Redis 8.10.2 / Redis Object Cache 3.0.0 / Predis); the caller enables the owned cache fixture with `infra/remote/cache-fixture.sh enable wp69` before the run, because `infra/remote/run-benchmark.sh` does not enable the cache itself |
| Runner | `infra/remote/run-benchmark.sh` unmodified, no parallel suites during measurement |

This document covers the wp69 cache-on measurement of **2026-10-20** only. The
frozen-baseline checkpoint of **2026-10-05** in
[architecture/performance.md](../architecture/performance.md) (numeric artifact
[docs/en/reports/M1-60-tools.json](../reports/M1-60-tools.json))
is a **different measurement run** on the same pinned baseline, and this
document does **not** replace it. The two runs disagree on the verdict: the
2026-10-05 checkpoint met 4 of the 6 original gates (policy p50 3.714 ms, HTTP
p50 55.868 ms, memory max 5.254 MiB), while the 2026-10-20 run met 2 of 6
(Section 3). Both are kept as they are: neither supersedes the other until the
gap between them is explained.

## 2. SLO Table by Target

The thresholds below are the current acceptance budget of
`tests/E2E/benchmark.php`. The five original gates are unchanged. The
complete-HTTPS p50 gate uses the revised `< 55 ms` requirement that the
project owner authorized on 2026-11-09 (quoted verbatim in the header above).
The `< 55 ms` value is an engineering margin over the measured
52.39–53.465 ms range, not a measured performance gain. Every historical
measurement and verdict in Section 3 keeps its `historical, <50 ms>` label.

| Gate (benchmark key) | Metric | Threshold | Applies to | Measurement |
|---|---|---|---|---|
| `auth_median_lt_5_ms` | p50 of `auth_ms` | < 5 ms | wp69 cache-on | Section 4 |
| `policy_median_lt_5_ms` | p50 of `policy_ms` | < 5 ms | wp69 cache-on | Section 4 |
| `auth_policy_median_lt_5_ms` | p50 of `auth_policy_ms` | < 5 ms | wp69 cache-on | Section 4 |
| `list_dispatch_p50_lt_50_ms` | p50 of `dispatch_ms` | < 50 ms | wp69 cache-on | Section 4 |
| `list_http_p50_lt_55_ms` | p50 of `http_ms` | < 55 ms | wp69 cache-on | Section 4 |
| `memory_max_lt_8_mib` | max of `memory_bytes` | < 8 MiB | wp69 cache-on | Section 4 |

Targets other than wp69 cache-on are measured only; they have **no SLO yet**:

| Target | Status |
|---|---|
| role-flow | Measured only, no SLO yet |
| multisite | Measured only, no SLO yet |
| cache-off | Measured only, no SLO yet |

Example reading: `list_http_p50_lt_55_ms` applies to wp69 cache-on; role-flow is measured only and has no SLO.

## 3. Observed Latencies: Shared Lab vs Isolated Lab (Report-Only)

Both columns are **report-only observations, not commitments**.

The shared-lab column is **second-hand**: it comes from the STAB-03 result row
of `ISSUE_v0.1_2026-10-16_06` as recorded at commit `75598bf` (and `bc1eb13`
before it), a row that the 2026-10-20 rework `ccec893` replaced with boolean
gates and the 2-of-6 verdict only. That row records the shared-lab wp69 run
`run-20261018010657-21233` with `request_errors: 0`, auth p50 3.82 ms,
policy p50 5.62 ms, auth+policy p50 9.43 ms, dispatch p50 14.13 ms, HTTP valid
p50 78.71 ms and memory max 8685952 bytes (8.28 MiB); the column above is that
row rounded. This ticket did **not** re-measure those numbers, so they stay
report-only and must not be read as a measurement by this ticket.

The isolated-lab column comes from the two isolated runs of 2026-10-20
documented in Section 4.1, with their run ids and server artifact paths.

| Metric | Unit | How it is measured | Shared-lab value (second-hand, from _06 at `75598bf`) | Isolated-lab value (2026-10-20) | Isolated-lab value (2026-10-21 product round) | Isolated-lab value (2026-10-22 capability memo round) | Original gate | Isolated gate result |
|---|---|---|---|---|---|---|---|---|
| policy p50 | ms | p50 of `policy_ms`, 100 valid samples | 5.6 | 5.68 / 5.67 | 3.35 / 3.36 / 3.35 | 0.656 / 0.644 / 0.645 | < 5 ms | Miss (2026-10-20), **Pass (2026-10-21)** |
| auth+policy p50 | ms | p50 of `auth_ms + policy_ms`, 100 valid samples | 9.4 | 9.51 / 9.50 | 7.06 / 7.10 / 7.08 | 4.385 / 4.362 / 4.364 | < 5 ms | Miss (2026-10-20, 2026-10-21), **Pass (2026-10-22)** |
| HTTP p50 (valid) | ms | p50 of `http_ms`, 100 valid samples | 78.7 | 79.42 / 79.31 | 55.78 / 55.91 / 55.87 | 52.780 / 52.830 / 52.603 | < 50 ms (historical, <50 ms>) | Miss (all rounds) |
| memory max (valid) | MiB | max of `memory_bytes` (peak minus MU-plugin baseline) | 8.28 | 8.284 / 8.284 | 5.884 / 5.884 / 5.885 | 5.884 / 5.884 / 5.884 | < 8 MiB | Miss (2026-10-20), **Pass (2026-10-21)** |

Supporting values of the same isolated runs (not gates of their own):

| Metric | Unit | Isolated run 1 | Isolated run 2 | Isolated-lab value (2026-10-22 capability memo round) | Original gate | Gate result |
|---|---|---|---|---|---|---|
| auth p50 | ms | 3.84 | 3.83 | 3.718 / 3.708 / 3.718 | < 5 ms | Pass (both runs) |
| dispatch p50 | ms | 14.22 | 14.21 | 8.291 / 8.267 / 8.287 | < 50 ms | Pass (both runs) |
| `request_errors` | count | 0 | 0 | 0 / 0 / 0 | = 0 | Pass (both runs) |
| `wrong_unknown_p50_ratio` | ratio | 1.037 | 1.029 | 0.994 / 1.013 / 1.002 | none | Informational only |

**The product round of 2026-10-21.** The isolated measurements of
`ISSUE_v0.1_2026-10-21_01` ran the same harness, the same isolated
procedure and the same unchanged thresholds on the same lab; the run ids are
`perf-f1-20261021-0445z`, `perf-f2-20261021-0445z` and
`perf-f3-20261021-0445z`, with a pre-change baseline round
`perf-base-20261021-0400z` measured on the same day. Every run reported
`request_errors: 0`, 60 visible tools and 33 191 logical JSON bytes, so the
advertised payload, the tool count and the denial results are unchanged. The
change removes the eager build of the adapter MCP server on every REST
request and the repeated per-tool site setting reads; the measured root cause
and the two remaining misses are documented in
[operations/performance-analysis.md](performance-analysis.md) Sections 3.1,
3.2 and 8.1.

**The capability memo round of 2026-10-22.** The isolated measurements of
`ISSUE_v0.1_2026-10-21_04` ran the same harness, the same isolated procedure
and the same unchanged thresholds on the same lab; the run ids are
`local-20261022T055048Z`, `local-20261022T055256Z` and
`local-20261022T055457Z`, with four pre-change rounds of the same day
(`local-20261022T045956Z` … `local-20261022T051346Z`) measured on the same
stack. Every run reported `request_errors: 0`, 60 visible tools and 33 191
logical JSON bytes, so the advertised payload, the tool count and the denial
results are unchanged. One live capability decision per user, capability and
object inside one advertised tool list round brings the policy median to
0.644–0.656 ms and the combined authentication-and-policy median to
4.362–4.385 ms, so that combined median passes its original threshold for
the first time. The complete-HTTPS median stays at 52.603–52.830 ms,
2.603–2.830 ms above its original threshold; the measured distance, the
`path:line` evidence that adapter 0.7.0 has no deferred-build API and every
excluded alternative are in
[operations/performance-analysis.md](performance-analysis.md) Sections 3.3
and 8.2.

**The STAB-03 ownership round of 2026-10-27.** A third isolated measurement
on the same lab repeated the wp69 cache-on run three times at revision
`53b272e96f36b85cea4734d9b4cb53a9821fe469`, with `tests/E2E/benchmark.php`
sha256 `ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec`,
the same `flock`/`trap` procedure and the same unchanged thresholds; the run
ids are `local-20261026T225819Z`, `local-20261026T225910Z` and
`local-20261026T225952Z`. Every round reported `request_errors: 0`, 60
visible tools and 33 191 logical JSON bytes, so the advertised payload, the
tool count and the denial results are unchanged. The complete-HTTPS median is
53.465 / 53.374 / 53.359 ms, i.e. 3.465 / 3.374 / 3.359 ms above the original
`< 50 ms` threshold, while the other five gates pass in all three rounds:
the gap is unchanged in nature and slightly larger than the 2.603–2.830 ms of
the capability memo round. Because a round misses, the threshold stays where
it is and the red case stays covered: while it is open,
`ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`
owns the `list_http_p50_lt_50_ms` (`historical, <50 ms>`) gate. The three rounds reused the already
running isolated stack instead of restarting it before each round, which
keeps the PHP caches warm and therefore favours the measured value; a warm
stack that still misses cannot hide a colder failure. The six thresholds of
Section 2 and the method of Section 4 are unchanged.

**The plugin-only diagnosis of 2026-10-31.** A fourth isolated measurement
ran the same wp69 cache-on procedure three times at revision
`536873f1ad73f774f4cf0417b640ebb8247caeb4`, with the same benchmark sha256
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec` and
adapter `0.7.0`; the run id is `20261031T0602Z`. Every round reported
`request_errors: 0`, 60 visible tools and 33 191 logical JSON bytes. The
complete-HTTPS median is 52.957 / 53.113 / 52.908 ms, i.e. 2.957 / 3.113 /
2.908 ms above the original threshold, while the other five gates pass in all
three rounds. The adapter build alone is 11.72 ms; the plugin-only candidates
(packs by scope, category dedupe, scope-visible registration, deferred
management providers) and the unmeasured pre-dispatch split are documented in
[performance-analysis.md](performance-analysis.md) Section 8.4. No threshold,
method, toolset or runtime was changed. The gate remains owned by
`ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`,
and the fix needs a separate ticket.

**The ownership round of 2026-11-07.** A fifth isolated measurement re-ran the
same wp69 cache-on procedure three times at revision
`f4535c36a55391138682cc9942e783a9b0befde5`, with the same benchmark sha256
`ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec` and
adapter `0.7.0`; the run ids are `local-20261107T110959Z`,
`local-20261107T111037Z` and `local-20261107T111111Z`. Every round reported
`request_errors: 0` and 60 visible tools. The complete-HTTPS median is
52.585 / 53.379 / 53.064 ms, i.e. 2.585 / 3.379 / 3.064 ms above the original
`< 50 ms` threshold, while the other five gates pass in all three rounds. The
stack was stopped and redeployed before the rounds instead of being reused, so
the figures come from a cold start; `rtl.py preflight` reported
`stale_firewall_bridges=0`. No threshold, method, toolset or runtime was
changed. The gate stays owned by
`ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`
until a separate change closes the gap.

**What the isolated run shows.** The isolated measurement is within **≤1.5%**
of the second-hand shared-lab numbers (largest relative difference: 1.49% on
the policy p50) and produces the same verdict: 2
of 6 gates pass (`auth_median_lt_5_ms`, `list_dispatch_p50_lt_50_ms`), and
`policy_median_lt_5_ms`, `auth_policy_median_lt_5_ms`,
`list_http_p50_lt_50_ms` (`historical, <50 ms>`) and `memory_max_lt_8_mib` fail. Two consecutive
isolated runs agree to within 0.02 ms on the policy and auth+policy p50 and
within 0.11 ms on the HTTP p50, so the gap is reproducible and not a one-off
sample.

Consequently:

- The hypothesis that the shared lab caused the STAB-03 misses is **not**
  confirmed by the isolated run.
- STAB-03 is **not** closed by this document. The failing gates are a real
  gap between the current build and the original thresholds, and closing it
  needs either a product change or an explicit owner decision on the
  thresholds. Both are out of the scope of this document.
- Because the isolated run does not meet the original thresholds, the
  thresholds of Section 2 are a **reference set that the current build does
  not yet satisfy**, and the decision above stays provisional until that gap
  is closed.
- The product round of 2026-10-21 closes two of the four gaps (the policy
  median and the memory peak) without moving a threshold; the two remaining
  gaps are measured and attributed in
  [performance-analysis.md](performance-analysis.md) Section 8.1.
- The capability memo round of 2026-10-22 closes one more gap (the combined
  authentication-and-policy median) without moving a threshold, so five of the
  six gates pass in the lab. The last gap, the complete-HTTPS median, is
  2.603–2.830 ms above its threshold and is attributed to the separately
  installed adapter in
  [performance-analysis.md](performance-analysis.md) Section 8.2; STAB-03
  therefore stays open.

### 3.1 Confirmation of 2026-10-20 (three more isolated runs)

A second isolated measurement on the same baseline, on 2026-10-20 UTC,
repeated the wp69 cache-on run three times with the same procedure and the
same isolation, and reproduced the same verdict: the authentication and
dispatch medians stay below their thresholds, while the policy median, the
combined authentication-and-policy median, the complete-HTTPS median and the
memory peak stay above theirs. The three runs agree to 0.047 ms on the policy
median, 0.814 ms on the complete-HTTPS median and 2624 bytes on the memory
peak, and they match the two runs of 2026-10-20 to within 0.05 ms, 0.99 ms
and 0.004 MiB. The shared-lab noise hypothesis is therefore refuted.

That investigation also split one request into phases (core start-up, plugin
load, ability-pack load, the window between `init` and `rest_pre_dispatch`,
the REST dispatch, the response flush and transfer) and split the memory peak
into a startup part and a request part, once by the retained memory and once
by the measured peak. 59.9 % of the request sits in the window where the
plugin registers its abilities and the adapter builds the server, and 81.5 %
of the memory peak is allocated while the request is handled; the transport
contributes 0 ms of connection time and 0.816 ms of response transfer. The
phase split, the memory mechanism, the comparison with the 2026-10-05
checkpoint and the per-gate verdict are in
[performance-analysis.md](performance-analysis.md). No gate, threshold or
measurement rule was changed.

Noise sources that the isolated run did remove, and the ones it did not:

| Suspected noise source | State after the isolated run |
|---|---|
| Parallel suites of this project | Removed: no other suite held the wp69 lock or the lab lease during the measurement, and only one project was running on the lab |
| Shared cache contention between runs | Removed: the owned cache fixture was enabled and disabled around each run, and no other run was in flight |
| Cron `option` rewritten by core outside the suite | Not removed: the fixture keeps `settings_digest` and `cron_digest` for drift comparison, and the drift itself is not isolated by this run |
| Production-like load | Not present: the lab is a single test stack, so these numbers are not a production load profile |

## 4. Measurement Method

1. The **caller** enables the owned cache fixture with
   `infra/remote/cache-fixture.sh enable wp69` —
   `infra/remote/run-benchmark.sh` does not enable the cache, it only
   refuses to run without the persistent cache — then runs
   `infra/remote/run-benchmark.sh` unmodified on wp69, and disables the fixture
   again with `infra/remote/cache-fixture.sh disable wp69`; run no other suite
   in the same lab namespace during measurement.
2. The benchmark warms up with 10 samples per case, then records 100 samples
   for each of the `valid`, `unknown`, and `wrong` kinds, plus 10 concurrent
   workers (`tests/E2E/benchmark.php`).
3. Report the p50 of each latency series, the max of `memory_bytes`
   (peak minus MU-plugin baseline), `wrong_unknown_p50_ratio`, and
   `request_errors`.
4. A valid SLO verdict requires `request_errors` equal to 0 and all six gates
   present in the report JSON; missing metrics are reported as unavailable,
   never filled with 0.

### 4.1 Provenance of the isolated measurement of 2026-10-20

| Item | Value |
|---|---|
| Measured at (UTC) | run 1: 2026-10-20T03:04:26Z → 03:05:13Z; run 2: 2026-10-20T03:05:35Z → 03:06:22Z |
| Run ids | `slo-20261020T030426-7294`, `slo-20261020T030535-7419` |
| Command | `RTL_AGENT=opencode python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --compose-file compose.yaml toolbox -- bash -c "<cache-enable → run-benchmark.sh → cache-disable wrapper>"` |
| Wrapper behaviour | The **caller** (not the benchmark script) enables the owned cache fixture: `infra/remote/cache-fixture.sh enable wp69` → `infra/remote/run-benchmark.sh` (unmodified) → `infra/remote/cache-fixture.sh disable wp69`, with an `EXIT` trap that disables the cache fixture, and an exclusive `flock` on the wp69 lock used by `infra/remote/run-role-seo.sh` so no suite of this project can run in parallel — the same caller step as Section 4, step 1 |
| Exit codes | `rtl.py exec` exit 0; benchmark script exit 0; cache enable exit 0; cache disable exit 0 |
| Report artifact (server) | `/work/.local/slo-20261020/slo-20261020T030426-7294/benchmark.json` and `/work/.local/slo-20261020/slo-20261020T030535-7419/benchmark.json` (gitignored area) |
| Lab namespace | `rtl-xnetvn-agent-hub-wp` (single namespace for this project; 5 of 5 containers healthy) |
| Environment | Ubuntu 24.04.4 LTS, 6 CPU, 11908 MB RAM total, ~10044 MB available, 217 GB free disk, Docker 29.4.1, Compose 5.1.3; load average 0.30 before run 1 and 0.70 after it; load average 0.90 before run 2 and 3.68 after it |
| Isolation evidence | `rtl.py preflight` reported `running_projects=1`; `rtl.py projects` listed this project as the only running namespace; `rtl.py lease` was held by this worktree for the whole measurement; no other suite was running |
| Runtime under test | WordPress 6.9, PHP 8.2.34, adapter 0.7.0, Redis 8.10.2 / Redis Object Cache 3.0.0 / Predis, 60 visible tools |
| Revision | `caebb65` (`merge(issue-06): stability follow-up fixes and campaign evidence`); the sha256 of `tests/E2E/benchmark.php`, `tests/E2E/benchmark-mu-plugin.php`, `tests/E2E/http-fixture.php`, `infra/remote/run-benchmark.sh`, `infra/remote/run-wp.sh`, `infra/remote/cache-fixture.sh`, `xnetvn-agent-hub-wp.php` and of every file under `src/` was verified identical between the measured checkout and the server before the run |

## 5. Rollback (Quay Lui)

The rollback position is: **keep the original gates and record shared-lab
numbers only**. No new threshold replaces a gate in `tests/E2E/benchmark.php`
(lines 260-265) as a result of shared-lab noise. This position is historical:
the separate 2026-11-09 owner requirement change above revises only the
complete-HTTPS p50 budget and is not a response to shared-lab noise.

The following are prohibited:

- Editing any threshold in `ISSUE_v0.1_2026-10-16_06`.
- Editing any gate in `tests/E2E/benchmark.php` (including lines 260-265)
  to make a gate pass.
- Reading shared-lab numbers as production load commitments.
- Reading the shared-lab numbers of Section 3 as a measurement of this ticket:
  they are second-hand from `_06` and were **not** re-measured here.
- Reading the isolated-lab numbers of Section 3 as proof that STAB-03 is
  closed, or as a production load profile.
