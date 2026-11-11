# Coverage checkpoint

Measured 2026-10-06 UTC / local with PHP8.2.34, Xdebug3.5.3, PHPUnit9.6 and the installed php-code-coverage9.2 merger. [Numeric evidence](../../.github/coverage/M1-lines.json) contains relative paths, source fingerprints and executable-line counts only. Raw artifacts and test logs stay in fresh private directories on the owned remote lab; they are not published.

| Scope | Covered / executable | Coverage |
|---|---:|---:|
| All95 production PHP files, including bootstrap/uninstall |2325 /2795|83.1843%|
| Domain, combined |63 /67|94.0299%|
| Auth, combined |387 /429|90.2098%|
| Policy, combined |48 /53|90.5660%|
| Domain, unit only |63 /67|94.0299%|
| Pure Auth helpers, unit only |57 /60|95.0000%|
| Entire Auth namespace, unit only |191 /429|44.5221%|

Pure Auth includes FailureReason, GeneratedKey, HeaderExtractor, IssuedKey, KeyGenerator, KeyHasher, ParsedToken, Pepper, StoredKey and TokenParser. These classes do not call WordPress APIs; platform/storage services remain in the combined Auth scope. This distinction follows original M1-S01's pure-PHP requirement and does not exclude those services from release coverage. Full Auth unit coverage must not be reported as 95%.

The combined figure merges177 unit tests and four WordPress integration suites on6.9/7.1.2, single-site and actual multisite. Each single-site run has four deliberately skipped network-only cases; both multisite runs execute them. The merger requires the exact complete source filter from every artifact and includes files with no executed statements. Missing evidence never counts as zero coverage or success. Denominator changes from loaded/unloaded executable-line analysis are retained, not adjusted to pass. In particular,89.9767% Auth was below90%; an additional corrupt-verifier test brought the measured result above the threshold without rounding.

New cases verify late cached-cookie replacement, preservation of earlier denials, route isolation, storage-unavailable503 and rate429, challenge/cache/retry headers, live tool visibility, pepper opacity/corruption, malformed stored verification metadata, deferred lifecycle notices, failed-delivery retry and suppression for a deleted key. WordPress functions/storage are substituted only in isolated unit processes; integration uses the actual core/database and intercepts every email before delivery. Unit substitutes are not real HTTP evidence.

## Reproduction

Use remote-test-lab only. The toolbox image installs the official pinned Xdebug archive after checking its SHA-256, and defaults to no active Xdebug features. The website images remain unchanged. In the owned toolbox, run `bash /work/infra/remote/run-coverage.sh`. It refuses active browser-site cache drop-ins, creates a fresh0700 directory, writes0600 PHPUnit artifacts and runs unit plus all four integration modes sequentially with `XDEBUG_MODE=coverage` and a bounded768MiB PHP limit. Reporting uses the installed native merger with debugging disabled. No previous artifact is reused.

The reporting script accepts only the runner's fixed five filenames from an owned private directory. It never accepts a user-selected PHP report or external serialized data. Do not copy raw artifacts into Git. The public JSON is a reviewed numeric projection; source hashes bind it to the tested production files.

## Limits and next gates

Line coverage does not prove branch/path coverage, security correctness, real-client compatibility or production capacity. Auth remains close to its release threshold, so new functionality must keep the denominator and these gates intact. HTTP35/35, real Redis and benchmarks are recorded separately; coverage does not replace them. Full M1, laterM2-M7 features, two real client applications, unresolvedN02 wording and the full HTTPS performance target remain incomplete.

Official references verified2026-10-06: [Xdebug stable downloads](https://xdebug.org/download), [Xdebug active-mode inspection](https://xdebug.org/docs/all_functions#xdebug_info), [PHPUnit9.6 coverage analysis](https://docs.phpunit.de/en/9.6/code-coverage-analysis.html). Merger/filter/report APIs were verified against the installed9.2 source before use.
