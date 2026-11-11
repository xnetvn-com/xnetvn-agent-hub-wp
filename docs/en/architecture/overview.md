# Architecture and immutable boundaries

The product is a WordPress plugin exposing private Abilities through the official MCP Adapter. PHP namespace: `XNetVN\AgentHubWP`; PSR-4 root: `src/`; text domain/slug/server ID: `xnetvn-agent-hub-wp`; ability namespace: `xnetvn/`. Function, hook and option prefix: `xnetvn_agent_hub_wp_`. License: GPL-2.0-or-later. Minimum PHP 8.2 and WordPress 6.9.

The current core has requirement checks, provider kernel, per-site storage, dynamic capability/scope policy, exact-route authentication, a private registry and five system tools through the official MCP Adapter. Audit/privacy, cookie-authenticated management and profile/admin UI are implemented with scoped evidence. Content and Media packs are implemented under the approved 2026-10-08 amendment; Comments (ten tools, ADR-0016), Taxonomy (nine tools, ADR-0017) and Users (twelve tools, ADR-0018) packs are implemented. Full runtime acceptance and OAuth remain open. Completion is recorded separately from implemented primitives.

## HTTP request lifecycle
1. Match only normalized `/xnetvn-agent-hub-wp/mcp`; management uses `/xnetvn-agent-hub-wp/v1` and is outside bearer-key authentication.
2. Origin and TLS guards deny unsafe requests before auth. Missing Origin is permitted for non-browser clients; explicit null or an unlisted origin is denied.
3. Bearer authentication overrides cookie identity and creates a request-local AuthContext. Cached early cookie users must be reauthenticated at REST authentication priority 80. Preserve all earlier guard errors.
4. Recheck key expiry/revocation, owner existence and current capabilities, site enablement and IP constraints. Enforce key/IP rate limits.
5. The adapter handles protocol negotiation and transport through the single ServerGateway facade.
6. Filter tool listings and recheck policy at calls and the wrapped ability permission callback. Core REST dispatch remains responsible for object-specific permissions.
7. Validate schema, enforce output/resource budgets and remove sensitive fields; return structured content and JSON text. Audit only top-level argument names.
8. Return no-store/nosniff headers, 401 challenges and 429 retry guidance when applicable.

STDIO uses the explicitly selected WordPress user, skips HTTP guards and still enforces capabilities and site policy. Missing AuthContext is permitted only for STDIO, never HTTP.

## Effective permissions
Every term must succeed: core capability/object permission; ability tier <= key and site ceilings; enabled toolset; explicit allow/deny constraints; admin tier explicitly enabled; authenticated HTTP context. Tiers: read < write < destructive < admin. Default site ceiling is destructive; admin is off. Capability rules are dynamic: `use_xnetvn_agent_hub_wp` follows edit_posts unless a role override exists; management follows manage_options or network management.

Third-party abilities require explicit approval, safe metadata and permission interception on compatible WordPress versions. Missing annotations default to destructive. Tier filters can only raise risk; policy filters can only deny additional access. Do not silently expose a third-party path that bypasses the private registry.

Multisite super administrators retain default use and management, but an explicit site role matrix and corrupt policy still deny delegated use. A deny-only `map_meta_cap` boundary runs before core's super-administrator shortcut; the ordinary dynamic `user_has_cap` derivation remains in place. Management/repair and unrelated WordPress capabilities are preserved. Switching sites re-evaluates the active site's roles, settings, key tables and pepper without writing capability definitions into roles.

## Layer boundaries
Admin/REST/CLI call services. Services depend on domain contracts. Persistence and rate limiting implement contracts. Packs are independent of authentication, transport, admin and adapter APIs. The registry is the authority for ability metadata; arbitrary core meta forwarding is not assumed.

Prefer internal `rest_do_request()` dispatch for core domains. Never call a REST endpoint by starting another HTTP request, and never bypass its permission callback. Use direct WordPress APIs only when no REST operation exists.

## Permanent exclusions
No arbitrary SQL, PHP or shell execution; no file editors, wp-config/.htaccess modification, external file writes outside sanctioned core APIs, secrets/salts/token/password hashes, Application Passwords or API-key management tools. Never expose configuration that disables this plugin's protections. Never accept arbitrary plugin/theme ZIPs.

## Public extension surface
`xnetvn_agent_hub_wp_loaded` receives the registered kernel. `xnetvn_agent_hub_wp_taxonomy_allowed` is a deny-only taxonomy gate (true to keep, anything else to refuse; never widens the resolver allowlist). `xnetvn_agent_hub_wp_user_privileged_caps` is add-only (never removes defaults); `xnetvn_agent_hub_wp_user_allowed` is deny-only (only exact true passes, never widens protection); `xnetvn_agent_hub_wp_user_meta_writable` is add-only with empty default (registered show_in_rest keys only, hard denylist always applies). Future key lifecycle actions, deny-only policy and tier-raising filters require tests, documentation and changelog entries. Requirements must fail closed before modern PHP or adapter code is loaded.

Official sources: [Abilities API](https://developer.wordpress.org/apis/abilities-api/), [REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/), [MCP versioning](https://modelcontextprotocol.io/specification/versioning), [MCP tools](https://modelcontextprotocol.io/specification/2026-07-28/server/tools). Verified 2026-10-04.

## Policy truth-table evidence

The64 combinations of ability/key/site tiers assert ability risk <= both ceilings. Additional cases independently deny missing HTTP context, unknown transport, disabled site, missing WordPress capability, absent toolset, explicit allow/deny restriction, disabled admin tier and unapproved third-party descriptors. Extra policy hooks preserve the base intersection and may deny more; malformed/error hook results deny. Tier hooks may only raise risk. Registry permission and handler layers bind the current actor and recheck policy. Output schemas are revalidated after redaction/bounding.

## Sanctioned media writes

The permanent file-editing exclusion does not prohibit attachment persistence through core upload APIs into the active site uploads directory. Only bounded validated temporary files and reviewed core upload paths are allowed. No generic filesystem operation is exposed as a tool. Settings have one canonical inventory in [ENVIRONMENT.md](../ENVIRONMENT.md).
