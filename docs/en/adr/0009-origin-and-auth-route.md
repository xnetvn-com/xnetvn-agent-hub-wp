# ADR-0009: Origin, TLS and exact-route bearer authentication

- Status: Accepted
- Date: 2026-10-04

## Context

MCP HTTP requires Origin protection. WordPress can cache a cookie user before REST_REQUEST exists.

## Decision

Authenticate keys only on the exact MCP route, reject cookie authentication there and preserve prior guard failures.

## Consequences

Reauthenticate late when needed; trusted TLS proxy headers require source IP constraints. Realm-only challenges need real-client verification.

## Alternatives considered

Prefix route matching and authentication only at the adapter transport callback were rejected.

## Verification

N-10–N-19 and N-33–N-35; U4/U5/U10 installed-host and client probes.

## Sources

- https://modelcontextprotocol.io/specification/2026-07-28/basic/transports/streamable-http (verified 2026-10-04).
