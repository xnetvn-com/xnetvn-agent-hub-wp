# Change History (Changelog)

All notable changes to **xNetVN Agent Hub WP** will be recorded in this document.
The format is based on [Keep a Changelog](https://keepachangelog.com/vi/1.0.0/) and the project follows [Semantic Versioning (SemVer)](https://semver.org/lang/vi/).

---

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
- **REST & MCP authentication separation:** REST API management uses only Cookie + Nonce, key display exactly 1 time, atomic key rotation and password reauthentication for high-risk keys.
- **Audit & Privacy:** Audit log buffering, periodic cleanup (retention), personal data export and erasure under GDPR/CCPA, key lifecycle notifications and clean cron removal on uninstall.
- **Administration interface:** Profile page and 6 administration pages (Overview, Keys, Audit, Policy, Settings, Diagnostics) with plain JS secret dialogs, focus restoration, Clipboard API and safe DOM rendering meeting WCAG 2.2 AA accessibility standards.
- **M1 system toolset (5 Abilities):** `get-session-info`, `get-site-info`, `discover-abilities`, `get-ability-info`, `execute-ability`.
- **MCP Adapter gateway:** Integration with the official WordPress `mcp-adapter` on `/xnetvn-agent-hub-wp/mcp`.
- **OutputPipeline:** Sensitive field removal, data sanitization and strict output Schema validation.
- **Per-site database:** Initialize `*_keys` and `*_audit` tables, safe migration with atomic leases.
- **Secure API key model:** `HMAC-SHA256` hashing with an independent 32-byte secret Pepper per site.
- **Transport & Origin protection:** Mandatory TLS/HTTPS and Origin header checks.
- **Sliding window rate limiter:** 120 requests/minute/key and 10 failed attempts/minute/IP (Redis Object Cache support).
- **Complete Vietnamese documentation set:** Comprehensive construction of the `docs/vi/` documentation for Vietnamese readers.

### Changed
- Freeze the 1.0.0 release candidate under the display name xNetVN Agent Hub WP; the plugin slug, main file, folder and text domain stay `xnetvn-agent-hub-wp`.

### Fixed
- Compatibility handling for the `HTTP_AUTHORIZATION` header behind Apache/Nginx FastCGI.
- Ensure absolute atomicity during key rotation on MySQL InnoDB, preventing race conditions.
- Prevent Super Administrator permission bypass on WordPress Multisite when a subsite applies its own restrictive policy.
- Safely remove revoker references and notification preferences through the GDPR process without affecting other owners' keys.
- Clean up all cron events with arguments when uninstalling the plugin.

---

## [0.1.0] - 2026-10-04

### Added
- Initial foundation release (Pre-release foundation).
- Initialize the Kernel architecture, ServiceProvider, Domain Contracts and automated testing framework.

## Content/Media additions — included in 1.0.0

- Add 19 content tools and 7 private media tools, keeping core per-object permissions, closed schemas, approved DTOs and UTC conflict detection.
- Upload through raw REST bodies; HTTPS fetch is off by default, with full DNS checks, pre-send IP pinning and byte limits.
- Keep mutation receipts until the output checks finish; bound costly operations per site and key. Security acceptance and release remain separate gates.
