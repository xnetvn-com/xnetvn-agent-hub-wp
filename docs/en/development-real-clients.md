# Published MCP client gate

Status: implementation prepared; runtime acceptance remains unverified. This gate covers published Inspector 2.9.0 and FastMCP Slim 4.0.11 CLI applications. It does not establish desktop GUI behavior, manual accessibility acceptance, production readiness or full M1 completion.

The remote-only toolbox installer creates an exclusive private tooling directory, rejects symlink ancestors, verifies the FastMCP wheel digest and pins Inspector's version. Installation and version probes have time limits. Existing tooling requires reconciliation. Inspector has no supported version flag: read installed package metadata instead of launching its web application.

Before creating credentials, provision the public lab certificate through a trusted administrative channel into the fixed private trust directory. Never export the private key. Trusting an unverified peer's certificate is insufficient. Certificate-chain and hostname verification remain enabled. Client environments exclude inherited runtime injection, proxies, TLS-disable flags and TLS key logging.

The runner refuses existing fixtures. Ephemeral keys, users and configuration remain in private server files. CLI argv contains configuration paths, never tokens. Raw client output and exception traces are not exported.

Each application must list the permitted tools, identify the key owner, execute a synthetic read and hide higher-tier tools. After bound-key revocation, a failed CLI invocation alone is insufficient. Test-only server instrumentation must record HTTP401, the actual internal revoked-key reason and anonymous request context for the exact MCP route, validated ephemeral control and allowlisted client case. Missing evidence, malformed JSON and unrelated transport errors fail the gate.

Instrumentation is excluded from the release ZIP and records only bounded status/boolean evidence. Cleanup restores settings, removes bound users/keys/audit records and instrumentation, then deletes private client configuration. Failed cleanup or remaining paths cannot return success.

Local regressions: `python3 -I -B tests/Tooling/test_client_safety.py` and `python3 -I -B tests/Tooling/test_client_cleanup.py`. Cleanup failure injection verifies that failed removal cannot report success and successful cleanup preserves the client failure status. The previous cleanup behavior was reproduced with a source mutation: exit0 while private files remained. Run WordPress HTTP/integration behavior remotely before acceptance. Preserve failures and recovery evidence separately from passing results.

Official TLS contracts: [Python SSL default client context](https://docs.python.org/3/library/ssl.html#ssl.create_default_context) and [Node extra CA certificates](https://nodejs.org/api/cli.html#node_extra_ca_certsfile), checked 2026-10-06.
