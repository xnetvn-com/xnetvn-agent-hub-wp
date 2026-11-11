# Authorization & Access Control Policy (Access Policy)

> **Rules for the user authorization model, role management and the principle of least privilege in xNetVN Agent Hub WP.**

---

## 1. Principle of Least Privilege (PoLP)

- Every AI Agent operating on WordPress is granted only the absolute minimum permissions needed to perform its specific task.
- Every newly created API key must explicitly specify:
  - The maximum permitted risk tier (`tier_max`).
  - The specific toolsets it may use (`toolsets`).
  - An optional Allowlist and Denylist for individual tools.
  - Optional network IP address restrictions for calls using the key (`cidr_restrictions`).

---

## 2. WordPress Roles & Capabilities

The plugin defines two Dynamic Meta Capabilities based on WordPress core capabilities:

| Plugin Capability | Corresponding WordPress Capability | Applicable User Groups | Technical Meaning |
|---|---|---|---|
| `use_xnetvn_agent_hub_wp` | Inherited from `edit_posts` (or an override configuration) | Author, Editor, Administrator | Allows users to create, view and manage **their own** API keys and use those keys to call the MCP Server. |
| `manage_xnetvn_agent_hub_wp` | Inherited from `manage_options` (or Super Admin on Multisite) | Administrator, Super Admin | Allows viewing all users' keys, revoking other users' keys, viewing all audit logs and modifying site-wide settings. |

---

## 3. Boundaries Between Owners & Administrators

- **Key Owner:**
  - May view only the list of keys they created.
  - May **Rotate** only their own keys. Administrators cannot rotate keys on behalf of their owners, preventing the takeover of newly generated secrets.
  - When creating a key with the `destructive` or `admin` tier, the owner must re-enter their current WordPress account password to reauthenticate their identity.
- **Site Administrator:**
  - May view the site-wide key list (but cannot view secrets).
  - May **Revoke** any key belonging to any user if exposure or a security policy violation is suspected.
  - May configure the site's risk ceiling (`site_ceiling`) and the list of Toolsets enabled on the system.

---

## 4. Permission Management on WordPress Multisite

- On a Multisite network, each subsite has an independent key database and settings.
- Super Administrators have default management permissions, but if a subsite has an explicit deny policy, the safety boundary at the `map_meta_cap` hook blocks delegated access before the WordPress Core super-privilege mechanism applies.
- Switching site context (`switch_to_blog()`) always accurately recalculates the target site's roles, settings and Pepper key.
