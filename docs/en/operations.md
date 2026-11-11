# Operations and verification boundaries

The core implements six private ability packs (62 tools), scoped authentication, credential management, audit/privacy and profile/admin pages. Full runtime acceptance and later milestones remain open. Passing a subset does not establish production readiness.

## Keys

Create a key on its owner's Profile page. Creation/rotation return the token once; acknowledgement or Escape clears the dialog and reload never recovers it. Store it in the client's secret store. Existing keys can only become more restrictive; create a new key when adding permissions.

Management requires a logged-in WordPress cookie and its `wp_rest` nonce. Bearer keys and Application Password identity alone cannot substitute. Managers can list/revoke other owners' keys; rotation belongs to the owner. Destructive/Admin issuance checks the current password with a five-attempt minute budget. Quota-sensitive writes use a non-blocking, connection-bound database lock. Keys and named locks need one authoritative database primary; multi-primary/NDB behavior is unverified.

Rotation additionally requires InnoDB and a mysqli connection with no enclosing transaction. Its insertion and old-key grace update commit together; it never retries writes on a replacement connection. If the commit response is lost, inspect the refreshed key list and revoke any undisclosed replacement before retrying. A response failure alone does not establish whether the database committed.

Revoke affected keys in Profile or Agent Hub → Keys. Authentication reads persisted expiry/revocation and current owner permissions. Role loss denies access; owner deletion revokes their keys. Pending notices recheck the original key/owner binding.

## HTTPS and diagnostics

Use HTTPS for MCP. Plain HTTP requires both a local/development environment and the explicit development constant. The remote browser sites disable that exception. Approve browser origins explicitly; tokens never belong in URLs.

Only configured proxy headers and CIDRs can assert TLS in the plugin. Forwarded client IP never substitutes for the socket peer. Hosting that rewrites `REMOTE_ADDR` changes this boundary: the lab disables the official Apache image's broad `mod_remoteip` configuration. This does not claim the proxy peer is the visitor's original address.

Overview sends a non-secret sample header to the management diagnostic and receives presence booleans only. If absent, inspect Apache/Nginx/FastCGI or WAF header handling. Preserve Authorization without bypassing authentication or TLS checks. Management and MCP responses use `no-store` and `nosniff`.

## Audit, privacy and email

Audit buffers bounded metadata and writes one INSERT at shutdown. It records fixed identifiers/reasons/timings and filtered top-level argument names. Raw arguments, credentials, headers and hashes are omitted. User agents are bounded and recognizable credential patterns redacted. Optional IP masking applies IPv4 /24 and IPv6 /48 to audit and key-use metadata.

Daily retention uses bounded batches and schedules remaining backlog separately. It also cleans expired rate buckets and notice markers. WordPress's confirmed privacy workflow exports owner key/audit metadata and notification preferences; erasure deletes keys and associated markers, removes preferences and anonymizes audit correlation. It also anonymizes revoker IDs on another owner's key without deleting that owner's credential. Deletion consumes the first remaining batch to prevent offset skips and completion checks all remaining owner/revoker references.

Creation, administrator revocation, seven-day expiry and changed-network notices respect site choices and owner opt-out. Mail runs after key mutation locks are released. Tests intercept all mail; real delivery is unverified. `wp_mail()` acceptance does not prove inbox delivery.

Uninstall removes every owned cron hook, including events with arguments. Default deletion drops only the owned tables and prefixed options. Explicit preservation keeps data. Global preferences remain if any network site preserves data; otherwise bounded owner batches remove them. Large-network uninstall remains unverified. Scoped Redis concurrency, isolation and outage evidence is recorded in the [performance checkpoint](architecture/performance.md); other cache backends and production failover remain unverified.

Deactivation is separate from uninstall. On a single site it clears the plugin's four owned scheduled events on the current site. Network-wide deactivation visits every site in bounded pages and runs the same cleanup under `switch_to_blog()`, restoring the current blog in `finally`. Keys, audit rows, settings and user preferences remain in place.

Persistent cache must provide shared atomic increments and fail on unavailable storage. Do not enable the Redis provider's unsupported graceful mode: a local-memory fallback can lose shared counters. A global cache failure can stop WordPress before this plugin loads, returning a core500 rather than Agent Hub's503 unavailable response. Recover the backend first; reconcile an unknown mutation outcome before retrying. Cache restart/flush can remove ephemeral rate buckets, so infrastructure operations must remain outside delegated tools that could reset their own limits. The lab uses bounded memory with `noeviction`, unique site prefixes and an integer read timeout compatible with its pinned provider.

## Taxonomy operations

Issue keys with the `taxonomy` toolset (add `content` when agents create posts before assigning terms). Daily work uses tier `write`; grant `destructive` only for term deletion. Contributors and Authors can create flat tags per core but cannot create hierarchical categories; Editors and Administrators manage all terms within their object permissions.

Enabling `show_in_rest` for a legacy taxonomy also exposes its public term-reading route to unauthenticated site visitors; enable it only when term names are non-sensitive. Assignment uses direct core functions: it does not change post_modified, does not send save_post and does not create revisions, though term counts and relationship hooks still run. Term counts cover published posts only; zero does not prove unused. Deletion is permanent: children are reparented, relationships removed and term meta deleted, so preview with dry_run first.

## Users operations

Issue keys with the `users` toolset. Daily lookup uses tier `read`; owner-profile edits use tier `write`; account administration needs tier `admin` plus site `allow_admin_tier` (default off, only for `manage_options` holders with password re-entry). Only Administrators see the ten admin/lookup tools by default; Contributors and above see the two owner-profile tools when granted plugin use.

The Admin protection zone has no exceptions: administrators, super administrators and privileged-role/capability holders cannot be edited, re-roled, banned, reset or session-terminated through MCP, including by their own keys; owners edit only non-sensitive profile fields. No password is accepted or returned; new accounts get a random server password plus a core email to set their own; resets only email the account owner with cooldown and budgets. Banning moves the account to the capability-less `banned` role, removes direct caps and destroys cookie sessions; unbanning restores the snapshot after revalidation. Banned keys stop at authentication without revocation. Email changes keep the core old-address notice; sessions survive email changes unless terminated. Editor carries `unfiltered_html` by default and remains assignable; widen protection with `xnetvn_agent_hub_wp_user_privileged_caps` when needed. Exclude `/wp/v2/users` request bodies from activity-log plugins because `create-user` transmits a one-time random password.

## Remote lab

Service/database/website/browser/E2E work goes through `remote-test-lab`. The compose file has WordPress6.9/7.1.2, a database and toolbox with2560 MiB of limits; Playwright temporarily adds2048 MiB. Ports are loopback-only and Hestia exposes HTTPS. Ignored `.env.rtl` contains test values; real environments and private keys are not synchronized.

Review the lab's rendered resource and secret boundaries before deployment. Server-wide changes require separate user approval. Version/digest-pinned images and the SHA-256-verified official6.9 archive provide reproducible inputs. Domain names, addresses, trusted proxy CIDRs and test passwords are supplied through ignored environment configuration rather than committed internal literals. Remote browser configuration accepts only the configured project lab sites, handles their self-signed certificates, disables recordings and clears credential fields before automatic failure snapshots.

`infra/remote/check-package.sh` checks the built ZIP in a separate CLI-only WordPress installation and table prefix. It copies core and test dependencies, activates the packaged plugin without development vendor files and runs Plugin Check. Its mail sink prevents outbound mail and it does not modify the browser-site tables. Package validation is separate from source-directory scans and full milestone acceptance.

Initial preflight reported the original hourly Docker prune and two stale firewall bridge rule sets. Keep the owned stack running and capture `diagnose` immediately on failures. Cron/firewall/server-wide changes require separate authorization; none were made.

Official references, verified2026-10-05: [REST authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/), [exporter](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-exporter-to-your-plugin/), [eraser](https://developer.wordpress.org/plugins/privacy/adding-the-personal-data-eraser-to-your-plugin/), [uninstall](https://developer.wordpress.org/plugins/plugin-basics/uninstall-methods/), [named locks](https://dev.mysql.com/doc/refman/8.4/en/locking-functions.html), [WCAG2.2](https://www.w3.org/TR/WCAG22/). Remote core integration suites have passed, but complete milestone runtime and production acceptance remain unverified.
