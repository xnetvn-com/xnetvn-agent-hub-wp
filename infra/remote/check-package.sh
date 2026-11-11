#!/usr/bin/env bash
# Validate the release artifact on an isolated, CLI-only WordPress test installation.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote test container required.\n' >&2; exit 2; }
cd /work
archive="${1:-/work/dist/xnetvn-agent-hub-wp-1.0.0.zip}"
bash /work/bin/verify-zip.sh "$archive"
site_path=/work/.local/package-site
mkdir -p "$site_path/wp-content/plugins" "$site_path/wp-content/mu-plugins"
# Copy core only; do not reuse browser-site configuration, plugins or database tables.
rsync -a --exclude=wp-content --exclude=wp-config.php /sites/wp71/ "$site_path/"
rsync -a /sites/wp71/wp-content/themes/ "$site_path/wp-content/themes/"
cat > "$site_path/wp-config.php" <<'PHP'
<?php
define('DB_NAME', getenv('WORDPRESS_DB_NAME'));
define('DB_USER', getenv('WORDPRESS_DB_USER'));
define('DB_PASSWORD', getenv('RTL_DB_PASSWORD'));
define('DB_HOST', getenv('WORDPRESS_DB_HOST'));
define('DB_CHARSET', 'utf8mb4');
define('DB_COLLATE', '');
define('WP_ENVIRONMENT_TYPE', 'local');
define('WP_DEBUG', true);
define('WP_DEBUG_DISPLAY', false);
define('WP_DEBUG_LOG', __DIR__ . '/debug.log');
define('DISABLE_WP_CRON', true);
$table_prefix = 'package71_';
if (!defined('ABSPATH')) { define('ABSPATH', __DIR__ . '/'); }
require_once ABSPATH . 'wp-settings.php';
PHP
chmod 600 "$site_path/wp-config.php"
cat > "$site_path/wp-content/mu-plugins/mail-sink.php" <<'PHP'
<?php
add_filter('pre_wp_mail', static function () { return true; });
PHP
cli=(wp --allow-root --path="$site_path")
if ! "${cli[@]}" core is-installed >/dev/null 2>&1; then
  "${cli[@]}" core install --url=https://example.test --title='Isolated package validation' --admin_user=admin --admin_password="$RTL_ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email
fi
cp -a /sites/wp71/wp-content/plugins/mcp-adapter "$site_path/wp-content/plugins/"
# Install the official WordPress.org checker when this lab site does not carry it.
if [[ -d /sites/wp71/wp-content/plugins/plugin-check ]]; then
  cp -a /sites/wp71/wp-content/plugins/plugin-check "$site_path/wp-content/plugins/"
fi
if [[ ! -d "$site_path/wp-content/plugins/plugin-check" ]]; then
  "${cli[@]}" plugin install plugin-check --activate
fi
# Pin the checker for this release; a newer version must be recorded and reviewed first.
plugin_check_version="$("${cli[@]}" plugin list --name=plugin-check --field=version)"
printf 'Plugin Check version: %s\n' "$plugin_check_version"
if [[ "$plugin_check_version" != '2.1.0' ]]; then
  printf 'Unexpected Plugin Check version %s (expected 2.1.0); record the new version and rerun.\n' "$plugin_check_version" >&2
  exit 2
fi
# Overlay extraction retains files removed from a newer archive and tests stale code.
# Remove only this disposable package fixture's previous plugin directory.
package_plugin="$site_path/wp-content/plugins/xnetvn-agent-hub-wp"
[[ ! -L "$package_plugin" ]] || { printf 'Unexpected package fixture symlink.\n' >&2; exit 2; }
if [[ -d "$package_plugin" ]]; then
  rm -r -- "$package_plugin"
fi
unzip -oq "$archive" -d "$site_path/wp-content/plugins"
"${cli[@]}" plugin activate mcp-adapter xnetvn-agent-hub-wp plugin-check
"${cli[@]}" eval 'if (!wp_get_ability("xnetvn/get-session-info")) { WP_CLI::error("Packaged system ability unavailable."); } WP_CLI::success("Packaged system ability registered.");'
"${cli[@]}" plugin check xnetvn-agent-hub-wp --require="$site_path/wp-content/plugins/plugin-check/cli.php" --format=json
sha256sum "$archive"
