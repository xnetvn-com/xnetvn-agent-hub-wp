#!/usr/bin/env bash
# Owned role/SEO network fixture lifecycle with guaranteed cleanup.
#
# Runs the NET-01..NET-07 isolation matrix on the multisite lab: pre-seed
# transport and version gates on main and child, owned seed, the MCP
# network matrix, then restore and one secret-free report. Cleanup runs in
# an EXIT trap for every early exit and explicitly at the end of the flow.
#
# The matrix is split in two runs so each run stays under the lab's
# per-minute auth-failure budget: `a` runs the per-blog isolation groups
# (NET-01/02/06/07), `b` runs the cross-blog policy groups (NET-03/04/05)
# with a budget-decay pause before NET-05. No argument keeps the legacy
# full run. Both parts seed and clean up independently.
#
# Toolbox of compose.ui-network.yaml only. Never converts the single-site
# labs and never touches compose, startup or ingress.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }

part="${1:-all}"
if [[ "$part" != 'all' && "$part" != 'a' && "$part" != 'b' ]]; then
  printf 'Select matrix part a, b or all.\n' >&2
  exit 2
fi
export XNETVN_ROLE_SEO_NET_PART="$part"

export WORDPRESS_TABLE_PREFIX=ui_network_
export XNETVN_RTL_DOMAIN="${RTL_DOMAIN_WPNETWORK:?Provide the test network domain}"
export XNETVN_RTL_ALIAS="${RTL_ALIAS_WPNETWORK:?Provide the test network alias}"
site_path='/sites/wpnetwork'
main_url="https://${RTL_DOMAIN_WPNETWORK}/"
child_url="https://${RTL_DOMAIN_WPNETWORK}/ui-second/"

run_id="${XNETVN_ROLE_SEO_RUN_ID:-run-$(date -u +%Y%m%d%H%M%S)-$$}"
export XNETVN_ROLE_SEO_RUN_ID="$run_id"

base_dir="/work/.local/role-seo-network"
lock_file="$base_dir/network.lock"
private_state="$base_dir/private/$run_id/state.json"
run_dir="$base_dir/$run_id"
mkdir -p "$base_dir" "$run_dir"
exec 9>"$lock_file"
flock -n 9 || { printf 'Another role SEO network run holds this target.\n' >&2; exit 2; }
[[ ! -e "$private_state" && ! -L "$private_state" ]] || { printf 'Reconcile the existing role SEO network fixture first.\n' >&2; exit 2; }

fixture() {
  local blog_url="${1:?Select main or child}"
  shift
  WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path="$site_path" --url="$blog_url" eval-file /work/tests/E2E/role-seo-network-fixture.php "$@" --use-include
}

counts_of() {
  fixture "$1" counts
}

counts_stable() {
  php -r '$a = json_decode( stream_get_contents( STDIN ), true ); $b = json_decode( $argv[1], true ); foreach ( array( "keys", "posts", "terms", "media" ) as $k ) { if ( ( $a[ $k ] ?? null ) !== ( $b[ $k ] ?? null ) ) { exit( 1 ); } }' "$1"
}

cleanup_role_seo_network() {
  trap - EXIT
  fixture "$child_url" quota-restore >/dev/null 2>&1 || true
  fixture "$main_url" cleanup >/dev/null 2>&1 || true
  rmdir "$base_dir/private/$run_id" 2>/dev/null || true
}
trap cleanup_role_seo_network EXIT

# Pre-seed gate: same ingress, known domain, both blogs installed.
core_version="$(WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path="$site_path" --url="$main_url" core version 2>/dev/null || true)"
[[ "$core_version" == 7.1.* ]] || { printf '{"suite":"role-seo-network","target":"network","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$run_id"; exit 2; }
for blog_url in "$main_url" "$child_url"; do
  WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path="$site_path" --url="$blog_url" plugin is-active xnetvn-agent-hub-wp >/dev/null 2>&1 || { printf '{"suite":"role-seo-network","target":"network","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$run_id"; exit 2; }
  WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path="$site_path" --url="$blog_url" plugin is-active mcp-adapter >/dev/null 2>&1 || { printf '{"suite":"role-seo-network","target":"network","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$run_id"; exit 2; }
done
child_path="$(WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path="$site_path" --url="$child_url" option get siteurl 2>/dev/null || true)"
[[ "$child_path" == "https://${RTL_DOMAIN_WPNETWORK}/ui-second" ]] || { printf '{"suite":"role-seo-network","target":"network","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$run_id"; exit 2; }

# NET-01 transport preflight before any seed; redirects or dead routes block.
preflight_code=0
php /work/tests/E2E/role-seo-network.php preflight >"$run_dir/preflight.json" 2>"$run_dir/preflight.stderr" || preflight_code="$?"
[[ "$preflight_code" == '0' ]] || { printf '{"suite":"role-seo-network","target":"network","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$run_id"; exit 2; }

# Main seed and per-blog verify.
counts_before_main="$(counts_of "$main_url")" || { printf '{"suite":"role-seo-network","target":"network","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$run_id"; exit 2; }
counts_before_child="$(counts_of "$child_url")" || { printf '{"suite":"role-seo-network","target":"network","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$run_id"; exit 2; }
seed_ok=1
code=0; fixture "$main_url" seed >"$run_dir/seed.json" 2>"$run_dir/seed.stderr" || code="$?"; [[ "$code" == '0' ]] || seed_ok=0
verify_code=0; fixture "$main_url" verify >"$run_dir/verify-main.json" 2>"$run_dir/verify-main.stderr" || verify_code="$?"; [[ "$verify_code" == '0' ]] || seed_ok=0
verify_child=0; fixture "$child_url" verify >"$run_dir/verify-child.json" 2>"$run_dir/verify-child.stderr" || verify_child="$?"; [[ "$verify_child" == '0' ]] || seed_ok=0

# The MCP network matrix, then explicit cleanup and one secret-free report.
matrix_file="$run_dir/matrix.json"
matrix_code=2
if [[ "$seed_ok" == '1' ]]; then
  # One acceptance phase runs part a and part b in the same window, so the
  # isolation part's expected denials still sit in the weighted per-minute
  # auth-failure budget when the policy part starts; the child's later calls
  # (including valid ones) then answer 429 and the case results become
  # unmeasurable. Start the policy part two minute boundaries after the
  # isolation part, when only the current window counts, exactly like the
  # pause the matrix already keeps before its own explicit-deny probes.
  if [[ "$part" == 'b' ]]; then
    sleep $(( 122 - 10#$(date -u +%S) ))
  fi
  if php /work/tests/E2E/role-seo-network.php matrix >"$matrix_file" 2>"$run_dir/matrix.stderr"; then
    matrix_code=0
  else
    matrix_code="$?"
  fi
fi
trap - EXIT
code=0; fixture "$main_url" cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || code="$?"
rmdir "$base_dir/private/$run_id" 2>/dev/null || true
cleanup_ok=1
[[ "$code" == '0' && ! -e "$private_state" && ! -L "$private_state" ]] || cleanup_ok=0
counts_final_main="$(counts_of "$main_url")" || cleanup_ok=0
counts_final_child="$(counts_of "$child_url")" || cleanup_ok=0
if [[ "$cleanup_ok" == '1' ]]; then
  printf '%s' "$counts_before_main" | counts_stable "$counts_final_main" || cleanup_ok=0
  printf '%s' "$counts_before_child" | counts_stable "$counts_final_child" || cleanup_ok=0
fi

export ROLE_SEO_NETWORK_MATRIX_FILE="$matrix_file"
export ROLE_SEO_NETWORK_PREFLIGHT="$run_dir/preflight.json"
export ROLE_SEO_NETWORK_VERIFY="$verify_code" ROLE_SEO_NETWORK_VERIFY_CHILD="$verify_child" ROLE_SEO_NETWORK_CLEANUP="$cleanup_ok"
export ROLE_SEO_NETWORK_COUNTS_BEFORE_MAIN="$counts_before_main" ROLE_SEO_NETWORK_COUNTS_FINAL_MAIN="$counts_final_main"
export ROLE_SEO_NETWORK_COUNTS_BEFORE_CHILD="$counts_before_child" ROLE_SEO_NETWORK_COUNTS_FINAL_CHILD="$counts_final_child"
export ROLE_SEO_NETWORK_MATRIX_CODE="$matrix_code" ROLE_SEO_NETWORK_SEED="$seed_ok"
export ROLE_SEO_NETWORK_PART="$part"
merge_code=0
merge_out="$(php -r '
$matrix_file = getenv( "ROLE_SEO_NETWORK_MATRIX_FILE" );
$matrix = array( "cases" => array() );
if ( is_file( $matrix_file ) ) {
  $decoded = json_decode( file_get_contents( $matrix_file ), true );
  if ( is_array( $decoded ) ) { $matrix = $decoded; }
}
$preflight = json_decode( file_get_contents( getenv( "ROLE_SEO_NETWORK_PREFLIGHT" ) ), true );
$cases = $matrix["cases"] ?? array();
if ( is_array( $preflight ) ) {
  foreach ( $preflight["cases"] ?? array() as $id => $result ) { $cases[ $id ] = $result; }
}
$part = getenv( "ROLE_SEO_NETWORK_PART" );
if ( ! is_string( $part ) || ! in_array( $part, array( "all", "a", "b" ), true ) ) { $part = "all"; }
$required = "a" === $part ? array( "NET-01", "NET-02", "NET-06", "NET-07" ) : ( "b" === $part ? array( "NET-03", "NET-04", "NET-05" ) : array( "NET-01", "NET-02", "NET-03", "NET-04", "NET-05", "NET-06", "NET-07" ) );
foreach ( $required as $must ) {
  $found = false;
  foreach ( $cases as $id => $result ) {
    if ( $id === $must || str_starts_with( $id, $must . "-" ) ) {
      $found = true;
      break;
    }
  }
  if ( ! $found ) {
    $cases[ $must ] = array( "ok" => false, "reason" => "internal_error" );
  }
}
if ( 2 === (int) getenv( "ROLE_SEO_NETWORK_MATRIX_CODE" ) || ! is_file( $matrix_file ) ) {
  foreach ( $cases as $id => $result ) {
    $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
  }
}
if ( "0" !== (string) getenv( "ROLE_SEO_NETWORK_VERIFY" ) || "0" !== (string) getenv( "ROLE_SEO_NETWORK_VERIFY_CHILD" ) || "1" !== (string) getenv( "ROLE_SEO_NETWORK_SEED" ) ) {
  foreach ( $cases as $id => $result ) {
    $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
  }
}
$failed = false;
foreach ( $cases as $result ) { if ( true !== ( $result["ok"] ?? false ) ) { $failed = true; } }
$report = array(
  "suite" => "role-seo-network",
  "target" => "network",
  "part" => $part,
  "run_id" => getenv( "XNETVN_ROLE_SEO_RUN_ID" ),
  "cases" => $cases,
  "counts" => array(
    "before_main" => json_decode( (string) getenv( "ROLE_SEO_NETWORK_COUNTS_BEFORE_MAIN" ), true ),
    "final_main" => json_decode( (string) getenv( "ROLE_SEO_NETWORK_COUNTS_FINAL_MAIN" ), true ),
    "before_child" => json_decode( (string) getenv( "ROLE_SEO_NETWORK_COUNTS_BEFORE_CHILD" ), true ),
    "final_child" => json_decode( (string) getenv( "ROLE_SEO_NETWORK_COUNTS_FINAL_CHILD" ), true ),
  ),
  "cleanup" => "1" === (string) getenv( "ROLE_SEO_NETWORK_CLEANUP" ),
);
$run_dir = dirname( $matrix_file );
file_put_contents( $run_dir . "/results.json", json_encode( $report, JSON_PRETTY_PRINT ) );
echo json_encode( $report ) . "\n";
$exit = 0;
if ( "1" !== (string) getenv( "ROLE_SEO_NETWORK_CLEANUP" ) ) { $exit = 2; } elseif ( $failed ) { $exit = 1; }
exit( $exit );
')" || merge_code="$?"
if [[ "$merge_code" != '0' && "$merge_code" != '1' && "$merge_code" != '2' ]]; then
  printf '{"suite":"role-seo-network","target":"network","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$run_id"
  exit 2
fi
printf '%s\n' "$merge_out"
exit "$merge_code"
