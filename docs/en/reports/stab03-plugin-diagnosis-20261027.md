# Plugin-only diagnosis for STAB-03 — 2026-10-31

- **Ticket:** `ISSUE_v0.1_2026-10-27_04_nguyen-nhan-va-thiet-ke-toi-uu-plugin-cho-stab-03-duoi-50-ms`
- **Assignee:** `impl-stab03-diag-20261031` (T3, taken over after 85 hours without progress)
- **Revision:** `536873f1ad73f774f4cf0417b640ebb8247caeb4` (base `a4b283f14e07a0d13572efe70b2fbb04c2fc18aa`)
- **Environment:** Remote Test Lab `xnetvn-agent-hub-wp`, wp69, WordPress 6.9, PHP 8.2, adapter 0.7.0, Redis cache-on; 3 sequential measurement rounds.
- **Scope:** diagnosis and design handoff only; no change to `src/`, dependencies, runners, thresholds, toolsets or retained data.

## 1. Measurements

| Round | `http_ms` p50 | Distance to 50 ms | `pre_dispatch_ms` | `dispatch_ms` | `adapter_init_ms` | Errors | Tools |
|---|---|---|---|---|---|---|---|
| 1 | 52.956692 ms | 2.956692 ms | 40.772915 | 8.766199 | 11.721398 | 0 | 60 |
| 2 | 53.112690 ms | 3.112690 ms | 40.898085 | 8.795498 | 11.729598 | 0 | 60 |
| 3 | 52.907789 ms | 2.907789 ms | 40.775061 | 8.808198 | 11.702498 | 0 | 60 |

All three rounds reported `request_errors=0`, `visible_tools=60` and a payload of 33 191 logical JSON bytes. Five of the six gates pass; `list_http_p50_lt_50_ms` misses in 3 of 3.

Report file hashes:

- `benchmark-child-1.json` — `4e05f12fc046c15f4b1b256cb48e01ff226b27206c816a175fdf63a4f2443285`
- `benchmark-child-2.json` — `2cf503c713b318e700a670973907109ed5cadda47e0b74069df58ba6d6c7ac12`
- `benchmark-child-3.json` — `3a41689346aa19b9991de9f833cfb26d6d2e33d16ee5757b70b2897ab9aa28af`

Measured source hashes match the local checkout:

- `tests/E2E/benchmark.php` — `ac9bc801a372857522cb50fd0320e9eb5b719c8d718e9099add8210cb6e1d1ec`
- `infra/remote/run-benchmark.sh` — `1fb8f1df39a2c62a84e4019f2a7f26994a15e56334b680b11675a9d956f8a551`
- Adapter installed on the lab — `0.7.0`

## 2. Timeline of round 1

The values below are aggregate p50 marks, not a per-sample split; nested phases are never added twice.

| Mark | Value |
|---|---|
| `mu_load_ms` (request start → MU-plugin) | 7.200003 ms |
| `bootstrap_ms` (request start → `plugins_loaded`) | 10.334969 ms |
| `init_ms` (request start → `init`) | 14.428139 ms |
| `pre_dispatch_ms` (request start → `rest_pre_dispatch`) | 40.772915 ms |
| `phase_rest_api_init_ms` | 15.224598 ms |
| `phase_mcp_adapter_init_ms` | 11.721398 ms |
| `phase_wp_abilities_api_init_ms` | 0.749500 ms |
| `phase_categories_init_ms` | 0.109600 ms |
| `dispatch_ms` | 8.766199 ms |
| `auth_ms` | 3.662600 ms |
| `policy_ms` | 0.659300 ms |
| `ttfb_ms` | 51.663000 ms |
| `http_ms` | 52.956692 ms |
| memory peak | 6 173 232 B |

## 3. Attribution

| Region | Owner | Evidence |
|---|---|---|
| Core start-up + MU-plugin | WordPress core | `mu_load_ms` 7.20 ms; contains no Agent Hub code |
| Plugin load + adapter | product + dependency | `bootstrap_ms − mu_load_ms` ≈ 3.13 ms; `src/Bootstrap/Loader.php:39` → `src/Plugin.php:89,113-152` |
| Building all pack definitions at `init` 0 | product | `src/Abilities/Registry.php:92-104`; `init_ms − bootstrap_ms` ≈ 4.09 ms, including core init |
| Registering 120 abilities + 6 categories | product | `src/Abilities/Registry.php:135-175`, `:119-133`; dedicated phases 0.749500 + 0.109600 ms |
| Building the server and projecting 60 tools | adapter dependency 0.7.0 | `ServerGateway.php:88-117`; `phase_mcp_adapter_init_ms` 11.721398 ms; `McpAdapter.php:221,278`, `McpServer.php:90,141,237`, `McpComponentRegistry.php:134,182,585,673` |
| `scope_hint()` reading the key a second time | product | `ServerGateway.php:158-171` and `Authenticator.php:62-80` share `find_by_key_id()`; not timed separately |
| Authentication + rate limit + owner check | product | `Authenticator.php:62-140`; `auth_ms` 3.662600 ms |
| Policy for 60 tools | product | `Registry.php:299-315`; `policy_ms` 0.659300 ms |
| Dispatch + output/audit/JSON | product + adapter | `dispatch_ms` 8.766199 ms; the part after authentication and policy is not split |

**Not measured:** the plugin-only part inside `bootstrap_ms`, `init_ms` and `pre_dispatch_ms − init_ms` (≈ 26.35 ms). Dedicated timers for provider construction, pack build and 120 ability registrations do not exist. That part must therefore not be recorded as 0 or assigned entirely to the plugin; it is the largest `unavailable` gap.

## 4. Plugin-only candidates

| # | Candidate | Evidence | Expected saving (hypothesis) | Invariant / test | Risk |
|---|---|---|---|---|---|
| 1 | Build only the packs visible to the key scope, after the scope hint, instead of all six packs at `init` | `Registry.php:92-104` builds 120 definitions; the benchmark exposes only 60 tools; `names_for_scope()` at `:216-240` already filters by scope | 0.5–1.5 ms | A missing or malformed key must fall back to the full catalog; `allowed()` still checks every tool live; catalog/scope/policy tests | The adapter needs the tool names at `mcp_adapter_init`; the scope hint only narrows and never grants |
| 2 | Register categories once per toolset instead of once per definition | `Registry.php:119-133` calls `wp_has_ability_category()` for every definition; there are only six toolsets | 0.1–0.3 ms | Category set and labels unchanged; `wp_has_ability_category` contract test | A future dynamic toolset must still be registered |
| 3 | Register only the 60 scope-visible abilities on an authenticated MCP route, with a full-catalog fallback | `Registry.php:135-175` registers 120; the scope exposes 60 | 0.2–0.4 ms | Fallback on a missing or malformed key; private metadata and the output pipeline unchanged; role/scope matrix | If the key is not yet authenticated, the hint may only narrow |
| 4 | Defer constructing the management controllers and `Pages` on a non-management request | `Plugin.php:140-150` builds `KeysController`, `AuditController`, `SettingsController` and `Pages` on every request | < 0.2 ms | Management cookie+nonce routes still register correctly; REST route tests | Missing a management hook on a non-management route |
| 5 | Merge `find_by_key_id()` between `scope_hint()` and `authenticate()` | `ServerGateway.php:158-171`, `Authenticator.php:62-80` | not recommended | Would need a request-local cache of one stored key; authentication order and secret handling must stay exact | Caching a credential-derived object before authorization is a security risk |

The combined benefit of #1–#4 may be under 2 ms, which is not clearly enough to close the 2.91–3.11 ms gap. A separate fix ticket should implement #1–#3 (with the full-catalog fallback), re-run exactly three rounds and run the integration/HTTP checks. If the gap remains, the remainder sits in adapter 0.7.0 and in the unsplit pre-dispatch window; that needs a dedicated instrumentation ticket, and a profiler must not be written inside the diagnosis ticket.

## 5. Adapter 0.7.0

Excluded options (the conclusion of Section 8.2 stands):

- Reflection into `$component_registry` or `add_mcp_tool()` breaks the encapsulation of the dependency.
- Calling `register_tools()` after the server is built is impossible: the registry is private and has no getter.
- Adding records inside `mcp_adapter_tools_list` cannot help: the filter receives already projected records and the build cost is paid earlier.
- Advertising fewer tools changes the contract and the 33 191-byte payload.
- A persistent tool-object cache would store request-bound objects (abilities, schemas, permissions) outside their request.
- `create_server()` refuses calls outside `mcp_adapter_init` (`McpAdapter.php:278`); building the server at the first `tools/list` is refused as well.

## 6. Conclusion and fix-ticket input

- Measured gap: **2.907789–3.112690 ms**; the largest measured cause is the adapter build at **11.721398 ms**, but the plugin-only part of the pre-dispatch window is **not split**.
- Recommended candidates: #1 (packs by scope), #2 (category dedupe), #3 (scope-visible ability registration with a full-catalog fallback). #4 is a minor optimization; #5 should not be done because of the security risk.
- The fix ticket must keep the six thresholds, the toolset, the payload, live authentication and policy, private ability metadata and the output pipeline; at most 5 runtime/test/docs files.
- After the fix: re-run exactly three wp69 cache-on benchmark rounds, `composer check`, `composer test:integration -- 6.9` and `git diff --check`; hand the gate owner `ISSUE_v0.1_2026-10-27_02` a valid close only if all three rounds are `< 50 ms` and all six gates pass. This diagnosis ticket does not close STAB-03 on its own.

## 7. Operational evidence

- `rtl.py preflight` → exit 1, only the four existing bridge firewall warnings; no block.
- `rtl.py lease` before round 1 → `nobody holds project xnetvn-agent-hub-wp`.
- `rtl.py sync --dry-run` → only mtime differences; `sync` exit 0; `status` running, all 5 containers healthy.
- `rtl.py plan` → exit 2 because of the known RAM double-count (`2816 + 2816 > 5120`), not a real shortage; no redeploy, per the owner decision.
- Every round: `flock -n 9` on `/work/.local/role-seo/wp69.lock`, `cache-fixture.sh enable wp69`, a trap to disable it, `run-benchmark.sh` unmodified.
- After round 3: both `object-cache.php` and `owned-dropin.sha256` are absent; the cache fixture is clean.
- No credential, token or private state was printed or copied.

## 8. Limits

- The numbers come from one lab container with 1 CPU and 512 MiB; they are not production figures or a capacity commitment.
- There are no paired raw samples, so per-request attribution is not inferred.
- This ticket changed no `src/`, dependency, benchmark, threshold or toolset.
- Integration/HTTP checks were not run after a fix because there is no runtime fix yet; that belongs to the fix ticket.
