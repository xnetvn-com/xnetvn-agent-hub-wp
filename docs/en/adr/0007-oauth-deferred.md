# ADR-0007: OAuth deferred to M7

- Status: Accepted
- Date: 2026-10-04

## Context

Cloud connectors introduce a separate identity and token trust boundary.

## Decision

Use API keys for HTTP and explicit WP-CLI users for STDIO in phase one; design OAuth separately in M7.

## Consequences

No claim of ChatGPT or cloud-Claude connector compatibility is made for key-only authentication.

## Alternatives considered

Pretending bearer API keys implement OAuth discovery was rejected.

## Verification

M7 requires PKCE S256, metadata, audience binding and no token passthrough tests.

## Sources

- https://modelcontextprotocol.io/specification/2025-11-25/basic/authorization (verified 2026-10-04).
