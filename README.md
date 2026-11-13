<a id="readme-top"></a>

<div align="center">

<img src=".wordpress-org/icon-256x256.png" alt="xNetVN Agent Hub WP icon" width="96">

# xNetVN Agent Hub WP

**A capability-scoped MCP server that keeps AI agents within the permissions of the user who issued the key.**

[![CI](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/actions/workflows/ci.yml/badge.svg)](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/actions/workflows/ci.yml)
[![CodeQL](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/actions/workflows/codeql.yml/badge.svg)](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/actions/workflows/codeql.yml)
[![PHP 8.2+](https://img.shields.io/badge/php-8.2%2B-777bb4)](composer.json)
[![License](https://img.shields.io/github/license/xnetvn-com/xnetvn-agent-hub-wp)](LICENSE)

[Wiki](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/wiki) · [User guide](docs/user-guide.md) · [Report a bug](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/issues/new?template=bug_report.yml) · [Request a feature](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/issues/new?template=feature_request.yml)

</div>

<p align="center">
  <img src=".wordpress-org/screenshot-1.png" alt="Agent Hub overview screen with the MCP endpoint, a connection check and key shortcuts" width="760">
</p>

<details>
<summary><strong>Table of contents</strong></summary>

- [Why](#why)
- [Features](#features)
- [Quick start](#quick-start)
- [Installation](#installation)
- [Usage](#usage)
- [A look inside](#a-look-inside)
- [Configuration](#configuration)
- [Architecture](#architecture)
- [Documentation](#documentation)
- [FAQ](#faq)
- [Roadmap and changelog](#roadmap-and-changelog)
- [Contributing](#contributing)
- [Support](#support)
- [Security](#security)
- [License](#license)

</details>

## Why

An MCP client gives an AI agent hands. The hard part is not connecting it; it is keeping those hands inside the permissions of the person who asked for the work, and being able to prove afterwards what happened. A shared token with broad rights is easy to set up and impossible to defend.

xNetVN Agent Hub WP adds that control layer to WordPress 6.9 and later. Every key belongs to one WordPress user, the key can only narrow what that user may already do, the owner's capabilities are rechecked on every request, and each call can be reviewed in an audit log.

- **Capability intersection, not a parallel permission system**: a call passes only when the owner's core capability, the key tier and toolsets, the site ceiling, the allow/deny lists and the authenticated transport all agree (`src/Policy/Policy.php`).
- **Split authentication**: the MCP route accepts bearer keys only; key management accepts a login cookie plus a REST nonce only, and a cookie identity never substitutes for a key (`src/Rest/ManagementGate.php`).
- **Privacy-shaped audit**: records keep identity, outcome, timing, transport and top-level argument names, never argument values, tokens, hashes or cookies (`src/Audit/AuditEvent.php`).

## Features

| | Feature | What you get |
|---|---|---|
| 🔑 | User-owned keys | `xnv_…` keys bound to one WordPress user; the secret is shown once and stored as HMAC-SHA256 with a separate random per-site pepper. |
| 🛡️ | Capability intersection | A call succeeds only when core capabilities, key scope, tier ceiling, toolset and site settings all allow it. The `admin` tier is off by default. |
| 🔀 | Split authentication | Bearer keys on the MCP route; cookie plus `wp_rest` nonce on management routes. Neither credential works on the other surface. |
| 🧰 | 62 reviewed abilities | Six toolsets: system (5), content (19), taxonomy (9), media (7), comments (10) and users (12), each with a strict input and output schema. |
| 🧾 | Audit and privacy | Top-level argument names only, bounded retention, optional IP anonymisation, and WordPress personal-data export and erasure support. |
| 🚫 | Permanent exclusions | No arbitrary PHP, SQL or shell execution, no file editors, no secret or key-management tools, and no public abilities. |

## Quick start

**Requirements:** WordPress 6.9+, PHP 8.2+, MySQL 8.0+ or MariaDB 10.6+ with InnoDB, HTTPS in production, and the official MCP Adapter `>=0.7.0 <0.8.0` to enable MCP.

```bash
composer install
composer build
```

Expected result: `composer build` writes `dist/xnetvn-agent-hub-wp-1.0.0.zip`, a release archive that already contains the Composer autoloader. Upload that ZIP in **Plugins → Add New Plugin → Upload Plugin**, activate it, then install and activate the official MCP Adapter.

## Installation

<details>
<summary><strong>From a locally built ZIP</strong></summary>

1. Build the ZIP from a checkout with `composer build` (written to `dist/`).
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, choose the ZIP and select **Install Now**.
3. Activate the plugin, then activate the official MCP Adapter `0.7.x` to enable MCP (`docs/en/INSTALL.md`).

</details>

<details>
<summary><strong>From a source checkout</strong></summary>

```bash
cd wp-content/plugins
git clone https://github.com/xnetvn-com/xnetvn-agent-hub-wp.git xnetvn-agent-hub-wp
cd xnetvn-agent-hub-wp
composer install --no-dev --optimize-autoloader
wp plugin activate xnetvn-agent-hub-wp
```

A fresh checkout without `vendor/` refuses to boot and shows a "dependencies are missing" notice instead of a fatal error (`src/Bootstrap/Loader.php`).

</details>

<details>
<summary><strong>From WordPress.org</strong></summary>

The plugin is not listed in the WordPress.org directory yet, so install it from the ZIP or a source checkout above. `readme.txt` uses the directory format for a future submission.

</details>

<details>
<summary><strong>Activation order and the adapter</strong></summary>

Both orders work. The plugin does not declare a hard `Requires Plugins` header: it activates without the adapter, shows an admin notice, and keeps the MCP server disabled until a supported adapter is active. The accepted range is exact, `0.7.0` or later and below `0.8.0`; an unsupported version is reported instead of being used (`src/Mcp/ServerGateway.php`).

</details>

## Usage

1. Open **Users → Profile** and create a key with the lowest tier and the toolsets your client needs. Copy the key now: it is shown once.
2. Point the client at the site's MCP endpoint and send the key as a bearer token.
3. Confirm the first call, then watch it in **Agent Hub → Activity**.

```bash
curl -sS https://example.com/wp-json/xnetvn-agent-hub-wp/mcp \
  -H "Authorization: Bearer $XNETVN_KEY" \
  -H "Content-Type: application/json" \
  -H "Accept: application/json, text/event-stream" \
  -H "MCP-Protocol-Version: 2026-07-28" \
  -H "Mcp-Method: tools/list" \
  -d '{
    "jsonrpc": "2.0",
    "id": 1,
    "method": "tools/list",
    "params": {
      "_meta": {
        "io.modelcontextprotocol/protocolVersion": "2026-07-28",
        "io.modelcontextprotocol/clientInfo": { "name": "example-agent", "version": "1.0.0" },
        "io.modelcontextprotocol/clientCapabilities": {}
      }
    }
  }'
```

Expected result: a JSON-RPC response whose tool list contains only the abilities the key's tier, toolsets and scope allow. The `2026-07-28` profile is sessionless, so every request repeats these headers and the `_meta` block; the older `2025-11-25` handshake needs `initialize` and `Mcp-Session-Id` instead. The full envelope, including the `tools/call` variant with `Mcp-Name`, is in the [AI agent skill pack](docs/en/guides/agent-skill-pack.md) and its [connection guide](skills/xnetvn-agent-hub-wp/references/connect.md).

## A look inside

| Keys | Activity | Access settings |
|---|---|---|
| <img src=".wordpress-org/screenshot-4.png" alt="Keys screen filtered by state and owner with rotate and revoke actions" width="300"> | <img src=".wordpress-org/screenshot-6.png" alt="Activity screen listing each request with user, ability, outcome and transport" width="300"> | <img src=".wordpress-org/screenshot-8.png" alt="Access settings with the site risk ceiling and connection options" width="300"> |

The [project wiki](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/wiki) walks through every screen, the security model and the engineering pages.

## Configuration

All settings live under **Agent Hub → Settings** and are stored per site.

| Setting | Default | What it controls |
|---|---|---|
| `enabled` | `true` | Whether the MCP server may start at all. |
| `tier_ceiling` | `destructive` | Highest risk tier any key on this site may request. |
| `allow_admin_tier` | `false` | Whether the `admin` tier can be granted. |
| `allowed_origins` | empty | Exact browser origins allowed to call the MCP route. |
| `rate_limit_key_per_min` | `120` | Requests allowed per key per minute. |
| `audit_retention_days` | `90` | Days an activity row is kept before the cleanup job removes it. |
| `media_remote_fetch_enabled` | `false` | Whether media abilities may download from a remote URL. |
| `delete_data_on_uninstall` | `true` | Whether deleting the plugin removes its keys, activity rows and settings. |

The full list, with ranges and defaults, is in [docs/en/ENVIRONMENT.md](docs/en/ENVIRONMENT.md).

## Architecture

```mermaid
flowchart LR
  client([MCP client]) --> route[Exact MCP route]
  route --> auth[Bearer auth and HMAC]
  auth --> policy[Policy and key scope]
  policy --> registry[Ability registry]
  registry --> packs[Six ability packs]
  registry --> output[Output pipeline]
  output --> audit[(Activity log)]
```

Only `src/Mcp/ServerGateway.php` holds the adapter class and hook dependencies, so an adapter upgrade stays inside one facade. See [the architecture overview](docs/en/architecture/overview.md) for the request lifecycle and layer boundaries.

## Documentation

- [Project wiki](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/wiki) — task-oriented guide, security model and engineering pages
- [AI agent skill pack](docs/en/guides/agent-skill-pack.md) — how an agent connects, chooses scope and handles failures
- [User guide](docs/user-guide.md) — every screen and setting
- [Installation](docs/en/INSTALL.md) and [environment reference](docs/en/ENVIRONMENT.md)
- [Architecture](docs/en/architecture/overview.md), [API key design](docs/en/architecture/api-keys.md) and [threat model](docs/en/security/threat-model.md)
- [Development](docs/en/development.md), [testing](docs/en/TESTING.md) and [operations](docs/en/operations.md)
- [Changelog](CHANGELOG.md) and [roadmap](docs/en/roadmap.md)

## FAQ

<details>
<summary><strong>Do I need another plugin?</strong></summary>

Yes, to use MCP. Install and activate the official MCP Adapter `0.7.x`. The plugin itself activates without it and keeps MCP disabled with an admin notice.

</details>

<details>
<summary><strong>Can agents run arbitrary PHP, SQL or shell commands?</strong></summary>

No. Those capabilities are permanently excluded. The 62 abilities cover content, taxonomy, media, comments, users and safe system discovery, each with fixed descriptions and strict schemas.

</details>

<details>
<summary><strong>How are API keys stored?</strong></summary>

A key is 60 characters in the form `xnv_<key_id>_<secret>`. The plaintext is shown once and never stored; the database keeps an HMAC-SHA256 hash and a key identifier, with a separate random 32-byte pepper in the site options.

</details>

<details>
<summary><strong>Which MCP clients work?</strong></summary>

Any client that can send a bearer token in the `Authorization` header **and** speaks an MCP HTTP profile the adapter supports: the `2026-07-28` sessionless profile, or the `2025-11-25` handshake with `initialize` and `Mcp-Session-Id`. OAuth is not implemented, so a client that requires an OAuth flow cannot connect. The client gate is prepared for MCP Inspector 2.9.0 and the FastMCP Slim 4.0.11 CLI, not desktop GUI clients; runtime acceptance of those clients remains unverified.

</details>

<details>
<summary><strong>What happens to my data when I uninstall?</strong></summary>

By default, deleting the plugin drops its keys and activity tables and removes its options and scheduled events. Turn off **Delete plugin data when uninstalled** before deleting the plugin if you want to keep that data. Content, media and terms created by agents are never deleted.

</details>

## Roadmap and changelog

The content, taxonomy, media, comments, users and system packs are implemented. Blocks, appearance, settings, plugins and maintenance remain planned. Status per milestone is tracked in the [roadmap](docs/en/roadmap.md), and every released change is recorded in [CHANGELOG.md](CHANGELOG.md).

## Contributing

Contributions are welcome. Read [CONTRIBUTING.md](CONTRIBUTING.md) before opening a pull request, keep commits in Conventional Commits form, and run `composer check` locally. New here? Start with an issue labelled [good first issue](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/labels/good%20first%20issue).

## Support

Ask a question in [Discussions](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/discussions) or open an [issue](https://github.com/xnetvn-com/xnetvn-agent-hub-wp/issues). Use the issue forms so the report arrives with the details needed to reproduce it.

## Security

Please do not report vulnerabilities in public issues. See [SECURITY.md](SECURITY.md) for private reporting to security@xnetvn.net. Remove keys, passwords, personal data and authorization headers from every report.

<p align="right"><a href="#readme-top">back to top</a></p>

## License

Released under the GNU General Public License v2.0 or later by xNetVN Inc. See [LICENSE](LICENSE).
