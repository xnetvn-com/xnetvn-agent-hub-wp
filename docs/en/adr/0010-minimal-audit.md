# ADR-0010: Audit metadata without argument values

- Status: Accepted
- Date: 2026-10-04

## Context

Audit logs can become a secondary source of secrets and personal data.

## Decision

Store request identity, outcome, timing and top-level argument names; never store credential or argument values.

## Consequences

Retention, IP masking and privacy export/erasure require tests and bounded purges.

## Alternatives considered

Recording full request headers, bodies or tool outputs was rejected.

## Verification

Canary tests, retention batches and user export/erasure integration tests.

## Sources

- https://developer.wordpress.org/plugins/privacy/ (verified 2026-10-04).
