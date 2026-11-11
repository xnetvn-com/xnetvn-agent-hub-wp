# M5 — Users contract

Design authority: D1 through D4 decided 2026-10-09; EU-01 through EU-08 and EU-10 through EU-20 approved for implementation on the working branch per owner direction for this task (EU-09 deferred).
Implementation is present; complete runtime acceptance and the independent M5 security gate remain open.

## Private execution boundary

Twelve abilities use toolset `users`. Ten use visibility `list_users`; `get-my-profile` and `update-my-profile` use `read`. Visibility never replaces core object permissions (`list_users` for listing, `edit_user`/`promote_user`/`create_users` per target). HTTP requires the verified key owner's AuthContext; key/site tiers and allow/deny lists still intersect current permissions. Packs never change the actor.

| Ability | Tier | Backing | Additional constraints |
|---|---|---|---|
| list-users | read | REST GET collection view | Bounded filters/page, status active/banned/any, page-overflow refusal |
| get-user | read | REST GET item view | Exact ID, ban info when banned |
| list-roles | read | Direct wp_roles | privileged/assignable/banned/is_default/capability_count, sorted |
| get-user-capabilities | read | Direct WP_User | Requires edit_user, 500 cap, privileged intersection |
| get-my-profile | read | REST GET me edit | Owner only, email only when requested |
| update-my-profile | write | REST POST me | No email/username/password/roles, meta allowlist default deny |
| create-user | admin | REST POST collection | Explicit assignable role, random password unset, reread guard, notify |
| update-user | admin | REST POST item | Standard chain, email/slug duplicate, meta allowlist, reread |
| set-user-role | admin | Direct WP_User::set_role | Standard chain, promote_user, one role, atomic, reread |
| set-user-status | admin | Direct set_role/caps/sessions | Snapshot, banned role, remove caps, destroy sessions, safe unban |
| send-password-reset | admin | Direct retrieve_password | Standard chain, banned invalid_state, 10/min budget, 900s cooldown |
| terminate-user-sessions | admin | Direct WP_Session_Tokens | Standard chain, banned allowed, counted destroy_all |

Each definition has explicit readonly/destructive/idempotent/openWorldHint annotations. All tool descriptions are fixed English and treat user names, bios, URLs and meta as untrusted data.

## Input contracts

Every root input is an object with additionalProperties=false. IDs are positive integers. page>=1; per_page=1..100, default 20. Role slugs match `^[a-z0-9_-]{1,64}$` and pass RoleGuard at call time. Username `^[A-Za-z0-9._@-]{1,60}$`; email format email 3..100; display name 1..250; person names 100; URL uri 100; bio 5000; slug `^[a-z0-9-]{1,50}$`; locale 20 validated against core; meta object max 20 keys, default deny all writes. `create-user` requires username, email, role; no password/roles array. `update-user`/`update-my-profile` require at least one field; email/username/password/roles rejected where specified. `set-user-status` status active/banned, reason_code spam/abuse/security/policy/other, restore_role single assignable.

## Output contracts

`list-users` returns `{items,total,total_pages,page}` with `truncated` when bounded. User DTO is an allowlist: id, name, slug, link (string only), status active/banned, roles, registered_date UTC Z, protected, description, url, ban `{banned_at,reason_code}` only when banned with valid snapshot, plus Group B username/email/first_name/last_name/nickname/locale/meta only when requested and `edit_user` holds. Never avatar_urls, capabilities, pass, activation keys, session tokens, links, embeds, or plugin-injected fields. `get-user-capabilities` returns sorted caps max 500 with `truncated`, privileged intersection, protected, status. Mutations return MutationResult receipts; failures return PartialFailure with created_id/affected_id. All outputs pass OutputPipeline redaction and byte bounding.

## Protection and credentials

UserTargetGuard evaluates missing/self/protected/denied/allowed per request without caching: super admin, administrator role, privileged role, direct privileged cap, or effective `has_cap` across PCS implies protected; `xnetvn_agent_hub_wp_user_allowed` deny-only filter can only refuse. RoleGuard PCS is the 4.3.2 set (unfiltered_html excluded); assignable requires pattern, existence, not banned, not privileged, in editable_roles. Banned role is capability-less, lazily created, refused when pre-existing with caps. Snapshot is versioned JSON via user option with strict validation. No password in any schema; random 32-char server password unset after REST; reset links never returned; email change keeps core old-address notice; no Set-Cookie in my-profile flows.

## Errors and limits

New reasons `duplicate_user`, `protected_account`, `role_unavailable`, `cooldown_active` with fixed messages; core `rest_user_*`/`existing_user_*`/`no_password_reset`/`invalidcombo`/`retrieve_password_email_failure` mapped exact-first. Standard admin chain order: not_found, protected_account, invalid_state, forbidden. Rate budgets: create 5/min/owner, reset 10/min/owner plus 900s/target cooldown (proposed numbers). No bulk, network, delete-user (D4 deferred), password set, individual caps, role definition edits, or key management.

No new site settings: the `users` toolset is automatic from the registry; `WpSiteSettings`, `ENVIRONMENT.md` and admin screens are untouched.
