# xNetVN Agent Hub WP

[![Version](https://img.shields.io/badge/version-v1.0.0-blue.svg)](CHANGELOG.md)
[![PHP](https://img.shields.io/badge/PHP-%3E%3D8.2-777bb4.svg)](INSTALL.md)
[![WordPress](https://img.shields.io/badge/WordPress-%3E%3D6.9-21759b.svg)](INSTALL.md)
[![License: GPL-2.0-or-later](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)](LICENSE)
[![Security: OWASP](https://img.shields.io/badge/Security-OWASP%20API-red.svg)](security/threat-modeling.md)

Formerly xNetVN Agent Hub for WordPress.

> **xNetVN Agent Hub WP** is a foundational WordPress plugin providing a standardized, enterprise-secure **Model Context Protocol (MCP)** server, allowing Artificial Intelligence agents (AI Agents) to interact with WordPress in a controlled way with fine-grained permissions through the Abilities API.

---

## 📌 Table of Contents

1. [Project Overview](#-project-overview)
2. [Key Features](#-key-features)
3. [Architecture & Security Boundaries](#-architecture--security-boundaries)
4. [System Requirements](#-system-requirements)
5. [Quick Installation & Activation](#-quick-installation--activation)
6. [Usage Guide](guides/operation-guide.md)
7. [API Key Management (API Keys)](#-api-key-management-api-keys)
8. [Testing & Quality Assurance](#-testing--quality-assurance)
9. [Documentation Directory Structure](#-documentation-directory-structure)
10. [Contributing & Copyright](#-contributing--copyright)

---

## 🚀 Project Overview

As AI Agent models (such as Claude Desktop, Cursor, OpenAI Agents, etc.) develop rapidly, connecting AI to an enterprise's core content management CMS requires a strict protection layer:
- Prevent AI from interfering with source code or server infrastructure.
- Control permissions precisely according to each actual WordPress account.
- Record a transparent audit trail, respecting personal data privacy (GDPR/CCPA).
- Completely prevent Prompt Injection, sensitive data leaks and denial-of-service (DoS) attacks.

**xNetVN Agent Hub WP** is designed around Clean Architecture and Hexagonal Architecture, operates through the official WordPress MCP adapter (`mcp-adapter`) and provides Abilities classified by Risk Tiers and Toolsets.

---

## ✨ Key Features

- **User-Owned API Keys:**
  - API keys in `xnv_<key_id>_<secret>` format (60 characters, Base32 + Base64URL).
  - Hashing using `HMAC-SHA256` combined with an independent 32-byte Pepper for each website.
  - Secrets appear only 1 time when issued or rotated (rotation).
- **Capability Intersection:**
  - Actual Agent permissions are the intersection: $\text{User Permissions} \cap \text{Key Scope} \cap \text{Site Ceiling} \cap \text{Toolset}$
  - Risk Tiers: `read` < `write` < `destructive` < `admin` (the default site ceiling is `destructive`; `admin` is disabled by default).
- **Route & Authentication Separation (Transport Separation):**
  - MCP route (`/wp-json/xnetvn-agent-hub-wp/mcp`): Accepts only Bearer Tokens, rejects all Cookies.
  - Management REST routes (`/wp-json/xnetvn-agent-hub-wp/v1/*`): Accept only a valid login Cookie + `wp_rest` nonce.
- **Sliding Window Rate Limiting:**
  - Limit of 120 requests/minute per API key.
  - Limit of 10 failed authentication attempts/minute per IP address.
- **Origin & Transport Guard:**
  - TLS/HTTPS is mandatory in production.
  - Strict Origin checks; reject `null` Origin or Origins outside the trusted list.
- **Audit & Privacy:**
  - Asynchronous audit logging at request shutdown (shutdown hook).
  - Never store argument values, passwords, hashes or tokens.
  - Integrates standard WordPress GDPR Personal Data Export and Personal Data Erasure tools.
- **M1 System Toolset (5 Core System Abilities):**
  1. `xnetvn/get-session-info`: Retrieve session information, permissions and remaining quotas.
  2. `xnetvn/get-site-info`: Retrieve basic website information and WordPress version.
  3. `xnetvn/discover-abilities`: Discover available tools.
  4. `xnetvn/get-ability-info`: Retrieve the detailed JSON Schema of a specific tool.
  5. `xnetvn/execute-ability`: Delegate execution of an allowed tool with strict schema enforcement.
- **Implemented business packs (57 tools beyond the system set):**
  - **Content (19):** posts, revisions, metadata, featured media, sticky state, bulk updates and search.
  - **Taxonomy (9):** taxonomies, terms, assignment and bounded bulk operations.
  - **Media (7):** listing, reading, updating, URL/data upload, limits and deletion.
  - **Comments (10):** reading, creation, replies, editing, moderation, bulk moderation and permanent deletion.
  - **Users (12):** listing, profiles, roles, status and session administration, with account changes gated by the Admin tier.

---

## 🏛️ Architecture & Security Boundaries

```
[ AI Agent Client ]
        │  (HTTP POST /wp-json/xnetvn-agent-hub-wp/mcp)
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 1. Guards: RouteGate, OriginGuard, TransportGuard (TLS)    │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 2. Authenticator: Bearer Token Parser, HMAC-SHA256, Limiter │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 3. ServerGateway (MCP Adapter Facade)                       │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 4. Registry & Policy: Capability Intersection Engine        │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 5. Ability Packs: SystemPack / ContentPack (REST Dispatch)  │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 6. OutputPipeline: Redaction, Schema Validation, Boundaries  │
└─────────────────────────────────────────────────────────────┘
        │
        ▼
┌─────────────────────────────────────────────────────────────┐
│ 7. AuditLogger (Shutdown Hook): Logging without secrets    │
└─────────────────────────────────────────────────────────────┘
```

See [Architecture Documentation (ARCHITECTURE.md)](ARCHITECTURE.md) and [Threat Modeling (threat-modeling.md)](security/threat-modeling.md) for details.

---

## 💻 System Requirements

| Component | Minimum Requirement | Recommendation |
|---|---|---|
| **PHP** | 8.2 (with extensions: `mysqli`, `json`, `hash`, `pcre`) | 8.3 or later |
| **WordPress** | 6.9 | 6.9 or 7.x |
| **Database** | MySQL 8.0+ / MariaDB 10.6+ (InnoDB support) | MySQL 8.4 LTS |
| **Plugin dependency** | `mcp-adapter` (Official WordPress MCP Adapter) | Activate a stable version |
| **Web Server** | Nginx / Apache with valid HTTPS | Nginx + PHP-FPM + Redis Cache |

---

## 📦 Quick Installation & Activation

### Step 1: Install the Plugin Dependency
Install and activate the `mcp-adapter` plugin on your WordPress website.

### Step 2: Download and Install xNetVN Agent Hub
Download the release zip or clone the source into `wp-content/plugins/xnetvn-agent-hub-wp/`:
```bash
cd wp-content/plugins/
git clone https://github.com/xnetvn-com/xnetvn-agent-hub-wp.git
cd xnetvn-agent-hub-wp
composer install --no-dev --optimize-autoloader
```

### Step 3: Activate the Plugin
Activate the plugin through WP-CLI or the WordPress administration screen:
```bash
wp plugin activate xnetvn-agent-hub-wp
```

See [Installation Documentation (INSTALL.md)](INSTALL.md) for detailed instructions.

---

## 🔑 API Key Management (API Keys)

1. Log in to WordPress administration with an account having `edit_posts` or higher permissions.
2. Navigate to your **Profile** or **Agent Hub → API Keys**.
3. Click **"Create New Key"**:
   - Give the key a memorable name.
   - Select the maximum risk tier (`read`, `write`, `destructive`).
   - Select allowed toolsets (`system`, `content`,...).
   - Set an expiry period (maximum 365 days).
   - Optional: Enter the CIDR IP ranges allowed to use the key.
4. **Important:** Copy the secret API key displayed in the dialog. This key will **never be displayed again** after closing the dialog!

---

## 🧪 Testing & Quality Assurance

The project applies a comprehensive TDD testing process:
```bash
# Check source code formatting (PHPCS)
composer lint

# Highest-level static analysis (PHPStan Level 8 without a baseline)
composer analyse

# Run Unit Tests
composer test:unit

# Run Integration Tests in the WordPress environment
composer test:integration

# Run the entire integrated check suite
composer check
```

See [Testing Documentation (TESTING.md)](TESTING.md) for more.

---

## 📚 Documentation Directory Structure

English documentation is organized in `docs/en/`, with corresponding Vietnamese translations in `docs/vi/`:
- **[Detailed documentation index (index.md)](index.md)**
- **Technical standards (`standards/`):** [Coding Standards](standards/coding-standards.md), [API Standards](standards/api-standards.md), [Testing Standards](standards/testing-standards.md).
- **Policies (`policies/`):** [Security Policy](policies/security-policy.md), [Privacy Policy](policies/privacy-policy.md), [Data Retention](policies/data-retention-policy.md).
- **Guides (`guides/`):** [Developer Guide](guides/developer-guide.md), [Operation Guide](guides/operation-guide.md), [Deployment Guide](guides/deployment-guide.md), [AI Agent Skill Pack](guides/agent-skill-pack.md).
- **Compliance (`compliance/`):** [GDPR Compliance](compliance/gdpr-compliance.md), [ISO 27001](compliance/iso27001-compliance.md), [PCI DSS](compliance/pci-dss-compliance.md).
- **Operations & Incident Response (`operations/`):** [Runbook](operations/runbooks/serviceA-runbook.md), [Incident Response](operations/incident-response.md), [Backup & Restore](operations/backup-restore.md).

---

## 📄 Contributing & Copyright

- See the source code contribution guide at **[CONTRIBUTING.md](CONTRIBUTING.md)**.
- See the community code of conduct at **[CODE_OF_CONDUCT.md](CODE_OF_CONDUCT.md)**.
- Copyright license: **GNU General Public License v2.0 or later (GPL-2.0-or-later)**. See **[LICENSE](LICENSE)**.

**Copyright:**
```
Copyright (c) 2026 xNetVN Inc.
Website: https://xnetvn.com/
Licensing contact: license@xnetvn.net
```
