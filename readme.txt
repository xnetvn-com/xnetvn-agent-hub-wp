=== xNetVN Agent Hub WP ===
Contributors: xnetvn
Tags: mcp, ai, abilities, automation
Requires at least: 6.9
Tested up to: 7.1
Requires PHP: 8.2
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html

A private MCP server for AI agents that limits every request to the WordPress permissions of the key owner.

== Description ==

xNetVN Agent Hub WP gives an AI agent a reviewed set of WordPress tools without giving it the site. Each key belongs to a WordPress user, every request is rechecked against that user's current permissions and the key scope, and each call can leave an audit record you can review.

= What you can do =

* **Hand an agent a real, limited identity.** Effective permission is the intersection of the user's capabilities, the key scope, the site ceiling and the selected toolsets. Revoking the key or changing the role applies on the next request.
* **Choose tools one group at a time.** 62 reviewed abilities in six toolsets: content, taxonomy, media, comments, users and system. System is always on; the others are opt-in, and individual abilities can be denied.
* **Keep risky work behind a higher tier.** Abilities are read, write, destructive or admin. The site ceiling defaults to destructive, admin is off by default, and a destructive or admin key asks for the account password.
* **See and account for what happened.** Activity lists time, user, ability, outcome and transport, with filters for owner, key, ability, outcome and date range; rows are kept 90 days by default. A personal data exporter and eraser cover the owner's keys, activity and preferences.
* **Keep credentials out of reach.** A key is shown once; only an HMAC-SHA256 hash keyed with a separate random per-site pepper is stored. The MCP route takes bearer keys only; management routes take a cookie with a REST nonce.
* **Bound abuse and control media.** Per-key and per-IP limits, a response cap, IP and origin allowlists and a TLS requirement run first. Remote media download is off by default and accepts public HTTPS only, with byte, pixel and MIME limits.

= Get started in minutes =

1. Install and activate the official MCP Adapter 0.7.x from https://wordpress.org/plugins/mcp-adapter/ to enable MCP. You can activate xNetVN Agent Hub WP first; it shows a notice and keeps MCP disabled until the adapter is active.
2. Install and activate xNetVN Agent Hub WP, then create a key on your profile with the lowest tier and toolsets the client needs.
3. Point the client at https://example.com/wp-json/xnetvn-agent-hub-wp/mcp and send the key as a bearer token.

= Who it is for =

Site owners, agencies and developers who want an AI agent to work with posts, media, comments, taxonomy and users under a real WordPress identity, without code execution, SQL, file edits or security settings.

= Compatibility =

* WordPress 6.9 or later, PHP 8.2 or later.
* Single-site and Multisite test suites target WordPress 6.9 and 7.1.2.
* The official MCP Adapter 0.7.x (0.7.0 or later, below 0.8.0), installed and active to use MCP. The plugin can be activated without it; MCP stays disabled and an admin notice explains the missing dependency.
* Clients that send a bearer token; OAuth is not implemented. The client gate covers MCP Inspector 2.9.0 and FastMCP Slim 4.0.11 CLI, not desktop GUI clients.

= Privacy, security and your data =

* **Privacy:** Keys, activity, settings and the pepper stay in your database. No telemetry and no vendor service.
* **Security:** Report vulnerabilities to security@xnetvn.net; never include secrets in a public issue. No bug bounty.
* **Uninstall:** "Delete plugin data when uninstalled" is on by default and removes keys, activity, site options, events and notification preferences. The capability-less banned role and two per-user options (ban snapshot, password-reset timestamp) are not removed. Turn it off first to keep the site data.
* **Source code:** https://github.com/xnetvn-com/xnetvn-agent-hub-wp

= Translations and accessibility =

A translation template ships in languages/; no translated language packs exist yet and this readme is English only. No accessibility conformance or performance claim is made for this release.

= Documentation and support =

* User guide: https://github.com/xnetvn-com/xnetvn-agent-hub-wp/blob/main/docs/user-guide.md
* Project wiki: https://github.com/xnetvn-com/xnetvn-agent-hub-wp/wiki
* Support forum: https://wordpress.org/support/plugin/xnetvn-agent-hub-wp/
* Issues: https://github.com/xnetvn-com/xnetvn-agent-hub-wp/issues

= For developers =

* Hooks include xnetvn_agent_hub_wp_loaded, xnetvn_agent_hub_wp_policy_decision, xnetvn_agent_hub_wp_tool_started, xnetvn_agent_hub_wp_tool_finished and xnetvn_agent_hub_wp_remote_url_allowed.
* Management REST namespace /wp-json/xnetvn-agent-hub-wp/v1/, MCP endpoint /wp-json/xnetvn-agent-hub-wp/mcp, and two capabilities: use_xnetvn_agent_hub_wp and manage_xnetvn_agent_hub_wp, both evaluated dynamically. Banning a user creates a capability-less banned role when the site does not have one.

= Credits =

* Uses the official MCP Adapter by WordPress.org (GPL-2.0-or-later) and the WordPress Abilities API; banner and icon are original artwork with no third-party logo or bundled font.

== External services ==

The plugin has no vendor service and contacts none on its own. It can make one kind of outbound request, only for an authorized MCP client.

* **Service:** The destination is the public HTTPS URL supplied by the client that calls upload-media-from-url. There is no fixed provider.
* **When it is called:** Never on installation, activation, cron or an admin page load. Only when "Enable public HTTPS media downloads" is on (off by default; an administrator enables it) and a key calls upload-media-from-url. With it off, the ability returns a "remote fetch disabled" error and no request is made.
* **What is sent:** One HTTPS GET to that URL, with the path and query string, the server IP address and a standard user agent. Redirects follow only through the same public-HTTPS policy.
* **What comes back:** The file is stored in the site's own uploads directory. xNetVN receives nothing: no URL, no file, no report.
* **Terms and privacy:** The destination site's terms and privacy policy apply, because the request comes from your server as an ordinary visitor.

== Installation ==

= Automatic installation =

1. In your dashboard, go to Plugins > Add New Plugin and search for "xNetVN Agent Hub WP".
2. Select Install Now, then Activate.

= Manual installation =

1. Download the plugin zip from WordPress.org.
2. Go to Plugins > Add New Plugin > Upload Plugin, choose the zip and select Install Now.
3. Activate the plugin.

= After activation =

1. Install and activate the official MCP Adapter 0.7.x to enable MCP. The plugin can be activated without it; MCP stays disabled and an admin notice explains the missing dependency.
2. Create a key on your own Profile page, store it in the client secret store, and configure the client at https://example.com/wp-json/xnetvn-agent-hub-wp/mcp with the key as a bearer token.

For a source checkout, run composer install before activation.

== Frequently Asked Questions ==

= Do I need another plugin? =

Yes, to use MCP. Install and activate the official MCP Adapter 0.7.x from https://wordpress.org/plugins/mcp-adapter/. The plugin can be activated without it; it shows an admin notice and keeps the MCP server disabled until the adapter is active. It also checks the adapter version before starting the server.

= Which WordPress and PHP versions do I need? =

WordPress 6.9 or later and PHP 8.2 or later. The maintained single-site and Multisite suites target WordPress 6.9 and 7.1.2.

= Does this plugin make my site public? =

No. The MCP endpoint is a private route that requires a user-owned bearer key. It registers no public abilities, and a key only narrows what its owner may already do.

= Can agents run arbitrary PHP, SQL or shell commands? =

No. Those capabilities are permanently excluded. The 62 abilities cover content, taxonomy, media, comments, users and safe system discovery, each with a fixed description and strict schemas.

= How are API keys stored? =

A key is 60 characters in the form xnv_<key_id>_<secret>. The plaintext value is shown once and never stored. The database keeps an HMAC-SHA256 hash and a key identifier, with a separate random 32-byte pepper in the site's options table.

= What limits can I set? =

Per-key request limits and per-IP failed-authentication limits, maximum key lifetime and count, a response cap, site and key IP allowlists, an origin allowlist, a site risk ceiling, and media byte, timeout, redirect, pixel and MIME limits.

= Does it work on Multisite? =

Yes. Settings and data are stored per site. Network-wide deactivation clears scheduled events on every site, and uninstall checks each site's setting while keeping shared per-user preferences as long as any site keeps its data.

= Which MCP clients work? =

Any client that can send a bearer token in the Authorization header. OAuth is not implemented, so a client that requires an OAuth flow cannot connect.

= What happens to my data when I uninstall? =

Deleting the plugin removes its own data by default: keys and activity tables are dropped, its site options and events are deleted, and notification preferences are removed. The capability-less banned role and the per-user ban snapshot and password-reset timestamp stay. Content, media and terms created by agents are not deleted.

= Why do I get a 401 response? =

The key is missing, expired or revoked, or the web server strips the Authorization header. Send "Authorization: Bearer your_api_key", check the key on the Keys page, and ask your host to forward the header. A logged-in session is not a substitute for a key.

= Why do I get a 403 or 429 response? =

A 403 is a request refused before the ability ran: non-HTTPS, an Origin outside the allowlist, a missing capability, a tier or scope above the key, or an IP outside an allowlist. A 429 means a per-minute limit; wait for Retry-After and retry reads only.

= Where can I get help? =

Use https://wordpress.org/support/plugin/xnetvn-agent-hub-wp/ or open an issue at https://github.com/xnetvn-com/xnetvn-agent-hub-wp/issues. Report security issues privately to security@xnetvn.net.

== Screenshots ==

1. Overview - start here: create a key, copy the endpoint, check the connection.
2. Your profile - create a key with the lowest tier and toolsets the client needs.
3. The new key is shown once - copy it into the client secret store now.
4. Keys - filter by state and owner, then revoke or rotate without touching other users.
5. Tools - see which reviewed abilities a key can reach, with tier and toolset.
6. Activity - every request with user, ability, outcome and transport.
7. Activity filtered - see which calls were refused and by which ability.
8. Settings: Access - decide who may connect and the highest risk a key can request.
9. Settings: Media - bound remote downloads, uploads, size and MIME types.
10. Help - connect a client, read the common failures, revoke in an emergency.

== Changelog ==

= 1.0.0 =
* First 1.0 release: 62 private abilities across six toolsets, user-owned keys with per-key scope, audit and privacy tooling, and guarded MCP transport through the official MCP Adapter 0.7.x.
* The plugin can be activated before the MCP Adapter is installed; MCP stays disabled with an admin notice until a supported adapter is active.
* Deactivation clears the plugin's owned scheduled events on the current site, and on every site for network-wide deactivation.
* Remote media fetch honors the site's WP_HTTP_BLOCK_EXTERNAL and WP_ACCESSIBLE_HOSTS settings on every request and redirect hop.

= 0.1.0 =
* Initial pre-release foundation, runtime requirement checks and developer tooling.

== Upgrade Notice ==

= 1.0.0 =
First public release. Requires WordPress 6.9, PHP 8.2 and the official MCP Adapter 0.7.x.
