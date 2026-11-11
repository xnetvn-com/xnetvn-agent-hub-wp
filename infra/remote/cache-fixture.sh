#!/usr/bin/env bash
# Enable only a checksum-bound official drop-in on the disposable browser site.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }
action="${1:?Select enable or disable}"
site="${2:-wp69}"
[[ "$site" == 'wp69' || "$site" == 'wp71' ]] || { printf 'Unsupported cache fixture site.\n' >&2; exit 2; }
fixture_dir="/work/.local/cache-fixture/$site"
state="$fixture_dir/owned-dropin.sha256"
dropin="/sites/$site/wp-content/object-cache.php"
run_wp() { bash /work/infra/remote/run-wp.sh "$site" "$@"; }
case "$action" in
  enable)
    [[ ! -e "$state" && ! -L "$state" && ! -e "$dropin" && ! -L "$dropin" ]] || { printf 'Reconcile the existing cache fixture before enabling.\n' >&2; exit 2; }
    install -d -m 700 "$fixture_dir"
    if ! run_wp plugin is-installed redis-cache; then
      curl --fail --location --silent --show-error --output "$fixture_dir/redis-cache.zip" https://downloads.wordpress.org/plugin/redis-cache.3.0.0.zip
      printf '%s  %s\n' '7a03342d3defbe94cac8d0470b42dc1a1c34331b19c47205df20ceccd3c8f54a' "$fixture_dir/redis-cache.zip" | sha256sum --check
      run_wp plugin install "$fixture_dir/redis-cache.zip"
    fi
    [[ "$(run_wp plugin get redis-cache --field=version)" == '3.0.0' ]] || { printf 'Unsupported cache fixture version.\n' >&2; exit 2; }
    run_wp plugin verify-checksums redis-cache --strict
    if run_wp plugin is-active redis-cache; then
      printf 'Reconcile the already active cache plugin.\n' >&2
      exit 2
    fi
    # Record the expected identity before changing activation or the drop-in.
    sha256sum "/sites/$site/wp-content/plugins/redis-cache/includes/object-cache.php" | cut -d ' ' -f1 > "$state"
    chmod 600 "$state"
    run_wp plugin activate redis-cache
    run_wp redis enable
    [[ "$(sha256sum "$dropin" | cut -d ' ' -f1)" == "$(cat "$state")" ]]
    run_wp eval 'if (!wp_using_ext_object_cache() || !wp_cache_set("xnetvn-fixture-probe", 1, "xnetvn-fixture", 60) || 2 !== wp_cache_incr("xnetvn-fixture-probe", 1, "xnetvn-fixture")) { throw new RuntimeException("Shared cache is unavailable."); } wp_cache_delete("xnetvn-fixture-probe", "xnetvn-fixture"); WP_CLI::line("Persistent cache and atomic increment verified.");'
    ;;
  disable)
    [[ -f "$state" && ! -L "$state" && ! -L "$dropin" ]] || { printf 'No regular owned cache fixture to disable.\n' >&2; exit 2; }
    if [[ -e "$dropin" ]]; then
      [[ "$(sha256sum "$dropin" | cut -d ' ' -f1)" == "$(cat "$state")" ]] || { printf 'Cache drop-in ownership mismatch.\n' >&2; exit 2; }
      run_wp redis disable
    fi
    run_wp plugin deactivate redis-cache
    [[ ! -e "$dropin" ]]
    rm -- "$state"
    printf 'Owned cache fixture disabled.\n'
    ;;
  *) printf 'Unsupported cache fixture action.\n' >&2; exit 2;;
esac
