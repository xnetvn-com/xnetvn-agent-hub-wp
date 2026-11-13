# Project Documentation Index (Documentation Index)

> **Central documentation reference portal for xNetVN Agent Hub WP (`xnetvn-agent-hub-wp`).**
> *Note: the canonical project documentation is the English set in `docs/en/`; `docs/vi/` carries the Vietnamese translation. When the two disagree, the English document is authoritative and the Vietnamese one is brought back in line with it.*

---

## 🧭 Quick Navigation

```
docs/en/
├── 1. Basic documentation: README.md | INSTALL.md | ARCHITECTURE.md | TESTING.md | DEPLOYMENT.md | development*.md
├── 2. Operations & Configuration: ENVIRONMENT.md | GLOSSARY.md | TROUBLESHOOTING.md | FAQ.md | SUPPORT.md
├── 3. Technical standards: standards/ (coding, api, ui-ux, testing, logging, issue-storage)
├── 4. Policies: policies/ (security, privacy, incident, access, data-retention)
├── 5. Business guides: guides/ (developer, operation, deployment, scaling, monitoring, agent-skill-pack)
├── 6. International compliance: compliance/ (gdpr, iso27001, pci-dss, audit)
├── 7. Architecture & Design: architecture/ (overview, system-overview, DFD, ADR) | specs/
├── 8. Information security: security/ (threat-modeling, threat-model, vulnerability, secure-coding, http-acceptance)
├── 9. Operations & Runbooks: operations/ (runbooks, incident-response, backup, disaster-recovery) | operations.md
├── 10. Personnel & Onboarding: onboarding/ (checklist, responsibilities, tooling)
├── 11. Legal & History: legal/ | changelogs/ (release notes) | reports/ | templates/
```

---

## 📋 Detailed Catalog

### 1. Basic Documentation & Quick Start
- **[README.md](README.md):** Project overview, key features, architecture summary and getting started guide.
- **[INSTALL.md](INSTALL.md):** System requirements (PHP 8.2+, WP 6.9+), installation methods and database initialization.
- **[ARCHITECTURE.md](ARCHITECTURE.md):** Layered architecture, Capability Intersection and immutable security boundaries.
- **[TESTING.md](TESTING.md):** Test Pyramid, Unit Test, Integration Test, E2E Test and coverage measurement.
- **[DEPLOYMENT.md](DEPLOYMENT.md):** ZIP packaging, Nginx/Apache configuration, server clusters, Redis Cache and rollback.
- **[ENVIRONMENT.md](ENVIRONMENT.md):** wp-config constant catalog, wp_options table options and environment configuration.
- **[GLOSSARY.md](GLOSSARY.md):** Technical glossary (MCP, Abilities, Pepper, Capability Intersection,...).
- **[TROUBLESHOOTING.md](TROUBLESHOOTING.md):** Error code reference (401, 403, 429, 503) and diagnostic procedures.
- **[FAQ.md](FAQ.md):** Frequently asked questions about features and security.
- **[SUPPORT.md](SUPPORT.md):** Technical support channels and response time commitments (SLA).
- **[roadmap.md](roadmap.md):** Development roadmap with milestones M0–M7, from the foundation through OAuth and release preparation.
- **[CONTRIBUTING.md](CONTRIBUTING.md):** Source code contribution guide, Git workflow and conventional commits.
- **[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md):** Civil and inclusive community code of conduct.
- **[LICENSE](LICENSE):** GNU General Public License v2.0 or later open-source license.
- **[CHANGELOG.md](CHANGELOG.md):** Change history and releases following SemVer.
- **[development.md](development.md):** Development workflow, local checks and the remote lab boundary.
- **[development-coverage.md](development-coverage.md):** Coverage baseline, measurement and the numeric evidence.
- **[development-dependency-security.md](development-dependency-security.md):** Dependency, supply-chain and advisory policy.
- **[development-real-clients.md](development-real-clients.md):** Published MCP client harness and its limits.
- **[changelogs/release-notes/v0.1.0.md](changelogs/release-notes/v0.1.0.md):** 0.1.0 release notes.
- **[changelogs/release-notes/v1.0.0.md](changelogs/release-notes/v1.0.0.md):** 1.0.0 release notes.
- **[reports/mcp-specialized-skills-acceptance.md](reports/mcp-specialized-skills-acceptance.md):** MCP specialized skills acceptance report.
- **[reports/stab03-plugin-diagnosis-20261027.md](reports/stab03-plugin-diagnosis-20261027.md):** STAB-03 plugin-only diagnosis and measurements.

### 2. Technical Standards (`standards/`)
- **[standards/coding-standards.md](standards/coding-standards.md):** PHP, JS, CSS and Docblock source formatting standards.
- **[standards/api-standards.md](standards/api-standards.md):** API design standards (MCP JSON-RPC & Management REST).
- **[standards/ui-ux-standards.md](standards/ui-ux-standards.md):** WordPress Admin user interface standards, WCAG 2.2 AA.
- **[standards/testing-standards.md](standards/testing-standards.md):** Test writing standards, Given-When-Then principles and mocks.
- **[standards/logging-standards.md](standards/logging-standards.md):** Audit logging standards, IP masking and no secret storage.
- **[standards/issue-storage.md](standards/issue-storage.md):** Where tickets live, why the store is local-only and how a future migration runs.

### 3. Project Policies (`policies/`)
- **[policies/security-policy.md](policies/security-policy.md):** Information security policy, vulnerability handling and prohibited boundaries.
- **[policies/privacy-policy.md](policies/privacy-policy.md):** Personal data privacy policy under GDPR/CCPA.
- **[policies/incident-policy.md](policies/incident-policy.md):** Severity classification and incident response process P0 - P4.
- **[policies/access-policy.md](policies/access-policy.md):** Access authorization policy and the principle of least privilege.
- **[policies/data-retention-policy.md](policies/data-retention-policy.md):** Audit log retention and destruction policy.

### 4. Practical Guides (`guides/`)
- **[guides/developer-guide.md](guides/developer-guide.md):** Detailed guide for developers extending Ability Packs.
- **[guides/operation-guide.md](guides/operation-guide.md):** Daily system operations guide, key management and load monitoring.
- **[guides/deployment-guide.md](guides/deployment-guide.md):** Guide to setting up automated CI/CD pipelines.
- **[guides/scaling-guide.md](guides/scaling-guide.md):** Guide to horizontal system scaling for large loads.
- **[guides/monitoring-guide.md](guides/monitoring-guide.md):** Performance monitoring, p95/p99 latency and incident alerts.
- **[guides/agent-skill-pack.md](guides/agent-skill-pack.md):** Public guidance for connecting an AI agent to the MCP server and using its abilities safely.

### 5. Compliance & Legal (`compliance/` & `legal/`)
- **[compliance/gdpr-compliance.md](compliance/gdpr-compliance.md):** Meeting GDPR provisions (Right to Know, Right to Be Forgotten).
- **[compliance/iso27001-compliance.md](compliance/iso27001-compliance.md):** Security controls under the ISO/IEC 27001 framework.
- **[compliance/pci-dss-compliance.md](compliance/pci-dss-compliance.md):** Relevant data security principles.
- **[compliance/audit-process.md](compliance/audit-process.md):** Internal audit and action tracing process.
- **[legal/terms-of-service.md](legal/terms-of-service.md):** Terms of service and legal responsibilities when using the product.
- **[legal/third-party-notices.md](legal/third-party-notices.md):** Copyright notices for third-party libraries.

### 6. In-Depth Architecture & Security (`architecture/` & `security/`)
- **[architecture/system-overview.md](architecture/system-overview.md):** Detailed system architecture overview.
- **[architecture/data-flow-diagrams/main-dfd.md](architecture/data-flow-diagrams/main-dfd.md):** Main data flow diagram through the layers.
- **[architecture/decision-records/DR-001-initial-design.md](architecture/decision-records/DR-001-initial-design.md):** Initial architecture decision.
- **[architecture/overview.md](architecture/overview.md):** Concise architecture and immutable boundaries.
- **[architecture/ability-catalog.md](architecture/ability-catalog.md):** Ability catalog and risk tiers.
- **[architecture/adapter-notes.md](architecture/adapter-notes.md):** Adapter version notes and compatibility.
- **[architecture/api-keys.md](architecture/api-keys.md):** Key model and lifecycle.
- **[architecture/performance.md](architecture/performance.md):** Performance measurements and SLO context.
- **[architecture/persistence.md](architecture/persistence.md):** Persistence schema and storage boundaries.
- **[architecture/plugin-check-notes.md](architecture/plugin-check-notes.md):** Plugin Check findings and dispositions.
- **[security/http-acceptance.md](security/http-acceptance.md):** HTTP acceptance evidence.
- **[security/threat-model.md](security/threat-model.md):** Threat model summary.
- **[security/threat-modeling.md](security/threat-modeling.md):** Threat analysis using STRIDE and OWASP API Top 10.
- **[security/vulnerability-management.md](security/vulnerability-management.md):** Security vulnerability management and remediation process.
- **[security/secure-coding-guide.md](security/secure-coding-guide.md):** Secure coding handbook to prevent WordPress vulnerabilities.

**Architecture decision records:**
- **[adr/0001-record-decisions.md](adr/0001-record-decisions.md):** Record the frozen product decisions.
- **[adr/0002-abilities-and-adapter.md](adr/0002-abilities-and-adapter.md):** Abilities API and the official adapter.
- **[adr/0003-adapter-version-range.md](adr/0003-adapter-version-range.md):** Adapter version range.
- **[adr/0004-key-model.md](adr/0004-key-model.md):** Key model.
- **[adr/0005-capability-intersection.md](adr/0005-capability-intersection.md):** Capability intersection.
- **[adr/0006-runtime-floors.md](adr/0006-runtime-floors.md):** Runtime floors.
- **[adr/0007-oauth-deferred.md](adr/0007-oauth-deferred.md):** OAuth deferred.
- **[adr/0008-core-rest-dispatch.md](adr/0008-core-rest-dispatch.md):** Core REST dispatch.
- **[adr/0009-origin-and-auth-route.md](adr/0009-origin-and-auth-route.md):** Origin and auth route.
- **[adr/0010-minimal-audit.md](adr/0010-minimal-audit.md):** Minimal audit.
- **[adr/0011-toolsets-and-discovery.md](adr/0011-toolsets-and-discovery.md):** Toolsets and discovery.
- **[adr/0012-textual-ip.md](adr/0012-textual-ip.md):** Store IP addresses as text.
- **[adr/0013-content-type-policy.md](adr/0013-content-type-policy.md):** Content types, extended DTO and edit conflicts.
- **[adr/0014-media-policy.md](adr/0014-media-policy.md):** Bounded media and remote fetching.
- **[adr/0015-safe-tool-errors.md](adr/0015-safe-tool-errors.md):** Allowlisted execution reasons.
- **[adr/0016-comments-policy.md](adr/0016-comments-policy.md):** Comments policy and direct moderation.
- **[adr/0017-taxonomy-policy.md](adr/0017-taxonomy-policy.md):** Taxonomy policy.
- **[adr/0018-users-policy.md](adr/0018-users-policy.md):** Users policy.
- **[adr/0019-coverage-policy.md](adr/0019-coverage-policy.md):** Coverage enforcement policy.

**Milestone specifications:**
- **[specs/M1-core.md](specs/M1-core.md):** M1 core authentication, policy and MCP facade.
- **[specs/M2-content.md](specs/M2-content.md):** M2 content pack.
- **[specs/M2-taxonomy.md](specs/M2-taxonomy.md):** M2 taxonomy pack.
- **[specs/M3-comments.md](specs/M3-comments.md):** M3 comments pack.
- **[specs/M3-media.md](specs/M3-media.md):** M3 media pack.
- **[specs/M5-users.md](specs/M5-users.md):** M5 users pack.
- **[specs/content-media-implementation-plan.md](specs/content-media-implementation-plan.md):** Content and media implementation plan.

### 7. Operations & Incidents (`operations/`)
- **[operations/runbooks/serviceA-runbook.md](operations/runbooks/serviceA-runbook.md):** MCP Server service operations handbook.
- **[operations/incident-response.md](operations/incident-response.md):** Emergency incident response steps.
- **[operations/backup-restore.md](operations/backup-restore.md):** Key database and pepper backup and restore process.
- **[operations/disaster-recovery.md](operations/disaster-recovery.md):** Disaster recovery plan.
- **[operations/performance-slo.md](operations/performance-slo.md):** MCP performance SLO on the isolated lab and the verdict of each benchmark gate.
- **[operations/performance-analysis.md](operations/performance-analysis.md):** wp69 cache-on gate analysis, cost allocation of one `tools/list` request and the product rounds.
- **[operations/mcp-behavior-evals.md](operations/mcp-behavior-evals.md):** Offline MCP behaviour evaluation controller (98 FAKE cases, no model call).
- **[operations.md](operations.md):** Operations overview and incident entry points.

### 8. Personnel & Onboarding (`onboarding/`)
- **[onboarding/onboarding-checklist.md](onboarding/onboarding-checklist.md):** Step-by-step checklist for new project members.
- **[onboarding/team-responsibilities.md](onboarding/team-responsibilities.md):** Responsibility allocation across Core, Security and QA teams.
- **[onboarding/tooling-guide.md](onboarding/tooling-guide.md):** Internal tool installation and usage guide (Composer, PHPCS, RTL).

### 9. Standard Templates (`templates/`)
- **[templates/bug_report.md](templates/bug_report.md):** Standard technical bug report template.
- **[templates/feature_request.md](templates/feature_request.md):** Standard new feature request template.
- **[templates/refactor.md](templates/refactor.md):** Standard source code refactoring request template.
- **[templates/pr_template.md](templates/pr_template.md):** Standard Pull Request template.
- **[templates/meeting_minutes_template.md](templates/meeting_minutes_template.md):** Technical meeting minutes template.
- **[templates/report_template.md](templates/report_template.md):** Periodic technical report template.
- **[templates/issue_template.md](templates/issue_template.md):** Standard issue template.
- **Tickets:** project tickets are not kept in this tree. They live in the local store `.local/docs/en/issues/`, which Git ignores and never ships; list them with `issue_tool.py board` and read the storage rules in [standards/issue-storage.md](standards/issue-storage.md).
