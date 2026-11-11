# Threat model

This is the required security design, not proof of a deployed security boundary. The plugin exposes a private exact-route MCP server and user-owned credentials; runtime acceptance is tracked separately. Revisit this model before authentication, uploads, upgraders, multisite and OAuth changes.

| Threat | Required control and evidence |
|---|---|
| Spoofed identity/key theft | Random 256-bit secret; HMAC with separate pepper; exact route; bearer only; uniform 401; expiry/revocation/owner checks |
| Tampering/privilege escalation | User capabilities intersect scope; core object permissions; narrow-only key PATCH; admin off; deny-only filters |
| Repudiation | Safe request IDs and outcome audit; UTC; no credentials or argument values; bounded retention |
| Information disclosure | Output schemas/field filtering; no secrets/hashes; escaped UI; private reporting; export/erasure controls |
| Denial of service | Key/IP rate limits; payload/depth/output/bulk/timeout limits; bounded cron purges |
| Elevation of privilege | No arbitrary execution/file editing; trusted upgrader sources; role ceiling; DISALLOW_FILE_MODS/EDIT respected |
| CSRF/DNS rebinding | Validated Origin; management nonce+cookie; MCP rejects cookies even with a nonce; trusted proxy allowlist |
| SSRF | HTTPS public hostnames only; complete A/AAAA checks; pinned cURL address before transmission; exclude own host and alternate ports; manual revalidated redirects; streamed byte limit; no credentials/proxy; deny-only filters; fail closed without transport proof |
| Prompt injection | Site content is untrusted data; fixed tool descriptions; scope, toolset and confirmation constrain effects; term/user names/descriptions/URLs/meta are data, deletion needs destructive tier with dry_run and default-term guard; user admin tools need admin tier with protection zone and no password/email-of-owner paths |
| Taxonomy abuse | Managed-taxonomy allowlist with block list and deny-only filter; int-only assignment without silent drops or side-effect creation; parent-loop prechecks; default-term and trash guards; bulk caps with costly slots |
| Users privilege escalation | Admin protection zone (UserTargetGuard: super admin/administrator/privileged role/direct privileged cap/effective has_cap) with no exceptions including owner; assignable-role gate (RoleGuard) with single-role atomic change; banned-role lifecycle with snapshot, direct-cap removal and session destroy; no passwords through MCP; owner email/password immutable; cooldown plus per-minute create/reset budgets |
| Supply-chain compromise | Lock files; dependency audit/review; official separately installed adapter; version gate and contract tests |
| Personal-data leakage | Audit stores top-level argument names only; IP masking; retention/export/erasure; no user email/username without explicit request plus edit_user; no avatar hashes/capabilities/sessions/keys in outputs; canary audit tests |
| Cross-site access | Per-site prefix/pepper/context; bounded switch_to_blog; network abilities only for super administrators |

OWASP API Top 10 (2023) mapping: API1 object authorization through core controllers; API2 credential lifecycle; API3 strict schemas and sensitive field controls; API4 resource limits; API5 tier/toolset/function authorization; API6 high-risk confirmation; API7 SSRF controls; API8 fail-closed defaults; API9 versioned tool inventory; API10 safe upstream consumption.

Negative tests must include valid key plus bad Origin/TLS (earlier errors cannot become success), cached cookie identity plus another user's key, route confusion, forbidden direct ability execution, scope widening, audit canaries, DNS rebinding, oversized base64 and uninstall boundaries.

Official references: [WordPress security](https://developer.wordpress.org/apis/security/), [OWASP API Security](https://owasp.org/API-Security/), [MCP security practices](https://modelcontextprotocol.io/specification/2025-11-25/basic/security_best_practices). Verified 2026-10-04.

Media persistence is limited to core raw-body attachment upload APIs. Filename components, content MIME, approved/core MIME intersection, image pixels/memory and per-key expensive-work slots are checked before persistence. Temporary files are removed in finally. Password confirmation applies to issuing destructive/admin keys; per-call elicitation remains M6.
