# ADR-0003: Adapter dependency and compatibility range

- Status: Accepted
- Date: 2026-10-04

## Context

Adapter 0.7.0 introduces exact revision schemas and breaking minor changes.

## Decision

Require mcp-adapter as a plugin and gate MCP to >=0.7.0 <0.8.0 after the completed compatibility spike. M0 had no adapter dependency; the product dependency and facade are introduced in M1-S08.

## Consequences

The plugin must remain loadable with a diagnostic when adapter compatibility fails. Installed0.7.0 accepted a private custom-server ability; Inspector2.9 listed and called it over STDIO and listed it in modern protocol mode. Hook signatures and runtime version are recorded in adapter-notes.md. Realm-only401 triggers Inspector OAuth fallback; document this behavior for key-only clients. Product-key HTTP, other clients and production remain separate unverified gates.

## Alternatives considered

Bundled adapters and unrestricted version ranges were rejected.

## Verification

M1-S00 installed-source/runtime probes verified the dependency contract. N-32 must cover unsupported versions in product tests. Re-run facade contracts on dependency updates; acceptance of the version range does not mean the product integration exists or that every release within the range was tested.

## Sources

- https://wordpress.org/plugins/mcp-adapter/ (verified 2026-10-04).
