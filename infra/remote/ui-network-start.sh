#!/usr/bin/env bash
# Owned multisite network installer for UI acceptance (P13). Runs as the
# toolbox command of compose.ui-network.yaml only: fresh named volumes, no
# conversion of the single-site wp69/wp71 labs, no server-wide action.
#
# Order matters. The guards run before any mutation, the mail sink is
# prepared before the child site exists, and the child site is created before
# the plugin is activated on it, so a cold start never touches a blog that
# does not exist yet.
set -euo pipefail

# The lab guard: this installer never runs outside the owned test namespace.
if [ "${XNETVN_RTL:-}" != "1" ]; then
  printf 'The network installer requires the lab environment.\n' >&2
  exit 2
fi

work_dir='/work'
site_path='/sites/wpnetwork'
if [ "$(pwd)" != "$work_dir" ]; then
  cd "$work_dir"
fi
if [ ! -d "$site_path" ]; then
  printf 'The owned network volume is missing.\n' >&2
  exit 2
fi

# The network domain and its alias must both be known and must differ. The
# single-site hosts come from the lab environment file, not from the process
# environment: the network toolbox only declares the network hosts, so a
# process environment check would never see a single-site host and would pass
# without proving anything.
: "${RTL_DOMAIN_WPNETWORK:?Provide the test network domain}"
: "${RTL_ALIAS_WPNETWORK:?Provide the test network alias}"
if [ "$RTL_DOMAIN_WPNETWORK" = "$RTL_ALIAS_WPNETWORK" ]; then
  printf 'The network domain and the network alias must differ.\n' >&2
  exit 2
fi
xnetvn_env_file="$work_dir/.env"
if [ ! -f "$xnetvn_env_file" ]; then
  printf 'The lab environment file is missing: the network host guard cannot run.\n' >&2
  exit 2
fi
xnetvn_single_hosts=''
for xnetvn_single_name in RTL_DOMAIN_WP69 RTL_ALIAS_WP69 RTL_DOMAIN_WP71 RTL_ALIAS_WP71; do
  xnetvn_single_host="$(sed -n -E "s/^${xnetvn_single_name}=(.*)$/\1/p" "$xnetvn_env_file" | head -n 1)"
  if [ -z "$xnetvn_single_host" ]; then
    printf 'The lab environment file has no %s: the network host guard cannot run.\n' "$xnetvn_single_name" >&2
    exit 2
  fi
  xnetvn_single_hosts="$xnetvn_single_hosts $xnetvn_single_host"
done
for xnetvn_single_host in $xnetvn_single_hosts; do
  if [ "$xnetvn_single_host" = "$RTL_DOMAIN_WPNETWORK" ] || [ "$xnetvn_single_host" = "$RTL_ALIAS_WPNETWORK" ]; then
    printf 'The network host must not collide with a single-site host.\n' >&2
    exit 2
  fi
done

composer install --prefer-dist --no-interaction --no-progress
export WORDPRESS_TABLE_PREFIX=ui_network_
export XNETVN_RTL_DOMAIN="$RTL_DOMAIN_WPNETWORK" XNETVN_RTL_ALIAS="$RTL_ALIAS_WPNETWORK"

if ! wp --allow-root --path="$site_path" core is-installed >/dev/null 2>&1; then
  wp --allow-root --path="$site_path" core multisite-install --subdomains=0 --url="https://$XNETVN_RTL_DOMAIN" --title='Agent Hub WordPress Network Test' --admin_user=admin --admin_password="$RTL_ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email
fi
if ! wp --allow-root --path="$site_path" plugin is-installed mcp-adapter; then
  # The dependency directory is a named volume: an upgrader cannot remove its mount point.
  adapter_stage="$(mktemp -d)"
  curl --fail --location --output "$adapter_stage/adapter.zip" https://downloads.wordpress.org/plugin/mcp-adapter.0.7.0.zip
  printf '%s  %s\n' '37494e71072cde9f486041bb292e1d7b2ab55670ffaf0252ad1ee656b16906ca' "$adapter_stage/adapter.zip" | sha256sum --check
  unzip -q "$adapter_stage/adapter.zip" -d "$adapter_stage/unpacked"
  cp -a "$adapter_stage/unpacked/mcp-adapter/." "$site_path/wp-content/plugins/mcp-adapter/"
  rm -rf -- "$adapter_stage"
fi

# The mail sink belongs to the owned network volume only. The preparation
# refuses to overwrite foreign content and fails instead, so an unexpected file
# stops the installer before any actor exists.
wp --allow-root --path="$site_path" eval-file /work/tests/E2E/prepare.php --use-include
xnetvn_sink="$site_path/wp-content/mu-plugins/xnetvn-agent-hub-wp-e2e-mail-sink.php"
if [ ! -f "$xnetvn_sink" ] || ! grep -q 'pre_wp_mail' "$xnetvn_sink"; then
  printf 'The owned mail sink is missing or carries foreign content.\n' >&2
  exit 2
fi

# The child site exists before the plugin is activated on it.
child_url="https://$XNETVN_RTL_DOMAIN/ui-second/"
if ! wp --allow-root --path="$site_path" site list --field=url 2>/dev/null | grep -qxF "$child_url"; then
  wp --allow-root --path="$site_path" site create --slug=ui-second --title='Agent Hub Second Site Test' --email=admin@example.test
fi
for site_url in "https://$XNETVN_RTL_DOMAIN" "$child_url"; do
  wp --allow-root --path="$site_path" --url="$site_url" plugin activate mcp-adapter xnetvn-agent-hub-wp
  wp --allow-root --path="$site_path" --url="$site_url" option update permalink_structure '/%postname%/'
done

# Subdirectory multisite routing needs the core multisite rewrite block.
# `core multisite-install` never writes .htaccess itself, and a flush can leave
# single-site rules behind (all requests to /index.php), which routes
# /ui-second/ to a frontend 404. Rewrite the file idempotently on every start so
# fresh volumes get the block and reused volumes converge instead of staying stale.
cat > "$site_path/.htaccess" <<'EOF'
RewriteEngine On
RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]
RewriteBase /
RewriteRule ^index\.php$ - [L]
# add a trailing slash to /wp-admin
RewriteRule ^([_0-9a-zA-Z-]+/)?wp-admin$ $1wp-admin/ [R=301,L]
RewriteCond %{REQUEST_FILENAME} -f [OR]
RewriteCond %{REQUEST_FILENAME} -d
RewriteRule ^ - [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(wp-(content|admin|includes).*) $2 [L]
RewriteRule ^([_0-9a-zA-Z-]+/)?(.*\.php)$ $2 [L]
RewriteRule . index.php [L]
EOF
chown www-data:www-data "$site_path/.htaccess"
# Core installs/uploads made by root WP-CLI must remain writable to Apache.
install -d -o www-data -g www-data -m 0755 "$site_path/wp-content/uploads"
chown -R www-data:www-data "$site_path/wp-content/uploads"
child_uploads="$site_path/wp-content/uploads/sites"
if [ -d "$child_uploads" ]; then
  chown -R www-data:www-data "$child_uploads"
fi
touch /tmp/xnetvn-toolbox-ready
exec sleep infinity
