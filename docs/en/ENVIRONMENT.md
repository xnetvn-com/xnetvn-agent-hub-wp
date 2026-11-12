# Environment Configuration Guide (Environment Configuration)

> **Detailed documentation of environment variables, configuration constants and optional settings for xNetVN Agent Hub WP.**

---

## 1. PHP / WordPress Configuration Constants (Constants)

The following constants can be defined in `wp-config.php` to customize plugin behavior:

| Constant | Type | Default | Behavior |
|---|---|---|---|
| `XNETVN_AGENT_HUB_WP_ALLOW_INSECURE` | `bool` | `false` | Allows HTTP only in explicitly local/development environments. Never enable in production. |


---

## 2. Database Options (WordPress Options)

These settings are stored in the `wp_options` table of each website (or each subsite in a Multisite network):

### 2.1 System Configuration (`xnetvn_agent_hub_wp_settings`)
Stored as a non-autoloaded WordPress option array with these fields:

| Setting | Type | Default |
|---|---|---|
| `enabled` | `bool` | `true` |
| `allowed_roles_override` | `null or array` | `null` |
| `tier_ceiling` | `string` | `"destructive"` |
| `allow_admin_tier` | `bool` | `false` |
| `max_keys_per_user` | `int` | `10` |
| `max_key_lifetime_days` | `int` | `365` |
| `allowed_origins` | `array` | `[]` |
| `site_ip_allowlist` | `array` | `[]` |
| `trusted_proxy_headers` | `array` | `[]` |
| `trusted_proxy_cidrs` | `array` | `[]` |
| `fallback_header_enabled` | `bool` | `true` |
| `rate_limit_key_per_min` | `int` | `120` |
| `rate_limit_auth_fail_per_min_ip` | `int` | `10` |
| `output_max_bytes` | `int` | `262144` |
| `audit_retention_days` | `int` | `90` |
| `anonymize_ip` | `bool` | `false` |
| `exposed_third_party_abilities` | `array` | `[]` |
| `disable_adapter_default_server` | `bool` | `false` |
| `notify_key_created` | `bool` | `true` |
| `notify_key_revoked` | `bool` | `true` |
| `notify_key_expiring` | `bool` | `true` |
| `notify_unusual_ip` | `bool` | `true` |
| `delete_data_on_uninstall` | `bool` | `true` |
| `media_remote_fetch_enabled` | `bool` | `false` |
| `media_remote_max_bytes` | `int` | `10485760` |
| `media_remote_timeout_seconds` | `int` | `15` |
| `media_remote_max_redirects` | `int` | `3` |
| `media_data_max_bytes` | `int` | `1048576` |
| `media_pixels_max` | `int` | `40000000` |
| `media_mime_denylist` | `array` | `[]` |

The non-autoloaded WordPress option stores a PHP array, not a separate JSON document. These defaults come from WpSiteSettings::DEFAULTS. Key toolsets are selected from the private registry, not an enabled_toolsets site option. Numeric media ranges: remote bytes 1024..67108864; data bytes 1024..8388608; timeout 3..30 seconds; redirects 0..5; pixels 1000000..100000000. MIME denylist can only remove approved types. Remote fetching defaults off. Limits are provisional budgets, not measured production capacity.

### 2.2 Secret Pepper (`xnetvn_agent_hub_wp_pepper`)
- **Value:** A 32-byte binary string randomly generated using `random_bytes(32)`.
- **Autoload configuration:** `autoload = 'no'` (must not be automatically loaded into memory for every ordinary WordPress request).
- **Purpose:** Secret key used by `hash_hmac('sha256', $secret, $pepper)` when authenticating API keys.

---

## 3. Per-Environment Configuration (Environment Profiles)

### 3.1 Local Development Environment (Local / Development)
- Enabling `XNETVN_AGENT_HUB_WP_ALLOW_INSECURE = true` is allowed when testing without a local SSL certificate.
- Enable `WP_DEBUG = true` and `WP_DEBUG_LOG = true`.
- Use WP-Env or Docker Compose tools.

### 3.2 Staging & Automated Testing Environment (Staging / CI)
- Full HTTPS is mandatory.
- Configure Redis Cache to test distributed rate counters.
- Run the entire integration and E2E test matrix.

### 3.3 Production Environment (Production)
- HTTPS/TLS with a valid certificate is mandatory.
- Keep the maximum site ceiling at `destructive` (do not enable `admin` unless truly necessary and closely monitored).
- Configure Proxy/CDN IP ranges accurately to protect against IP Spoofing.
- Ensure the MySQL database uses InnoDB and scheduled backups are configured for `wp_xnetvn_agent_hub_wp_keys` and the Pepper option.
