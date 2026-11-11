#!/usr/bin/env bash
set -euo pipefail
site="${1:?Select wp69 or wp71}"
shift
case "$site" in
  wp69) export WORDPRESS_TABLE_PREFIX=site69_ XNETVN_RTL_DOMAIN="$RTL_DOMAIN_WP69" XNETVN_RTL_ALIAS="$RTL_ALIAS_WP69";;
  wp71) export WORDPRESS_TABLE_PREFIX=site71_ XNETVN_RTL_DOMAIN="$RTL_DOMAIN_WP71" XNETVN_RTL_ALIAS="$RTL_ALIAS_WP71";;
  *) printf 'Unsupported test site.\n' >&2; exit 2;;
esac
exec wp --allow-root --path="/sites/$site" "$@"
