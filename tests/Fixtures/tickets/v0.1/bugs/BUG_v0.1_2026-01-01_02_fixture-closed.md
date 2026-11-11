# Fixture closed ticket

Test fixture for the acceptance-gate ownership rules. It is not a project
ticket and must never be claimed or migrated.

## 0. Ticket Metadata (Required)

| Field | Value |
|---|---|
| ID (= file name without `.md`) | BUG_v0.1_2026-01-01_02_fixture-closed |
| Target version (`X.Y`, no patch number) | 0.1 |
| Created (`YYYY-MM-DD`) | 2026-01-01 |
| Requester | Test suite |
| Assignee | Unassigned |
| Status (`Draft`, `Ready`, `In Progress`, `In Review`, `Done`, `Cancelled`) | Done |
| Priority (P1, P2, P3, P4) | P3 |
| Related links | None |
| Minimum capability tier (`T1`, `T2`, `T3`; see instruction `agent-delivery`) | T1 |
| Touches red zone (`No` or `Yes: <kind>`) | No |
| Depends on (issue IDs, or `None`) | None |
| Baseline check command (one command that runs verbatim, with its expected result) | `composer check` -> exit 0 |
| Working branch (the assignee fills it in when claiming) | None |

## 1. Summary

Closed fixture used to prove that a gate of a closed owner never covers a red
case.
