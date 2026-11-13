# Changelog

Changes use Semantic Versioning and the Keep a Changelog format.

## [Unreleased]

### Added
- Public AI-agent skill pack under `skills/xnetvn-agent-hub-wp/` that explains the MCP endpoint, the ability model, the workflow rules, the safety contract and the troubleshooting vocabulary, using placeholders only.
- WordPress.org listing preparation for the 1.0.0 submission: rewritten public readme, English user guide, banner and icon, ten captured screenshots, Live Preview blueprint and a lab capture fixture; `.wordpress-org` stays out of the release ZIP.

### Changed
- Add a deterministic `composer check:skills` gate that validates the five public skill-pack files and the canonical ability catalog with a 29-case self-test, and include it in `composer check` and the CI quality job; `.distignore` and the release ZIP deny list now name `skills`, and the packaging regression proves a ZIP that contains `skills/` is rejected.
- Move the pull request labeler onto the self-hosted runner so a hosted-minute or billing fault stops blocking every pull request. The workflow keeps `pull_request_target`, never checks out pull request code, holds only `contents: read` and `pull-requests: write`, and now runs only for pull requests whose head branch lives in this repository; the workflow-hardening contract enforces all four conditions.
- Relax the complete-HTTPS p50 acceptance budget from `< 50 ms` to `< 55 ms` under the project owner's 2026-11-09 authorization ("Bạn quyết STAB-03: hạ ngưỡng"); the other five benchmark gates, the sample count, the warmup and the measurement method are unchanged, and the STAB-03 exception is retired after three valid rounds at 52.834670 / 53.161070 / 53.275170 ms.
- Split the CI runner switch in two: `XNETVN_CI_RUNNER` moves the jobs that need only PHP, Node and Composer, and `XNETVN_CI_RUNNER_DOCKER` must also be `true` before the two `wp-env` matrices leave the hosted runner. A host whose container network cannot reach its own containers keeps the integration jobs hosted without holding back the rest, and a manual `self_hosted_trial` dispatch still selects the self-hosted runner for both kinds.
- Move `ci.yml:plan`, `ci.yml:ci-result` and `coverage-check.yml:plan` onto the same guarded self-hosted switch. They hold `contents: read` or nothing and never check out untrusted fork code, and a hosted `plan` that the account cannot start was stopping the whole source gate while every expensive job was cost-free.
- Move the `workflow-lint.yml` jobs onto the same guarded switch, so the automation guard runs instead of failing at a hosted job the account cannot start. The draft release and the Pages deployment stay on hosted runners because they hold a write token or an OIDC identity; the labeler is handled in the entry above.

### Fixed
- Keep activation independent of the MCP Adapter: the plugin no longer declares the hard `Requires Plugins` header, so a site without the adapter can activate it, see the requirement notice, and keep MCP disabled until a supported adapter is active.
- Unslash the last-used user agent once at the request boundary, so a user agent containing quotes or backslashes is stored consistently with the audit row instead of keeping WordPress slashes.

### Security
- Harden management REST nonce input handling: a present `X-WP-Nonce` header must contain exactly one nonempty string and never falls back to a parameter; an absent header may use one nonempty string `_wpnonce`; sanitizer-changing values are rejected and only the unchanged candidate reaches `wp_verify_nonce()`. Cookie, capability, owner and error-vocabulary boundaries are unchanged, and no vulnerability is claimed.

## [1.0.0] - 2026-11-04

### Added
- Private twelve-tool Users pack with Admin protection zone, assignable-role guard, capability-less banned role with snapshot and session termination, owner-only profile tools, explicit-role creation with random passwords, safe error vocabulary and deny-only user filters.
- Private nine-tool Taxonomy pack with managed-taxonomy resolution, hierarchical parent guards, default-term protection, dry-run deletion, int-only direct assignment with default refill and trash guard, bulk creation/assignment caps and registered-only term meta.
- Private ten-tool Comments pack with per-comment object permissions, direct core moderation preserving author network identity, default review-held creation, forced KSES, two-step permanent deletion and cutoff-bounded spam/trash purge.
- Private 19-tool Content and seven-tool Media packs with current core object permissions, strict schemas, safe DTOs, UTC conflict detection, bounded raw-body upload and deny-only extension hooks.
- Request-private HTTPS fetching with all-answer DNS policy, pre-send cURL pinning, streamed bounds and default-off configuration.
- Deactivation clears the plugin's four owned scheduled events on the current site; network-wide deactivation visits every site in bounded pages with `switch_to_blog()`/`restore_current_blog()` cleanup in `finally`. Keys, audit rows and settings are not deleted.
- Remote media fetch evaluates the site's `WP_HTTP_BLOCK_EXTERNAL` and `WP_ACCESSIBLE_HOSTS` settings before DNS resolution and on every redirect hop; an accessible host never bypasses the private-address and all-answer DNS policy.
- Trusted mutation receipts through projection/output validation and per-key expensive-work concurrency slots.
- Cookie/nonce-only management REST, one-time key disclosure, narrowed atomic edits, password reauthentication and shared owner mutation exclusion.
- Audit buffering/retention, privacy export/erasure, configurable lifecycle notifications and default/preserve uninstall with complete cron cleanup.
- Profile and six administration pages with native secret dialogs, focus restoration, Clipboard API and safe DOM rendering.
- Remote-test-lab configuration and isolated release-package validation for WordPress6.9/7.1.2.
- Immutable private ability contracts, deny-only scope policy, raising-only tier resolution and a guarded WordPress registry.
- Five system abilities: session/site info, permitted discovery, ability details and policy-bound meta execution.
- Version-gated official-adapter facade on `/xnetvn-agent-hub-wp/mcp`, HTTP/STDIO tool filtering and logical structured-output validation.
- Output redaction/truncation that revalidates the final schema and refuses impossible safe output.
- Explicit permanent-operation denylist, admin-risk roster and callback-identity conflict handling.
- Per-site key/audit database schema and forward-only migration with an atomic ownership-bound lease.
- Prepared credential repositories with narrow-only scope updates, fresh revocation readback and five-minute last-use throttling.
- Dynamic `use_xnetvn_agent_hub_wp` / `manage_xnetvn_agent_hub_wp` capabilities and validated site settings.
- Exact MCP-route Origin/TLS guards, separate opaque pepper, bearer identity, failed-auth/key budgets and safe REST response headers.
- `xnetvn_agent_hub_wp_auth_failed` action carries only an internal failure identifier and validated peer IP.
- `xnetvn_agent_hub_wp_allowed_origins` filter provides explicit additional origin approval.
- Fail-closed PHP and WordPress requirement checks with delayed, escaped administration notices.
- Service-provider kernel and `xnetvn_agent_hub_wp_loaded` action, dispatched once per kernel registration.
- Composer development tooling, PHPUnit tests, WordPress integration environment and production ZIP verification.
- Architecture, security and contribution documentation; continuous integration definitions.
- Real Redis concurrency, site-isolation, outage and sixty-tool HTTPS benchmark fixtures with numeric evidence and explicit unmet performance targets.
- Opt-in toolbox coverage collection across unit and both single/multisite core versions, complete source filters and numeric per-suite evidence.
- MCP performance SLO reference for the wp69 cache-on flow: the original benchmark gates kept unchanged, with the shared-lab and the 2026-10-20 isolated-lab measurements recorded side by side and the open STAB-03 warning stated as still open.

### Changed
- Freeze the 1.0.0 release candidate under the display name `xNetVN Agent Hub WP`; the plugin slug, main file, folder and text domain stay `xnetvn-agent-hub-wp`.
- Consolidate the GitHub Actions source gate into `ci.yml`, validate feature branches through the pull request event only, serialise `wp-env` jobs on the shared runner, remove duplicate coverage preconditions, and keep GitHub Pages deployment behind the `XNETVN_PAGES_ENABLED` repository variable.
- Select the CI runner through the boolean `XNETVN_CI_RUNNER` switch with the runner labels fixed in code and an `ubuntu-24.04` fallback, scale the two `wp-env` matrices with `XNETVN_CI_MAX_PARALLEL`, keep the write-token, OIDC and `pull_request_target` jobs on hosted runners, run ten boundary legs on pull requests and the full sixteen-leg matrix on the default branch, skip the source matrix for documentation-only pull requests, and stop a pull-request matrix at the first failure.
- Share one version consistency check across `quality`, the changelog validation and the compliance workflow, so a mismatch between the plugin header, runtime constant, readme stable tag and changelog fails before the tag.
- Skip the coverage collectors for documentation-only pull requests while keeping the required coverage gate reporting a clear, artifact-free success; source pull requests keep all four suites.
- Reset the managed `wp-env` cache checkouts and remove the project's Docker volumes before start, keeping images and local files, so a stale database volume cannot block the next environment without forcing an image pull.
- Prefer IPv4 and disable the 250 ms address-family auto-selection in the `wp-env` jobs (`NODE_OPTIONS=--dns-result-order=ipv4first --no-network-family-autoselection`), so a runner with broken IPv6 egress does not hang on the WordPress download.
- Scope the Composer, static-analysis and Semgrep caches by runner environment, so a cache written on a self-hosted runner is never restored on a hosted runner and vice versa.
- Expose stable branch-protection contexts: `ci-result` aggregates every source-gate job, and `workflow-lint` always reports while its expensive steps stay behind change detection.
- Scan the pull-request commit range and the final merge tree for leaked secrets, require both SBOM generators and all four CycloneDX/SPDX files before a draft release, and fail an aggregate when a mandatory job is unexpectedly skipped.
- Harden workflow self-checks: inspect shell-only steps for untrusted interpolation, map every required context to a real non-matrix pull-request job, and cover docs-only, rename, delete and unknown-base change detection.
- Add the pinned MIT `zizmor` audit with a medium severity floor to the workflow self-check, and keep the deployment guide aligned with the authoritative workflow layout instead of a stale copy-paste example.

### Security
- Judge pull-request secret scans with the base revision's Gitleaks configuration and ignore file, so a pull request cannot replace the policy that scans its own commits.
- Pass SBOM manifest metadata to the shell through environment variables, stop persisting checkout credentials, run the pull-request labeler on a GitHub-hosted runner, and scope write permissions to the jobs that need them.

### Fixed
- Align the native coverage gate with the documented 1.0 policy (ADR-0019): a pull request must keep combined coverage at or above 80%, `Domain`/`Auth`/`Policy` at or above 90%, each integration suite at or above 70%, and never regress below the recorded baseline; the unit suite is measured without an absolute overall floor. New behaviour tests for the ability registry, the output pipeline, authentication wiring and the domain constructors restore the anti-regression measures.
- Keep the text of the plugin buttons readable in every admin colour scheme of WordPress: the buttons of the hub state their own colours instead of inheriting an accent that can drop below 4.5:1, and the variant `forced-colors: active` declares the colours of the system palette so that the page and the accessibility scan agree.
- Turn every animation and every transition of the hub off when the system asks for reduced motion, including the check boxes of the core whose rule is more specific than the rule of the plugin.
- Resolve one live capability decision per user, capability and object inside a single advertised tool list round instead of once per listed tool; the decision result, the denial results and the live capability check of the tool call, the execution path, the permission callback and the discovery outside the MCP route are unchanged.
- Build the MCP server only for the exact MCP route and only with the tool names the presented delegation scope can use, instead of building the whole ability catalog on every REST request; the visible tool list, its payload and the denial results are unchanged, and the ability lookup outside the MCP route keeps its recorded results.
- Read each site policy setting once per request in the policy decision path instead of once per listed tool; the decision result and the live capability check are unchanged.
- Resolve newly reviewed development-tool advisories with scoped dependency pins and verified wp-env Git/YAML API compatibility.
- Allow the enabled fallback credential header when Apache populates empty Authorization variables; preserve malformed nonempty header precedence.
- Make hidden and unknown tool failures uniform across legacy/modern HTTP status variants while preserving unrelated wire validation errors.
- Apply explicit site delegation restrictions before WordPress's multisite super-administrator shortcut.
- Refuse partial reconstruction of corrupt security policy and bind reviewed abilities against callback replacement.
- Commit key rotation atomically before disclosure/events; reject unsupported storage and enclosing transactions.
- Erase revoker references and notification preferences/markers without deleting another owner's key.
- Audit meta-tool target identities and remove argument-bearing cron events on uninstall.
- Reuse validation only for exactly identical freshly read site policy values, retaining live permissions and corruption rejection.
- Bound test instrumentation ownership and cleanup; reset client credentials between requests and retain missing measurements as unavailable.
- Load WordPress admin user functions before checking editable roles so unban and role changes work over MCP/REST exactly as in admin context while staying fail-closed when unavailable; recheck promote_user immediately before role writes.

## [0.1.0] - 2026-10-04

### Added
- Initial pre-release foundation. MCP tools and user API keys are planned and unavailable at this milestone.
