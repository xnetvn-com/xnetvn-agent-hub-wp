# Testing & Quality Assurance Guide (Testing Guide)

> **Guide to the comprehensive testing process, TDD methodology, test matrix and automation tools for xNetVN Agent Hub WP.**

---

## 1. Testing Strategy & Pyramid

The project applies a strict Test Pyramid model with the principle: **Testing is a First-Class Citizen of the source code**.

```
          /\
         /  \     End-to-End Tests (10%)
        /    \    - Playwright Browser Tests (Admin UI, WCAG 2.2 AA)
       /──────\   - Real MCP Client Execution (Claude Desktop, etc.)
      /        \
     /          \  Integration Tests (20%)
    /            \ - WP-PHPUnit with WordPress 6.9 & 7.1.2
   /──────────────\ - Multisite, MySQL Transaction, REST Gate
  /                \
 /                  \ Unit Tests (70%)
/                    \- PHPUnit 9.6, Brain Monkey, Mockery
/──────────────────────\- Pure Domain, Crypto, Policy, Contracts
```

- **Code Coverage targets (enforced by `.github/scripts/CoverageGate.php`, ADR-0019):**
  - Minimum **90%** coverage for the core `Domain`, `Auth`, `Policy` layers.
  - Minimum **80%** combined project coverage for every pull request except documentation-only pull requests that match the explicit allowlist; those are exempt from collection and never claim a measurement.
  - Minimum **70%** for each integration suite and **90%** for `unit.Domain` and `unit.PureAuth`.
  - No measure may regress below the recorded baseline `.github/coverage/M1-lines.json`.
  - The unit suite has no absolute overall floor; it is one of five suites and the integration suites exercise the WordPress runtime.
- **Testing standards:**
  - No warnings or errors are ignored.
  - Static analysis: **PHPStan Level 8 without a baseline** (0 errors).
  - WordPress formatting standards: **PHPCS WordPress-Extra + WordPress-Core + WordPress-Docs**.
  - Version compatibility: **PHPCompatibilityWP** (checks PHP 7.2 for Bootstrap files, PHP 8.2 for the entire plugin).

---

## 2. Test Suites

### 2.1 Unit Tests

- **Location:** `tests/Unit/`
- **Tools:** PHPUnit 9.6 + Brain Monkey + Mockery.
- **Purpose:** Test pure business logic, cryptographic calculations (KeyGenerator, KeyHasher, Pepper), permission resolution (TierResolver, Policy), token format validation and error simulation.
- **Command:**
  ```bash
  composer test:unit
  ```

### 2.2 Integration Tests

- **Location:** `tests/Integration/`
- **Tools:** `wp-phpunit/wp-phpunit` on a real WordPress database.
- **Purpose:**
  - Test the plugin boot lifecycle (`PluginBootTest.php`).
  - Test actual database interactions with `$wpdb` tables (`PersistenceTest.php`).
  - Test atomic key rotation transactions on MySQL InnoDB (`RotationAtomicityTest.php`).
  - Test conflict-prevention locking (`WpOwnerLock`).
  - Test Single site and Multisite Network environments (`MultisiteTest.php`).
  - Test GDPR personal data export and erasure (`AuditPrivacyTest.php`).
- **Commands (Remote Test Lab `toolbox`; single-site then multisite):**
  ```bash
  composer test:integration
  XNETVN_TEST_MULTISITE=0 composer test:integration
  XNETVN_TEST_MULTISITE=1 composer test:integration
  ```
  `composer test:integration` runs `bin/test-integration.sh`, which delegates to the lab `toolbox` outside CI. See §4 for lab setup.

### 2.3 Contract Tests

- **Location:** `tests/Contract/`
- **Purpose:** Ensure Ability Packs always strictly comply with the `AbilityPack` and `AbilitySpec` contracts.
- **Example test class:** `PackContractTestCase.php`.

### 2.4 End-to-End & Accessibility Tests (E2E & WCAG)

- **Location:** `tests/E2E/`
- **Tools:** Playwright Test Runner + `@axe-core/playwright`.
- **Purpose:**
  - Test user interfaces in a real browser: API key management on the Profile page and Admin Dashboard.
  - Target **WCAG 2.2 Level AA**: Color contrast, keyboard navigation and form labels for screen readers. Axe results are signals only; they do not by themselves establish WCAG conformance (see §5).
  - Test actual connections from sample MCP Clients (`McpClient.php`, `real-clients.py`).
- **Commands:**
  ```bash
  npm run test:e2e
  ```
  The lab form of this command is in §4. Run one site at a time with `--base-url "$UI_BASE_URL"`; repeat for the second site. `UI_BASE_URL` is the HTTPS address from the lab handoff (never a production URL, never committed).

### 2.5 Packaging & Plugin Check Tests

- **Location:** `tests/Packaging/` & `bin/`
- **Purpose:**
  - Package the product release `.zip` and verify its file list contains no unnecessary code, development dependencies or junk files.
  - Run the official WordPress standards checker (`Plugin Check`).
- **Command:**
  ```bash
  composer test:packaging
  ```

### 2.6 Network and HTTP Matrix Tests

- **Location:** `bin/test-network.sh`, `infra/remote/run-network.sh`, `infra/remote/run-http-matrix.sh`, `tests/E2E/http-matrix.php`.
- **Purpose:** Exercise the multisite/network path and the security HTTP matrix (Origin, TLS, auth, rate limits) on the lab stack.
- **Commands (Remote Test Lab `toolbox`):**
  ```bash
  composer test:network
  composer test:http
  ```

### 2.6.1 Comment Lifecycle Review (Retained)

- **Location:** `infra/remote/run-comments-review.sh`, `tests/E2E/comments-review.php` and `tests/E2E/comments-review-fixture.php`.
- **Purpose:** Exercise the ten comment abilities through real HTTPS MCP calls: create, read, list, count, reply, update, approve, hold, spam, unspam, trash, untrash, bulk moderation, delete and purge.
- **Retention:** `wp69` retains approved, hold, spam and trash review comments and never calls purge; its CMT-09 phase is a read-only audit plus a target-only sentinel delete. `wp71` restores every review spam/trash row, re-audits immediately before each purge call, and blocks purge when any review, sentinel or foreign candidate remains. Only explicitly marked disposable comments may be deleted, and the journal records each intentional deletion.
- **Commands (Remote Test Lab `toolbox`):**
  ```bash
  bash /work/infra/remote/run-comments-review.sh wp69
  bash /work/infra/remote/run-comments-review.sh wp71
  ```
  Run `wp69` before `wp71`. Inside the lab toolbox the report is under `/work/.local/comments-review/<site>/<run_id>/`; the host copy is `.local/comments-review/<site>/<run_id>/`.
- **Boundary:** This suite does not replace the multisite, rate-limit, Origin/TLS, real-client or production acceptance gates.

### 2.7 UI Suite Mapping (Flow Coverage)

Each UI behavior in [UI/UX Standards](standards/ui-ux-standards.md) maps to one focused Playwright file. Filter by the listed tag; a focused tag pass never replaces the full P13 acceptance in §5.

| Suite | Spec file | Covers |
|---|---|---|
| UI-FX | `tests/E2E/ui-fixture.spec.js` | Fixture login, ownership/quota, settings restore, `390px` reflow, dataset match. |
| UI-COM | `tests/E2E/ui-common.spec.js` | Unified navigation, one heading, loading/error/retry, session guidance, reflow/`320px`, RTL, scoped styles, artifact safety. |
| UI-OV | `tests/E2E/ui-overview.spec.js` | Getting started, endpoint copy, `Unavailable` fields, error retry, header diagnostic (presence only), reflow/focus/contrast. |
| UI-TL | `tests/E2E/ui-tools.spec.js` | Catalog search by name, toolset/tier filters with AND, empty state, metadata-only listing (no run action). |
| UI-KY | `tests/E2E/ui-keys.spec.js` | Scoped filters, cross-owner denial, single-mutation guard, uncertain-result handling, secret dialog focus/clear, password for destructive/admin tiers. |
| UI-AC | `tests/E2E/ui-activity.spec.js` | Owner/field scoping, UTC conversion, reversed-range guard, paging, text-only rendering, management denial. |
| UI-ST | `tests/E2E/ui-settings.spec.js` | Grouped fields with bounds, role-override mode, invalid-input guard, rejected-save readability, reflow/focus, management denial. |
| UI-HP | `tests/E2E/ui-help.spec.js` | Four guided steps, endpoint copy, clipboard-denial fallback, keyboard troubleshooting, reflow/focus/contrast. |
| UI-NT | `tests/E2E/ui-notices.spec.js` | Environment notice cause plus next step, capability gating, reflow/focus/contrast. |

Supporting files `tests/E2E/admin.spec.js` (admin smoke plus axe) and `tests/E2E/ui-artifacts.spec.js` (credential-like artifact scan) are not counted in the nine UI suites above.

### 2.8 MCP Role and SEO Acceptance Campaign

- **Location:** `tests/E2E/role-seo-acceptance.php` (manifest, verifier and artifact scan), `infra/remote/run-role-seo-acceptance.sh` (campaign runner) and the owned group runners `infra/remote/run-role-seo.sh`, `infra/remote/run-role-seo-network.sh` and `infra/remote/run-role-seo-network-matrix.sh`.
- **Tools:** the owned PHP MCP harness (`tests/E2E/RoleSeoHarness.php`, `tests/E2E/McpClient.php`) over real HTTPS against the installed adapter. WP-CLI only seeds the fixture, measures the after-condition and cleans up; it never replaces an MCP end-to-end call.
- **Purpose:** collect the cases of every role and SEO group of one campaign (fixture, permissions, content, media, lifecycle, stability, network) into one sanitized report and compare them with the expected manifest of that campaign.
- **MCP only:** the campaign never drives a browser. The admin UI, the frontend theme, Google indexing and the release are never marked complete by it, and an integration-only pass is never read as an MCP end-to-end pass.
- **Role semantics:** the actors `administrator`, `editor`, `author`, `contributor`, `subscriber` and `banned` carry the read, write, destructive and admin key scopes. A scope only narrows the capabilities of the current user, it never grants one. A denial counts as a pass only when the refusal class matches the expectation and no unauthorized write happened; a schema or wire error is never read as a permission denial.
- **Targets:** `wp69` (WordPress 6.9), `wp71` (WordPress 7.1.2) and the multisite `network-main` and `network-child` (WordPress 7.1.2).
- **Network URL:** the network phase uses the canonical network domain of the fresh `rtl.py plan` and `rtl.py handoff` (for example `https://xnetvn-agent-hub-wp-wpnetwork.vm-2-215.local`). The `.sslip.io` alias is not a target: WordPress multisite redirects it to the network home and drops the blog path, so the `/ui-second/` child would be unreachable. Every network command runs against the canonical domain.
- **Measured restore:** every group report carries the counts of the owned site before and after its run, with the settings and cron digests, so the verifier can tell an owned leak from a cron digest drift. The merged report of the network group carries the counts of both blogs and both of its parts; an empty counts block would leave the owned restore of that group unmeasured.
- **Controlled cron and quota:** the owned fixture snapshots the settings, the cron digest and the upload quota of the owned site before it seeds and restores them after the group. The campaign never raises the quota, the cron or the site ceiling of the lab and it disables only the drop-in cache that it enabled itself.
- **Metrics:** the report counts the cases by role, target, scope mode and variant, and carries the p50, p95, p99, the maximum and the sample count of every latency bucket, the peak memory delta per actor, the number of stability rounds and the leak-free flag. A metric that was not measured stays `unavailable`; it is never replaced by zero and never extrapolated to production load.
- **Report safety:** a report keeps the case id, the role, the target, an allowlisted status and reason, counts, timings and boolean checks. It never keeps an argument value, a response body, a post body, a cookie, a bearer token, a credential hash or a raw identifier. The verifier scans every artifact of the campaign whatever its extension, and a refusal never prints the matched text.
- **Coverage on the network blogs:** both phases run the literal B-E matrix files. The network phase runs them once per blog through `infra/remote/run-role-seo-network-matrix.sh`, one blog at a time because the two blogs share the network tables, and it runs the network matrix of the network group afterwards. Every blog therefore reports the same B-E case count as a single site (permissions 53, content 48, media 53, lifecycle 35, 189 cases per blog) plus the cases of the network group.
- **Open gates:** a red case, or a red cleanup flag whose cause is measured, can be declared as an open gate of another ticket. The list always comes from the verifier code, never from the manifest, so a campaign cannot silence a red case of its own; every gate names one ticket that exists in the local ticket store (`.local/docs/en/issues/`) and one narrow key; a cleanup flag is only a gate when the owned counts and the settings digest are unchanged and the cron digest is the only difference, and the group is listed by that ticket. The owner of a gate must also be **open**: the verifier reads the status of the ticket file and applies a gate only while that ticket is still open, so a gate of a closed ticket never hides a red case of the campaign. The verifier reports every gate separately with its owner and never counts it as a pass of this campaign, while a red case that is not declared stays a failure of this campaign and the run exits nonzero.
- **Report root:** `prepare` and `verify` refuse a report root that lives under a world-writable directory that is not sticky (for example `/tmp` on a host without the sticky bit), because another user could replace the artifacts. Use a directory inside the project, for example `.local/remote-test/role-seo/<campaign>`.
- **Lab runbook for the network phase:** the canonical network domain has no DNS record inside the toolbox container, so the acceptance runner adds one entry for it to the hosts file of that container, pointing at the lab host of the fresh plan. The change lives only inside the container of this project, it never touches the server, the firewall, Traefik or Swarm, and it disappears when the container is recreated. The browser runner of the lab does the same thing with `--add-host`.
- **Commands (offline, without a lab):**
  ```bash
  php tests/E2E/role-seo-acceptance.php selftest
  php tests/E2E/role-seo-acceptance.php fingerprint --root .
  ```

---

## 3. Comprehensive Pre-commit Checks (Pre-commit Quality Gate)

To ensure all source code meets standards 100% before creating a commit or Pull Request:

```bash
# Run all quality checks (Lint, Analyse, Unit Test)
composer check
```

The `composer check` command is equivalent to running these sequentially:

1. `composer lint`: Check PSR/WordPress coding standards.
2. `composer lint:bootstrap`: Check PHP 7.2 syntax compatibility for bootstrap files.
3. `composer analyse`: Highest-level static analysis with PHPStan Level 8.
4. `composer test:unit`: Run all Unit Tests.

`composer check` also runs `composer check:docs`, which compares the documented setting defaults with `WpSiteSettings::DEFAULTS` (`bin/check-settings-docs.py`) and verifies English/Vietnamese documentation parity plus every relative documentation link (`bin/check-docs-parity.py`).

---

## 4. Testing in the Remote Test Lab

Heavy tests, multithreaded load tests, browser E2E compatibility checks and security HTTP matrix tests run on the shared test server through the `lc-udev-remote-test-lab` skill (`rtl.py`). Do not run services, databases or browsers locally for these suites.

### 4.1 Setup and Preflight

```bash
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" preflight
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" plan --compose-file compose.yaml --site wp69:80 --site wp71:80
```

Read the plan output (free RAM, disk, limits, projects). A blocking preflight or plan finding stops the run; diagnose with `rtl.py diagnose --fetch` and distinguish environment failures from project bugs.

### 4.2 Start the Lab (Two Sites)

```bash
npm run remote:plan
npm run remote:up
```

`npm run remote:plan` renders and lints the two-site plan (`wp69:80`, `wp71:80`). `npm run remote:up` syncs, starts the stack and creates both WordPress sites. Keep lab volumes on stop; never purge or delete real data.

### 4.3 Test Addresses (`UI_BASE_URL` and `--base-url`)

```bash
python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" handoff --dir "$PWD"
UI_BASE_URL="<HTTPS address from the handoff for one site>"
```

Set `UI_BASE_URL` in the shell from the `rtl.py handoff` of the matching site namespace. It is a test address, not a credential; never commit it and never invent it. Every E2E invocation below reads that variable through `--base-url "$UI_BASE_URL"`. Run the selected suites once per site (`wp69`, then `wp71`).

### 4.4 Run the Suites

```bash
# Security HTTP matrix
composer test:http

# Focused UI suites, one site at a time
RTL_AGENT="$USER" python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir "$PWD" --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js --grep UI-OV'
```

Replace the `--grep` tag with the suite from §2.7 (`UI-FX`, `UI-COM`, `UI-OV`, `UI-TL`, `UI-KY`, `UI-AC`, `UI-ST`, `UI-HP`, `UI-NT`). Integration and network suites use §2.2 and §2.6. UI checks cover viewports `390px`/`768px`/`1440px` and reflow `320px`, English plus RTL, Unicode and long names, keyboard, focus, labels, contrast, loading, empty, error, success, and expired-session or lost-permission cases where applicable.

The P13 acceptance suite is `tests/E2E/admin.spec.js` plus every `tests/E2E/ui-*.spec.js` file. Run it without a tag filter, once per single-site lab:

```bash
RTL_AGENT="$USER" python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir "$PWD" --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test tests/E2E/admin.spec.js tests/E2E/ui-*.spec.js --config=tests/E2E/playwright.remote.config.js'
```

On the multisite network, use the canonical `.local` domain (the alias loses the blog path), append `/ui-second/` for the child target, and run only the network acceptance group:

```bash
RTL_AGENT="$USER" python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir "$PWD" --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test tests/E2E/ui-acceptance.spec.js --grep "@ui-acc-net" --config=tests/E2E/playwright.remote.config.js'
```

`tests/E2E/seo-review.spec.js` belongs to the retained S6 article flow, requires the S6 handoff, and is out of P13; never add it to the suite above.

#### 4.4.1 Machine measurement of contrast and motion (`UI-MANUAL`)

The suite `tests/E2E/ui-manual-equivalence.spec.js` measures by machine what the manual list of UI-04 asks for. It is read only: it never creates, revokes or edits a key.

```bash
# One run per site: the address of wp69 first, then the address of wp71.
RTL_AGENT="$USER" python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" e2e --dir "$PWD" --name xnetvn-agent-hub-wp --base-url "$UI_BASE_URL" -- sh -c 'npm ci --ignore-scripts --no-audit --no-fund && npx playwright test --config=tests/E2E/playwright.remote.config.js --grep UI-MANUAL'
```

The evidence of a run lands in `.local/remote-test/<timestamp>/`: `ui-manual-equivalence.json` and one `ui-manual/<variant>--<engine>.json` per variant and engine. The thresholds of the suite are fixed and are never lowered to make a case green:

| Threshold | Value |
|---|---|
| Contrast of normal text (WCAG 2.2 SC 1.4.3) | 4.5:1 |
| Contrast of large text | 3:1 |
| axe violations of `.xnetvn-agent-hub` (`wcag2a`, `wcag2aa`) | 0 in every measured variant |
| `forced-colors: active` | 0 axe violations and a minimum ratio of 4.5:1 |
| `prefers-reduced-motion: reduce` | 0 animated and 0 transitioning elements inside the hub |

The variants are the reference viewport, the zoom levels 200 % and 400 %, the Arabic locale with `dir="rtl"`, the admin colour schemes `fresh`, `light` and `midnight`, the colour schemes of the browser and `forced-colors: active`, and the reduced motion. The admin colour schemes are emulated with the stylesheet of the picker of the profile page and the matching body class; the stylesheet of a scheme is printed after the stylesheet of the plugin, so a rule of the plugin that must win over the core needs a higher specificity than the rule of the core (`.wp-core-ui .button` is 0-2-0). The plugin states its own colours for the buttons of the hub and names the colours of the system palette in the block `@media (forced-colors: active)`; it never changes a file of the core and never sets `forced-color-adjust: none`.

### 4.5 Run the MCP Acceptance Campaign

The campaign runs two sequential phases of the same namespace: `single` first, then the reviewed single-to-network transition of the base runbook, then `network`, then the reviewed rollback to `single`. The runner never starts, stops or converts a stack by itself, and the two phases never run back to back.

```bash
set -o pipefail
ROLE_SEO_CAMPAIGN="role-seo-$(date -u +%Y%m%dT%H%M%SZ)"
php tests/E2E/role-seo-acceptance.php prepare --campaign "$ROLE_SEO_CAMPAIGN" --report-dir ".local/remote-test/role-seo/$ROLE_SEO_CAMPAIGN"
composer check
RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" preflight
RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" sync --compose-file compose.yaml --dry-run
RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --compose-file compose.yaml toolbox -- bash /work/infra/remote/run-role-seo-acceptance.sh single "$ROLE_SEO_CAMPAIGN" | tee ".local/remote-test/role-seo/$ROLE_SEO_CAMPAIGN/single.log"
# Reviewed single-to-network transition; the phase below needs the network stack active.
RTL_AGENT=codex python3 -I -B "$HOME/.agents/skills/lc-udev-remote-test-lab/scripts/rtl.py" exec --compose-file compose.ui-network.yaml toolbox -- bash /work/infra/remote/run-role-seo-acceptance.sh network "$ROLE_SEO_CAMPAIGN" | tee ".local/remote-test/role-seo/$ROLE_SEO_CAMPAIGN/network.log"
php tests/E2E/role-seo-acceptance.php verify --campaign "$ROLE_SEO_CAMPAIGN" --report-dir ".local/remote-test/role-seo/$ROLE_SEO_CAMPAIGN"
```

`prepare` creates the report root with mode `0700` after checking that no path component is a symlink or foreign owned, and refuses a campaign that still holds data. Each phase prints exactly one JSON block between `ROLE_SEO_REPORT_BEGIN` and `ROLE_SEO_REPORT_END`; the verifier parses those two markers, compares the observed cases with the expected manifest and refuses a missing or duplicated block, a malformed JSON block, a missing, unknown or duplicated case, a fingerprint that differs from the manifest, a false cleanup flag and an artifact that carries credential-shaped content.

The code fingerprint is the SHA-256 over the sorted relative paths and the bytes of `src/`, the text files of `tests/`, `infra/remote/`, `composer.lock`, `xnetvn-agent-hub-wp.php` and `uninstall.php`. The same code computes it on the receiving machine and on the synced copy of the lab, so both phases must carry the same fingerprint; a change of the code between the phases invalidates the first one.

The private state of the campaign (the raw group reports and the fixture ledgers) stays on the lab. Only the sanitized phase block travels to the receiving machine, and the verifier scans the report directory before the hand-off.

One `rtl.py exec` call is limited to 600 s, so a longer phase (for example the `single` phase with five `stability` rounds on both sites) runs in the background **inside** the toolbox container and its own report is retrieved verbatim: start `nohup … "$ROLE_SEO_CAMPAIGN" > <report-dir>/<phase>.console.log 2>&1 &`, wait for the `ROLE_SEO_REPORT_END` block, then write that block verbatim into `<phase>.log` for `verify`. Running the network matrix through its raw runner needs the canonical domain in the toolbox container's `/etc/hosts` (the acceptance runner does this in `ensure_canonical_dns`). Never raise the 600 s limit, never run two rounds in parallel and never swap the `single`-before-`network` order.

---

## 5. Target Versus Observed (Acceptance Record)

`git diff --check` proves whitespace only; it never proves runtime, hosted CI, real-client, host or production readiness. P13 comprehensive acceptance ran on 2026-11-04: 198/198 on `wp69` and `wp71`, 6/6 on network main and child, Chromium/Firefox/WebKit, zero skips. Evidence: `.local/remote-test/20261104T102449Z` (`wp69`), `.local/remote-test/20261104T103736Z` (`wp71`), `.local/remote-test/20261104T105310Z` (network main), `.local/remote-test/20261104T105421Z` (network child).

| Claim | Target | Observed in this revision |
|---|---|---|
| PHP quality gate | `composer check` exit 0 | Per-run result only; record command, revision and environment. |
| Integration (`XNETVN_TEST_MULTISITE=0/1`), network, HTTP matrix | Green on both sites | Per-run result only; see run record. |
| UI suites `UI-FX/COM/OV/TL/KY/AC/ST/HP/NT` | Green per suite on `wp69` and `wp71` | Per-run result only; a focused tag never replaces full acceptance. |
| MCP role and SEO acceptance campaign (§2.8, §4.5) | Expected manifest equals the observed cases on `wp69`, `wp71`, `network-main` and `network-child`; no missing, unknown or duplicated case; `fail=0`, `skip=0`; owned cleanup verified | Per-run result only; the campaign report and the verifier output are the evidence. |
| MCP campaign metrics (§2.8) | Real p50, p95, sample count, memory delta and round count | Per-run result only; an unmeasured metric stays `unavailable` and never becomes zero. |
| Trusted TLS, real MCP clients (Claude, ChatGPT and others), hosted CI, host and production | Separately evidenced | Unverified in this revision; the lab exception of the self-signed certificate covers the lab clients only. |
| Axe automated checks | 0 axe violations on changed UI | Signal only; never read as WCAG conformance. |
| WCAG 2.2 AA conformance | Conform per [UI/UX Standards](standards/ui-ux-standards.md) | Not established; manual WCAG, keyboard and screen-reader verification remain separately owned. |
| P13 acceptance | Three browsers, two sites, multisite green | PASS — 198/198 on `wp69` and `wp71`; 6/6 on network main and child; Chromium, Firefox and WebKit; 0 failed, 0 skipped (2026-11-04 evidence paths above). |
| Manual, screen-reader, real-client, hosted CI, host, production | Separately evidenced | Unverified in this revision. |

Record each run with time, revision, site (`wp69`/`wp71`), browser, role, command and the `UI_BASE_URL` source (`rtl.py handoff`); keep credentials, hashes and argument values out of logs and artifacts.
