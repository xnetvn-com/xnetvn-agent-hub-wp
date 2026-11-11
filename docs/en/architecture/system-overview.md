# System Architecture Overview

> **A detailed description of the technical architecture, relationships between components, and responsibility boundaries in xNetVN Agent Hub WP.**

---

## 1. Core Architecture Component Map

The system is organized into independent business subsystems that communicate through Interfaces (Contracts):

```
┌────────────────────────────────────────────────────────────────────────┐
│                        XNetVN\AgentHubWP\Plugin                        │
│                         (Coordinating Kernel)                         │
└──────────────────────────────────┬─────────────────────────────────────┘
                                   │ Initialize & load ServiceProviders
                                   ▼
┌───────────────────┬───────────────────┬────────────────────────────────┐
│ 1. AUTH           │ 2. MCP            │ 3. REST & ADMIN                │
│ - Authenticator   │ - ServerGateway   │ - ManagementGate               │
│ - KeyService      │ - Registry        │ - KeysController               │
│ - KeyGenerator    │ - SystemPack      │ - AuditController              │
│ - KeyHasher       │ - OutputPipeline  │ - SettingsController           │
│ - PepperStore     │ - SchemaBuilder   │ - Pages (Admin UI)             │
├───────────────────┼───────────────────┼────────────────────────────────┤
│ 4. GUARDS         │ 5. POLICY         │ 6. PERSISTENCE                 │
│ - RouteGate       │                   │ - WpdbKeyRepository            │
│ - TransportGuard  │ - Policy          │ - WpdbAuditRepository          │
│ - OriginGuard     │ - TierResolver    │ - WpSiteSettings               │
│ - SlidingWindow-  │ - HardDeny        │ - WpOwnerLock                  │
│   Limiter         │ - ToolVisibility  │ - Migrator                     │
└───────────────────┴───────────────────┴────────────────────────────────┘
```

---

## 2. Detailed Responsibilities of Each Subsystem

### 2.1 Authentication and API Key Subsystem (`src/Auth/`)
- **`KeyGenerator`:** Generate random 60-character strings conforming to `xnv_<key_id>_<secret>`.
- **`KeyHasher`:** Compute `HMAC-SHA256` signatures with a secret Pepper key.
- **`PepperStore`:** Store and read the independent Pepper key from the database (`autoload = 'no'`).
- **`Authenticator`:** Receive requests, extract Bearer Tokens, check rate limits, compare hashes, and initialize the `AuthContext` object.
- **`KeyService`:** Key lifecycle business logic: creation, atomic Rotate, Revoke, and permission scope updates (narrow-only).

### 2.2 MCP Gateway and Abilities Subsystem (`src/Mcp/`, `src/Abilities/`)
- **`ServerGateway`:** The only point in the entire plugin that interacts with the external `mcp-adapter` plugin. Encapsulates conversion from network protocol to internal Ability calls.
- **`Registry`:** Manage the catalog of permitted Abilities, register Ability Packs, and apply authorization policy.
- **`OutputPipeline`:** Output processing chain: remove sensitive fields, hide PII, format text (`rendered` / `raw`), and revalidate integrity against JSON Schema.

### 2.3 Policy and Permission Evaluation Subsystem (`src/Policy/`)
- **`TierResolver`:** Resolve and compare risk tiers (`read` < `write` < `destructive` < `admin`).
- **`Policy`:** Compute the intersection permission matrix: WordPress User Permissions $\cap$ API Key Scope $\cap$ Site Ceiling $\cap$ Toolset.
- **`HardDeny`:** A hard-deny list that blocks dangerous tool calls.

### 2.4 Persistence and Database Subsystem (`src/Persistence/`)
- **`WpdbKeyRepository`:** Operate on `wp_xnetvn_agent_hub_keys` using 100% Prepared Statements (`$wpdb->prepare`).
- **`WpdbAuditRepository`:** Operate on `wp_xnetvn_agent_hub_audit`, supporting batch writes at the shutdown hook.
- **`WpOwnerLock`:** Implement MySQL `GET_LOCK()` to protect concurrent user writes.
- **`Migrator`:** Manage forward-only database table schema versions.

### 2.5 Management and REST Subsystem (`src/Rest/`, `src/Admin/`)
- Provide internal REST API endpoints protected by Cookie + Nonce for WordPress administration screens.
- Build intuitive interfaces on the **User Profile** and **Agent Hub** pages conforming to WCAG 2.2 AA.
