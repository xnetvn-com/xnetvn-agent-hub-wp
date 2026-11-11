# MCP specialized skills acceptance report

## 1. Overview

- **Report:** acceptance of the five MCP skills, the six ability packs and the retained SEO review flow.
- **Project:** xNetVN Agent Hub for WordPress (`xnetvn-agent-hub-wp`), target version 0.1.0.
- **Date:** 2026-10-27.
- **Executor:** `impl-s8-acceptance-20261027` (ticket `ISSUE_v0.1_2026-10-21_12_nghiem-thu-skills-mcp-toan-dien-va-ban-giao-bai-seo-con`).
- **Base revision:** `f6115574a7d8ebb1e2d2d83e989aa6290864f2dc` on `issue/ISSUE_v0.1_2026-10-21_12_...`; the final campaign runs on the same tree after the FX-08 and FX-06 fixes.
- **Lab namespace:** `xnetvn-agent-hub-wp`, single-site `compose.yaml`, WordPress 6.9 (`wp69`) and WordPress 7.1.3 (`wp71`, lab drift from the 7.1.2 label).

## 2. Result summary

| Criterion | Result | Basis |
|---|---|---|
| ACCEPT-01 structural baseline | **Pass** | 22 asset files verified, checker `64/64`, `composer check` and `composer build` green |
| ACCEPT-02 behavioral model cases | **Blocked** | No Codex harness and no provider permission in this session; 98 scenarios exist, no model trace was produced |
| ACCEPT-03 catalog, HTTP and pack matrices | **Met against the revised requirement** | The complete-HTTPS p50 gate uses the owner-authorized `< 55 ms` budget effective 2026-11-09; the STAB-03 exception is retired after three valid rounds (p50 52.834670 / 53.161070 / 53.275170 ms, 0 errors, 60 tools). Campaign `role-seo-20261109T180500Z` passed `verify` with `problems=[]`, `open_gates.hit=[]`, single 423 pass / 0 fail / 1 not applicable and network 416 pass / 0 fail, with every cleanup flag true. Earlier STAB-03 rows stay historical. |
| ACCEPT-04 article read-back and browser | **Pass** | One published article per site with three images, terms and author; RTL browser `15 passed` per site |
| ACCEPT-05 retention, verify and reconcile | **Pass** | `verify` and `reconcile` exit 0 on both sites; retained `post 1 / media 3 / terms 3 / users 2`; trial posts listed and kept |
| ACCEPT-06 bilingual reports and T3 review | **Pending** | This report pair exists; the independent T3 review and the open items below decide the final status |

**Overall:** the acceptance is **not a full PASS**. Four criteria pass, ACCEPT-03 is **met against the revised requirement** (three valid rounds plus campaign `role-seo-20261109T180500Z` with `problems=[]`), ACCEPT-02 is blocked because no model harness is available, and ACCEPT-06 still awaits the independent T3 review. This report is not a security-milestone sign-off, a hosted-CI result, a real-client result or a production/publishing acceptance.

**2026-11-01 update:** the rerun at revision `cd60d68` **does not achieve ACCEPT-03**: integration still has one red stability case and the wp71 catalog has 57 red cases inside an HTTP 503 window. Section 9 has the detail; sections 3–8 stay as the 2026-10-27 campaign history.

**2026-11-10 update:** the project owner authorized relaxing only the complete-HTTPS p50 requirement from `< 50 ms` to `< 55 ms`, effective 2026-11-09 (quoted verbatim: "Bạn quyết STAB-03: hạ ngưỡng"). The STAB-03 exception is retired after three valid rounds; Section 11 records the evidence and the completed campaign. Every earlier STAB-03 measurement and FAIL verdict in Sections 3 and 10 stays as history labelled `historical, <50 ms>`.

## 3. What was verified

### 3.1 Structural baseline (ACCEPT-01)

| Check | Command | Result |
|---|---|---|
| Asset structure and manifest | `python3 -I -B .agents/evals/check-test-debug-assets.py --stage all --self-test` | exit 0, `64/64 passed` |
| Asset manifest | `python3 -I -B .agents/evals/check-test-debug-assets.py --stage all --manifest` | exit 0, **22** files; checker sha256 `95bf0c9a34445c3b33447aeca82fd16e70efad0c371b5e8956d40bee876c9277` |
| Project gates | `composer check` | exit 0: PHPCS clean, PHP 7.2 bootstrap syntax, PHPStan level 8 with no baseline, **224 unit tests / 50 962 assertions**, settings docs 30 keys per language |
| Release staging | `composer build` | exit 0, `Release ZIP structure verified`, ZIP sha256 `14793ef800512b1e5b85f52f5d44c7da7a95b9ff5645cda81d3b600daf3bcf49` (build-specific: `zip` stores file mtimes, so this hash is not reproducible across builds) |

The structural check proves structure only. It is not runtime evidence and it is not a model-behavior result.

### 3.2 Runtime single-site (ACCEPT-03)

| Check | Command | Result |
|---|---|---|
| Integration, WordPress 6.9 | `composer test:integration -- 6.9` | exit 0, **344 tests / 8 051 assertions / 6 skipped** |
| Integration, WordPress 7.1.2 | `composer test:integration -- 7.1.2` | exit 0, **344 tests / 8 051 assertions / 6 skipped** |
| Integration, multisite 7.1.2 | `XNETVN_TEST_MULTISITE=1 composer test:integration -- 7.1.2` | exit 0, **344 tests / 8 077 assertions / 1 skipped** |
| HTTP matrix | `composer test:http` | exit 0, **35/35 passed, 0 failed** |
| Acceptance campaign, single phase | `bash infra/remote/run-role-seo-acceptance.sh single role-seo-20261027T021126Z` | 422 pass, 0 fail, 0 skip, 1 not applicable, 1 open gate |
| Acceptance campaign, network phase | `bash infra/remote/run-role-seo-acceptance.sh network role-seo-20261027T021126Z` | 416 pass, 0 fail, 0 skip |
| Campaign verifier | `php tests/E2E/role-seo-acceptance.php verify --campaign role-seo-20261027T021126Z …` | exit 0, `problems=[]`, `open_gates.hit=["case:STAB-03@wp69"]` |

The integration totals include 10 tests added by the two regression files merged before the final campaign (`RoleSeoAcceptanceGateTest`, `RoleSeoFixtureCronStabilityTest`); the earlier measurements in this session were 334/7 990/6 and 334/8 016/1 and are not the final totals.

The six skipped tests are the known environment-dependent skips; no skip was added by this acceptance. The multisite run keeps its single known skip.

#### 3.2.1 Final acceptance campaign

The first campaign `role-seo-20261026T205420Z` produced 424 rows: **421 pass, 2 fail, 0 skip, 1 not applicable**. The two red cases were:

- `FX-08@wp71` — `internal_error` caused by the runner comparing the whole counts JSON (including `cron_digest`) instead of the owned counts. The defect is tracked as `BUG_v0.1_2026-10-27_01_...`; it is a test-only runner bug, not a product defect. The campaign was re-run after the fix.
- `STAB-03@wp69` — only the `list_http_p50_lt_50_ms` (historical, <50 ms>) gate fails; the other five benchmark gates pass. This is the historical 2026-10-27 position; Section 11 records the revised requirement and its evidence.

**Final campaign `role-seo-20261027T021126Z`:** single phase 424 rows — **422 pass, 0 fail, 0 skip, 1 not applicable, 1 declared open gate**; all 12 owned cleanups true. Network phase **416/416 pass, 0 fail, 0 skip**; all 9 owned cleanups true. `verify` exit 0 with `problems=[]` and `open_gates.hit=["case:STAB-03@wp69"]`; the gate is reported separately and is not counted as a pass. No required case is skipped and no threshold was relaxed.

The first campaign of the same tree exposed two test-harness defects, both fixed before the final run and tracked as their own tickets: FX-08 compared the whole counts JSON (`BUG_v0.1_2026-10-27_01_...`, fixed in `089e25d`) and FX-06 had the same pattern (`BUG_v0.1_2026-10-27_02_...`, fixed in `7f77fa5`). A third run exposed a stability-sweep flake: an aborted `STAB-01`/`STAB-05` sweep left two held uploads and three trashed posts, and the next sweep inherited that state. The two attachments (5676–5677) and three trashed posts (5901–5903) were verified as owned scratch objects and removed; the stability group then passed 6/7 (only the declared `STAB-03` gate red) and the final campaign was clean. The cleanup-on-failure weakness is recorded as a residual risk, not as a product defect.

### 3.3 Performance gate `STAB-03`

| Gate | Value | Threshold | Result |
|---|---|---|---|
| `auth_median_lt_5_ms` | passed | < 5 ms | Pass |
| `policy_median_lt_5_ms` | passed | < 5 ms | Pass |
| `auth_policy_median_lt_5_ms` | passed | < 5 ms | Pass |
| `list_dispatch_p50_lt_50_ms` | passed | < 50 ms | Pass |
| `list_http_p50_lt_50_ms` (historical, <50 ms>) | 52.39–53.46 ms across the 2026-10-21/22 and 2026-10-27 rounds | < 50 ms | **Fail** |
| `memory_max_lt_8_mib` | passed | < 8 MiB | Pass |

The remaining gap is 2.39–3.46 ms. The 2026-10-27 rounds were taken on a warm stack (a condition that favours the measurement) and measured 53.464704 / 53.373806 / 53.358508 ms, all false. The measured analysis in `docs/en/operations/performance-analysis.md` Section 8.2 shows that most of the remaining cost is the separately installed MCP adapter 0.7.0, which builds its 60 tool projections before the request is served and has no deferred-build API; the plugin-owned policy window is already 0.64–0.66 ms. Adapter 0.7.1 (2026-10-15) was checked read-only and does not add a deferred build either. Closing the gap needs a dependency change or an explicit owner decision on the threshold. Neither the threshold nor the measurement was changed. The owner answered the open question on 2026-10-27 with **option B**: keep `< 50 ms` and keep the ticket as the gate owner until upstream provides a deferred build; the ticket therefore stays open and is not marked complete. The gate is owned by `ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`.

### 3.4 Catalog, network and pack matrices

- **Full-catalog role/blog matrix (S5):** 62 tools, 595 declared tool/role/scope rows, 186 auth/target outcomes = 62×401 + 124×403 + 0×429, rate limit 11 → 429, A–H groups 11/11, at revision `8bca2f6`. The plugin code is identical on the final revision (`git diff 8bca2f6..HEAD -- src/` is empty); the S5 result is therefore reused and this reuse is recorded as a limit, not as fresh runtime evidence.
- **Network (fresh phase of the final campaign):** 416/416 pass, 0 fail, 0 skip across `network-main` and `network-child`, including the four network gates and the B–E matrices; every owned cleanup true. The stack was switched to `compose.ui-network.yaml` (separate `ui_network_*` volumes), the phase ran in the same campaign, and the stack was switched back to `compose.yaml` with the volumes kept.
- **HTTP matrix:** 35/35, including the method/route/auth/rate probes.

### 3.5 Retained article and browser (ACCEPT-04)

| Site | Post | URL | Author | Terms | Images | Browser |
|---|---|---|---|---|---|---|
| wp69 | 4028 | `https://xnetvn-agent-hub-wp-wp69.192.168.2.215.sslip.io/huong-dan-xay-dung-bai-viet-wordpress-ro-rang-va-de-doc-sr20261026t153406z/` | 1015 (`author`) | category 597, tags 598–599 | featured 4025, inline 4026–4027 | `15 passed (14.2s)`; fetched artifact `.local/remote-test/20261026T214759Z` (`.last-run.json`: status `passed`, no failed tests) |
| wp71 | 1823 | `https://xnetvn-agent-hub-wp-wp71.192.168.2.215.sslip.io/huong-dan-xay-dung-bai-viet-wordpress-ro-rang-va-de-doc-sr20261026t153406z/` | 233 (`author`) | category 237, tags 238–239 | featured 1820, inline 1821–1822 | `15 passed (14.5s)`; fetched artifact `.local/remote-test/20261026T214834Z` (`.last-run.json`: status `passed`, no failed tests) |

Both articles return HTTP 200. The browser spec is read-only: it reads the allowlisted handoff under `/work/.local/seo-review/`, opens the permalink on the lab host, checks one H1, H2/H3 order, paragraphs, strong text, lists, internal links, two HTTPS sources, featured and two inline images with `naturalWidth > 0` and alt/caption, and taxonomy. Both handoffs record `revision: unknown` and `fingerprint: 6c448db8d1a72c3ce2689c1ef95b72d92b741c1d4fa6c4951e0a37f953beb8c2`; the edit URLs are `https://xnetvn-agent-hub-wp-wp69.192.168.2.215.sslip.io/wp-admin/post.php?post=4028&action=edit` and `https://xnetvn-agent-hub-wp-wp71.192.168.2.215.sslip.io/wp-admin/post.php?post=1823&action=edit`. The excerpt is verified through MCP only because the theme does not render it. No SEO plugin is installed, so meta description, canonical, Open Graph and schema are **unsupported**, not passed.

### 3.6 Retention and reconciliation (ACCEPT-05)

| Check | wp69 | wp71 |
|---|---|---|
| `run-seo-review.sh <site> review-20261026T153406Z verify` | exit 0, 13/13 checks true | exit 0, 13/13 checks true |
| `run-seo-review.sh <site> review-20261026T153406Z reconcile` | exit 0, reconciled true | exit 0, reconciled true |
| Retained state | post 1, media 3, terms 3, users 2 | post 1, media 3, terms 3, users 2 |
| Credential and temp cleanup | keys revoked 2, credentials removed, temp removed | same |

Trial and failure posts are retained and listed, not deleted: wp69 4007, 4012, 4017, 4022, 4033, 4038; wp71 1818. The user-edited title on 4022 and 4038 was preserved and never overwritten. No `wp_delete_post`, `wp_delete_attachment`, `wp_delete_user` or `wp_delete_term` path was used by the retention flow.

### 3.7 Behavioral model cases (ACCEPT-02)

`.agents/evals/test-debug-scenarios.json` defines **98** scenarios: I 11, J 27, K 24, R 12, M 12, P 12. The static checker proves their structure and the S7 routing table (`J-16 → debugging`, `J-04/J-05/J-27 → none`) but it does not prove model behavior. This session had no Codex client, no provider permission for a behavioral run and no trace recorder, so ACCEPT-02 is **blocked**. The ticket requires a real model trace plus an independent rubric review; manual reading or a green checker is not accepted as a substitute.

## 4. Not verified

- Hosted CI, real MCP clients, a production host, package publishing and WordPress.org distribution.
- SEO plugin metadata (meta description, canonical, Open Graph, schema, rankings). No SEO plugin is installed on the lab.
- Behavioral model routing and refusal traces (ACCEPT-02).
- The full-catalog matrix on the exact final revision; it is reused from S5 on identical plugin code.
- `wp71` patch level: the lab reports WordPress **7.1.3**, while the compose label and several tickets say 7.1.2. The four network cases and the MCP behavior do not depend on the patch level, but the drift is recorded.
- The `REVIEWED_FIXTURES` change in the shared `private-local-udev` checkout is present in the working tree but **not committed** because that repository requires the owner's root context. Project checks read the working tree; a future clone will not see the change until the owner commits it.
- `docs/vi/templates/*` still differ from the current guide/gate block (pre-existing drift; `issue_tool.py templates` reports 4 templates to update).

## 5. Risks and limits

1. **Revised complete-HTTPS gate.** `list_http_p50_lt_50_ms` (historical, <50 ms>) remained 2.6–3.5 ms above the original threshold on wp69 at the 2026-10-27 position; the plugin could not close it while adapter 0.7.0 builds its tool projections eagerly, and 0.7.1 does not change that. At that time the threshold was not relaxed and the owner chose to keep the gate open. On 2026-11-09 the owner authorized the revised `< 55 ms` requirement, and the exception is retired after three valid rounds; Section 11 records the completed two-phase campaign `role-seo-20261109T180500Z` that met the revised requirement.
2. **Test-runner stability.** FX-08 and FX-06 compared a counts string that includes `cron_digest`; both are fixed (`089e25d`, `7f77fa5`) and their tickets are complete. A stability sweep that aborts can still leave owned scratch objects behind (one run left two held uploads and three trashed posts); the next sweep inherits that state and fails. The owned objects were identified by marker and removed, and the clean sweep then passed. The cleanup-on-failure weakness is a residual test-harness risk, not a product defect. Eight fixture runs in eight minutes can also saturate the per-minute auth-failure window; the acceptance runs one phase at a time and does not claim back-to-back repeatability.
3. **Lab scale.** All numbers are from one deliberately limited container (1 CPU, 512 MiB) on a shared server. They are not a production load profile, a capacity promise or an availability promise.
4. **Reused matrices.** The catalog and network evidence comes from earlier campaigns on code-identical revisions. That is recorded as a limit; a future acceptance should re-run both on its exact revision.
5. **Retained data.** The articles, media, terms and authors stay on the lab volumes. Only the owner may ask to delete them; this report and the acceptance do not delete retained content.

## 6. How to reproduce

```bash
# Structural baseline
python3 -I -B .agents/evals/check-test-debug-assets.py --stage all --self-test
python3 -I -B .agents/evals/check-test-debug-assets.py --stage all --manifest
composer check
composer build

# Local and lab suites
composer test:integration -- 6.9
composer test:integration -- 7.1.2
XNETVN_TEST_MULTISITE=1 composer test:integration -- 7.1.2
composer test:http

# Acceptance campaign (the lab transition owns the stack)
RTL_AGENT=<agent> python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec \
  --compose-file compose.yaml toolbox -- bash /work/infra/remote/run-role-seo-acceptance.sh single role-seo-YYYYMMDDTHHMMSSZ

# Retained article verification
RTL_AGENT=<agent> python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec \
  --compose-file compose.yaml toolbox -- bash /work/infra/remote/run-seo-review.sh wp69 review-20261026T153406Z verify
RTL_AGENT=<agent> python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e \
  --base-url https://xnetvn-agent-hub-wp-wp69.192.168.2.215.sslip.io -- \
  sh -c 'export XNETVN_SEO_REVIEW_HANDOFF=/work/.local/seo-review/wp69/review-20261026T153406Z/handoff.json; npx playwright test tests/E2E/seo-review.spec.js --config=tests/E2E/playwright.remote.config.js'
```

Do not run a destructive suite against the retained review objects, and do not use `--volumes`, purge or prune on the shared lab.

## 7. Handover and retention

- Articles: wp69 post 4028, wp71 post 1823. Trial posts: wp69 4007, 4012, 4017, 4022, 4033, 4038; wp71 1818.
- Media: wp69 4025–4027, 4030–4032, 4035–4037; wp71 1820–1822. Terms: wp69 597–605, wp71 237–239. Authors: wp69 1015, 1017, 1019; wp71 233.
- Journals: `/work/.local/seo-review/private/<target>/review-20261026T153406Z/journal.json` on the lab.
- The website stays available for the project owner; no retained resource is deleted or reassigned.

## 8. Next steps

1. Fix `BUG_v0.1_2026-10-27_03_...` (stability abort leaves owned scratch objects and the next sweep inherits them) and re-run the stability group.
2. Close or keep `STAB-03` honestly: at the 2026-10-27 position the owner chose to keep the gate open and the threshold `< 50 ms` (historical, <50 ms>) unchanged until upstream provides a deferred adapter build. The 2026-11-09 requirement change supersedes this step; Section 11 records it.
3. Grant a Codex/provider harness and run the 98 behavioral scenarios with an independent trace review (ACCEPT-02).
4. Re-run the catalog and network matrices on the exact final revision before any full PASS claim.
5. The owner commits the shared `REVIEWED_FIXTURES` change in `private-local-udev` when the root-context guard is satisfied.

## 9. 2026-11-01 rerun (revision `cd60d68`)

- **Executor:** `opencode-s8-acceptance-20261101`, branch `issue/ISSUE_v0.1_2026-10-21_12_nghiem-thu-skills-mcp-toan-dien-va-ban-giao-bai-seo-con`.
- **Base revision:** `cd60d68fadcc3c7b791c17c8afcf1bc55b7a5b9e`; the tree was clean before the ticket edit. `git diff 8bca2f6..HEAD -- src/` and `git diff 0ed7047..HEAD -- src/` are both empty, so the plugin code matches the S5/S6 campaigns; only `tests/`, `infra/` and documentation changed.
- **Environment:** RTL single-site `compose.yaml`, `wp69` WordPress 6.9, `wp71` WordPress 7.1.3 (the compose label still says 7.1.2). Preflight exit 1: server-wide firewall bridge warning only, resources sufficient (10 GiB free RAM, 213 GiB disk). The lease of worktree `27-04-stab03-diagnosis-r2` had expired, so the sync took it over.
- **Input fingerprint:** the commit above; `tests/E2E/catalog-role-matrix.php` sha256 `4eb728fa9d929ca72bfeaa8380f319a193e3f255054f79c759eda304951f62e0`; `infra/remote/run-catalog-role.sh` sha256 `f7ba04993110d4922507705b923235f26317deca8852709f686d44e665db6b8d`; ignored asset manifest (22 files) sha256 `35645abb838df60322c9304aea2c882a10c77437b5de01e01c72450b83e0df2b`.

| Check | Command / campaign | Result |
|---|---|---|
| Asset structure | `check-test-debug-assets.py --stage all --self-test` | exit 0, `64/64 passed` |
| Client structure | `check-client-assets.py --self-test` | exit 0, `13/13 passed` |
| Project gates | `composer check` | exit 0, **253 unit tests / 51 029 assertions**, PHPStan L8, 30 settings docs keys |
| Packaging | `composer test:packaging`, `composer build` | exit 0; ZIP sha256 `d1ea14e3e04d5bb1e8833234b5e4d2b9608cce0174a3d2ffa8f507a500a1b377` (build-specific) |
| Offline behaviour | `run-mcp-behavior-evals.py --run` + `--verify`, run `mcp-behavior-s8-20261101T031138Z` | 98/98 `success` records; verify `problems=[]`. This is the offline FAKE controller, **not** model behaviour; no independent T3 rubric review yet |
| HTTP matrix | `composer test:http` | 35/35 cases true, exit 0 |
| Integration 6.9 (documented command) | `composer test:integration -- 6.9` | **exit 1**: 344 tests, **11 failures**, 6 skipped. All 11 `RoleSeoStabilityTest` cases fail on `XNETVN_MATRIX_SITE`/`XNETVN_ROLE_SEO_RUN_ID` because the wrapper does not pass the variables |
| Integration 6.9/7.1.2 (variables set in the toolbox) | `bash /work/infra/remote/run-integration.sh <version>` | both **exit 1**: 344 tests / 8 109 assertions / **1 failure** / 6 skipped, the same `test_stability_phase_leaves_nothing_behind_after_a_cron_drift` |
| Catalog wp69 | `run-catalog-role.sh wp69 cat-20261101T031743Z` | **0 failures**: 595 declared, 528 executed, 67 documented N/A, 62 tools, 843 requests, 10/10 gates true; cleanup and reconcile true; audit 646 rows, `bad=0` |
| Catalog wp71 | `run-catalog-role.sh wp71 cat-20261101T040828Z` | **57 failures**: 530 executed, 65 N/A, `matrix=false`, `counts_stable=false`; 50 `internal_error` HTTP 503 + 7 `unavailable`, clustered at indexes 28–87; cleanup and reconcile true (15 users/keys, 64 posts, 15 terms, 11 media, 18 comments); audit 575 rows, `bad=0` |
| Retained article | `run-seo-review.sh <site> review-20261026T153406Z verify` and `reconcile` | both sites exit 0, **13/13 checks true**, retained `post 1 / media 3 / terms 3 / users 2`; keys revoked, credentials and temp removed |
| Browser | `seo-review.spec.js` through RTL, 3 engines | wp69 `15 passed` (artifact `20261101T050212Z`), wp71 `15 passed` (`20261101T050233Z`) |
| Network | `compose.ui-network.yaml` | **Not run**: the single-site campaign was already red, so the flow stopped per contract and did not switch stacks |

**Red stability case (reproduced directly):** `role-seo-stability.php phase-cleanup` with run `s8pc1` returns `abort_observed=false`, `abort_ids_absent=false`, `workers_closed=false`, `barrier_idle=true`, `settings_restored=true`, `drift_case_clean=true`, `failed_case_clean=true`, and every `leftover_* = 0`. The earlier attempt left three empty directories `s8ac69-a/b/c` (no `state.json`, no WordPress object); they were reconciled and the three owned directories were removed with `rmdir`. `BUG_v0.1_2026-10-27_03` was cancelled because the class “did not reproduce”; at this revision it **does reproduce** on both 6.9 and 7.1.3.

**wp71 HTTP 503 block:** the wp71 container did not restart (2-day uptime), the logs carry no fatal or OOM line, `matrix.stderr` is empty, and the same plugin code and matrix passed on wp71 on 2026-10-26. The evidence therefore leans toward a transient availability window during seeding/matrix, with **no root cause concluded**. Per contract the campaign stays **not achieved** and is not re-run to force green.

**Rerun verdict:** ACCEPT-01 passes; ACCEPT-02 covers the offline controller only and still lacks the independent rubric review; ACCEPT-03 **does not pass**; ACCEPT-04 and ACCEPT-05 pass again on the retained article; ACCEPT-06 awaits the T3 reviewer. The article, images, terms and author stay on the lab volume.

**Next steps from the rerun:** (1) reopen a fix ticket for the stability `phase-cleanup`/abort path and rerun that group; (2) add `XNETVN_MATRIX_SITE` and `XNETVN_ROLE_SEO_RUN_ID` to the integration wrapper or documentation; (3) investigate the wp71 503 window and rerun that scope with a new campaign; (4) run the network matrix after the reds close.

## 10. Re-acceptance of 2026-11-09 (revision `037d289`)

**Why this section exists.** Section 9 ended with three reds and one untested area: the stability drift case failed on both suites, the wp71 catalog carried a block of `internal_error` cases, and the network matrix was never run because the flow stopped at the first red. This section records a fresh campaign set at revision `037d289eb257ed77b9acf47d53725409048c505d` and what closed each red.

- **Stability case.** The 2026-11-01 rerun left one `test_stability_phase_leaves_nothing_behind_after_a_cron_drift` failure, plus eleven `RoleSeoStabilityTest` failures when `XNETVN_MATRIX_SITE` and `XNETVN_ROLE_SEO_RUN_ID` were absent, so the case failed in `set_up()` before any mutation. After the lab repair of 2026-11-07, the same case passes with both variables supplied: `run-integration.sh 6.9` and `7.1.2` each return 354 tests / 8 232 assertions / **0 failed** / 6 skipped. No product, test, threshold or fixture change was made, and `BUG_v0.1_2026-10-27_03` stays Cancelled.
- **wp71 `internal_error`.** The cause is a harness identifier collision, not a product fault: `tests/E2E/catalog-role-matrix.php` treated every payload integer in the reserved 5000–5999 range as a logical fixture id and rewrote it through the ledger, while wp71's real post identifiers had grown into that range (section 9 recorded 57 such cases; the 2026-11-07 rerun recorded 95). The harness now resolves only explicit logical markers and passes every other integer through unchanged; wp71 is green in the campaign below.
- **Network.** With the single-site campaigns green, the flow switched to `compose.ui-network.yaml` and ran the network phase on both blogs; `network-main` is green below and the child-blog row is recorded in the table below.

| Area | Campaign / command | Result |
|---|---|---|
| Catalog wp69 | `run-catalog-role.sh wp69 cat-20261109T031959Z` | **0 failed**: 595 declared, 528 executed, 67 documented N/A, 62 tools, 843 requests; gates 10/10 true; cleanup restored, reconcile 0 |
| Catalog wp71 | `run-catalog-role.sh wp71 cat-20261109T040535Z` | **0 failed**: 595 declared, 530 executed, 65 documented N/A, 849 requests; gates 10/10 true |
| Network main | `run-catalog-role.sh network-main cat-20261109T044825Z` | **0 failed**: 595 declared, 594 executed, 1 documented N/A, 983 requests; `gates.matrix=true`, 10/10 gates true |
| Network child | `run-catalog-role.sh network-child cat-20261109T102043Z` | **0 failed**: 595 declared, 594 executed, 1 documented N/A, 983 requests; `gates.matrix=true`, 10/10 gates true; blog 2 |
| Integration 6.9 | `run-integration.sh 6.9` | **0 failed**: 354 tests / 8 232 assertions / 6 skipped |
| Integration 7.1.2 | `run-integration.sh 7.1.2` | **0 failed**: 354 tests / 8 232 assertions / 6 skipped |
| Offline controller | `run-mcp-behavior-evals.py --run` + `--verify` + `--review`, run `mcp-behavior-20261109T103826Z` | 98/98 records `success` (execution status only); `--verify` `problems=[]`; records sha256 `b4a8ba79ce1e0fdf3e42566614f7341c62fe9d4dd6fae737bdb4e90c308f067b`; corpus sha256 `2660a3fa…`; rubric `7 PASS / 59 FAIL / 32 UNKNOWN`. Offline FAKE transport only, **not** model behaviour |
| Structural baseline | `check-test-debug-assets.py --stage all --self-test` and `--manifest`, `check-client-assets.py --self-test`, `composer check`, `composer build`, `bin/verify-zip.sh` | asset checker 64/64; manifest 22 files; client 20/20; `composer check` exit 0 (**269 unit tests / 51 070 assertions**); `composer build` and `verify-zip` exit 0, ZIP sha256 `b0256c9e8ce87dd2f583d88edbcd63393441fee3dc128c62015749df42fe4cf3` (build-specific) |
| Retained article | `run-seo-review.sh <site> review-20261026T153406Z verify` | wp69 post 4028 and wp71 post 1823: **13/13 checks true**, `post 1 / media 3 / terms 3 / users 2`, author unchanged, nothing deleted or reassigned |

**Cause of the 503 windows.** The 503 windows that interrupted seeding and the matrix are WordPress automatic updates running in maintenance mode. The site debug log records `[08-Nov-2026 04:38:51]` and `[09-Nov-2026 03:14:41]` at the boundaries of those windows, and the same revision and fixtures are green outside them. This is an environment event, not a product or harness defect: no threshold, test or fixture was changed, and the campaigns above are fresh runs, not retries over a failed one.

**Provenance.** Every campaign of this set carries `XNETVN_MATRIX_REVISION=037d289…`, and all four campaigns report the same matrix hash `07d4d07ce9a0b312ea9fe65318fb3cb3241b3ae0ef1a33f97b1f9b905613d411`, so the four campaign reports describe one tree and one harness rather than a mix of revisions.

**Verdict.** ACCEPT-01 is met. ACCEPT-04 and ACCEPT-05 are met on the retained article. ACCEPT-06 is updated by this section and still awaits the independent T3 review. ACCEPT-02 is **not met**: the independent rubric scored 98/98 cases as 7 PASS / 59 FAIL / 32 UNKNOWN (`--review` imported with `problems=[]`; `review.json` sha256 `c499892d56b55c403fe999ded40279d66e1055402eefad2abf53bd1a0bba6a98`, `verdicts.json` sha256 `e68f6df2cfb9d820bced75b3948a7995174a5dcab4fbec595f7f63c720e074ca`). The cause is the offline method itself: the FAKE transport routes by `mode` only, so all 33 R/M/P specialist cases and seven debugging cases receive `xnetvn-mcp-testing` instead of the rubric's `role-security` / `media-testing` / `seo-publishing` / `debugging`; 10 cases miss a required refusal, 6 propose a forbidden or contradictory run, and 32 traces are a generic template. The harness must not emit the expected answer, so self-grading cannot repair this; no model behaviour is claimed and the FAKE limitation stays. ACCEPT-03 **remains not met as a whole** even though the catalog and network matrices are green, because `list_http_p50_lt_50_ms` (historical, <50 ms>) is still open at 52.585 / 53.379 / 53.064 ms against `< 50 ms`, owned by `ISSUE_v0.1_2026-10-27_02_dong-khoang-trong-stab-03-list-http-tren-wp69`. This is the 2026-11-09 verdict before the requirement change; Section 11 records the revised requirement. This section therefore does not claim a full PASS.

## 11. Requirement change and fresh gate evidence (2026-11-10)

The project owner authorized relaxing only the complete-HTTPS p50 requirement
from `< 50 ms` to `< 55 ms`, effective 2026-11-09. The authorization, quoted
verbatim, is: "Bạn quyết STAB-03: hạ ngưỡng". The current gate key is
`list_http_p50_lt_55_ms`; the other five gates, the 100-sample count, the
10-sample warmup, the measurement method and the adapter pin are unchanged.
This is a requirement change, not a measured performance gain.

**Three valid wp69 cache-on rounds** ran at branch revision `4921d66` (benchmark
key changed in `0389505`) with the owned cache fixture enabled:

| Round | p50 (ms) | p95 (ms) | `request_errors` | `visible_tools` | Gate | Delta vs 50 ms | Headroom vs 55 ms |
|---|---:|---:|---:|---:|---|---:|---:|
| 1 | 52.834670 | 54.781869 | 0 | 60 | Pass | +2.834670 | 2.165330 |
| 2 | 53.161070 | 55.193170 | 0 | 60 | Pass | +3.161070 | 1.838930 |
| 3 | 53.275170 | 54.897070 | 0 | 60 | Pass | +3.275170 | 1.724830 |

Every p50 is at or above 50 ms, so every round carries the nonblocking warning
`p50 >= 50 ms`; the warning does not fail the revised gate. Report sha256:
`7bf53289ad46a7348428542f23e0328fd9821e9d52ad2ea21b20acf1fe1bab5a`,
`edc267b4fff8ba6cea9662811214a38d44fddc27a5632b9d87e26ee2060cec2c` and
`6fe0426b53c4354d368406d2ce0affc650f4e2b7c139e658e9a05906ba174aeb`.

**Exception retirement.** The `case`/`STAB-03`/`wp69` exception is removed from
`xnetvn_agent_hub_wp_role_seo_acceptance_open_gates()` after those three rounds.
`composer test:integration -- 6.9 --filter RoleSeoAcceptanceGateTest` passes
5 tests / 14 assertions, and `php tests/E2E/role-seo-acceptance.php selftest`
passes all 23 checks in a clean copy of the tree; the same command in this
worktree fails `prepare-creates-the-manifest` because `.local` is a symlink to
the shared store and the ownership guard refuses a symlinked component. The
STAB-03 case stays in the stability suite.

**Campaign status: met.** The fresh two-phase campaign
`role-seo-20261109T180500Z` ran under one campaign id on the post-change
fingerprint `1f383c341b5f41f55e7fc04fb9a4a48b82c2ffd492b89ae1805c794232fa8447`.
The single phase passed 423 of 424 rows (0 fail, 0 skip, 1 not applicable) and
the network phase passed 416 of 416 rows, with every cleanup flag true in both
phases. The verifier returned `problems=[]`, `open_gates.hit=[]` and `ok=true`;
`case:STAB-03@wp69` is absent. The required reconciliation evidence is those
cleanup flags plus the empty problem and gate lists, because the CLI has no
separate `reconcile` mode. ACCEPT-03 is therefore **met against the revised
requirement**, and every earlier STAB-03 measurement and FAIL verdict above
stays as history with `historical, <50 ms>`.
