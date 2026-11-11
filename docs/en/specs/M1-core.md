# M1 core implementation specification

## Objective and authority
Implement the user-owned API-key, exact-route authentication, capability intersection and private MCP ability architecture described in the original implementation plan. Frozen identity, routes, table names, defaults and exclusions remain binding. Autonomous execution, scoped remote testing and safe feature-branch commits/pushes are authorized. Release, merge and production publication remain separate gates.

## Interfaces and sequence
1. Installed-adapter spike proves private custom-server abilities, runtime version, hook signatures, core-hook version floors, cached-cookie/route behavior, 401 headers and real-client configuration. Record each U1–U7/U10 independently; do not infer a live result from a static scan.
2. Domain: RiskTier enum (read/write/destructive/admin, ordered ranks), validated KeyId/KeySecret/Toolset value objects, immutable Scope/ApiKey/AuthContext and denial decisions. Core domain classes call no WordPress APIs. A key record carries identity and scope, never a raw token.
3. Crypto: KeyGenerator::generate(): GeneratedKey; KeyHasher::hash(secret, pepper): string and verify(secret, pepper, stored): bool; TokenParser::parse(token): ?ParsedToken. GeneratedKey reveals its full token once. Debug/JSON/serialization surfaces never expose secrets. Malformed tokens are rejected before repository access.
4. Persistence: per-site forward-only schema, prepared repositories, bounded uniqueness retries, narrow-only scope updates, cache invalidation, throttled touches and audit batches. Settings/pepper are not autoloaded.
5. Dynamic use/manage capabilities; route/Origin/TLS guards; request-local auth; timing-safe missing-key path; owner/expiry/IP/site checks and bounded key/IP rate limits. REST auth priority80 preserves previous errors and reauthenticates any early-cached cookie user.
6. Policy and private registry wrap permission callbacks for every transport. Only ServerGateway imports adapter classes. Filter listing/calling and recheck invoked abilities in meta tools.
7. Schema/output contracts, system pack, minimal safe audit/privacy/retention/uninstall, management REST with cookie+nonce, narrow-only edits/rotation/password checks, accessible profile/admin UI.
8. End-to-end N-01–N-35 and runtime clients, performance measurements, operations docs and independent security review.

## Schema and safety constraints
Names are `xnetvn/<verb-object>` and MCP uses hyphens. Every definition has strict input/output object schemas, risk tier, toolset, visibility capability and consistent annotations. The registered arguments omit public/show_in_rest metadata; core may inject its false default, which must remain false. The plugin's definition map is authoritative.

The final output must still match its schema after redaction and bounds are applied. Never manufacture success, invalid structured content or a truncated envelope not declared by its schema. If valid bounded output cannot be produced, fail the tool with a safe execution error. Site-created content is untrusted data.

No tool may access SQL/PHP/shell/file editors, configuration secrets, credential management, arbitrary plugin/theme ZIPs or protection-disabling settings. Dynamic filters can only increase risk or deny access.

## Tests and acceptance
Domain and pure Auth helper unit coverage >=90% for original M1-S01; broader Domain/Auth/Policy combined coverage >=90% and total >=80% for release; 10,000-key uniqueness; independent known HMAC vector; malformed/oversized token table; secret debug/export checks; >=30 policy cases; role matrix; repository lifecycle; management nonce/ownership/scope checks; all N-01–N-35 through real HTTP; strict pack contracts and WP_Ability integration. Two real MCP clients and performance measurements are explicit runtime gates, not substitutes for unit tests.

Static/local acceptance: composer check, integration on WP6.9/current stable, clean dependency audits, production ZIP and installed-package Plugin Check. Hosted CI, external cloud connector behavior, production readiness and directory acceptance need separate evidence and remain unverified until actually exercised.
