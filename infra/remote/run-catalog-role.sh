#!/usr/bin/env bash
# Owned whole-catalog role/blog matrix runner (S5).
#
# Runs exactly one target and one cat-YYYYMMDDTHHMMSSZ campaign: guard checks,
# owned seed, the real HTTPS MCP matrix, explicit ledger cleanup and one
# secret-free merged report. It never starts, stops, syncs or converts a
# Compose stack and never prints a token, cookie or raw payload.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }

target="${1:-}"
campaign="${2:-}"
phase="${XNETVN_CATALOG_PHASE:-all}"
[[ "$#" -eq 2 ]] || { printf 'Select one target and one campaign.\n' >&2; exit 2; }
case "$target" in
  wp69|wp71|network-main|network-child) ;;
  *) printf 'Select wp69, wp71, network-main or network-child.\n' >&2; exit 2;;
esac
[[ "$campaign" =~ ^cat-[0-9]{8}T[0-9]{6}Z$ ]] || { printf 'Select a cat-YYYYMMDDTHHMMSSZ campaign.\n' >&2; exit 2; }
case "$phase" in
  all|main|auth-subscriber-1|auth-subscriber-2|auth-banned-1|auth-banned-2|auth-anonymous-1|auth-anonymous-2|finish) ;;
  *) printf 'Select a reviewed catalog phase.\n' >&2; exit 2;;
esac

network=0
site_path="/sites/$target"
prefix="site${target#wp}_"
base_url=''
other_url=''
case "$target" in
  wp69)
    base_url="https://${RTL_ALIAS_WP69:?Provide the wp69 alias}"
    other_url=''
    ;;
  wp71)
    base_url="https://${RTL_ALIAS_WP71:?Provide the wp71 alias}"
    other_url=''
    ;;
  network-main)
    network=1
    site_path='/sites/wpnetwork'
    prefix='ui_network_'
    base_url="https://${RTL_DOMAIN_WPNETWORK:?Provide the test network domain}/"
    other_url="${base_url}ui-second/"
    ;;
  network-child)
    network=1
    site_path='/sites/wpnetwork'
    prefix='ui_network_'
    base_url="https://${RTL_DOMAIN_WPNETWORK:?Provide the test network domain}/ui-second/"
    other_url="https://${RTL_DOMAIN_WPNETWORK}/"
    ;;
esac

base_dir='/work/.local/catalog-role'
private_dir="$base_dir/private/$target/$campaign"
run_dir="$base_dir/$target/$campaign"
state_file="$private_dir/state.json"
lock_dir="$base_dir/lock"
if [[ "$network" == '1' ]]; then
  lock_file="$base_dir/network.lock"
else
  lock_file="$base_dir/$target.lock"
fi
mkdir -p "$base_dir" "$lock_dir" "$run_dir"
exec 9>"$lock_file"
flock -n 9 || { printf 'Another catalog role run holds this lock.\n' >&2; exit 2; }
if [[ "$phase" == 'main' || "$phase" == 'all' ]]; then
  [[ ! -e "$state_file" && ! -L "$state_file" ]] || { printf 'Reconcile the existing catalog role fixture first.\n' >&2; exit 2; }
else
  [[ -e "$state_file" && ! -L "$state_file" ]] || { printf 'The catalog role phase needs an existing fixture.\n' >&2; exit 2; }
fi

# The canonical network domain has no DNS record inside the toolbox. The entry
# points at the lab host of the fresh plan and lives only inside this container.
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

blog_id='1'
if [[ "$target" == 'network-child' ]]; then
  ensure_canonical_dns || { printf 'The canonical network domain does not resolve in the toolbox.\n' >&2; exit 2; }
  child_ids="$(WORDPRESS_TABLE_PREFIX=ui_network_ wp --allow-root --path="$site_path" blog list --fields=blog_id,url --format=csv 2>/dev/null | tail -n +2 | awk -F',' '$2 ~ /\/ui-second\/$/ { print $1 }' || true)"
  child_count="$(printf '%s\n' "$child_ids" | grep -c '^[0-9][0-9]*$' || true)"
  [[ "$child_count" == '1' ]] || { printf 'Select exactly one child blog for the matrix.\n' >&2; exit 2; }
  blog_id="$(printf '%s\n' "$child_ids" | head -n 1)"
elif [[ "$network" == '1' ]]; then
  ensure_canonical_dns || { printf 'The canonical network domain does not resolve in the toolbox.\n' >&2; exit 2; }
fi

export XNETVN_MATRIX_TARGET="$target"
export XNETVN_MATRIX_CAMPAIGN="$campaign"
export XNETVN_MATRIX_BLOG="$blog_id"
export XNETVN_MATRIX_BASE_URL="$base_url"
export XNETVN_MATRIX_OTHER_URL="$other_url"
export XNETVN_MATRIX_PRIVATE_DIR="$private_dir"
export XNETVN_MATRIX_REVISION="${XNETVN_MATRIX_REVISION:-$(git -C /work rev-parse HEAD 2>/dev/null || true)}"
export XNETVN_MATRIX_PHASE="$phase"

fixture() {
  if [[ "$network" == '1' ]]; then
    WORDPRESS_TABLE_PREFIX="$prefix" wp --allow-root --path="$site_path" --url="$base_url" eval-file /work/tests/E2E/catalog-role-fixture.php "$@" --use-include
  else
    bash /work/infra/remote/run-wp.sh "$target" --url="$base_url" eval-file /work/tests/E2E/catalog-role-fixture.php "$@" --use-include
  fi
}

counts_of() {
  fixture counts
}

counts_stable() {
  php -r '$a = json_decode( stream_get_contents( STDIN ), true ); $b = json_decode( $argv[1], true ); foreach ( array( "users", "keys", "posts", "terms", "media", "comments" ) as $k ) { if ( ( $a[ $k ] ?? null ) !== ( $b[ $k ] ?? null ) ) { exit( 1 ); } }' "$1"
}

cleanup_role_catalog() {
  trap - EXIT
  local code=0
  fixture cleanup >"$run_dir/cleanup-trap.json" 2>/dev/null || code="$?"
  if [[ "$code" == '0' && ! -e "$state_file" && ! -L "$state_file" ]]; then
    rmdir "$private_dir" 2>/dev/null || true
  fi
}
trap cleanup_role_catalog EXIT

if [[ "$phase" == 'main' ]]; then
  counts_before="$(counts_of)" || { printf '{"suite":"catalog-role-matrix","target":"%s","campaign":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$campaign"; exit 2; }
  printf '%s' "$counts_before" >"$run_dir/counts-before.json"
  prepare_code=0
  fixture prepare >"$run_dir/prepare.json" 2>"$run_dir/prepare.stderr" || prepare_code="$?"
  verify_code=0
  catalog_code=0
  matrix_code=2
  state_before_code=0
  state_after_code=1
  audit_code=0
  cleanup_code=1
  reconcile_code=1
  if [[ "$prepare_code" == '0' ]]; then
    fixture verify >"$run_dir/verify.json" 2>"$run_dir/verify.stderr" || verify_code="$?"
    fixture catalog >"$run_dir/catalog-report.json" 2>"$run_dir/catalog.stderr" || catalog_code="$?"
    fixture state before >"$run_dir/state-before-report.json" 2>"$run_dir/state-before.stderr" || state_before_code="$?"
    if [[ "$verify_code" == '0' && "$catalog_code" == '0' && "$state_before_code" == '0' ]]; then
      matrix_code=0
      php /work/tests/E2E/catalog-role-matrix.php >"$run_dir/matrix-main.stdout" 2>"$run_dir/matrix.stderr" || matrix_code="$?"
    fi
  fi
elif [[ "$phase" == 'finish' ]]; then
  counts_before="$(cat "$run_dir/counts-before.json" 2>/dev/null || true)"
  [[ -n "$counts_before" ]] || { printf 'The main phase counts are unavailable.\n' >&2; exit 2; }
  prepare_code=0
  verify_code=0
  catalog_code=0
  state_before_code=0
  matrix_code=0
  php /work/tests/E2E/catalog-role-matrix.php >"$run_dir/matrix.json" 2>"$run_dir/matrix.stderr" || matrix_code="$?"
  state_after_code=0
  audit_code=0
  fixture state after >"$run_dir/state-after-report.json" 2>"$run_dir/state-after.stderr" || state_after_code="$?"
  fixture audit >"$run_dir/audit-report.json" 2>"$run_dir/audit.stderr" || audit_code="$?"
  cleanup_code=1
  reconcile_code=1
elif [[ "$phase" == 'all' ]]; then
  counts_before="$(counts_of)" || { printf '{"suite":"catalog-role-matrix","target":"%s","campaign":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$campaign"; exit 2; }
  prepare_code=0
  fixture prepare >"$run_dir/prepare.json" 2>"$run_dir/prepare.stderr" || prepare_code="$?"
  verify_code=0
  catalog_code=0
  matrix_code=2
  state_before_code=0
  state_after_code=1
  audit_code=0
  cleanup_code=1
  reconcile_code=1
  if [[ "$prepare_code" == '0' ]]; then
    fixture verify >"$run_dir/verify.json" 2>"$run_dir/verify.stderr" || verify_code="$?"
    fixture catalog >"$run_dir/catalog-report.json" 2>"$run_dir/catalog.stderr" || catalog_code="$?"
    fixture state before >"$run_dir/state-before-report.json" 2>"$run_dir/state-before.stderr" || state_before_code="$?"
    if [[ "$verify_code" == '0' && "$catalog_code" == '0' && "$state_before_code" == '0' ]]; then
      matrix_code=0
      php /work/tests/E2E/catalog-role-matrix.php >"$run_dir/matrix.json" 2>"$run_dir/matrix.stderr" || matrix_code="$?"
      fixture state after >"$run_dir/state-after-report.json" 2>"$run_dir/state-after.stderr" || state_after_code="$?"
      fixture audit >"$run_dir/audit-report.json" 2>"$run_dir/audit.stderr" || audit_code="$?"
    fi
  fi
else
  counts_before="$(cat "$run_dir/counts-before.json" 2>/dev/null || true)"
  [[ -n "$counts_before" ]] || { printf 'The main phase counts are unavailable.\n' >&2; exit 2; }
  prepare_code=0
  verify_code=0
  catalog_code=0
  state_before_code=0
  matrix_code=0
  php /work/tests/E2E/catalog-role-matrix.php >"$run_dir/matrix-$phase.stdout" 2>"$run_dir/matrix.stderr" || matrix_code="$?"
  state_after_code=1
  audit_code=0
  cleanup_code=1
  reconcile_code=1
fi

if [[ "$phase" != 'all' && "$phase" != 'finish' ]]; then
  if [[ "$matrix_code" == '0' ]]; then
    trap - EXIT
  fi
  printf '{"suite":"catalog-role-matrix","phase":"%s","target":"%s","campaign":"%s","matrix_exit":%s}\n' "$phase" "$target" "$campaign" "$matrix_code"
  exit "$matrix_code"
fi

trap - EXIT
cleanup_code=0
fixture cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || cleanup_code="$?"
state_gone=0
[[ ! -e "$state_file" && ! -L "$state_file" ]] && state_gone=1
rmdir "$private_dir" 2>/dev/null || true
counts_after="$(counts_of)" || cleanup_code=2
if [[ "$cleanup_code" != '0' || "$state_gone" != '1' ]]; then
  cleanup_code=2
fi
reconcile_code=0
fixture reconcile >"$run_dir/reconcile.json" 2>"$run_dir/reconcile.stderr" || reconcile_code="$?"
counts_ok=0
printf '%s' "$counts_before" | counts_stable "$counts_after" && counts_ok=1
settings_equal=0
baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_before")" || settings_equal=0
final_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_after")" || settings_equal=0
[[ "$baseline_settings" == "$final_settings" ]] && settings_equal=1
baseline_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_before")" || baseline_cron=''
final_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_after")" || final_cron=''
cron_drift=0
[[ "$baseline_cron" == "$final_cron" ]] || cron_drift=1

export CR_RUN_DIR="$run_dir"
export CR_TARGET="$target"
export CR_CAMPAIGN="$campaign"
export CR_MATRIX_EXIT="$matrix_code"
export CR_PREPARE_EXIT="$prepare_code"
export CR_VERIFY_EXIT="$verify_code"
export CR_CATALOG_EXIT="$catalog_code"
export CR_CLEANUP_EXIT="$cleanup_code"
export CR_RECONCILE_EXIT="$reconcile_code"
export CR_AUDIT_EXIT="$audit_code"
export CR_COUNTS_OK="$counts_ok"
export CR_SETTINGS_EQUAL="$settings_equal"
export CR_CRON_DRIFT="$cron_drift"
export CR_COUNTS_BEFORE="$counts_before"
export CR_COUNTS_AFTER="$counts_after"
merge_code=0
merge_out="$(php -r '
$run_dir = (string) getenv( "CR_RUN_DIR" );
$read = static function ( string $name ) use ( $run_dir ): array {
  $file = $run_dir . "/" . $name;
  if ( ! is_file( $file ) ) { return array(); }
  $decoded = json_decode( (string) file_get_contents( $file ), true );
  return is_array( $decoded ) ? $decoded : array();
};
$matrix = $read( "matrix.json" );
$before = $read( "state-before-report.json" );
$after = $read( "state-after-report.json" );
$cleanup = $read( "cleanup.json" );
$reconcile = $read( "reconcile.json" );
$audit = $read( "audit-report.json" );
$mail = $after["mail"] ?? array();
$mail_safe = ! isset( $mail["domain"] ) || "" === (string) $mail["domain"] || "example.test" === (string) $mail["domain"];
$cleanup_ok = "0" === (string) getenv( "CR_CLEANUP_EXIT" ) && "0" === (string) getenv( "CR_RECONCILE_EXIT" );
$counts_ok = "1" === (string) getenv( "CR_COUNTS_OK" );
$settings_equal = "1" === (string) getenv( "CR_SETTINGS_EQUAL" );
$matrix_exit = (int) getenv( "CR_MATRIX_EXIT" );
$failed = (int) ( $matrix["counts"]["failed"] ?? 0 );
$audit_ok = "0" === (string) getenv( "CR_AUDIT_EXIT" ) && (int) ( $audit["rows"] ?? 0 ) > 0 && 0 === (int) ( $audit["bad"] ?? 0 ) && 0 === (int) ( $audit["canary_hits"] ?? 0 ) && 0 === (int) ( $audit["value_like"] ?? 0 ) && 0 === (int) ( $audit["campaign_hits"] ?? 0 );
$blocked = true === ( $matrix["blocked"] ?? false ) || $matrix_exit === 2 || "0" !== (string) getenv( "CR_PREPARE_EXIT" );
$report = array(
  "suite" => "catalog-role-matrix",
  "target" => (string) getenv( "CR_TARGET" ),
  "campaign" => (string) getenv( "CR_CAMPAIGN" ),
  "matrix_exit" => $matrix_exit,
  "blocked" => $blocked,
  "network" => (bool) ( $matrix["network"] ?? false ),
  "blog" => (int) ( $matrix["blog"] ?? 0 ),
  "revision" => (string) ( $matrix["revision"] ?? "unavailable" ),
  "inputs" => $matrix["inputs"] ?? array(),
  "catalog" => $matrix["catalog"] ?? array(),
  "cases" => $matrix["cases"] ?? array(),
  "coverage" => $matrix["coverage"] ?? array(),
  "counts" => array(
    "declared" => (int) ( $matrix["counts"]["declared"] ?? 0 ),
    "executed" => (int) ( $matrix["counts"]["executed"] ?? 0 ),
    "covered" => (int) ( $matrix["counts"]["covered"] ?? 0 ),
    "na" => (int) ( $matrix["counts"]["na"] ?? 0 ),
    "failed" => $failed,
    "requests" => (int) ( $matrix["counts"]["requests"] ?? 0 ),
    "tools" => (int) ( $matrix["counts"]["tools"] ?? 0 ),
  ),
  "tools_missing_allowed" => $matrix["tools_missing_allowed"] ?? array(),
  "tools_missing_denied" => $matrix["tools_missing_denied"] ?? array(),
  "scope_gaps" => $matrix["scope_gaps"] ?? array(),
  "a_h" => $matrix["a_h"] ?? array(),
  "auth" => $matrix["auth"] ?? array(),
  "rate" => $matrix["rate"] ?? array(),
  "phase_files" => $matrix["phase_files"] ?? array(),
  "deferred" => $matrix["deferred"] ?? array(),
  "schema_mode" => (string) ( $matrix["schema_mode"] ?? "unavailable" ),
  "foreign_purge" => (int) ( $matrix["foreign_purge"] ?? 0 ),
  "audit" => array(
    "ok" => $audit_ok,
    "rows" => (int) ( $audit["rows"] ?? 0 ),
    "bad" => (int) ( $audit["bad"] ?? 0 ),
    "canary_hits" => (int) ( $audit["canary_hits"] ?? 0 ),
    "value_like" => (int) ( $audit["value_like"] ?? 0 ),
    "campaign_hits" => (int) ( $audit["campaign_hits"] ?? 0 ),
    "exit" => (int) getenv( "CR_AUDIT_EXIT" ),
  ),
  "state" => array(
    "before" => $before,
    "after" => $after,
    "settings_equal" => $settings_equal,
    "cron_drift" => "1" === (string) getenv( "CR_CRON_DRIFT" ),
    "counts_stable" => $counts_ok,
    "mail" => $mail,
    "mail_safe" => $mail_safe,
  ),
  "cleanup" => array(
    "ok" => $cleanup_ok,
    "cleanup_exit" => (int) getenv( "CR_CLEANUP_EXIT" ),
    "reconcile_exit" => (int) getenv( "CR_RECONCILE_EXIT" ),
    "removed" => $cleanup["removed"] ?? array(),
    "reconciled" => $reconcile["reconciled"] ?? false,
  ),
  "gates" => array(
    "matrix" => 0 === $matrix_exit,
    "prepare" => "0" === (string) getenv( "CR_PREPARE_EXIT" ),
    "verify" => "0" === (string) getenv( "CR_VERIFY_EXIT" ),
    "catalog" => "0" === (string) getenv( "CR_CATALOG_EXIT" ),
    "cleanup" => $cleanup_ok,
    "counts_stable" => $counts_ok,
    "settings_equal" => $settings_equal,
    "mail_safe" => $mail_safe,
    "audit" => $audit_ok,
    "cron_drift_reported" => true,
  ),
);
$json = json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
if ( false !== $json ) {
  file_put_contents( $run_dir . "/results.json", $json, LOCK_EX );
  echo $json . "\n";
}
$exit = 0;
if ( $blocked || ! $cleanup_ok || ! $counts_ok || ! $settings_equal ) { $exit = 2; }
elseif ( 0 !== $matrix_exit || $failed > 0 || ! $mail_safe || ! $audit_ok ) { $exit = 1; }
exit( $exit );
')" || merge_code="$?"
if [[ "$merge_code" != '0' && "$merge_code" != '1' && "$merge_code" != '2' ]]; then
  printf '{"suite":"catalog-role-matrix","target":"%s","campaign":"%s","blocked":true,"cleanup":false}\n' "$target" "$campaign"
  exit 2
fi
printf '%s\n' "$merge_out"
exit "$merge_code"
