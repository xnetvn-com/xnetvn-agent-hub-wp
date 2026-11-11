#!/usr/bin/env bash
# Owned role/SEO B-E matrix on one blog of the multisite network lab.
#
# Runs exactly one matrix group (permissions, content, media, lifecycle) on
# exactly one blog (network-main, network-child): owned seed, the MCP matrix,
# then the ledger cleanup of that blog and one secret-free report. Cleanup
# runs in an EXIT trap for every early exit and explicitly at the end of the
# flow.
#
# The two blogs share the network tables (users, posts and terms are network
# wide), so the blogs never run at the same time: one flock guards the whole
# network, and every run cleans up its own blog before the next one starts.
#
# Toolbox of compose.ui-network.yaml only. Never converts the single-site
# labs and never touches compose, startup or ingress.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }

group="${1:-}"
blog="${2:-}"
[[ "$#" -eq 2 ]] || { printf 'Select one group and one blog.\n' >&2; exit 2; }
case "$group" in
  permissions|content|media|lifecycle) ;;
  *) printf 'Select permissions, content, media or lifecycle.\n' >&2; exit 2;;
esac
case "$blog" in
  network-main|network-child) ;;
  *) printf 'Select network-main or network-child.\n' >&2; exit 2;;
esac

export WORDPRESS_TABLE_PREFIX=ui_network_
export XNETVN_RTL_DOMAIN="${RTL_DOMAIN_WPNETWORK:?Provide the test network domain}"
export XNETVN_RTL_ALIAS="${RTL_ALIAS_WPNETWORK:?Provide the test network alias}"
site_path='/sites/wpnetwork'
main_url="https://${RTL_DOMAIN_WPNETWORK}/"
child_url="https://${RTL_DOMAIN_WPNETWORK}/ui-second/"

run_id="${XNETVN_ROLE_SEO_RUN_ID:-run-$(date -u +%Y%m%d%H%M%S)-$$}"
[[ "$run_id" =~ ^[a-z0-9-]{1,26}$ ]] || { printf 'Unsafe role SEO run id.\n' >&2; exit 2; }
export XNETVN_ROLE_SEO_RUN_ID="$run_id"

target="$blog"
base_dir="/work/.local/role-seo"
lock_dir="/work/.local/role-seo-network"
lock_file="$lock_dir/network.lock"
private_state="$base_dir/private/$target/$run_id/state.json"
run_dir="$base_dir/$target/$run_id"
mkdir -p "$base_dir" "$lock_dir"
exec 9>"$lock_file"
flock -n 9 || { printf 'Another role SEO network run holds this target.\n' >&2; exit 2; }
[[ ! -e "$private_state" && ! -L "$private_state" ]] || { printf 'Reconcile the existing role SEO fixture first.\n' >&2; exit 2; }
mkdir -p "$run_dir"

# The blog of this run: the root blog of the network or the child site. The
# child blog id comes from the network itself, so a wrong or missing child
# stops the run instead of touching another blog.
blog_id='1'
blog_url="$main_url"
if [[ "$blog" == 'network-child' ]]; then
  child_ids="$(WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path="$site_path" blog list --fields=blog_id,url --format=csv 2>/dev/null | tail -n +2 | awk -F',' '$2 ~ /\/ui-second\/$/ { print $1 }' || true)"
  child_count="$(printf '%s\n' "$child_ids" | grep -c '^[0-9][0-9]*$' || true)"
  [[ "$child_count" == '1' ]] || { printf 'Select exactly one child blog for the matrix.\n' >&2; exit 2; }
  blog_id="$(printf '%s\n' "$child_ids" | head -n 1)"
  blog_url="$child_url"
fi
export XNETVN_MATRIX_SITE="$target"
export XNETVN_ROLE_SEO_BLOG="$blog_id"

# The canonical network domain of the lab has no DNS record inside the
# toolbox container; the lab resolves it through the hosts file of the
# container, exactly like the browser runner of the lab does with
# --add-host. The entry names the lab host of the fresh plan and lives only
# inside the container of this project.
ensure_canonical_dns() {
  local domain="${RTL_DOMAIN_WPNETWORK:-}" alias="${RTL_ALIAS_WPNETWORK:-}" ip=''
  [[ -n "$domain" && -n "$alias" ]] || return 1
  if getent hosts "$domain" >/dev/null 2>&1; then
    return 0
  fi
  ip="$(getent hosts "$alias" | awk 'NR==1{print $1}')"
  [[ -n "$ip" ]] || return 1
  printf '%s %s\n' "$ip" "$domain" >>/etc/hosts
  getent hosts "$domain" >/dev/null 2>&1
}

ensure_canonical_dns || { printf 'The canonical network domain does not resolve in the toolbox.\n' >&2; exit 2; }

fixture() {
  WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path="$site_path" --url="$blog_url" eval-file /work/tests/E2E/role-seo-fixture.php "$@" --use-include
}

counts_of() {
  fixture counts
}

counts_stable() {
  php -r '$a = json_decode( stream_get_contents( STDIN ), true ); $b = json_decode( $argv[1], true ); foreach ( array( "keys", "posts", "terms", "media" ) as $k ) { if ( ( $a[ $k ] ?? null ) !== ( $b[ $k ] ?? null ) ) { exit( 1 ); } }' "$1"
}

cleanup_role_seo_network_matrix() {
  trap - EXIT
  fixture cleanup >/dev/null 2>&1 || true
  rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
}
trap cleanup_role_seo_network_matrix EXIT

# Owned seed, the matrix of this group and the ledger cleanup of this blog.
counts_before="$(counts_of)" || { printf '{"suite":"role-seo-network-matrix","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"; exit 2; }
seed_ok=1
code=0; fixture seed >"$run_dir/seed.json" 2>"$run_dir/seed.stderr" || code="$?"; [[ "$code" == '0' ]] || seed_ok=0
verify_code=0; fixture verify >"$run_dir/verify.json" 2>"$run_dir/verify.stderr" || verify_code="$?"; [[ "$verify_code" == '0' ]] || seed_ok=0
matrix_file="$run_dir/matrix.json"
matrix_code=2
if [[ "$seed_ok" == '1' ]]; then
  if php "/work/tests/E2E/role-seo-$group.php" >"$matrix_file" 2>"$run_dir/matrix.stderr"; then
    matrix_code=0
  else
    matrix_code="$?"
  fi
fi
# The owned user ids come from the private ledger before the cleanup removes
# it. Only the ids travel; the tokens of the run never leave this process.
owned_users="$(php -r '
$file = $argv[1];
if ( ! is_file( $file ) ) { echo "[]"; exit( 0 ); }
$state = json_decode( file_get_contents( $file ), true );
if ( ! is_array( $state ) ) { echo "[]"; exit( 0 ); }
$ids = array();
foreach ( $state["ledger"] ?? array() as $entry ) {
  if ( "user" === ( $entry["type"] ?? "" ) ) { $ids[] = (int) ( $entry["id"] ?? 0 ); }
}
echo json_encode( array_values( array_unique( array_filter( $ids ) ) ) );
' "$private_state")"
trap - EXIT
code=0; fixture cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || code="$?"
rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
cleanup_ok=1
[[ "$code" == '0' && ! -e "$private_state" && ! -L "$private_state" ]] || cleanup_ok=0
# On multisite the core removes a user only from the site, so the network wide
# row of an owned user survives the fixture cleanup. The runner removes
# exactly the rows of its own ledger, bound to the run id, so a run leaves no
# owned user behind and never touches the data of another run.
removed_users=0
owned_users_left=0
sweep_out=''
if [[ -n "$owned_users" && "$owned_users" != '[]' ]]; then
  sweep_out="$(XNETVN_ROLE_SEO_OWNED_USERS="$owned_users" wp --allow-root --path="$site_path" --url="$blog_url" eval '
require_once ABSPATH . "wp-admin/includes/ms.php";
global $wpdb;
$run = (string) getenv( "XNETVN_ROLE_SEO_RUN_ID" );
$ids = json_decode( (string) getenv( "XNETVN_ROLE_SEO_OWNED_USERS" ), true );
$removed = 0;
foreach ( (array) $ids as $id ) {
  $id = (int) $id;
  $user = get_userdata( $id );
  if ( false === $user ) { continue; }
  if ( 1 !== preg_match( "/\Arole-seo-" . preg_quote( $run, "/" ) . "-[a-z_]+\z/", $user->user_login ) ) {
    throw new RuntimeException( "Role SEO network matrix user binding mismatch." );
  }
  if ( array() !== (array) get_blogs_of_user( $id ) ) {
    throw new RuntimeException( "Role SEO network matrix user still belongs to a blog." );
  }
  // The multisite aware core API removes the network wide row of a user
  // that no longer belongs to a site, so the run leaves no owned user.
  if ( ! wpmu_delete_user( $id ) ) {
    throw new RuntimeException( "Role SEO network matrix user cleanup failed." );
  }
  ++$removed;
}
$left = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->users} WHERE user_login LIKE %s", $wpdb->esc_like( "role-seo-" . $run . "-" ) . "%" ) );
echo $removed . " " . $left;
' 2>"$run_dir/sweep.stderr" | tail -n 1)" || sweep_out=''
fi
if [[ "$sweep_out" =~ ^([0-9]+)\ ([0-9]+)$ ]]; then
  removed_users="${BASH_REMATCH[1]}"
  owned_users_left="${BASH_REMATCH[2]}"
else
  cleanup_ok=0
fi
[[ "$owned_users_left" == '0' ]] || cleanup_ok=0
counts_final="$(counts_of)" || cleanup_ok=0
# Owned-only gate: the four owned counters of this blog plus the settings
# digest. The user count is network wide, so it is not a per-blog gate. The
# cron digest is reported but never gated: WP-Cron reschedules its events in
# the background, so a digest change between the two counts is platform
# churn and not a leak of the matrix (the single runner reports it the same
# way, see infra/remote/run-role-seo.sh:97-100).
if ! printf '%s' "$counts_before" | counts_stable "$counts_final"; then
  cleanup_ok=0
else
  baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_before")" || cleanup_ok=0
  final_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_final")" || cleanup_ok=0
  [[ "$baseline_settings" == "$final_settings" ]] || cleanup_ok=0
fi
baseline_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_before")" || baseline_cron=""
final_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_final")" || final_cron=""
cron_drift=0
[[ "$baseline_cron" == "$final_cron" ]] || cron_drift=1

export ROLE_SEO_MATRIX_FILE="$matrix_file"
export ROLE_SEO_VERIFY="$verify_code" ROLE_SEO_CLEANUP="$cleanup_ok" ROLE_SEO_COUNTS_BEFORE="$counts_before" ROLE_SEO_COUNTS_FINAL="$counts_final"
export ROLE_SEO_MATRIX_CODE="$matrix_code" ROLE_SEO_GROUP="$group" ROLE_SEO_TARGET="$target"
export ROLE_SEO_BLOG_ID="$blog_id" ROLE_SEO_HOST="$RTL_DOMAIN_WPNETWORK" ROLE_SEO_RUN_ID="$run_id"
export ROLE_SEO_USERS_REMOVED="$removed_users" ROLE_SEO_USERS_LEFT="$owned_users_left"
export ROLE_SEO_CRON_DRIFT="$cron_drift"
merge_code=0
merge_out="$(php -r '
$matrix_file = getenv( "ROLE_SEO_MATRIX_FILE" );
$matrix = array( "cases" => array() );
if ( is_file( $matrix_file ) ) {
  $decoded = json_decode( file_get_contents( $matrix_file ), true );
  if ( is_array( $decoded ) ) { $matrix = $decoded; }
}
$cases = $matrix["cases"] ?? array();
if ( ! is_array( $cases ) ) { $cases = array(); }
$group = (string) getenv( "ROLE_SEO_GROUP" );
$required = array(
  "permissions" => array( "AUTH-01", "AUTH-02", "AUTH-03", "AUTH-04", "AUTH-05", "AUTH-06", "AUTH-07", "AUTH-08", "AUTH-09", "AUTH-10" ),
  "content"    => array( "SEO-01", "SEO-02", "SEO-03", "SEO-04", "SEO-05", "SEO-06", "SEO-07", "SEO-08" ),
  "media"      => array( "IMG-01", "IMG-02", "IMG-03", "IMG-04", "IMG-05", "IMG-06", "IMG-07", "IMG-08", "IMG-09", "IMG-10" ),
  "lifecycle"  => array( "LIFE-01", "LIFE-02", "LIFE-03", "LIFE-04", "LIFE-05", "LIFE-06", "LIFE-07", "LIFE-08", "LIFE-09" ),
);
$must = $required[ $group ] ?? array();
$skip = 0;
foreach ( $must as $id ) {
  if ( ! isset( $cases[ $id ] ) || ! is_array( $cases[ $id ] ) ) { ++$skip; }
}
$fail = 0;
foreach ( $cases as $result ) {
  if ( ! is_array( $result ) || true !== ( $result["ok"] ?? false ) ) { ++$fail; }
}
if ( 2 === (int) getenv( "ROLE_SEO_MATRIX_CODE" ) || ! is_file( $matrix_file ) ) {
  foreach ( $cases as $id => $result ) { $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" ); }
  $fail = count( $cases );
}
if ( "0" !== (string) getenv( "ROLE_SEO_VERIFY" ) ) {
  foreach ( $cases as $id => $result ) { $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" ); }
  $fail = count( $cases );
}
$report = array(
  "suite"     => "role-seo-network-matrix",
  "group"     => $group,
  "target"    => getenv( "ROLE_SEO_TARGET" ),
  "blog"      => getenv( "ROLE_SEO_TARGET" ),
  "blog_id"   => (int) getenv( "ROLE_SEO_BLOG_ID" ),
  "run_id"    => getenv( "ROLE_SEO_RUN_ID" ),
  "host"      => getenv( "ROLE_SEO_HOST" ),
  "cases"     => $cases,
  "case_count" => count( $cases ),
  "fail"      => $fail,
  "skip"      => $skip,
  "users"     => array(
    "removed" => (int) getenv( "ROLE_SEO_USERS_REMOVED" ),
    "left"    => (int) getenv( "ROLE_SEO_USERS_LEFT" ),
  ),
  // The cron digest is information, never a gate: WP-Cron reschedules its
  // events in the background between the two counts of a run.
  "cron_drift" => "1" === (string) getenv( "ROLE_SEO_CRON_DRIFT" ),
  "counts"    => array(
    "before" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BEFORE" ), true ),
    "final"  => json_decode( (string) getenv( "ROLE_SEO_COUNTS_FINAL" ), true ),
  ),
  "cleanup"   => "1" === (string) getenv( "ROLE_SEO_CLEANUP" ),
);
$run_dir = dirname( $matrix_file );
file_put_contents( $run_dir . "/results.json", json_encode( $report, JSON_PRETTY_PRINT ) );
echo json_encode( $report ) . "\n";
$exit = 0;
if ( "1" !== (string) getenv( "ROLE_SEO_CLEANUP" ) ) { $exit = 2; } elseif ( 0 < $fail || 0 < $skip ) { $exit = 1; }
exit( $exit );
')" || merge_code="$?"
if [[ "$merge_code" != '0' && "$merge_code" != '1' && "$merge_code" != '2' ]]; then
  printf '{"suite":"role-seo-network-matrix","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"
  exit 2
fi
printf '%s\n' "$merge_out"
exit "$merge_code"
