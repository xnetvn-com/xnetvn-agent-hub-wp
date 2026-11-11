# DR-001: Initial Architecture Decision and MCP Adapter Selection

> **Architecture Decision Record (ADR / DR-001)**  
> **Status:** Accepted  
> **Approval Date:** 2026-10-04  
> **Decision Participants:** Lead Architect, Security Specialist, Core Maintainers

---

## 1. Context
When integrating the Model Context Protocol (MCP) into the WordPress ecosystem, there are two main approaches:
1. **Develop the entire MCP protocol server from scratch inside the plugin:** Write sockets, implement JSON-RPC over REST, and manage sessions internally.
2. **Use the Official WordPress MCP Adapter (`mcp-adapter`) with the Abilities API:** Use the official WordPress core community standard to standardize registration and discovery of interoperability capabilities.

A completely secure authentication mechanism is also required, because default methods such as Cookies, Application Passwords, or Basic Auth do not meet the AI Agent security boundary requirements:
- Cookies are susceptible to browser-based CSRF / DNS Rebinding attacks.
- Application Passwords grant the user's full permissions without fine-grained scope narrowing or risk tier limits.

---

## 2. Architecture Decision

1. **Delegate transport to `mcp-adapter` through `ServerGateway`:**
   - The `xnetvn-agent-hub-wp` plugin acts as a secure Ability provider (Capability-scoped Provider), rather than rewriting the MCP protocol.
   - All interaction with `mcp-adapter` is encapsulated inside `XNetVN\AgentHubWP\Mcp\ServerGateway`.
2. **Design User-Owned API Keys:**
   - Standard key format: `xnv_<key_id>_<secret>` (60 characters in total).
   - Store cryptographic `HMAC-SHA256` hashes using an independent 32-byte Pepper key on each site.
   - Key permissions follow the intersection principle: $\text{User Permissions} \cap \text{Key Scope} \cap \text{Site Ceiling} \cap \text{Toolset}$.
3. **Clearly Separate the 2 Authentication Routes:**
   - MCP route (`/wp-json/xnetvn-agent-hub-wp/mcp`): Accept Bearer Tokens only; reject all Cookies.
   - Management REST route (`/wp-json/xnetvn-agent-hub-wp/v1/*`): Accept WordPress login session Cookies with a `wp_rest` nonce only.

---

## 3. Consequences and Trade-offs

### Positive:
- Maximum compatibility with the future WordPress Abilities ecosystem.
- Completely eliminate CSRF risk and browser session hijacking attacks.
- Users can create multiple keys for different Agents with separate permission scopes and lifetimes.
- API key rotation does not disrupt service thanks to a 24-hour grace period.

### Negative / Challenges:
- The additional `mcp-adapter` dependency plugin must be installed for the MCP server to operate.
- Dedicated database tables (`*_keys`, `*_audit`) and a per-site migration process must be managed.
