# ADR-0020: Site machine principals

- Status: Accepted (design). Implementation is delivered by the 2026-11-17
  ticket suite; runtime and shared-lab acceptance remain `NOT RUN`.
- Date: 2026-11-17
- Supersedes: none. It extends ADR-0005 and ADR-0018 without changing the
  personal-key contract.

## Context

The WordPress.org review of 2026-11-16 raised external administrative
authority, not a line-by-line defect. The current MCP credential model binds
every credential to a WordPress user: `src/Auth/KeyService.php:50` creates a
personal `xnv_` key for a user, and `src/Domain/AuthContext.php:28` carries
that user ID into every request. ADR-0005 then intersects the key scope with
the live user's capabilities.

That model is correct for a person acting through MCP. It cannot represent a
website that automates editorial work without owning, impersonating or
inventing a WordPress account. Creating a hidden account, a synthetic
`WP_User`, or anonymous capability injection would grant WordPress authority
that no human deliberately holds, and the directory review would remain
unresolved.

The project owner therefore decided a separate principal for the current
website. The decision is recorded here and implemented by the ticket suite
listed in the site-machine-keys specification.

## Decision

### OD-01: Protected accounts are excluded from personal credentials

Preserve the ordinary personal-key workflow for non-protected users. Exclude
`Administrator`, `Super Admin`, and administrative equivalents (the
`RoleGuard::PRIVILEGED` baseline, with `unfiltered_html` excluded) from
personal `xnv_` key issuance, rotation, patch extension and bearer
authentication. Authenticated key management remains available to a manager
who is already authenticated by cookie and nonce.

### OD-02: A site key belongs to the website, not to a user

The new `xns_` credential belongs to the current website and executes with no
WordPress account. `user_id = 0` is the execution identity. A hidden account,
a synthetic `WP_User`, administrator impersonation, and capability injection
for user ID `0` are all prohibited. The credential never appears inside
`ApiKey` and is never represented as a WordPress user.

### OD-03: Every MCP credential passes the reviewed safe envelope

Apply `SafeMcpEnvelope` and `SafeEditorialHtml` (`wp_kses` with
`unfiltered_html` suppressed for the duration of the MCP write) to every MCP
credential, `xnv_` and `xns_`. The baseline is editorial authority
(`editorial-v1`), not general site administration.

### OD-04: Ban is current-site editorial suspension

Ban means assigning the capability-less `banned` role, removing direct
capabilities, and destroying existing WordPress sessions. New password login,
Application Password authentication, and password-reset blocking remain
outside this decision; they are not claimed as part of it.

### OD-05: Authorship is attribution, never authority

Every machine post, page and attachment creation explicitly selects an
eligible content author through `EditorialAuthors::require_eligible()`.
Attribution never supplies execution privileges and never becomes key
ownership. Ordinary edits preserve the previous author.

### OD-06: Machine moderation needs separate, default-off grants

`Ban users` (`ban-users`) and `Unban users` (`unban-users`) are independent
optional permissions, both off by default. Machine unban restores only a
validated safe snapshot (`v1` or `v2`); there is no arbitrary `restore_role`
parameter on a machine call.

### OD-07: Network-wide protection and user-wide session destruction

On Multisite, retain user-wide `WP_Session_Tokens::destroy_all()` destruction,
including logout at other sites. Protect `Administrator` and equivalent
accounts at every site of the current network, including inactive and
archived memberships, before either moderation action. The bounded scan fails
closed above 1,000 sites.

### OD-08: No legacy conversion for undeployed users

The owner states that there are no deployed users. Legacy-key conversion,
ownership transfer, bulk revocation, data cleanup, and compatibility
migration of deployed keys are excluded. Runtime restrictions still apply to
every credential presented after the change.

### Normative architecture

- Keep `ApiKey`, `AuthContext`, `KeyService`, `RequestContext`, the `xnv_`
  wire format, and the `/keys` routes unchanged.
- Add `SiteCredential`, `SiteScope`, `SiteAuthContext`, and the additive
  `SiteRequestContext::site_current()`.
- Store site keys in the per-site table
  `{prefix}xnetvn_agent_hub_wp_site_keys`; never add a `user_id` column.
- Use the `xns_` prefix and a strict codec; prefixes route without fallback.
- Authorize through `SitePolicy` and execute only explicitly registered
  handlers plus the fixed, code-owned built-in handlers (the editorial author
  lookup and the nine reviewed taxonomy operations) resolved by their exact
  canonical names in `SiteDispatcher` when the bound catalog contains those
  exact definitions; the built-ins are not passed through
  `register()`, are never a dynamic class or callback, and no input supplies a
  handler, class or callback; the same resolution drives `has_handler()` and
  `execute()`; the built-ins run only after `SitePolicy`; `register()` rejects
  their reserved names as duplicates; and the static `SafeMcpEnvelope` is the
  outer bound.
- Record machine actions with `principal_type = site`, nullable `user_id`,
  `blog_id`, and a namespaced `credential_ref` (`xns:<key_id>`).
- Keep the human `xnv:` reference and the machine `xns:` reference distinct so
  a shared 12-character key ID can never collide across principal kinds.

## Consequences

- A website can automate reviewed editorial work without a WordPress account.
- The personal-key surface becomes narrower: protected accounts lose personal
  credential issuance and bearer entry.
- Every MCP write path must carry the envelope and the KSES boundary; a single
  bypass is a release blocker.
- Moderation remains available only through explicit, separately granted
  machine permissions and never through generic user administration.
- Audit and activity rows gain a principal type and credential reference, so
  machine actions are visible without fabricating a WordPress user.
- The implementation temporarily crosses a catalog window (`62` to `63`
  abilities) between `FR_13` and `ISSUE_08`; the catalog, skill pack and role
  matrix are aligned inside that window, not by weakening assertions. This is
  expected, unverified and not run during authoring.
- Residual risk remains in transport policy, lab acceptance, and the
  unverified original laws; these are tracked explicitly, not hidden.

## Alternatives considered

- **A hidden WordPress account for the site.** Rejected: it invents an
  identity, inherits capabilities, and is indistinguishable from a human.
- **A synthetic `WP_User` or `user_id = 0` with injected capabilities.**
  Rejected: it grants anonymous authority and breaks the capability model.
- **Administrator impersonation.** Rejected: it violates least privilege and
  the directory requirement.
- **Reusing `xnv_` with a site scope.** Rejected: it keeps the credential bound
  to a human and makes revocation and attribution ambiguous.
- **Storing site keys in the existing personal key table.** Rejected: the
  schema assumes a user and would force a fabricated owner.
- **Blocking login and Application Passwords inside this change.** Deferred by
  OD-04; it is a separate security decision.

## Verification

- Design and implementation are governed by the 47 tickets listed in the
  site-machine-keys specification and the wave dispatch index.
- Local gates: PHPCS, PHPStan level 8 with no baseline, PHPUnit unit and
  packaging checks.
- Shared-lab gates: WordPress 6.9 and 7.1.2 integration, multisite, REST/HTTP
  matrices, browser E2E and real-client checks, run only through `rtl.py`.
- Independent review is required for every T3/red-zone ticket before `Done`.
- Original project laws `L-01` through `L-20` are not fully present in this
  checkout. They are recorded as `UNVERIFIABLE` until the owner recovers them
  or explicitly waives them; no ticket may claim them as verified.
- WordPress.org directory approval is not claimed by this ADR.

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
- Session token storage:
  https://developer.wordpress.org/reference/classes/wp_user_meta_session_tokens/update_sessions/
  (checked 2026-11-17).
- Directory guidelines:
  https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
  (checked 2026-11-17).
- Plugin developer FAQ:
  https://developer.wordpress.org/plugins/wordpress-org/plugin-developer-faq/
  (checked 2026-11-17).
