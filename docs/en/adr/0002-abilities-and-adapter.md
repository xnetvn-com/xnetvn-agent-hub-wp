# ADR-0002: Abilities API and the official adapter

- Status: Accepted
- Date: 2026-10-04

## Context

Protocol revisions and transport validation should follow a maintained official implementation.

## Decision

Use core Abilities API and the separately installed official MCP Adapter through one ServerGateway facade.

## Consequences

Packs remain independent of transport; adapter compatibility needs a runtime spike.

## Alternatives considered

A custom MCP protocol stack and bundling the adapter were rejected.

## Verification

M1 tests private custom-server tools, protocol revisions and HTTP/STDIO permissions.

## Sources

- https://wordpress.org/plugins/mcp-adapter/ (verified 2026-10-04).
