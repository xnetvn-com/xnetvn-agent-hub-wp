# ADR-0012: Store IP addresses as text

- Status: Accepted
- Date: 2026-10-04

## Context

Binary addresses interact poorly with character escaping and database formats.

## Decision

Use VARCHAR(45) for canonical IPv4/IPv6 addresses and explicit CIDR checks.

## Consequences

Normalize and validate IPs; trusted proxies and client IPs must not be confused.

## Alternatives considered

Unvalidated forwarded headers and binary addresses in string SQL formats were rejected.

## Verification

IPv4/IPv6/CIDR tests and exact stored strings in repository integration tests.

## Sources

- https://developer.wordpress.org/reference/classes/wpdb/ (verified 2026-10-04).
