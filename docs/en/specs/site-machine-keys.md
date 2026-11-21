# Site machine keys contract

- Status: Accepted design (2026-11-17). Implementation is planned by the
  47-ticket suite; product, integration and directory checks are `NOT RUN`
  until their tickets produce evidence.
- Authority: owner decisions `OD-01` through `OD-08` in
  [ADR-0020](../adr/0020-site-machine-principals.md). The English document is
  canonical; the Vietnamese file is a parity translation.
- Scope: the accountless `xns_` principal, its storage, authentication,
  policy, editorial operations, audit, management API and administration.
  The personal `xnv_` contract keeps its wire format and routes.

## Principal model

The plugin has exactly two MCP principal kinds:

| Principal | Credential | Execution identity | Management |
|---|---|---|---|
| User | `xnv_` (unchanged) | A live WordPress user ID | Existing `/keys` routes and personal page |
| Site | `xns_` (new) | No WordPress account; `user_id = 0` | Cookie and nonce only, `/site-keys` routes |

`principal_type` is always explicit (`user` or `site`). Prefix routing is
strict: an `xns_` token never falls back to the personal repository, and an
`xnv_` token never enters the site authenticator. A request that carries
conflicting human and site contexts is denied. The site principal never sets
the WordPress current user and never receives a capability through a filter
on user ID `0`.

Attribution is separate from execution. A machine request may credit an
eligible content author, but that author is not the key owner and gains no
execution privilege from the credit.

## Decision map

| Decision | Contract section |
|---|---|
| OD-01 | Personal `xnv_` keys exclude protected accounts; see Authentication and request context and Lifecycle and quotas. |
| OD-02 | The site principal is accountless (`user_id = 0`); see Principal model and Persistence and schema. |
| OD-03 | Every MCP credential passes the static envelope and KSES boundary; see Policy and safe envelope. |
| OD-04 | Ban is current-site editorial suspension, without login or password-reset blocking; see Users and moderation. |
| OD-05 | Authorship is attribution, never authority; see Content and taxonomy and Media. |
| OD-06 | Ban and unban are separate default-off grants; see Users and moderation. |
| OD-07 | Administrators are protected network-wide and sessions are destroyed user-wide; see Users and moderation. |
| OD-08 | No legacy conversion for undeployed users; see Residual risks and out of scope. |

## Token format

- Prefix: `xns_`.
- Body: 12 lowercase base32 characters for the key ID, then `_`, then 43
  unpadded base64url characters carrying 32 random bytes.
- Total length: exactly 60 characters. Uppercase, whitespace, wrong length,
  wrong alphabet, and `xnv_` input are rejected.
- Generation uses the platform CSPRNG. The secret is revealed exactly once
  through `IssuedSiteKey::reveal()`; a second reveal, clone, serialize or
  debug dump must not disclose it.
- Verification is HMAC-SHA256 with a separate random pepper and a
  constant-time comparison. Hashes, peppers and plaintext tokens never enter
  logs, audit rows, REST reads or error messages.
- `SiteTokenCodec::parse()` returns the existing `ParsedToken` shape so later
  tickets do not invent a second parsed-token contract.
- The personal `xnv_` token format is unchanged.

## Domain value objects

`SiteCredential` is a readonly value object with a positive `id` and
`blog_id`, a `KeyId`, a 1 to 100 character `name`, a `SiteScope`, UTC
`created_at`, nullable `revoked_at`, `created_by`, `revoked_by`,
`last_used_at` and `last_used_ip`. It has no `user_id` or owner property.

`SiteScope` is a readonly value object with:

- `profile = 'editorial-v1'` only;
- normalized, unique `toolsets` where `system` is mandatory;
- a non-empty, sorted, unique `allowed_abilities` list; an empty list throws
  and is never a wildcard;
- a unique `denied_abilities` list;
- `permissions` limited to `ban-users` and `unban-users`, default empty;
- at most 100 CIDR entries in `ip_allowlist`;
- an explicit `tier_ceiling`; and
- a mandatory UTC `expires_at`.

A moderation permission requires an admin tier ceiling, the `users` toolset,
`xnetvn/set-user-status` in the allowlist, and the same name absent from the
denylist. `is_narrower_than()` accepts only a profile-preserving subset of
toolsets, a non-empty subset of allowed abilities, a superset of denied
abilities, a subset of permissions, contained CIDRs, a ceiling no higher than
before, and an expiry no later than before. `to_array()` and `from_array()`
are strict and fail closed on unknown keys or corrupt values.

`SiteAuthContext` binds the credential, a non-empty request ID, the client IP
and the fixed principal type `site`. It exposes `blog_id()`,
`credential_ref()` (`xns:<key_id>`), `has_permission()`, and a safe
`__debugInfo()` with no secret material.

`SiteRequestContext` adds `site_current()` beside the existing human
`current()`. It also exposes `has_conflicting_principals()`; conflicts deny.

## Persistence and schema

Site keys live in `{prefix}xnetvn_agent_hub_wp_site_keys`, never in the
personal key table and never with a `user_id` column:

| Column | Type and rule |
|---|---|
| `id` | `BIGINT UNSIGNED AUTO_INCREMENT`, primary key |
| `key_id` | `VARCHAR(12)`, unique |
| `blog_id` | `BIGINT UNSIGNED`, indexed with state and expiry |
| `name` | `VARCHAR(100)` |
| `secret_hash` | `VARCHAR(64)` |
| `pepper_id` | `INT UNSIGNED` |
| `scope_json` | `LONGTEXT`, strict decoding |
| `expires_at` | `DATETIME`, UTC |
| `created_at` | `DATETIME`, UTC |
| `created_by` | `BIGINT UNSIGNED NULL` |
| `revoked_at` | `DATETIME NULL` |
| `revoked_by` | `BIGINT UNSIGNED NULL` |
| `revoke_reason` | `VARCHAR(64) NULL` |
| `last_used_at` | `DATETIME NULL` |
| `last_used_ip` | `VARCHAR(45) NULL` |
| `last_used_ua` | `VARCHAR(255) NULL` |

`Schema::VERSION` moves `1 -> 2` for this table and later `2 -> 3` for the
audit columns. Migrations are idempotent and never move or delete personal key
rows. Every repository read and write is scoped by the current `blog_id`,
including primary-key and key-ID lookups. A corrupt `scope_json` raises a
storage error instead of accepting permissive defaults.

`SiteKeyRepository` provides insert, find by key ID, find by ID, paginated
list, active count, rename/patch, revoke, last-used touch, and atomic
rotation. Rotation uses the pinned database connection with no replay: a
pre-commit failure rolls back and preserves the old expiry; an unknown commit
outcome raises a typed storage error and is never reported as success.

## Lifecycle and quotas

`SiteKeyService` is the only issuance path:

- `ACTIVE_QUOTA = 10` active keys per blog.
- `DEFAULT_EXPIRY_DAYS = 90`, capped by the existing key lifetime setting.
- `MAX_ROTATE_GRACE_HOURS = 24`, default `24`.
- The manager identity comes from `get_current_user_id()`, is checked live
  with `user_can( ..., Capabilities::MANAGE )`, and is never supplied by the
  caller.
- `create` and `rotate` require a valid current password and the existing
  password retry and rate limits.
- A scope is materialized from the reviewed default set and may only narrow.
  Moderation permissions additionally require the site settings admin opt-in
  and admin tier ceiling.
- The active count and create, patch, rotate and revoke operations run under
  `SiteKeyLock` (`xnetvn_ahwp_sk_<blog_id>`), always released in `finally`.
- At quota, create and rotate return `409` with the key-limit error. Rotation
  never silently revokes the old key to make room.
- Patch accepts only narrowing changes; it refuses a new password field, new
  permissions, added toolsets, a cleared allowlist, a higher tier, or a later
  expiry.
- Revocation is one-way and records the manager and reason. Deleting the
  creating user later does not invalidate the credential.

The REST surface returns `201` only on create and rotate, `200` on reads and
patch, and the existing deletion shape on revoke, and `409` on quota.
Secrets appear only in the issuance and rotation responses.

## Authentication and request context

`SiteAuthenticator::authenticate()` runs in this order before binding:

1. Site MCP enabled; HTTPS; allowed Origin.
2. IP rate budget and site/key rate budget.
3. Active state: not revoked and not expired.
4. Intersected site and key IP allowlists.
5. Constant-time secret verification.
6. A dummy HMAC when the key ID is unknown, so timing does not reveal
   existence.

Errors use the existing safe `401`, `403`, `429` and `503` shapes.

On the exact MCP route, `Authenticator` inspects the raw bearer header. An
`xns_` token is routed exclusively to the site authenticator and binds a site
context; `determine_user` returns `0`. An `xnv_` token follows the existing
human path, now with the protected-account eligibility check. Repeated
headers, query or body credentials, and oversized tokens are rejected before
repository reads. `rest_result()` fails closed on conflicting principals,
requires the current user to remain `0` for a site request, preserves earlier
security errors, and keeps `Cache-Control: no-store`. Site tokens are not
accepted on `/wp-json/xnetvn-agent-hub-wp/v1/*` management routes or on core
`/wp/v2/*` routes.

`ServerGateway` admits either a verified human context or a verified site
context. The site path requires `get_current_blog_id() === blog_id()` and
`get_current_user_id() === 0`. `scope_hint()` may return a `SiteScope` for an
`xns_` token, but the hint grants no authorization. `tool_names()` dispatches
a `SiteScope` to `Registry::names_for_site_scope()`. Only the gateway may
depend on adapter classes.

## Policy and safe envelope

`SafeMcpEnvelope` is a static, code-owned allowlist. It permits the reviewed
editorial and system operations and the two human self-profile operations for
a human principal only. At the envelope layer it also permits
`xnetvn/set-user-status` for a site principal; the separate moderation grant
is enforced by `SitePolicy` and the handler. It denies unknown, third-party,
fixture and general user-administration names. No setting, filter, key tier
or hook may widen it; extensions may only deny.

The reviewed editorial and system set contains 51 operations: System 5,
Content 19 plus `xnetvn/list-content-authors`, Taxonomy 9, Media 7 and
Comments 10. The other user operations are not part of it.

`SafeEditorialHtml` runs MCP writes inside a re-entrant KSES scope that
removes `unfiltered_html`, snapshots and adds only the KSES callbacks that are
not already present across `content_save_pre`, `content_filtered_save_pre`,
`excerpt_save_pre`, `title_save_pre` and `pre_comment_content`, and restores
the exact prior hooks in `finally`. It never calls a blind
`kses_remove_filters()`. Plain-text fields are stripped; rich content is
filtered as post HTML.

`SitePolicy::evaluate()` checks the envelope first, then the scope profile,
toolsets, explicit allowed and denied names, site settings, tier ceiling and
moderation grant. Deny-only extension filters may only turn an allow into a
deny. `SiteDispatcher` registers server-owned machine handlers, freezes after
registration, re-checks the schema and policy on every call, applies the
expensive-work budget under `site:<blog_id>:<key_id>`, and passes the result
through the existing output pipeline. It resolves the explicitly registered
handler for the exact canonical name; in addition, for the exact canonical
name `xnetvn/list-content-authors`, it resolves one fixed, code-owned built-in
handler when the bound catalog contains that exact definition. The built-in is
not passed through `register()`, is never a dynamic class or callback, and no
input supplies a handler, class or callback. The same resolution drives
`has_handler()` and `execute()`, it runs only after `SitePolicy`, and
`register()` rejects its reserved name as a duplicate. The same resolution
covers the nine reviewed taxonomy names, which are fixed, code-owned built-ins
and are never passed through `register()`; every other operation runs only
through an explicitly registered handler. A missing handler is
indistinguishable from an unavailable one and never falls back to a human
closure.

`Registry::names_for_site_scope()` returns only names that have a registered
machine handler, pass the envelope, and are permitted by the scope and site
settings. Discovery and `execute-ability` use the same path, so a hidden
operation cannot be executed by name. `execute-ability` keeps its read tier
but the target's own tier, scope and grant checks still apply.

## Editorial operation matrix

The catalog contains 63 canonical definitions. The machine surface is
bounded; it is not the full human surface.

| Domain | Count | Machine authority |
|---|---|---|
| System | 5 | Session, site info, discovery, ability info and policy-bound `execute-ability`; site session reports `user = null` and `principal.type = site`. |
| Content | 20 | The 19 existing content tools plus `xnetvn/list-content-authors`; object, type, status and meta guards; explicit author on creation; KSES; verified terms; partial-failure receipts. |
| Taxonomy | 9 | Fixed editorial capability map, resolver and hierarchy guards, integer-ID assignment, KSES. |
| Media | 7 | Required author on machine uploads, core upload persistence at `user_id = 0`, upload policy, safe remote fetch, KSES. |
| Comments | 10 | Post, parent, type and status guards, forced KSES, site comments at `user_id = 0` with `comment_author = WordPress MCP`. |
| Users: self-profile | 2 | Human only; no machine access. |
| Users: moderation | 1 | `xnetvn/set-user-status` only, with separate ban and unban grants and network protection. |
| Users: other administration | 9 | Permanently unavailable to MCP: create, update, role, list, get, roles, capabilities, reset, terminate sessions. |

## Content and taxonomy

Content creation requires an explicit eligible author for machine requests;
`EditorialAuthors::require_eligible()` re-reads the user, checks current blog
membership, `edit_posts`, non-banned state and non-protected eligibility.
`list-content-authors` returns only `{id, name}`, scans at most 1,000
candidates, and fails closed with a `503` instead of silently truncating.
Ordinary edits preserve the previous author. Duplicate, revision restore,
meta writes and bulk writes stay inside the KSES and object guards, and a
partial mutation returns an explicit receipt.

Taxonomy writes use the fixed editorial capability map, the object and
hierarchy resolver, default-term rules, strict integer term IDs, and the same
KSES boundary for human and machine writes. Unknown terms and mismatched
taxonomies are refused before mutation.

## Media

Machine uploads require an explicit eligible author and persist through core
upload APIs, not direct filesystem writes. Execution identity remains
`user_id = 0`; attribution never becomes execution authority. Human uploads
keep the existing optional-author schema and reject an explicit machine-style
override. The upload policy, MIME and size bounds, safe remote fetcher and
temporary-file cleanup remain in force. Captions, descriptions, titles and
alt text pass the HTML boundary for both principals.

## Comments

Comment creation and update enforce the exact post, parent, type and status
rules, force KSES on content and author fields, and use `user_id = 0` with
`comment_author = WordPress MCP` for machine requests. Private IP and user
agent data are not exposed. Moderation actions remain bounded editorial
operations and never become general comment administration.

## Users and moderation

The only machine user mutation is `xnetvn/set-user-status`:

- `ban-users` is required for `banned`; `unban-users` is required for
  `active`; neither grant implies the other.
- `restore_role` is rejected on every machine call. Unban restores only a
  validated `BanSnapshot` (`v1` or `v2`) with no privileged role or direct
  privileged capability.
- The target must exist, be a member of the current blog, and pass
  `NetworkUserProtection` both before the operation and immediately before the
  final role write. Administrator and equivalent accounts are protected at
  every site in the network; the scan is bounded and fails closed above 1,000
  sites.
- Ban assigns the capability-less `banned` role, removes direct capabilities,
  and calls user-wide `WP_Session_Tokens::destroy_all()`.
- A partial write keeps the snapshot and returns a partial-failure receipt; it
  is never blindly retried or reported as a clean rollback.
- A ban is refused with `xnetvn_agent_hub_wp_invalid_state` before any
  snapshot, role, capability or session write when the recovery point it would
  leave behind is unusable: the roles are empty, a role is not assignable, or
  a direct privileged capability is recorded.
- A repeated ban reuses the stored snapshot, removes the leftover captured
  capability and destroys every session irreversibly, while the stored
  snapshot bytes stay identical.
- A successful unban requires the exact role-key-stripped direct capability
  map. A live direct capability the snapshot does not name returns
  `xnetvn_agent_hub_wp_partial_failure` and keeps the snapshot, instead of a
  success receipt that clears the only recovery point.
- `xnetvn_agent_hub_wp_internal_error` remains an error.
- Password login blocking, Application Password revocation and password-reset
  blocking are out of scope under `OD-04`.

## Audit and activity

Audit rows gain `principal_type` (`user` or `site`), `blog_id`, and
`credential_ref` (`xnv:<key_id>` or `xns:<key_id>`). For a site row,
`user_id` and the legacy `key_id` column are `NULL`; the machine reference
lives only in `credential_ref`. Human rows keep the existing `key_id`.
Argument values are never logged, and both prefixes are scrubbed. The
repository exposes exactly eight list columns: `occurred_at`, `user_id`,
`ability`, `outcome`, `transport`, `principal_type`, `blog_id`,
`credential_ref`.

The audit REST query accepts `principal_type` and a strict
`credential_ref` pattern, always binds the current blog, and requires
management capability for machine, cross-user or `all` queries. Five
conflicting parameter combinations return `400 rest_invalid_param`; a bare
`key_id` remains human-only. Responses cast numeric fields, include no secret
or argument data, and keep `Cache-Control: no-store`.

The Activity screen keeps its five-column contract, renders `Website MCP key`
and `Not applicable` for a machine row, and never fabricates a WordPress
user. Key management and activity remain cookie and nonce operations; bearer
credentials cannot manage keys.

## REST management API

The `/xnetvn-agent-hub-wp/v1/site-keys` routes are cookie and nonce only and
require `Capabilities::MANAGE`:

| Route | Contract |
|---|---|
| `GET /site-keys` | Paginated, optional state, no cross-blog or user ID parameters, `{items,page,per_page}`; `page` and `per_page` echo the request. |
| `POST /site-keys` | Name, optional toolsets and ability lists, permissions, CIDRs, expiry, required current password; `201` with view and one-time secret. |
| `GET /site-keys/{id}` | Site view only, never a secret. |
| `PATCH /site-keys/{id}` | Narrowing fields only; widening is `409`. |
| `DELETE /site-keys/{id}` | Revocation with the existing deletion shape. |
| `POST /site-keys/{id}/rotate` | Required current password, optional grace 0 to 24 hours, `201` with view and one-time secret. |

Totals are intentionally not exposed in this slice: the list response carries
no `total`, `total_pages` or `X-WP-Total` value, and `page` and `per_page` are
the only pagination metadata.

The existing matrix still governs: at the management gate, `400` invalid input, `401` missing cookie,
`403` nonce, capability or current-password refusal, `409` widening, active
quota or unresolved rotation, `429` password retry budget exhausted and `503`
storage or lock failure; route-specific `404` and the general error matrix
still apply.

The view contains identity, scope, timestamps, management attribution and
last-use metadata, and never `secret`, `secret_hash`, `pepper_id` or
`user_id`. Unknown or caller-supplied identity fields are rejected. The
existing `/keys` schema and behavior for personal keys are unchanged.

## Admin interface

The Keys page offers a separate `WordPress MCP API Keys` area for site keys,
with create, reveal-once, narrow, rotate, revoke and list controls. It shows
scope, permissions, expiry and last use, and never renders a secret after the
issuance response. The Activity area keeps five columns and labels machine
rows with the site credential reference, not a fabricated user. All actions
use cookie authentication, a fresh nonce, live capability checks and the
existing admin asset pipeline.

## Privacy, notifications and lifecycle

Site-key records are site data. Privacy export and erasure handle them under
the site's policy without inventing an owner email or a WordPress user.
Lifecycle cleanup removes only revoked or expired site-key rows and their
metadata; uninstall removes the plugin tables and options according to the
existing uninstall contract. Packaging includes the new classes, migration
and assets, and excludes local evidence and secrets. Deactivation does not
silently grant or widen any credential.

## Errors and limits

The existing contracts remain: `401` credential refusal, `403` forbidden,
`409` quota or state conflict, `429` rate limit, `503` unavailable, `400`
strict invalid parameters, partial-failure receipts for committed
sub-mutations, and `Cache-Control: no-store` on protected responses. Key
lists, CIDR lists, page sizes, candidate scans, upload size, audit rows and
rate buckets are bounded. Error messages reveal no token, hash, pepper,
private payload or account existence beyond the safe contract.

## CI integration window

`FR_13` adds the 63rd ability. Full integration tests that assert hardcoded
`62` counts, and the per-pack `content => 19` expectation, are expected to
stay red between `FR_13` and `ISSUE_08`. This is a planned transition window,
not a reason to weaken an assertion. The catalog, skill pack and role matrix
are updated atomically in `ISSUE_07` and `ISSUE_08`.

## Original laws L-01..L-20

The authoritative definitions of the original project laws `L-01` through
`L-20` are not fully present in this checkout. They are recorded as
`UNVERIFIABLE` until the owner recovers the source or explicitly waives them.
No acceptance or directory reply may claim them as verified.

| Law | Status |
|---|---|
| L-01 | UNVERIFIABLE |
| L-02 | UNVERIFIABLE |
| L-03 | UNVERIFIABLE |
| L-04 | UNVERIFIABLE |
| L-05 | UNVERIFIABLE |
| L-06 | UNVERIFIABLE |
| L-07 | UNVERIFIABLE |
| L-08 | UNVERIFIABLE |
| L-09 | UNVERIFIABLE |
| L-10 | UNVERIFIABLE |
| L-11 | UNVERIFIABLE |
| L-12 | UNVERIFIABLE |
| L-13 | UNVERIFIABLE |
| L-14 | UNVERIFIABLE |
| L-15 | UNVERIFIABLE |
| L-16 | UNVERIFIABLE |
| L-17 | UNVERIFIABLE |
| L-18 | UNVERIFIABLE |
| L-19 | UNVERIFIABLE |
| L-20 | UNVERIFIABLE |

## Ticket map

The authoritative ticket files live under `.local/docs/en/issues/v1.0/`; the
dispatch index records full file paths and dependencies. Every ticket below
traces to `OD-01` through `OD-08` and to this contract.

| Wave | Ticket | Focus |
|---|---|---|
| W00 | ISSUE_01 | This contract and ADR-0020 |
| W01 | FR_01 | Protected-account exclusion in personal keys |
| W01 | FR_02 | Static safe envelope on every execution path |
| W01 | FR_04 | Site credential, scope and context objects |
| W01 | FR_20 | Network-wide administrator protection |
| W02 | FR_03 | KSES boundary for content and comments |
| W02 | FR_06 | Separate site token codec and wrappers |
| W02 | FR_08 | Machine request context and redaction |
| W02 | FR_21 | Ban snapshot attribution |
| W02 | FR_31 | Protected-account exclusion in bearer authentication |
| W03 | FR_05 | Per-site key storage and schema version 2 |
| W03 | FR_26 | Profile HTML boundary |
| W04 | FR_07 | Bounded site-key lifecycle and lock |
| W05 | FR_09 | Site authentication without cookie identity |
| W06 | FR_12 | Site policy and dispatcher |
| W07 | FR_13 | Editorial author selection and ability 63 |
| W07 | FR_27 | Server gateway machine admission |
| W08 | FR_10 | Site-key management REST routes |
| W08 | FR_15 | Machine taxonomy operations |
| W08 | FR_16 | Media author schema |
| W08 | FR_28 | Content author schema |
| W08 | ISSUE_07 | Catalog docs and skill validator at 63 |
| W09 | FR_11 | Site keys in the admin page |
| W09 | FR_14 | Machine content operations |
| W09 | ISSUE_08 | Catalog role matrix at 63 |
| W10 | FR_17 | Machine media operations |
| W11 | FR_18 | Machine comment workflows |
| W12 | FR_19 | Machine identity in session and discovery |
| W13 | FR_22 | Separate machine ban and unban grants |
| W14 | FR_23 | Audit principal, blog and credential reference |
| W14 | ISSUE_12 | Integration specs and envelope matrix |
| W15 | FR_24 | Site-key privacy and notifications |
| W15 | FR_29 | Principal-aware activity query |
| W15 | ISSUE_13 | HTTP matrix and real-client fixtures |
| W16 | FR_25 | Lifecycle cleanup and release packaging |
| W16 | FR_30 | Site-owned activity display |
| W17 | ISSUE_02 | Architecture boundaries and residual risk |
| W17 | ISSUE_11 | Browser UI E2E specs |
| W17 | ISSUE_14 | SVGs, translation template and listing capture |
| W18 | ISSUE_03 | Key API and settings contracts |
| W18 | ISSUE_15 | Architecture and performance docs |
| W19 | ISSUE_04 | User guide and skill references |
| W20 | ISSUE_09 | Guides, operations and README |
| W21 | ISSUE_10 | Docs indexes, UI standards and secret scanner |
| W22 | ISSUE_16 | Packaging, audit unit test and doc index |
| W23 | ISSUE_05 | Shared-lab security acceptance |
| W24 | ISSUE_06 | Factual directory response |

## Residual risks and out of scope

- `RequestsDownloadTransport.php:73-126` is the subject of an owner decision:
  keep the reviewed transport and document its controls, or harden it first.
  The parked `feature/http-api-transport` branch is not part of this contract.
- The shared lab was blocked on 2026-11-16. Runtime acceptance cannot be
  claimed until the lab is restored; local checks are not a substitute.
- The original laws remain `UNVERIFIABLE`.
- WordPress.org directory approval, hosted CI, production, release publication
  and directory submission are not claimed by this document.
- Legacy conversion, bulk revocation and deployed-key migration are excluded
  because there are no deployed users.
- Login blocking, Application Password revocation and password-reset blocking
  are excluded from the ban decision.
- General user administration and core REST routes are not exposed to site
  keys.

## Verification

| Gate | Where | Expected |
|---|---|---|
| PHPCS and bootstrap syntax | Local | Exit 0 |
| PHPStan level 8 | Local | Zero errors, no baseline |
| Unit suite | Local | All pass |
| Documentation parity and settings | Local | `composer check:docs` exits 0 |
| Packaging | Local | ZIP audit exits 0 |
| Integration 6.9 and 7.1.2 | Shared lab | All pass |
| Multisite and network isolation | Shared lab | All pass |
| HTTP and real-client matrices | Shared lab | All pass |
| Browser E2E | Shared lab | All pass |
| Security acceptance | Shared lab | Evidence-backed PASS |

Every behavior change starts with a failing test. Every T3/red-zone ticket
needs an independent review before `Done`. A blocked lab, missing owner
answer or unavailable prerequisite is reported as blocked, never as a pass.

## Sources

- WordPress roles and capabilities:
  https://developer.wordpress.org/plugins/users/roles-and-capabilities/
  (checked 2026-11-17).
- `WP_User::has_cap`:
  https://developer.wordpress.org/reference/classes/wp_user/has_cap/
  (checked 2026-11-17).
- REST post creation permission boundary:
  https://developer.wordpress.org/reference/classes/wp_rest_posts_controller/create_item_permissions_check/
  (checked 2026-11-17).
- `wp_insert_post`:
  https://developer.wordpress.org/reference/functions/wp_insert_post/
  (checked 2026-11-17).
- Session destruction:
  https://developer.wordpress.org/reference/classes/wp_session_tokens/destroy_all/
  (checked 2026-11-17).
- Directory guidelines:
  https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
  (checked 2026-11-17).
- Plugin developer FAQ:
  https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
  (checked 2026-11-17).
- Internal: [ADR-0020](../adr/0020-site-machine-principals.md),
  [capability intersection](../adr/0005-capability-intersection.md),
  [users policy](../adr/0018-users-policy.md),
  [ability catalog](../architecture/ability-catalog.md),
  [architecture overview](../architecture/overview.md),
  [threat model](../security/threat-model.md),
  [testing](../TESTING.md), [environment](../ENVIRONMENT.md) and
  [operations](../operations.md).
