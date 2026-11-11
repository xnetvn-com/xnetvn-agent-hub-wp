# ADR-0018: Users policy

Status: Accepted (2026-10-10). Owner decided D1..D4 on 2026-10-09; owner approved EU-01..EU-08 and EU-10..EU-20 for implementation on the working branch for this task (EU-09 deferred).

## Context

Catalog M5 listed ten user tools as Planned with `update-my-profile` visibility `list_users` and `set-user-role` REST. Direct WordPress source review (6.9.10 and 7.1.3, 2026-10-09) showed single-site `edit_users` can edit administrators, REST role change is non-atomic multi-role, `default_role` fallback risks privilege, edit-context listing silently drops users while counting them, and no core account lock exists. Official sources: WordPress 7.1.3/6.9.10, MCP 2026-07-28 tools, adapter 0.7.0, NIST SP 800-63B-4.

## Decisions

- D1 (owner): comprehensive Admin protection zone; no exceptions including owner and super admin.
- D2 (owner): no raw passwords through MCP; no owner email/password change through MCP.
- D3 (owner): ban via capability-less `banned` role with snapshot, direct-cap removal, session destroy.
- D4 (owner): `delete-user` deferred with mandatory reassign design later.
- EU-01: UserTargetGuard missing/self/protected/denied/allowed without caching.
- EU-02: PCS per 4.3.2 without unfiltered_html; add-only privileged filter.
- EU-03: `read` visibility for `get-my-profile`/`update-my-profile`.
- EU-04: add `get-my-profile`, `set-user-status`, `terminate-user-sessions`; HardDeny ADMIN +2.
- EU-05: `create-user` REST with explicit role, random password, reread, notify.
- EU-06: `set-user-role` Direct atomic single-role (deviates catalog REST note per L-20).
- EU-07/EU-08: ban snapshot/session flow plus Capabilities denial for `banned`.
- EU-09: deferred; login/app-password/reset blocking not implemented.
- EU-10/EU-11: terminate sessions and reset with cooldown/budget, no link return.
- EU-12: `update-user` other-email with old-address notice preserved.
- EU-13: allowlist DTO Groups A/B, view-context IDs plus WP_User projection, no avatar/caps.
- EU-14: RestBackedAbility kind `user` observing `rest_insert_user` with WP_User identity.
- EU-15: four new safe reasons plus exact-first user code mapping.
- EU-16: OutputPipeline adds `user_activation_key`, `session_tokens`.
- EU-17: user meta read registered only, write default deny with add-only allowlist and hard denylist.
- EU-18: one role per account through MCP.
- EU-19: proposed per-minute budgets and 900s cooldown (to be measured).
- EU-20: three public filters add-only/deny-only as specified.

## Consequences

Twelve user tools implemented; catalog, overview, threat model, api-standards, operations and changelog updated; independent M5 security review remains required before release; EU-09, hourly budgets, bulk/network/delete-user remain future work.
