#!/usr/bin/env bash
set -euo pipefail
cd /work
composer install --prefer-dist --no-interaction --no-progress
for site in wp69 wp71; do
  case "$site" in
    wp69) version='6.9'; prefix='integration69_'; export WORDPRESS_TABLE_PREFIX=site69_; export XNETVN_RTL_DOMAIN="$RTL_DOMAIN_WP69" XNETVN_RTL_ALIAS="$RTL_ALIAS_WP69";;
    wp71) version='7.1.2'; prefix='integration71_'; export WORDPRESS_TABLE_PREFIX=site71_; export XNETVN_RTL_DOMAIN="$RTL_DOMAIN_WP71" XNETVN_RTL_ALIAS="$RTL_ALIAS_WP71";;
  esac
  site_path="/sites/$site"
  if ! wp --allow-root --path="$site_path" core is-installed >/dev/null 2>&1; then
    wp --allow-root --path="$site_path" core install --url="https://$XNETVN_RTL_DOMAIN" --title="Agent Hub WordPress $version Test" --admin_user=admin --admin_password="$RTL_ADMIN_PASSWORD" --admin_email=admin@example.test --skip-email
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
  wp --allow-root --path="$site_path" plugin activate mcp-adapter xnetvn-agent-hub-wp
  wp --allow-root --path="$site_path" option update permalink_structure '/%postname%/'
  wp --allow-root --path="$site_path" eval-file /work/tests/E2E/prepare.php --use-include
  # Core installs/uploads made by root WP-CLI must remain writable to Apache.
  install -d -o www-data -g www-data -m 0755 "$site_path/wp-content/uploads"
  chown -R www-data:www-data "$site_path/wp-content/uploads"
  XNETVN_RTL_SITE_PATH="$site_path" XNETVN_RTL_TABLE_PREFIX="$prefix" php /work/infra/remote/write-test-config.php "$version"
done
touch /tmp/xnetvn-toolbox-ready
exec sleep infinity
