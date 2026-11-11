# Per-site persistence and guard implementation

The core creates only the documented keys/audit tables and a forward-only schema-version option. Migration uses an atomically claimed option lease rather than transient read/modify/write, with ownership-bound release. Activation and plugins_loaded run the same cheap version check. Future schema versions are refused; the site still loads with a deferred safe diagnostic.

Key lookups use fresh indexed database reads. The safe ApiKey metadata object contains no secret, HMAC or pepper. StoredKey holds an opaque verifier whose HMAC is outside exportable object properties. Identifier collisions reject inserts; scope updates compare against the read snapshot atomically and can only narrow. Revocation has no stale credential-cache interval. Last-used writes occur at most once per five minutes.

Rotation commits the replacement INSERT and old-key expiry UPDATE together on one pinned native mysqli connection. SQL is prepared through WordPress's placeholder API. The narrow transaction boundary deliberately avoids wpdb's reconnect/retry behavior: replaying a write after a connection loss can escape the original transaction. It locks and rechecks the expected old row, requires InnoDB, and refuses an enclosing transaction or disabled autocommit instead of implicitly committing another caller's work. No table conversion is performed. Creation events and one-time disclosure happen after commit; ordinary update/trigger failures roll back both writes without a compensating key. Database drop-ins that do not expose a mysqli connection or route queries across primaries require separate compatibility work and fail closed for rotation.

A lost commit acknowledgement can leave the client uncertain even though the database committed both changes. Reload the owner's key metadata and reconcile/revoke an undisclosed replacement before retrying; no automatic rotation replay is performed. This distributed failure boundary does not expose a token or prove an operation failed merely because its response was lost.

Queries bind values once to fixed templates; database failure is unavailable, never an empty count. Raw SQL errors are suppressed and new entries in WordPress's own saved-query array are redacted after the operation. Independent host/database logging is outside the plugin's control. Audit inserts accept only whitelisted metadata and argument names, use one batch statement and retain bounded query/purge sizes.

Rate buckets use atomic persistent-object-cache operations when available. Without persistent cache they use an atomic SQL increment in non-autoloaded per-site options, avoiding lost increments across PHP workers. Older buckets have a bounded cleanup method; scheduling is connected with audit retention in M1-S09. HTTP peer identity uses the validated socket address; untrusted forwarded-for values do not redefine it.

Settings match the documented defaults. trusted_proxy_cidrs is the explicit address constraint required alongside trusted_proxy_headers. Origin settings are validated as serialized origins without outbound DNS/HTTP resolution. Malformed stored policy closes delegated use; managers retain WordPress capability-based repair access. Repairs preserve valid restrictions and require explicit replacements for invalid known fields rather than silently restoring permissive defaults.

Multisite capability tests cover the core super-administrator shortcut, explicit role-matrix denial, corrupt policy, role/settings changes across sites and no persistent role mutation. New-site request migration creates only that site's owned tables; identical public key IDs and peppers remain independent across prefixes. Use core's verified `WP_MULTISITE=1` test environment flag inside the lab toolbox; the similarly named environment variable `WP_TESTS_MULTISITE` does not select multisite in the installed core harness.

The complete core6.9 and7.1.2 multisite integration suites passed94 tests/503 assertions on the scoped test lab. Single-site runs execute the original cases and use core's explicit `skipWithoutMultisite()` helper for four network-only cases. Independent connection fixtures initialize their actual blog ID before testing owner-lock contention; network owner-deletion fixtures delete the account rather than only removing its membership. These corrections preserve the required failure semantics. Full HTTP/client/cache/performance acceptance remains separate.

Implemented hooks: xnetvn_agent_hub_wp_loaded(kernel), xnetvn_agent_hub_wp_auth_failed(reason, peer_ip), xnetvn_agent_hub_wp_allowed_origins(origins). Auth runs at determine_current_user20 / REST auth80; Origin/TLS guard runs at REST auth5. Earlier denials are preserved, and prior successful authentication cannot substitute for a valid plugin key context. Request state is reset at shutdown.

Tests have exercised real WordPress databases, role changes, exact routes, Origin/TLS conditions, credential verification, role loss/recovery, revocation, expiry, missing headers and counter sharing. Full N-01–N-35 and adapter/management/audit acceptance remain separate later gates.

Official references (verified 2026-10-04):
- https://developer.wordpress.org/plugins/creating-tables-with-plugins/
- https://developer.wordpress.org/reference/classes/wpdb/prepare/
- https://developer.wordpress.org/reference/functions/add_option/
- https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/

Multisite sources, verified 2026-10-05:
- https://developer.wordpress.org/reference/classes/wp_user/has_cap/
- https://developer.wordpress.org/reference/hooks/map_meta_cap/
- https://developer.wordpress.org/reference/functions/switch_to_blog/
- https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/

Rotation sources, verified 2026-10-05:
- https://developer.wordpress.org/reference/classes/wpdb/__get/
- https://developer.wordpress.org/reference/classes/wpdb/remove_placeholder_escape/
- https://developer.wordpress.org/reference/classes/wpdb/query/
- https://dev.mysql.com/doc/refman/8.4/en/set-transaction.html
- https://mariadb.com/docs/server/reference/sql-statements/transactions/start-transaction
- https://www.php.net/manual/en/mysqli.begin-transaction.php
