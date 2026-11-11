# Roadmap

The implementation is incremental. A milestone is complete only after its acceptance checks run successfully; planning does not imply implementation.

| Milestone | Deliverable | Acceptance |
|---|---|---|
| M0 | Bootstrap, tooling, packaging, CI and architecture | Unit/integration/lint/static/Plugin Check/ZIP gates |
| M1 | User keys, exact-route auth, policy, private abilities, MCP facade, audit and admin | N-01–N-35; two real MCP clients; role/object permission tests |
| M2 | Content, taxonomy and blocks | Strict pack contracts; real REST object authorization |
| M3 | Media and comments | SSRF/DNS/MIME/base64 limits and moderation permission tests |
| M4 | Appearance and settings | Allowlists, sensitive admin settings, documented editor-helper exclusions |
| M5 | Users (12 tools implemented, ADR-0018), plugins and maintenance | Role ceiling (RoleGuard/UserTargetGuard), banned-role lifecycle, core upgrader restrictions, independent security review |
| M6 | Resources/prompts, CLI, network, confirmation, translation, accessibility | Transport parity, per-site isolation and browser accessibility evidence |
| M7 | OAuth and optional integrations; release preparation | OAuth trust-boundary tests and integration-specific contracts |

The stable 1.0 gate requires clean Plugin Check, no outstanding critical security findings, Domain/Auth/Policy coverage >=90%, overall coverage >=80%, complete docs, measured performance and three real MCP clients. Performance targets are unverified until measured: auth/policy median <5 ms with object cache; about 60 tools listed <50 ms; additional memory <8 MB/request.

OAuth and cloud connectors remain outside phase-one authentication. Publishing to WordPress.org, changing repository settings and production deployment require explicit authorization.

---

# Project Development Roadmap (Product Roadmap)

> **Feature development plans, major Milestones and the future vision of xNetVN Agent Hub WP.**

---

## 📍 Long-Term Vision

Build a gold-standard MCP Server platform for the entire WordPress ecosystem, enabling AI Agents to perform all administration, content creation, SEO optimization, media processing and operational automation tasks safely, transparently and with Horizontal Scaling to serve billions of requests per day.

---

## 🎯 Detailed Development Milestones

```
[M1: Core Foundation]  ──►  [M2: Content & Taxonomy]  ──►  [M3: Media & Comments]  ──►  [M4: Appearance & Site]
     (Implemented; acceptance pending)   (Current; blocks pending)   (Implemented; acceptance pending)   (Planned)
```

---

### Milestone 1: Core Foundation (M1 - Core Foundation) — [IMPLEMENTED; ACCEPTANCE PENDING]
- [x] Clean Kernel architecture with deterministically ordered ServiceProvider loading.
- [x] Initialize `*_keys` and `*_audit` databases with per-site prefixes.
- [x] Generate an independent 32-byte secret Pepper for each site.
- [x] API key model in `xnv_<key_id>_<secret>` format with `HMAC-SHA256` hashing.
- [x] Route separation: MCP routes accept Bearer Tokens; REST routes accept Cookie + Nonce.
- [x] Capability Intersection mechanism and Risk Tiers.
- [x] Sliding Window Limiter per Key and IP.
- [x] TransportGuard and OriginGuard.
- [x] Core system toolset (`SystemPack` with 5 abilities: `get-session-info`, `get-site-info`, `discover-abilities`, `get-ability-info`, `execute-ability`).
- [x] Integrate sensitive output data filtering (`OutputPipeline`).
- [x] Asynchronous audit logging (`AuditLogger`) and periodic cleanup (`Retention`).
- [x] Integrate WordPress GDPR personal data export and erasure.
- [x] Admin Pages & user Profile key management interfaces meeting WCAG 2.2 AA.

---

### Milestone 2: Content, Taxonomy & Blocks (M2 - Content, Taxonomy, Blocks) — [IN PROGRESS 🔄]
- [ ] Build the `RestBackedAbility` helper for safe internal calls through `rest_do_request()`.
- [x] Implement `ContentPack` (19 abilities; acceptance pending):
  - List post types and statuses (`list-post-types`, `list-post-statuses`).
  - Read, search and filter post lists (`list-posts`, `get-post`, `search-content`).
  - Create, update, trash, restore and permanently delete posts (`create-post`, `update-post`, `trash-post`, `restore-post`, `delete-post`).
  - Safely duplicate posts (`duplicate-post`) and perform bounded bulk updates (`bulk-update-posts`).
  - Manage post revisions (`list-revisions`, `get-revision`, `restore-revision`).
  - Manage featured media and sticky state (`set-featured-media`, `stick-post`).
  - Manage post metadata (`get-post-meta`, `update-post-meta`) against a registered allowlist.
- [x] Implement `TaxonomyPack` (9 abilities, ADR-0017):
  - List taxonomies and terms (`list-taxonomies`, `list-terms`, `get-term`).
  - Create, edit, assign and delete terms (`create-term`, `update-term`, `assign-post-terms`, `delete-term`).
  - Bulk ensure and bulk assign (`bulk-create-terms`, `bulk-assign-post-terms`) with caps and per-item results.
- [ ] Implement `BlocksPack` (6 abilities):
  - Look up blocks and block patterns (`list-block-types`, `list-block-patterns`, `get-block-pattern`).
  - Safely parse, validate and render blocks (`parse-blocks`, `validate-blocks`, `render-blocks`).

---

### Milestone 3: Multimedia & Interaction (M3 - Media, Comments & Moderation) — [IMPLEMENTED; ACCEPTANCE PENDING]
- [x] Implement `MediaPack` (7 abilities):
  - Browse and retrieve attachment information (`list-media`, `get-media`).
  - Upload multimedia files from URLs or binary data with MIME checks and strict size limits.
  - Update and delete attachments.
- [x] Implement `CommentsPack` (10 abilities, ADR-0016):
  - Read, filter and retrieve comments (`list-comments`, `get-comment`).
  - Create, reply to and edit comments (`create-comment`, `reply-comment`, `update-comment`).
  - Moderate comments (approve, spam, trash, permanently delete, bulk moderation).

---

### Milestone 4: Appearance, Settings & System Extensions (M4 - Appearance & Settings) — [PLANNED 📅]
- [ ] Implement `AppearancePack`:
  - Manage Themes, Menus, Menu Locations, Widgets, Sidebars.
  - Manage Block Templates, Template Parts, Global Styles and Custom CSS.
- [ ] Implement `SettingsPack`:
  - Read and update allowlisted website options protected by `manage_options`.
- [ ] OAuth 2.0 Authorization Server is deferred to M7 under ADR-0007.
- [ ] Integration SDKs for Python and TypeScript.

## Content and media implementation authority (2026-10-08)

E-01 through E-08 and early implementation were approved. Content has 19 tools; media has seven including get-media-upload-limits. Implementation and runtime acceptance remain separate. M1 independent security acceptance still gates release. Taxonomy, Blocks and Comments remain outside this feature. Remote media requires all-answer DNS validation, IP pinning, strict MIME/base64/file limits and MD/SS regression evidence.

Status update (2026-11-03): taxonomy (ADR-0017) and comments (ADR-0016) are implemented with acceptance pending, and blocks remain planned. This update does not change the 2026-10-08 decision record above.
