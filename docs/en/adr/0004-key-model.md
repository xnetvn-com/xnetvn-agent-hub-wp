# ADR-0004: User API keys and separate pepper

- Status: Accepted
- Date: 2026-10-04

## Context

A key is a delegated credential and cannot expand its owner's current privileges.

## Decision

Use random user-owned keys and HMAC-SHA256 with a separate random pepper; reveal each secret once.

## Consequences

Pepper loss invalidates keys. Lists, logs, exports and diagnostics must never contain credentials or hashes.

## Alternatives considered

Plaintext storage, reversible encryption, WordPress salts as pepper and bearer query strings were rejected.

## Verification

Known HMAC vectors, malformed-token rejection, entropy-format tests and audit canaries.

## Sources

- https://www.php.net/manual/en/function.hash-hmac.php (verified 2026-10-04).
