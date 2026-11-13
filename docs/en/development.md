# Development

Use PHP 8.2+, Composer 2.4+ for security auditing, Node 24, npm, Docker and WP-CLI. Install locked dependencies with `composer install` and `npm ci`. A project-local Composer PHAR may be used without upgrading system tools.

| Check | Command |
|---|---|
| Dependency metadata | `composer validate --strict` |
| WordPress style | `composer lint` |
| PHP 7.2 bootstrap syntax | `composer lint:bootstrap` |
| Static analysis | `composer analyse` |
| Unit tests | `composer test:unit` |
| All static/unit gates | `composer check` |
| Documentation gates | `composer check:docs` |
| Public skill pack | `composer check:skills` |
| Start disposable WordPress | `npm run env:start` |
| Real WordPress integration | `composer test:integration` |
| Remote HTTP rejection matrix | `composer test:http` |
| Review remote plan | `npm run remote:plan` |
| Deploy approved remote plan | `npm run remote:up` |
| Remote browser flows | `npm run test:e2e` |
| Dependency vulnerabilities | `composer audit` |
| Translation template | `bash bin/make-pot.sh` |
| Release ZIP | `composer build` |
| Release exclusion regressions | `composer test:packaging` |

Services, databases, websites, browsers and E2E/debug work run through remote-test-lab. Local linters and service-free unit tests remain local. The approved remote toolbox uses WordPress6.9 by default; `composer test:integration -- 7.1.2` selects the current-stable fixture. Core integration and browser sites use distinct table prefixes. Hosted CI retains disposable wp-env execution; do not start it on this workstation.

`XNETVN_TEST_MULTISITE=1 composer test:integration -- 6.9` runs the isolated core harness as multisite in the remote toolbox; use7.1.2 for the current-stable fixture. The browser installations stay unchanged. CI adds two required multisite combinations (PHP8.2, minimum/current WordPress, minimum adapter) and passes `WP_MULTISITE` inside the tests container. Other integration combinations remain single-site.

The current environment requires the official MCP Adapter, activated before this plugin. CI defines PHP 8.2–8.5 unit checks and stable WordPress integration combinations. PHP 8.5 integration and WordPress trunk remain experimental until actual compatibility evidence is recorded. wp-env 11.16 deprecates its dual-environment settings; they are retained for the required tests-cli setup until a separate-configuration migration has been verified.

Development dependency overrides select patched compatible-major versions of ajv, qs and ws because upstream Playground tooling pins versions with published advisories. The npm lock file records the selected versions. Reverify wp-env startup/integration and npm audit whenever changing these overrides; they are not production plugin dependencies.

Do not run `wp-env clean` on an environment containing valuable data. Smoke uninstall tests must use disposable sites or packaged copies, as uninstall deletes plugin files. The release builder stages a copy, installs production-only dependencies and removes the lock file only from that staging copy. The source checkout keeps its lock file and development dependencies.

The build command first tests that ZIP validation rejects hidden environment/configuration files and remote infrastructure. Run `infra/remote/check-package.sh` inside the lab toolbox after building to activate and check the actual package in separate CLI-only tables. Its WordPress test mail sink prevents outbound delivery.

The HTTP matrix runs in the owned lab toolbox against its WordPress6.9 HTTPS alias and internal plain-HTTP service. It creates random disposable users/keys, records credentials only in a server-side0700 directory with0600 state, and writes boolean/status-only results. The runner refuses an existing fixture until reconciled and cleans up bound keys/users/audit/options/mu-plugin files on normal exits. A killed container/process requires explicit reconciliation using the saved private state. Cookie/nonce creation uses the same alias as the HTTP client, so WordPress's COOKIEHASH matches. The test-only certificate exception is scoped to an allowlisted lab host; redirects are never followed. Nothing in this fixture ships in the production ZIP.

If this host's Node connection-family selection fails while IPv4 HTTPS succeeds, a process-local `NODE_OPTIONS=--no-network-family-autoselection` can be used for diagnosis. Do not disable TLS validation.

Official references: [wp-env](https://developer.wordpress.org/block-editor/reference-guides/packages/packages-env/), [WordPress Coding Standards](https://developer.wordpress.org/coding-standards/wordpress-coding-standards/), [PHP lifecycle](https://www.php.net/supported-versions.php). Verified 2026-10-04.
