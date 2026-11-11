# ADR-0019: Coverage enforcement policy

Status: Accepted (2026-10-31). Owner reviewed the threshold model on 2026-10-31 and chose the documented 1.0 policy instead of the stricter numbers that the CI gate declared on 2026-10-28. Amended on 2026-11-05 (A1).

## Context

Commit `1d58adb` introduced numeric coverage thresholds in `.github/scripts/CoverageGate.php`. The enforced numbers were `combined.overall >= 95`, `combined.Domain|Auth|Policy >= 90`, `unit.overall >= 80`, four integration suites `>= 70`, `unit.Domain|PureAuth >= 90`, plus a no-regression comparison against `.ecc/coverage/M1-lines.json` (now `.github/coverage/M1-lines.json`).

The native coverage run on `0882134` measured `combined.overall 6533/7857 = 83.15%`, `combined.Auth 391/435 = 89.89%`, and `unit.overall 1416/7841 = 18.06%`. The gate never passed on `main`, so every pull request inherited a red required job.

Two project sources disagree with the gate:

- `docs/en/TESTING.md` §1 and `docs/vi/TESTING.md` §1 promised 90% for `Domain`, `Auth` and `Policy` and **80% project-wide** for releases, not 95% combined and not 80% per unit suite.
- `.ecc/coverage/M1-lines.json` (now `.github/coverage/M1-lines.json`) records `unit.overall 572/2794 = 20.47%`. The unit suite is not a whole-plugin filter: the four integration suites exercise the WordPress runtime. An 80% unit floor therefore contradicted the recorded baseline and the documented design.

The coverage gate is a release-quality signal, not a substitute for behaviour tests. Lowering a floor without keeping the anti-regression rule would hide real losses.

## Decisions

- D1 (owner, 2026-10-31): enforce the documented 1.0 thresholds per pull request:
  - `combined.overall >= 80`
  - `combined.Domain >= 90`, `combined.Auth >= 90`, `combined.Policy >= 90`
  - each integration suite `>= 70`
  - `unit.Domain >= 90`, `unit.PureAuth >= 90`
- D2 (owner, 2026-10-31): remove the absolute `unit.overall >= 80` floor. The unit suite keeps the no-regression comparison (`572/2794` baseline) instead of an absolute target that the suite was never designed to meet.
- D3: keep the no-regression rule for every measure recorded in the baseline: `combined.overall`, `combined.Domain`, `combined.Auth`, `combined.Policy`, `unit.Domain`, `unit.PureAuth`, and `unit.overall`.
- D4: keep the skip contract that accepts exactly 17 single-site and 12 multisite skips for lab-only cases, and rejects every other skip count.
- D5: do not lower any threshold again without a new owner decision and a superseding ADR.

## Amendments

- A1 (owner, 2026-11-05): documentation-only pull requests are exempt from coverage collection. The exemption uses the same allowlist as the source gate (`docs/**`, root `README.md`, `CONTRIBUTING.md`, `SECURITY.md`, `SUPPORT.md`, `CODE_OF_CONDUCT.md`, `CHANGELOG.md`). Every other pull request keeps D1–D4 unchanged. The required check reports success with an explicit notice and never claims a measurement. This exemption does not lower any threshold, does not change the release coverage requirement and does not relax the no-regression rule for source changes.

## Consequences

- The gate now matches `docs/en/TESTING.md` and `docs/vi/TESTING.md`; the documentation describes the enforced numbers explicitly.
- Recovering the no-regression measures required new behaviour tests instead of a threshold edit: `tests/Unit/Abilities/RegistryTest.php`, `tests/Unit/Abilities/OutputPipelineTest.php`, `tests/Unit/Auth/ServiceWiringTest.php`, `tests/Unit/Domain/ValueObjectGuardsTest.php`, and the idempotent `tests/Support/StandaloneWordPressError.php` fixture.
- Local evidence at this change: `unit.overall 1627/7846 = 20.74%` (above the `20.47%` baseline), `combined.overall 6574/7862 = 83.62%`, `combined.Auth 395/435 = 90.81%`, `combined.Domain 68/69 = 98.55%`, `unit.Domain 68/69 = 98.55%`.
- Raising the numbers later is a normal ratchet: add tests first, then change this ADR and the gate in the same reviewed pull request. The 95/80 numbers may return as release gates once the source reaches them with measured evidence.
- The gate remains a numeric check on measured lines. It does not replace integration, runtime, client or production acceptance.
