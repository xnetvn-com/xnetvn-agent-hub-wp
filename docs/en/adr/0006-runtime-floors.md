# ADR-0006: Runtime floors and safe bootstrap

- Status: Accepted
- Date: 2026-10-04

## Context

WordPress 6.9 includes the Abilities API. Unsupported runtimes need a safe administrative notice rather than a parse error.

## Decision

Require PHP >=8.2 and WordPress >=6.9; keep pre-check bootstrap files parseable on PHP 7.2.

## Consequences

PHP 8.2 security support ends 2026-12-31; a later floor change needs an announced compatibility policy.

## Alternatives considered

Loading modern Composer autoloaded classes before checking requirements was rejected.

## Verification

Version matrix tests, PHPCompatibilityWP bootstrap check and CI parser checks. PHP 8.5 integration remains experimental.

## Sources

- https://www.php.net/supported-versions.php (verified 2026-10-04).
