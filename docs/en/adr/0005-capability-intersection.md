# ADR-0005: Capabilities intersect scope

- Status: Accepted
- Date: 2026-10-04

## Context

Roles can change after key creation; static capability snapshots would preserve revoked authority.

## Decision

Intersect current WordPress capabilities with key/site tiers, toolsets and allow/deny constraints.

## Consequences

Default key use follows edit_posts; management follows site/network management. Filters can only narrow access.

## Alternatives considered

Role snapshots and scope as an independent permission grant were rejected.

## Verification

Role-matrix integration tests and >=30 policy truth-table cases; HTTP without context always denied.

## Sources

- https://developer.wordpress.org/plugins/users/roles-and-capabilities/ (verified 2026-10-04).
