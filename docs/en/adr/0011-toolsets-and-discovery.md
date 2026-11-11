# ADR-0011: Toolsets and bounded discovery

- Status: Accepted
- Date: 2026-10-04

## Context

Large tool lists consume model context and can disclose unavailable capabilities.

## Decision

Group abilities into scoped toolsets with system discovery tools and deterministic listing.

## Consequences

Measure 20/60/120-tool payloads before selecting a listing threshold; meta execution must recheck registry and policy.

## Alternatives considered

Exposing every registered third-party ability by default was rejected.

## Verification

Scope-filtered sorted listing and 20/60/120-tool measurements in M1-S00.

## Sources

- https://modelcontextprotocol.io/specification/2026-07-28/server/tools (verified 2026-10-04).
