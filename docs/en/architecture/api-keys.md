# API-key contract (M1 target)

Keys are `xnv_<key_id>_<secret>`. The public identifier contains 12 lowercase base32 characters `[a-z2-7]`; the secret is 32 random bytes encoded as 43 base64url characters without padding. The total length is 60. Reject tokens outside `^xnv_[a-z2-7]{12}_[A-Za-z0-9_-]{43}$` before database access.

Store only HMAC-SHA256(secret, pepper), as 64 hexadecimal characters, with a pepper identifier. Generate a separate 32-byte pepper, stored without autoload in `xnetvn_agent_hub_wp_pepper`; do not derive it from WordPress salts. Compare with hash_equals and perform a dummy HMAC for a missing identifier. Raw tokens appear once during creation/rotation and never in list, audit, exports or diagnostics.

Each key belongs to one user on one site. Scope includes tier_max, toolsets, optional ability allow/deny lists, CIDR IP restrictions and expiry. Current user capabilities intersect with scope on every request. A temporarily forbidden owner yields 403 without revoking the key; a deleted owner yields 401 and revocation. Unknown, malformed, revoked and expired credentials yield the same public 401 message. Internal reasons belong in safe audit metadata.

Use the per-site keys and audit tables, prepared database operations, UTC timestamps and VARCHAR(45) IP strings. Key ID has a unique index, with at most three generation retries. Last-used updates occur at most every five minutes. Scope edits can only narrow privileges and expiry. Rotation creates a new key and bounds the old key's grace expiry (default 24 hours).

Key management requires logged-in cookie authentication, a valid wp_rest nonce and ownership or management capability. Destructive/admin key creation requires password reauthentication, bounded lifetime and rate limiting. MCP bearer keys cannot manage keys or authenticate to other REST routes. Never enable bearer-token query parameters or token passthrough.

Default policy: 10 active keys/user; maximum lifetime 365 days; 120 requests/minute/key; 10 failed auth attempts/minute/IP. Revocation invalidates any cache immediately. Management notifications and privacy export/erasure never disclose secrets or hashes.

Official sources: [WordPress authentication](https://developer.wordpress.org/rest-api/using-the-rest-api/authentication/), [PHP random_bytes](https://www.php.net/manual/en/function.random-bytes.php), [hash_hmac](https://www.php.net/manual/en/function.hash-hmac.php), [hash_equals](https://www.php.net/manual/en/function.hash-equals.php). Verified 2026-10-04.
