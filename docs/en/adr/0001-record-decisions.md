# ADR-0001: Record the frozen product decisions

- Status: Accepted
- Date: 2026-10-04

## Context

A new repository needs a durable record of fixed contracts.

## Decision

Use the original product identity, slug, PHP namespace, capability names, private abilities and risk tiers.

## Consequences

Changes to frozen contracts require a new decision and explicit owner approval.

## Alternatives considered

Ad hoc names and undocumented exceptions were rejected.

## Verification

Review public identifiers against the bootstrap and architecture.

## Sources

- https://developer.wordpress.org/plugins/plugin-basics/header-requirements/ (verified 2026-10-04).
