# Security policy

This policy covers version 1.0.0. Production deployment and security acceptance depend on completing the acceptance run for that version; this document does not establish either.

Report suspected vulnerabilities privately to **security@xnetvn.net**. Include affected versions, a minimal reproduction, observed impact and relevant environment details. Remove passwords, API keys, personal data and authorization headers. Do not post exploit details or secrets in public issues.

The project does not promise a response deadline or a supported release period before its first stable release. GitHub private vulnerability reporting is a separate repository setting and has not been verified or changed by this implementation.

See the [threat model](docs/en/security/threat-model.md). Arbitrary SQL/PHP/shell execution, file editors, secret access and key management through MCP abilities are permanently excluded.
