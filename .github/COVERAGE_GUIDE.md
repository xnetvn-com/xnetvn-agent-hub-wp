# Native coverage validation

`coverage-check.yml` adds an independent coverage gate on pushes to `main`,
pull requests and manual runs. It does not repeat packaging or lint: `ci.yml`
owns those gates, so the coverage workflow runs only the helper regression
tests, the PHP 8.2 unit collector, the four integration collectors and the
coverage decision. The workflow keeps its own concurrency, runner and evidence
rules; it never consumes artifacts from another workflow run.

## Complete and fresh evidence

The gate consumes exactly five reports from the same workflow run and revision:

1. PHP 8.2 unit tests.
2. WordPress 6.9 single-site integration.
3. WordPress 6.9 multisite integration.
4. Current stable WordPress single-site integration.
5. Current stable WordPress multisite integration.

The adapter is separately installed at version 0.7.0. The stable WordPress version
is resolved when wp-env starts; a previous lab run against a fixed version does
not prove which version a future hosted run will resolve.

Each report includes every production PHP file under `src/`, the plugin entrypoint
and `uninstall.php`, including files with no executable lines. Reports bind those
files to their SHA-256 fingerprints and bind the test run to the tested revision.
Missing suites, omitted files, mismatched revisions or fingerprints, malformed
line data, failed tests and unexpected skips are rejected as invalid evidence.
Four network-only skips are expected in each single-site suite; unit and multisite
suites must not skip tests. The gate's skip contract accepts exactly seventeen
single-site and twelve multisite lab-only skips and rejects every other count
(ADR-0019 D4).

The custom PHPUnit 9.6 printer exports numeric JSON directly from the in-process
php-code-coverage object. It records covered and uncovered executable line IDs.
It does not export credentials, argument values, test identifiers or serialized
PHP objects. The merger unions executable line IDs and treats a line as covered
if any participating suite covered it.

Clover reports remain available for inspection. They are not used to reconstruct
the merged executable line map: the installed Clover writer can replace statement
entries with method entries. The JSON gate never downloads or executes PHP
coverage reports and never calls `unserialize` on artifact data.

## Thresholds and regression

| Scope | Minimum line coverage |
|---|---:|
| Combined production sources | 80% |
| Each integration suite overall | 70% |
| Combined Domain, Auth and Policy, each | 90% |
| Unit Domain and pure Auth helpers, each | 90% |

The 80% combined threshold is the documented 1.0 policy (ADR-0019 D1). The unit
suite has no absolute overall floor; it keeps the no-regression comparison
against the recorded baseline. The 90% domain/auth/policy and 70% per-suite
floors remain. Comparisons use integer counts, so a
percentage below a threshold cannot pass because it rounds up for display.

The regression baseline is read from `.github/coverage/M1-lines.json` at the
pull request base revision or the previous push revision. If that path is absent
from the base revision, the gate falls back to the legacy
`.ecc/coverage/M1-lines.json` in the same base revision. For manual runs and
initial branch pushes, the fallback is the fetched `origin/main` revision. The
gate does not use a baseline edited by the candidate pull request. Missing or
invalid baseline evidence fails the check. Baseline percentages and target
booleans are not substitutes for the covered/executable counts.

Regression checks cover eleven measures: combined overall, combined Domain,
Auth and Policy, unit Domain and pure Auth helpers, and the overall coverage of
each of the five suites. Historical suite names must form a complete unit record,
the WordPress 6.9 single/multisite pair, and exactly one matching recorded core
version pair above that floor. Missing, orphaned or ambiguous pairs fail validation.
The candidate's stable-version aliases compare with that recorded pair; the
baseline version is not asserted to be the currently available WordPress release.

A checkpoint recording about 83.18% combined coverage and 20.47% unit coverage
does not meet these thresholds. Adding this gate does not make the existing
plugin pass it. Any missing tests or production fixes must be addressed in their
own authorized scope; the gate must not be weakened to make the current branch
green.

## Collector invocation and artifacts

The collector is loaded with `--prepend` as the first option immediately after
`vendor/bin/phpunit`. PHPUnit 9.6 processes that option before its regular argument
parser. The printer loads the installed project autoloader itself and preserves
the normal result printer output. Integration passes the revision and suite
explicitly into the wp-env test container and starts wp-env with
`--xdebug=coverage`.

The printer writes only `.local/ci/coverage.json`, with private file permissions.
It rejects unsafe paths and an existing report rather than reusing stale data.
Each job starts from a fresh checkout and a clean workspace, including on the
self-hosted runner; the workflow resets the managed `wp-env` cache checkouts and
removes the project's Docker volumes before start, keeping images and local
files. The `wp-env` jobs prefer IPv4 and disable the 250 ms address-family
auto-selection (`NODE_OPTIONS=--dns-result-order=ipv4first
--no-network-family-autoselection`). For developer-run collection,
preserve any existing data and use a fresh, owned run directory; do not delete
unrelated reports or follow symlinks.

Reports are uploaded under distinct artifact names for seven days. The decision
job downloads each exact expected artifact, not an arbitrary artifact pattern,
and uploads its numeric decision even when a valid measurement fails a threshold.
An uploaded artifact is not proof that tests or coverage passed.
`workflow-contracts.cjs` binds the artifact names, the gate arguments and the
`CoverageGate.php` suite roster to the integration matrix.

The workflow requests only `contents: read`, pins actions to full upstream commit
SHAs and does not persist checkout credentials. It uses lockfile-based download
caches and bounded job timeouts; integration concurrency follows the
operator-set positive integer `XNETVN_CI_MAX_PARALLEL` (default 2; set it to the
number of working matching runners; `0` falls back to the hosted width, four
here) when the self-hosted switch selects the persistent runners, and is four on
GitHub-hosted runners (one VM per job), including fork pull requests. It
does not cache test results, publish a release, change repository settings,
connect to the shared test lab or upload source to an external coverage service.

The final job uses `always()` and explicitly requires successful helper, unit and
integration jobs before it reads artifacts. On the full-collection path it fails
when any prerequisite fails, is cancelled or is skipped; a documentation-only
run skips the collectors intentionally. This avoids treating a skipped final required check
as acceptance. A documentation-only pull request (the same allowlist as the
source gate) skips the collectors and reports an explicit, artifact-free success;
it never claims a coverage measurement. Every other change runs all five suites.
The workflow contracts exercise all 64 combinations of those prerequisite
outcomes, the lint aggregate outcomes and the change-detection cases. PHP helpers also run the project's WordPress standards, PHPStan level 8
without a baseline and their own regression suite.

The source, lint and packaging gates run in `ci.yml`; `workflow-lint.yml`
checks the automation itself. The legacy `ci-build`, `ci-lint`, `ci-test` and
`code-analysis` workflows were consolidated into those two files, so this
workflow now runs only the coverage collectors and the numeric decision.

## Acceptance limits

This gate measures line coverage. It does not prove branch/path coverage,
security correctness, browser accessibility, real MCP client compatibility,
production capacity, legal compliance or milestone completion. A billing-blocked
GitHub job that never starts is not a test result. Hosted execution and the
requested independent security/Claude Opus 4.6 review remain separate evidence.

External Coveralls/Codecov configuration and a public coverage badge require
verified repository/service configuration and publishing authorization. No fake
badge or unverified successful upload is supplied.

For integration, Docker, database, website, browser/E2E and debugging work outside
GitHub-hosted runners, follow the project's remote-test-lab procedure. Keep
service-free unit tests and static checks local.

## References

- [GitHub workflow syntax](https://docs.github.com/en/actions/reference/workflows-and-actions/workflow-syntax)
- [Reusable workflows](https://docs.github.com/en/actions/how-tos/reuse-automations/reuse-workflows)
- [Secure use of GitHub Actions](https://docs.github.com/en/actions/reference/security/secure-use)
- [PHPUnit 9.6 coverage](https://docs.phpunit.de/en/9.6/code-coverage-analysis.html)
- [WordPress environment tooling](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/)
- [Project coverage procedure](../docs/en/development-coverage.md)
