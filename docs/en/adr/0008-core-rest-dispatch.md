# ADR-0008: REST-backed abilities

- Status: Accepted
- Date: 2026-10-04

## Context

Core controllers enforce registered fields, KSES and object-specific capabilities.

## Decision

Prefer internal rest_do_request dispatch and preserve core validation and object permissions.

## Consequences

Outputs must be reshaped and filtered; direct APIs require explicit capability/object checks.

## Alternatives considered

Direct database mutation and a loopback HTTP REST request were rejected.

## Verification

Author/Contributor/Editor object permission tests, invalid schemas and response reshaping.

## Sources

- https://developer.wordpress.org/reference/functions/rest_do_request/ (verified 2026-10-04).
