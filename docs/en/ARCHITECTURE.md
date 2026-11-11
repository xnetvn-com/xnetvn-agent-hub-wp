# System Architecture & Immutable Boundaries

> **Specification of the overall architecture, layered model, authorization principles and immutable security boundaries of xNetVN Agent Hub WP.**

---

## 1. Core Design Principles

1. **Clean Architecture & Hexagonal Architecture (Ports and Adapters):**
   - Completely separate the Domain Logic layer from infrastructure implementation details (WordPress Database, HTTP Request, MCP Adapter).
   - The Domain layer (`src/Domain/`) does not depend on any WordPress functions or constants.
   - All interactions with databases and external systems go through Contracts (Interfaces) in `src/Contracts/`.
2. **Principle of Least Privilege:**
   - AI Agents receive only the minimum permissions necessary to complete their tasks.
   - Arbitrary code execution, direct SQL queries and server system configuration edits are prohibited.
3. **Capability Intersection:**
   - An API key can never grant permissions beyond those of the user who owns it.
4. **Defense in Depth:**
   - Each MCP request must pass 7 consecutive control stages: Route Matcher -> Transport/TLS Guard -> Origin Guard -> Authenticator (Token Parsing & HMAC) -> Rate Limiter -> Capability/Policy Engine -> Object-level Authorization.

---

## 2. Layered Structure

```
┌─────────────────────────────────────────────────────────────┐
│ 1. HTTP / TRANSPORT LAYER                                  │
│    - RouteGate: Exact /xnetvn-agent-hub-wp/mcp route match  │
│    - TransportGuard: Require TLS/HTTPS                     │
│    - OriginGuard: Check the Origin header                  │
└─────────────────────────────────────────────────────────────┘
                               ▲
                               │
┌─────────────────────────────────────────────────────────────┐
│ 2. AUTH & RATE LIMIT LAYER                                 │
│    - TokenParser: Parse xnv_<key_id>_<secret> syntax        │
│    - KeyHasher & PepperStore: HMAC-SHA256, timing-safe      │
│    - Authenticator: Create request-local AuthContext       │
│    - SlidingWindowLimiter: 120 req/min/key, 10 fail/min/ip  │
└─────────────────────────────────────────────────────────────┘
                               ▲
                               │
┌─────────────────────────────────────────────────────────────┐
│ 3. MCP GATEWAY & POLICY LAYER                              │
│    - ServerGateway: Gateway to mcp-adapter                 │
│    - Registry: Manage the catalog of reviewed Abilities    │
│    - Policy & TierResolver: Calculate permission intersection│
│    - HardDeny & ToolVisibility: Exclude unauthorized access│
└─────────────────────────────────────────────────────────────┘
                               ▲
                               │
┌─────────────────────────────────────────────────────────────┐
│ 4. ABILITY PACKS LAYER                                     │
│    - SystemPack: 5 system tools (Discovery, Info, Exec)     │
│    - ContentPack (19): posts, revisions, meta, media links  │
│    - Taxonomy (9), Media (7), Comments (10), Users (12)     │
│    - OutputPipeline: Filter data, check Schema, Redaction   │
└─────────────────────────────────────────────────────────────┘
                               ▲
                               │
┌─────────────────────────────────────────────────────────────┐
│ 5. PERSISTENCE & AUDIT LAYER                               │
│    - WpdbKeyRepository: API key CRUD with Prepared SQL     │
│    - WpdbAuditRepository: Audit logging without secrets    │
│    - WpOwnerLock: Distributed lock for concurrent updates  │
│    - WpPrivacyStore: GDPR Export & Erasure support          │
└─────────────────────────────────────────────────────────────┘
```

---

## 3. Immutable Security Boundaries (Security Invariants)

The system commits to maintaining the following security boundaries absolutely in every version:

### 3.1 Route Authentication Boundary (Exact Route Matching)
- **MCP route (`/wp-json/xnetvn-agent-hub-wp/mcp`):**
  - Accept only the `Authorization: Bearer` header; tokens use the `xnv_<key_id>_<secret>` format.
  - Reject all requests using session Cookies, even with a valid `wp_rest` nonce. This completely prevents CSRF attacks through the user's browser.
- **Management REST routes (`/wp-json/xnetvn-agent-hub-wp/v1/*`):**
  - Accept only a logged-in WordPress user's session Cookie + a valid `wp_rest` nonce.
  - Reject authentication using a Bearer API Key.

### 3.2 API Key & Cryptographic Standards
- A key consists of a public identifier (`key_id`, 12 Base32 characters) and a secret (`secret`, 32 random bytes encoded as unpadded Base64URL).
- Store only the hash:
  $$\text{secret\_hash} = \text{HMAC-SHA256}(\text{secret}, \text{pepper})$$
- Generate an independent random 32-byte Pepper for each site (`xnetvn_agent_hub_wp_pepper`), separate from WordPress AUTH_SALT / SECURE_AUTH_SALT.
- Use `hash_equals()` for hash comparison to prevent timing attacks.
- If key_id is not found in the DB, the system still performs a dummy HMAC calculation to maintain uniform response timing.

### 3.3 Independent Row-level Locking During Key Rotation (Atomic Key Rotation)
- API key rotation is the most critical action.
- The system requires an independent `mysqli` connection supporting ACID transactions on InnoDB tables.
- Lock the current record with `SELECT ... FOR UPDATE`, insert the new key record and set a 24-hour grace-period expiry (`grace period`) for the old key within the same transaction.

### 3.4 Absolutely Prohibited Tools (Permanent Exclusions)
- **Never provide arbitrary code execution tools:** No `eval()`, `exec()`, `passthru()`, `system()`, `shell_exec()` functions.
- **Never allow direct SQL queries:** No tool sends arbitrary SQL statements to the database.
- **Never allow system file edits:** Editing `.php`, `wp-config.php`, `.htaccess` files is prohibited.
- **No Secrets management through MCP:** Agents have no tools to read hashes, create other API keys or change plugin administration permissions.

---

## 4. Capability Intersection Model

For an Ability to execute, all conditions in the following matrix must hold simultaneously:

$$\text{Allow} = \text{UserCap} \land (\text{AbilityTier} \le \text{KeyTierMax}) \land (\text{AbilityTier} \le \text{SiteCeiling}) \land \text{ToolsetEnabled} \land \text{ScopeAllow} \land \neg \text{ScopeDeny}$$

Where:
- **`UserCap`:** The WordPress user who owns the key must have the corresponding capability (for example, `edit_posts` for post creation, `manage_options` for configuration).
- **`RiskTier`:** Ability risk classification:
  - `read` (Level 1): Read non-sensitive data only.
  - `write` (Level 2): Create or update content.
  - `destructive` (Level 3): Permanently delete posts or delete categories.
  - `admin` (Level 4): Change core configuration (locked at site level by default).
- **`KeyTierMax`:** The maximum risk tier specified when creating an API key.
- **`SiteCeiling`:** The maximum site-wide risk limit set by the system administrator in Settings (default: `destructive`).

---

## 5. Content Processing & Output Pipeline

- All content returned from WordPress is considered **Untrusted Content Data**.
- Data returned to AI Agents must pass through `OutputPipeline`:
  1. Remove internal metadata fields not declared in the Schema (`_links`, `_embedded`).
  2. Hide all sensitive information (user passwords, hashes, application passwords, private keys).
  3. Post formats: Support `rendered` (filtered through KSES), `excerpt` and `raw` (only with permission to edit the specific object).
  4. Revalidate the entire output structure against the published `outputSchema`. If the result does not match the schema or fails, the system returns a safe execution error instead of malformed data.

## Authoritative security boundary

The current authorization formula and permanent exclusions are maintained in [architecture/overview.md](architecture/overview.md). Every operation requires current core object permissions, key/site risk ceilings, enabled key toolset, allow/deny constraints, explicit admin opt-in when applicable, and a verified AuthContext on HTTP. Sanctioned uploads are through core APIs only. Do not interpret any abbreviated diagram above as permission to omit these terms.
