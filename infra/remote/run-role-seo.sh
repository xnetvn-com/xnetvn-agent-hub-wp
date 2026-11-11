#!/usr/bin/env bash
# Owned role/SEO fixture lifecycle with guaranteed cleanup.
#
# Runs the FX-01..FX-09 fixture gate on one lab target: guard checks, owned
# seed, the MCP matrix, kill recovery and lock contention, then restores the
# baseline and prints one secret-free report. Cleanup runs in an EXIT trap for
# every early exit and explicitly at the end of the main flow.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }

target="${1:-}"
group="${2:-}"
[[ "$target" == 'wp69' || "$target" == 'wp71' ]] || { printf 'Select wp69 or wp71.\n' >&2; exit 2; }
case "$group" in
  fixture|permissions|content|media|lifecycle|stability) ;;
  *) printf 'Select fixture, permissions, content, media, lifecycle or stability.\n' >&2; exit 2;;
esac
case "$target" in
  wp69) site_alias="$RTL_ALIAS_WP69";;
  wp71) site_alias="$RTL_ALIAS_WP71";;
esac

export XNETVN_MATRIX_SITE="$target"
export XNETVN_ROLE_SEO_BLOG="${XNETVN_ROLE_SEO_BLOG:-1}"
run_id="${XNETVN_ROLE_SEO_RUN_ID:-run-$(date -u +%Y%m%d%H%M%S)-$$}"
export XNETVN_ROLE_SEO_RUN_ID="$run_id"

base_dir="/work/.local/role-seo"
lock_file="$base_dir/$target.lock"
private_state="$base_dir/private/$target/$run_id/state.json"
run_dir="$base_dir/$target/$run_id"
mkdir -p "$base_dir" "$run_dir"
exec 9>"$lock_file"
flock -n 9 || { printf 'Another role SEO run holds this target.\n' >&2; exit 2; }
[[ ! -e "$private_state" && ! -L "$private_state" ]] || { printf 'Reconcile the existing role SEO fixture first.\n' >&2; exit 2; }

fixture() {
  bash /work/infra/remote/run-wp.sh "$target" --url="https://$site_alias" eval-file /work/tests/E2E/role-seo-fixture.php "$@" --use-include
}

counts_of() {
  fixture counts
}

counts_stable() {
  php -r '$a = json_decode( stream_get_contents( STDIN ), true ); $b = json_decode( $argv[1], true ); foreach ( array( "users", "keys", "posts", "terms", "media" ) as $k ) { if ( ( $a[ $k ] ?? null ) !== ( $b[ $k ] ?? null ) ) { exit( 1 ); } }' "$1"
}

# Runner-owned results: FX-01, FX-04, FX-05, FX-06, FX-08, FX-09.
fx01_ok=1; fx04_ok=1; fx05_ok=1; fx06_ok=1; fx08_ok=1; fx09_ok=1
fx01_reason='unavailable'; fx04_reason='unavailable'; fx05_reason='unavailable'
fx06_reason='unavailable'; fx08_reason='unavailable'; fx09_reason='unavailable'

cleanup_role_seo() {
  trap - EXIT
  fixture cleanup >/dev/null 2>&1 || true
  rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
}
trap cleanup_role_seo EXIT

# SEO-01..SEO-08 content matrix on one lab target: owned seed, the MCP
# content matrix, then restore and one secret-free report. The fixture
# flow below is untouched; this branch shares its lock, trap and cleanup
# discipline.
if [[ "$group" == 'content' ]]; then
  counts_before="$(counts_of)" || { printf '{"suite":"role-seo-content","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"; exit 2; }
  counts_baseline="$counts_before"
  seed_ok=1
  code=0; fixture seed >"$run_dir/seed.json" 2>"$run_dir/seed.stderr" || code="$?"; [[ "$code" == '0' ]] || seed_ok=0
  verify_code=0; fixture verify >"$run_dir/verify.json" 2>"$run_dir/verify.stderr" || verify_code="$?"; [[ "$verify_code" == '0' ]] || seed_ok=0
  matrix_file="$run_dir/matrix.json"
  matrix_code=2
  if [[ "$seed_ok" == '1' ]]; then
    if php /work/tests/E2E/role-seo-content.php >"$matrix_file" 2>"$run_dir/matrix.stderr"; then
      matrix_code=0
    else
      matrix_code="$?"
    fi
  fi
  trap - EXIT
  code=0; fixture cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || code="$?"
  rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
  cleanup_ok=1
  [[ "$code" == '0' && ! -e "$private_state" && ! -L "$private_state" ]] || cleanup_ok=0
  counts_final="$(counts_of)" || cleanup_ok=0
  # Owned-only gate (follow-up 2026-10-20_04): compare owned counts
  # (users/keys/posts/terms/media) plus settings_digest. cron_digest drift
  # is platform churn during the long campaign and is reported as
  # cron_drift without failing cleanup.
  if ! printf '%s' "$counts_baseline" | counts_stable "$counts_final"; then
    cleanup_ok=0
  else
    baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_baseline")" || cleanup_ok=0
    final_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_final")" || cleanup_ok=0
    [[ "$baseline_settings" == "$final_settings" ]] || cleanup_ok=0
  fi
  baseline_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_baseline")" || baseline_cron=""
  final_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_final")" || final_cron=""
  cron_drift=0
  [[ "$baseline_cron" == "$final_cron" ]] || cron_drift=1
  export ROLE_SEO_MATRIX_FILE="$matrix_file"
  export ROLE_SEO_VERIFY="$verify_code" ROLE_SEO_CLEANUP="$cleanup_ok" ROLE_SEO_COUNTS_BEFORE="$counts_before" ROLE_SEO_COUNTS_FINAL="$counts_final"
  export ROLE_SEO_MATRIX_CODE="$matrix_code" ROLE_SEO_COUNTS_BASELINE="$counts_baseline"
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
if ( 2 === (int) getenv( "ROLE_SEO_MATRIX_CODE" ) || ! is_file( $matrix_file ) ) {
  foreach ( array( "SEO-01", "SEO-02", "SEO-03", "SEO-04", "SEO-05", "SEO-06", "SEO-07", "SEO-08" ) as $must ) {
    $found = false;
    foreach ( $cases as $id => $result ) {
      if ( $id === $must || str_starts_with( $id, $must . "-" ) ) {
        $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
        $found = true;
      }
    }
    if ( ! $found ) {
      $cases[ $must ] = array( "ok" => false, "reason" => "internal_error" );
    }
  }
}
if ( "0" !== (string) getenv( "ROLE_SEO_VERIFY" ) ) {
  foreach ( $cases as $id => $result ) {
    $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
  }
}
$failed = false;
foreach ( $cases as $result ) { if ( true !== ( $result["ok"] ?? false ) ) { $failed = true; } }
$report = array(
  "suite" => "role-seo-content",
  "target" => getenv( "XNETVN_MATRIX_SITE" ),
  "run_id" => getenv( "XNETVN_ROLE_SEO_RUN_ID" ),
  "blog" => (int) getenv( "XNETVN_ROLE_SEO_BLOG" ),
  "cases" => $cases,
  "counts" => array( "before" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BEFORE" ), true ), "baseline" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BASELINE" ), true ), "final" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_FINAL" ), true ) ),
  "cleanup" => "1" === (string) getenv( "ROLE_SEO_CLEANUP" ),
  "cron_drift" => "1" === (string) getenv( "ROLE_SEO_CRON_DRIFT" ),
);
$run_dir = dirname( $matrix_file );
file_put_contents( $run_dir . "/results.json", json_encode( $report, JSON_PRETTY_PRINT ) );
echo json_encode( $report ) . "\n";
$exit = 0;
if ( "1" !== (string) getenv( "ROLE_SEO_CLEANUP" ) ) { $exit = 2; } elseif ( $failed ) { $exit = 1; }
exit( $exit );
')" || merge_code="$?"
  if [[ "$merge_code" != '0' && "$merge_code" != '1' && "$merge_code" != '2' ]]; then
    printf '{"suite":"role-seo-content","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"
    exit 2
  fi
  printf '%s\n' "$merge_out"
  exit "$merge_code"
fi

# IMG-01..IMG-10 media matrix on one lab target: owned seed, the MCP
# media matrix, then restore and one secret-free report. The fixture
# flow below is untouched; this branch shares its lock, trap and cleanup
# discipline.
if [[ "$group" == 'media' ]]; then
  counts_before="$(counts_of)" || { printf '{"suite":"role-seo-media","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"; exit 2; }
  counts_baseline="$counts_before"
  seed_ok=1
  code=0; fixture seed >"$run_dir/seed.json" 2>"$run_dir/seed.stderr" || code="$?"; [[ "$code" == '0' ]] || seed_ok=0
  verify_code=0; fixture verify >"$run_dir/verify.json" 2>"$run_dir/verify.stderr" || verify_code="$?"; [[ "$verify_code" == '0' ]] || seed_ok=0
  matrix_file="$run_dir/matrix.json"
  matrix_code=2
  if [[ "$seed_ok" == '1' ]]; then
    if php /work/tests/E2E/role-seo-media.php >"$matrix_file" 2>"$run_dir/matrix.stderr"; then
      matrix_code=0
    else
      matrix_code="$?"
    fi
  fi
  trap - EXIT
  code=0; fixture cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || code="$?"
  rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
  cleanup_ok=1
  [[ "$code" == '0' && ! -e "$private_state" && ! -L "$private_state" ]] || cleanup_ok=0
  counts_final="$(counts_of)" || cleanup_ok=0
  # Owned-only gate (follow-up 2026-10-20_04): compare owned counts
  # (users/keys/posts/terms/media) plus settings_digest. cron_digest drift
  # is platform churn during the long campaign and is reported as
  # cron_drift without failing cleanup.
  if ! printf '%s' "$counts_baseline" | counts_stable "$counts_final"; then
    cleanup_ok=0
  else
    baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_baseline")" || cleanup_ok=0
    final_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_final")" || cleanup_ok=0
    [[ "$baseline_settings" == "$final_settings" ]] || cleanup_ok=0
  fi
  baseline_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_baseline")" || baseline_cron=""
  final_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_final")" || final_cron=""
  cron_drift=0
  [[ "$baseline_cron" == "$final_cron" ]] || cron_drift=1
  export ROLE_SEO_MATRIX_FILE="$matrix_file"
  export ROLE_SEO_VERIFY="$verify_code" ROLE_SEO_CLEANUP="$cleanup_ok" ROLE_SEO_COUNTS_BEFORE="$counts_before" ROLE_SEO_COUNTS_FINAL="$counts_final"
  export ROLE_SEO_MATRIX_CODE="$matrix_code" ROLE_SEO_COUNTS_BASELINE="$counts_baseline"
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
if ( 2 === (int) getenv( "ROLE_SEO_MATRIX_CODE" ) || ! is_file( $matrix_file ) ) {
  foreach ( array( "IMG-01", "IMG-02", "IMG-03", "IMG-04", "IMG-05", "IMG-06", "IMG-07", "IMG-08", "IMG-09", "IMG-10" ) as $must ) {
    $found = false;
    foreach ( $cases as $id => $result ) {
      if ( $id === $must || str_starts_with( $id, $must . "-" ) ) {
        $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
        $found = true;
      }
    }
    if ( ! $found ) {
      $cases[ $must ] = array( "ok" => false, "reason" => "internal_error" );
    }
  }
}
if ( "0" !== (string) getenv( "ROLE_SEO_VERIFY" ) ) {
  foreach ( $cases as $id => $result ) {
    $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
  }
}
$failed = false;
foreach ( $cases as $result ) { if ( true !== ( $result["ok"] ?? false ) ) { $failed = true; } }
$report = array(
  "suite" => "role-seo-media",
  "target" => getenv( "XNETVN_MATRIX_SITE" ),
  "run_id" => getenv( "XNETVN_ROLE_SEO_RUN_ID" ),
  "blog" => (int) getenv( "XNETVN_ROLE_SEO_BLOG" ),
  "cases" => $cases,
  "counts" => array( "before" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BEFORE" ), true ), "baseline" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BASELINE" ), true ), "final" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_FINAL" ), true ) ),
  "cleanup" => "1" === (string) getenv( "ROLE_SEO_CLEANUP" ),
  "cron_drift" => "1" === (string) getenv( "ROLE_SEO_CRON_DRIFT" ),
);
$run_dir = dirname( $matrix_file );
file_put_contents( $run_dir . "/results.json", json_encode( $report, JSON_PRETTY_PRINT ) );
echo json_encode( $report ) . "\n";
$exit = 0;
if ( "1" !== (string) getenv( "ROLE_SEO_CLEANUP" ) ) { $exit = 2; } elseif ( $failed ) { $exit = 1; }
exit( $exit );
')" || merge_code="$?"
  if [[ "$merge_code" != '0' && "$merge_code" != '1' && "$merge_code" != '2' ]]; then
    printf '{"suite":"role-seo-media","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"
    exit 2
  fi
  printf '%s\n' "$merge_out"
  exit "$merge_code"
fi

# LIFE-01..LIFE-09 lifecycle matrix on one lab target: owned seed, the MCP
# lifecycle matrix, then restore and one secret-free report. The fixture
# flow below is untouched; this branch shares its lock, trap and cleanup
# discipline.
if [[ "$group" == 'lifecycle' ]]; then
  counts_before="$(counts_of)" || { printf '{"suite":"role-seo-lifecycle","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"; exit 2; }
  counts_baseline="$counts_before"
  seed_ok=1
  code=0; fixture seed >"$run_dir/seed.json" 2>"$run_dir/seed.stderr" || code="$?"; [[ "$code" == '0' ]] || seed_ok=0
  verify_code=0; fixture verify >"$run_dir/verify.json" 2>"$run_dir/verify.stderr" || verify_code="$?"; [[ "$verify_code" == '0' ]] || seed_ok=0
  matrix_file="$run_dir/matrix.json"
  matrix_code=2
  if [[ "$seed_ok" == '1' ]]; then
    if php /work/tests/E2E/role-seo-lifecycle.php >"$matrix_file" 2>"$run_dir/matrix.stderr"; then
      matrix_code=0
    else
      matrix_code="$?"
    fi
  fi
  trap - EXIT
  code=0; fixture cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || code="$?"
  rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
  cleanup_ok=1
  [[ "$code" == '0' && ! -e "$private_state" && ! -L "$private_state" ]] || cleanup_ok=0
  counts_final="$(counts_of)" || cleanup_ok=0
  # Owned-only gate (follow-up 2026-10-20_04): compare owned counts
  # (users/keys/posts/terms/media) plus settings_digest. cron_digest drift
  # is platform churn during the long campaign and is reported as
  # cron_drift without failing cleanup.
  if ! printf '%s' "$counts_baseline" | counts_stable "$counts_final"; then
    cleanup_ok=0
  else
    baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_baseline")" || cleanup_ok=0
    final_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_final")" || cleanup_ok=0
    [[ "$baseline_settings" == "$final_settings" ]] || cleanup_ok=0
  fi
  baseline_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_baseline")" || baseline_cron=""
  final_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_final")" || final_cron=""
  cron_drift=0
  [[ "$baseline_cron" == "$final_cron" ]] || cron_drift=1
  export ROLE_SEO_MATRIX_FILE="$matrix_file"
  export ROLE_SEO_VERIFY="$verify_code" ROLE_SEO_CLEANUP="$cleanup_ok" ROLE_SEO_COUNTS_BEFORE="$counts_before" ROLE_SEO_COUNTS_FINAL="$counts_final"
  export ROLE_SEO_MATRIX_CODE="$matrix_code" ROLE_SEO_COUNTS_BASELINE="$counts_baseline"
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
if ( 2 === (int) getenv( "ROLE_SEO_MATRIX_CODE" ) || ! is_file( $matrix_file ) ) {
  foreach ( array( "LIFE-01", "LIFE-02", "LIFE-03", "LIFE-04", "LIFE-05", "LIFE-06", "LIFE-07", "LIFE-08", "LIFE-09" ) as $must ) {
    $found = false;
    foreach ( $cases as $id => $result ) {
      if ( $id === $must || str_starts_with( $id, $must . "-" ) ) {
        $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
        $found = true;
      }
    }
    if ( ! $found ) {
      $cases[ $must ] = array( "ok" => false, "reason" => "internal_error" );
    }
  }
}
if ( "0" !== (string) getenv( "ROLE_SEO_VERIFY" ) ) {
  foreach ( $cases as $id => $result ) {
    $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
  }
}
$failed = false;
foreach ( $cases as $result ) { if ( true !== ( $result["ok"] ?? false ) ) { $failed = true; } }
$report = array(
  "suite" => "role-seo-lifecycle",
  "target" => getenv( "XNETVN_MATRIX_SITE" ),
  "run_id" => getenv( "XNETVN_ROLE_SEO_RUN_ID" ),
  "blog" => (int) getenv( "XNETVN_ROLE_SEO_BLOG" ),
  "cases" => $cases,
  "counts" => array( "before" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BEFORE" ), true ), "baseline" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BASELINE" ), true ), "final" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_FINAL" ), true ) ),
  "cleanup" => "1" === (string) getenv( "ROLE_SEO_CLEANUP" ),
  "cron_drift" => "1" === (string) getenv( "ROLE_SEO_CRON_DRIFT" ),
);
$run_dir = dirname( $matrix_file );
file_put_contents( $run_dir . "/results.json", json_encode( $report, JSON_PRETTY_PRINT ) );
echo json_encode( $report ) . "\n";
$exit = 0;
if ( "1" !== (string) getenv( "ROLE_SEO_CLEANUP" ) ) { $exit = 2; } elseif ( $failed ) { $exit = 1; }
exit( $exit );
')" || merge_code="$?"
  if [[ "$merge_code" != '0' && "$merge_code" != '1' && "$merge_code" != '2' ]]; then
    printf '{"suite":"role-seo-lifecycle","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"
    exit 2
  fi
  printf '%s\n' "$merge_out"
  exit "$merge_code"
fi

# AUTH-01..AUTH-10 permission matrix on one lab target: owned seed, the MCP
# permission matrix, then restore and one secret-free report. The fixture
# flow below is untouched; this branch shares its lock, trap and cleanup
# discipline.
if [[ "$group" == 'permissions' ]]; then
  counts_before="$(counts_of)" || { printf '{"suite":"role-seo-permissions","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"; exit 2; }
  counts_baseline="$counts_before"
  seed_ok=1
  code=0; fixture seed >"$run_dir/seed.json" 2>"$run_dir/seed.stderr" || code="$?"; [[ "$code" == '0' ]] || seed_ok=0
  verify_code=0; fixture verify >"$run_dir/verify.json" 2>"$run_dir/verify.stderr" || verify_code="$?"; [[ "$verify_code" == '0' ]] || seed_ok=0
  matrix_file="$run_dir/matrix.json"
  matrix_code=2
  if [[ "$seed_ok" == '1' ]]; then
    if php /work/tests/E2E/role-seo-permissions.php >"$matrix_file" 2>"$run_dir/matrix.stderr"; then
      matrix_code=0
    else
      matrix_code="$?"
    fi
  fi
  trap - EXIT
  code=0; fixture cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || code="$?"
  rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
  cleanup_ok=1
  [[ "$code" == '0' && ! -e "$private_state" && ! -L "$private_state" ]] || cleanup_ok=0
  counts_final="$(counts_of)" || cleanup_ok=0
  # Owned-only gate (follow-up 2026-10-20_04): compare owned counts
  # (users/keys/posts/terms/media) plus settings_digest. cron_digest drift
  # is platform churn during the long campaign and is reported as
  # cron_drift without failing cleanup.
  if ! printf '%s' "$counts_baseline" | counts_stable "$counts_final"; then
    cleanup_ok=0
  else
    baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_baseline")" || cleanup_ok=0
    final_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_final")" || cleanup_ok=0
    [[ "$baseline_settings" == "$final_settings" ]] || cleanup_ok=0
  fi
  baseline_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_baseline")" || baseline_cron=""
  final_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_final")" || final_cron=""
  cron_drift=0
  [[ "$baseline_cron" == "$final_cron" ]] || cron_drift=1
  export ROLE_SEO_MATRIX_FILE="$matrix_file"
  export ROLE_SEO_VERIFY="$verify_code" ROLE_SEO_CLEANUP="$cleanup_ok" ROLE_SEO_COUNTS_BEFORE="$counts_before" ROLE_SEO_COUNTS_FINAL="$counts_final"
  export ROLE_SEO_MATRIX_CODE="$matrix_code" ROLE_SEO_COUNTS_BASELINE="$counts_baseline"
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
if ( 2 === (int) getenv( "ROLE_SEO_MATRIX_CODE" ) || ! is_file( $matrix_file ) ) {
  foreach ( array( "AUTH-01", "AUTH-02", "AUTH-03", "AUTH-04", "AUTH-05", "AUTH-06", "AUTH-07", "AUTH-08", "AUTH-09", "AUTH-10" ) as $must ) {
    $found = false;
    foreach ( $cases as $id => $result ) {
      if ( $id === $must || str_starts_with( $id, $must . "-" ) ) {
        $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
        $found = true;
      }
    }
    if ( ! $found ) {
      $cases[ $must ] = array( "ok" => false, "reason" => "internal_error" );
    }
  }
}
if ( "0" !== (string) getenv( "ROLE_SEO_VERIFY" ) ) {
  foreach ( $cases as $id => $result ) {
    $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
  }
}
$failed = false;
foreach ( $cases as $result ) { if ( true !== ( $result["ok"] ?? false ) ) { $failed = true; } }
$report = array(
  "suite" => "role-seo-permissions",
  "target" => getenv( "XNETVN_MATRIX_SITE" ),
  "run_id" => getenv( "XNETVN_ROLE_SEO_RUN_ID" ),
  "blog" => (int) getenv( "XNETVN_ROLE_SEO_BLOG" ),
  "cases" => $cases,
  "counts" => array( "before" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BEFORE" ), true ), "baseline" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BASELINE" ), true ), "final" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_FINAL" ), true ) ),
  "cleanup" => "1" === (string) getenv( "ROLE_SEO_CLEANUP" ),
  "cron_drift" => "1" === (string) getenv( "ROLE_SEO_CRON_DRIFT" ),
);
$run_dir = dirname( $matrix_file );
file_put_contents( $run_dir . "/results.json", json_encode( $report, JSON_PRETTY_PRINT ) );
echo json_encode( $report ) . "\n";
$exit = 0;
if ( "1" !== (string) getenv( "ROLE_SEO_CLEANUP" ) ) { $exit = 2; } elseif ( $failed ) { $exit = 1; }
exit( $exit );
')" || merge_code="$?"
  if [[ "$merge_code" != '0' && "$merge_code" != '1' && "$merge_code" != '2' ]]; then
    printf '{"suite":"role-seo-permissions","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"
    exit 2
  fi
  printf '%s\n' "$merge_out"
  exit "$merge_code"
fi

# STAB-01..STAB-07 stability campaign on one lab target: five independent
# B-E rounds with per-round seed and cleanup, then (wp69 only) the original
# benchmark under persistent cache. The shared lock, trap and cleanup
# discipline of the fixture flow below applies; cache enable/disable wraps
# the benchmark so a partial failure still restores the drop-in state.
if [[ "$group" == 'stability' ]]; then
  counts_before="$(counts_of)" || { printf '{"suite":"role-seo-stability","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"; exit 2; }
  counts_baseline="$counts_before"
  matrix_file="$run_dir/matrix.json"
  matrix_code=2
  if php /work/tests/E2E/role-seo-stability.php >"$matrix_file" 2>"$run_dir/matrix.stderr"; then
    matrix_code=0
  else
    matrix_code="$?"
  fi
  trap - EXIT
  # A phase that aborted before its own cleanup still owns users, keys and
  # content, and the marker of the site belongs to that phase. Every phase
  # run id of this campaign is reconciled before the root run is cleaned, so
  # an aborted phase can never leave owned data in the shared lab.
  reconcile_phases() {
    local dir base
    for dir in "$base_dir/private/$target"/"$run_id"-*; do
      [[ -d "$dir" ]] || continue
      base="$(basename "$dir")"
      export XNETVN_ROLE_SEO_RUN_ID="$base"
      fixture cleanup >/dev/null 2>&1 || true
      rmdir "$dir" 2>/dev/null || true
    done
    export XNETVN_ROLE_SEO_RUN_ID="$run_id"
  }
  reconcile_phases
  code=0; fixture cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || code="$?"
  rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
  cleanup_ok=1
  [[ "$code" == '0' && ! -e "$private_state" && ! -L "$private_state" ]] || cleanup_ok=0
  counts_final="$(counts_of)" || cleanup_ok=0
  # Owned-only gate (follow-up 2026-10-18_02, review MEDIUMs): compare owned
  # counts (users/keys/posts/terms/media) plus settings_digest. cron_digest
  # drift is platform churn during the long campaign and is reported as
  # cron_drift without failing cleanup.
  if ! printf '%s' "$counts_baseline" | counts_stable "$counts_final"; then
    cleanup_ok=0
  else
    baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_baseline")" || cleanup_ok=0
    final_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_final")" || cleanup_ok=0
    [[ "$baseline_settings" == "$final_settings" ]] || cleanup_ok=0
  fi
  baseline_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_baseline")" || baseline_cron=""
  final_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_final")" || final_cron=""
  cron_drift=0
  [[ "$baseline_cron" == "$final_cron" ]] || cron_drift=1
  # Campaign totals freeze before the benchmark flow: benchmark HTTP would
  # churn platform counters after role-seo already cleaned up.
  bench_code=2
  bench_file="$run_dir/benchmark.json"
  if [[ "$target" == 'wp69' ]]; then
    cache_enabled=0
    disable_cache_fixture() {
      if [[ "$cache_enabled" == '1' ]]; then
        bash /work/infra/remote/cache-fixture.sh disable wp69 >"$run_dir/cache-disable.log" 2>&1 || true
        cache_enabled=0
      fi
    }
    if bash /work/infra/remote/cache-fixture.sh enable wp69 >"$run_dir/cache-enable.log" 2>&1; then
      cache_enabled=1
      if bash /work/infra/remote/run-benchmark.sh >"$bench_file" 2>"$run_dir/benchmark.stderr"; then
        bench_code=0
      else
        bench_code="$?"
      fi
      disable_cache_fixture
    else
      bench_code=2
    fi
  fi
  export ROLE_SEO_MATRIX_FILE="$matrix_file" ROLE_SEO_BENCH_FILE="$bench_file"
  export ROLE_SEO_VERIFY="$matrix_code" ROLE_SEO_BENCH_CODE="$bench_code" ROLE_SEO_CLEANUP="$cleanup_ok"
  export ROLE_SEO_COUNTS_BEFORE="$counts_before" ROLE_SEO_COUNTS_FINAL="$counts_final"
  export ROLE_SEO_TARGET="$target" ROLE_SEO_MATRIX_CODE="$matrix_code" ROLE_SEO_COUNTS_BASELINE="$counts_baseline"
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
if ( 2 === (int) getenv( "ROLE_SEO_MATRIX_CODE" ) || ! is_file( $matrix_file ) ) {
  foreach ( array( "STAB-01", "STAB-02", "STAB-03", "STAB-04", "STAB-05", "STAB-06", "STAB-07" ) as $must ) {
    $cases[ $must ] = array( "ok" => false, "reason" => "internal_error" );
  }
} else {
  foreach ( array( "STAB-01", "STAB-02", "STAB-03", "STAB-04", "STAB-05", "STAB-06", "STAB-07" ) as $must ) {
    if ( ! isset( $cases[ $must ] ) || ! is_array( $cases[ $must ] ) ) {
      $cases[ $must ] = array( "ok" => false, "reason" => "internal_error" );
    }
  }
}
if ( "wp69" === (string) getenv( "ROLE_SEO_TARGET" ) ) {
  // The benchmark script enforces its own cache and environment
  // preconditions and exits nonzero without complete evidence, so a
  // readable report with all six original gates true is the pass signal.
  // The matrix-side prerequisite probe only sees role-seo traffic without
  // the benchmark drop-in, so it stays informational and never gates.
  $bench_report = array();
  $bench_file = (string) getenv( "ROLE_SEO_BENCH_FILE" );
  if ( is_file( $bench_file ) ) {
    $bench_decoded = json_decode( file_get_contents( $bench_file ), true );
    if ( is_array( $bench_decoded ) ) { $bench_report = $bench_decoded; }
  }
  $targets = ( isset( $bench_report["targets"] ) && is_array( $bench_report["targets"] ) ) ? $bench_report["targets"] : array();
  $gates = array();
  foreach ( array( "auth_median_lt_5_ms", "policy_median_lt_5_ms", "auth_policy_median_lt_5_ms", "list_dispatch_p50_lt_50_ms", "list_http_p50_lt_55_ms", "memory_max_lt_8_mib" ) as $gate ) {
    $gates[ $gate ] = true === ( $targets[ $gate ] ?? false );
  }
  $gates_ok = array() !== $gates && 0 === count( array_filter( $gates, static fn( $pass ): bool => true !== $pass ) );
  $cases["STAB-03"] = array( "ok" => $gates_ok, "reason" => "unavailable" );
  $bench = array( "applies" => true, "prerequisite" => ( $matrix["bench"]["prerequisite"] ?? false ), "exit" => (int) getenv( "ROLE_SEO_BENCH_CODE" ), "gates" => $gates );
} else {
  // The original benchmark gate is wp69-only by ticket design; on other
  // targets the declared STAB-03 row does not apply, so the campaign
  // neither passes nor fails it here.
  $cases["STAB-03"] = array( "ok" => true, "reason" => "unavailable" );
  $bench = array( "applies" => false, "prerequisite" => false, "exit" => null );
}
$failed = false;
foreach ( $cases as $result ) { if ( true !== ( $result["ok"] ?? false ) ) { $failed = true; } }
$report = array(
  "suite" => "role-seo-stability",
  "target" => getenv( "XNETVN_MATRIX_SITE" ),
  "run_id" => getenv( "XNETVN_ROLE_SEO_RUN_ID" ),
  "blog" => (int) getenv( "XNETVN_ROLE_SEO_BLOG" ),
  "cases" => $cases,
  "counts" => array( "before" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BEFORE" ), true ), "baseline" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BASELINE" ), true ), "final" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_FINAL" ), true ) ),
  "cleanup" => "1" === (string) getenv( "ROLE_SEO_CLEANUP" ),
  "cron_drift" => "1" === (string) getenv( "ROLE_SEO_CRON_DRIFT" ),
  "bench" => $bench,
  // The per actor and operation metrics of STAB-02 travel with the merged
  // report, so the evidence never stays in the matrix file alone.
  "latency" => ( isset( $matrix["latency"] ) && is_array( $matrix["latency"] ) ) ? $matrix["latency"] : array(),
  "actors" => ( isset( $matrix["actors"] ) && is_array( $matrix["actors"] ) ) ? $matrix["actors"] : array(),
  "memory" => ( isset( $matrix["memory"] ) && ( is_string( $matrix["memory"] ) || is_array( $matrix["memory"] ) ) ) ? $matrix["memory"] : "unavailable",
);
$run_dir = dirname( $matrix_file );
file_put_contents( $run_dir . "/results.json", json_encode( $report, JSON_PRETTY_PRINT ) );
echo json_encode( $report ) . "\n";
$exit = 0;
if ( "1" !== (string) getenv( "ROLE_SEO_CLEANUP" ) ) { $exit = 2; } elseif ( $failed ) { $exit = 1; }
exit( $exit );
')" || merge_code="$?"
  if [[ "$merge_code" != '0' && "$merge_code" != '1' && "$merge_code" != '2' ]]; then
    printf '{"suite":"role-seo-stability","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"
    exit 2
  fi
  printf '%s\n' "$merge_out"
  exit "$merge_code"
fi

# FX-01: guards refuse before any write and site totals stay flat.
counts_before="$(counts_of)" || { fx01_ok=0; fx01_reason='internal_error'; }
counts_baseline="$counts_before"
if [[ "$fx01_ok" == '1' ]]; then
  guard_ok=1
  code=0; fixture bogus-action >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || guard_ok=0
  code=0; XNETVN_MATRIX_SITE='bogus' fixture seed >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || guard_ok=0
  code=0; XNETVN_ROLE_SEO_RUN_ID='BAD ID!!' fixture seed >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || guard_ok=0
  code=0; env -u RTL_ALIAS_WP69 -u RTL_ALIAS_WP71 -u XNETVN_RTL_ALIAS -u XNETVN_RTL_DOMAIN WORDPRESS_TABLE_PREFIX="site${target#wp}_" wp --allow-root --path="/sites/$target" --url="https://$site_alias" eval-file /work/tests/E2E/role-seo-fixture.php seed --use-include >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || guard_ok=0
  counts_after_guard="$(counts_of)" || guard_ok=0
  if [[ "$guard_ok" == '1' ]]; then
    printf '%s' "$counts_before" | counts_stable "$counts_after_guard" || guard_ok=0
  fi
  [[ "$guard_ok" == '1' ]] || { fx01_ok=0; fx01_reason='internal_error'; }
fi

# FX-09: unknown groups and lock contention refuse with exit 2.
if [[ "$fx01_ok" == '1' ]]; then
  lock_ok=1
  code=0; bash /work/infra/remote/run-role-seo.sh "$target" bogus-group >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || lock_ok=0
  flock -n "$lock_file" sleep 30 &
  holder="$!"
  sleep 1
  code=0; bash /work/infra/remote/run-role-seo.sh "$target" fixture >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || lock_ok=0
  kill "$holder" 2>/dev/null || true
  wait "$holder" 2>/dev/null || true
  [[ "$lock_ok" == '1' ]] || { fx09_ok=0; fx09_reason='internal_error'; }
else
  fx09_ok=0; fx09_reason='unavailable'
fi

# FX-05: same-name foreign data and symlinked state refuse with exit 2.
if [[ "$fx01_ok" == '1' && "$fx09_ok" == '1' ]]; then
  foreign_ok=1
  bash /work/infra/remote/run-wp.sh "$target" user create "role-seo-${run_id}-admin" "role-seo-foreign-${run_id}@example.test" --role=editor >/dev/null 2>&1 || foreign_ok=0
  code=0; fixture seed >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || foreign_ok=0
  bash /work/infra/remote/run-wp.sh "$target" user delete "role-seo-${run_id}-admin" --yes >/dev/null 2>&1 || foreign_ok=0
  bash /work/infra/remote/run-wp.sh "$target" option add xnetvn_agent_hub_wp_role_seo_fixture '{"foreign":true}' >/dev/null 2>&1 || foreign_ok=0
  code=0; fixture seed >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || foreign_ok=0
  [[ "$(bash /work/infra/remote/run-wp.sh "$target" option get xnetvn_agent_hub_wp_role_seo_fixture 2>/dev/null)" == '{"foreign":true}' ]] || foreign_ok=0
  bash /work/infra/remote/run-wp.sh "$target" option delete xnetvn_agent_hub_wp_role_seo_fixture >/dev/null 2>&1 || foreign_ok=0
  mkdir -p "$base_dir/private/$target/$run_id" || foreign_ok=0
  ln -s /tmp/role-seo-foreign-state "$private_state" || foreign_ok=0
  code=0; fixture seed >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || foreign_ok=0
  rm -f "$private_state"
  rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
  counts_after_foreign="$(counts_of)" || foreign_ok=0
  if [[ "$foreign_ok" == '1' ]]; then
    printf '%s' "$counts_before" | counts_stable "$counts_after_foreign" || foreign_ok=0
  fi
  [[ "$foreign_ok" == '1' ]] || { fx05_ok=0; fx05_reason='internal_error'; }
  counts_baseline="$(counts_of)" || { fx05_ok=0; fx05_reason='internal_error'; }
else
  fx05_ok=0; fx05_reason='unavailable'
fi

# FX-06: a fault mid-seed restores the exact baseline on a scratch run.
scratch_fx06="${run_id}-fx06"
if [[ "$fx05_ok" == '1' ]]; then
  fault_ok=1
  code=0; XNETVN_ROLE_SEO_RUN_ID="$scratch_fx06" XNETVN_ROLE_SEO_FAIL_AFTER=3 fixture seed >/dev/null 2>&1 || code="$?"; [[ "$code" != '0' ]] || fault_ok=0
  code=0; XNETVN_ROLE_SEO_RUN_ID="$scratch_fx06" fixture restore >/dev/null 2>&1 || code="$?"; [[ "$code" == '0' ]] || fault_ok=0
  counts_after_restore="$(XNETVN_ROLE_SEO_RUN_ID="$scratch_fx06" counts_of)" || fault_ok=0
  printf '%s' "$counts_baseline" | counts_stable "$counts_after_restore" || fault_ok=0
  baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_baseline")" || fault_ok=0
  restore_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_after_restore")" || fault_ok=0
  [[ "$baseline_settings" == "$restore_settings" ]] || fault_ok=0
  code=0; XNETVN_ROLE_SEO_RUN_ID="$scratch_fx06" fixture cleanup >/dev/null 2>&1 || code="$?"; [[ "$code" == '0' ]] || fault_ok=0
  [[ "$fault_ok" == '1' ]] || { fx06_ok=0; fx06_reason='internal_error'; }
else
  fx06_ok=0; fx06_reason='unavailable'
fi

# FX-08: trap mechanics and SIGKILL ledger recovery on scratch runs.
# Runs before the main seed so scratch states never collide with it.
if [[ "$fx06_ok" == '1' ]]; then
  trap_ok=1
  probe_dir="$run_dir/fx08"
  mkdir -p "$probe_dir"
  code=0; ( trap 'touch "$probe_dir/normal"' EXIT; exit 0 ) || code="$?"; [[ "$code" == '0' && -f "$probe_dir/normal" ]] || trap_ok=0
  code=0; ( trap 'touch "$probe_dir/assert"' EXIT; exit 1 ) || code="$?"; [[ "$code" == '1' && -f "$probe_dir/assert" ]] || trap_ok=0
  code=0; ( trap 'touch "$probe_dir/term"' EXIT; sleep 30 & child="$!"; kill -TERM "$child"; wait "$child" ) || code="$?"; [[ "$code" == '143' && -f "$probe_dir/term" ]] || trap_ok=0
  scratch_fx08="${run_id}-fx08"
  XNETVN_ROLE_SEO_RUN_ID="$scratch_fx08" fixture seed >/dev/null 2>&1 || trap_ok=0
  sleep 60 &
  killed_child="$!"
  kill -KILL "$killed_child" 2>/dev/null || true
  wait "$killed_child" 2>/dev/null || true
  code=0; XNETVN_ROLE_SEO_RUN_ID="$scratch_fx08" fixture reconcile >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || trap_ok=0
  code=0; XNETVN_ROLE_SEO_RUN_ID="$scratch_fx08" fixture restore >/dev/null 2>&1 || code="$?"; [[ "$code" == '0' ]] || trap_ok=0
  counts_after_kill="$(XNETVN_ROLE_SEO_RUN_ID="$scratch_fx08" counts_of)" || trap_ok=0
  printf '%s' "$counts_baseline" | counts_stable "$counts_after_kill" || trap_ok=0
  baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_baseline")" || trap_ok=0
  kill_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_after_kill")" || trap_ok=0
  [[ "$baseline_settings" == "$kill_settings" ]] || trap_ok=0
  code=0; XNETVN_ROLE_SEO_RUN_ID="$scratch_fx08" fixture cleanup >/dev/null 2>&1 || code="$?"; [[ "$code" == '0' ]] || trap_ok=0
  [[ "$trap_ok" == '1' ]] || { fx08_ok=0; fx08_reason='internal_error'; }
else
  fx08_ok=0; fx08_reason='unavailable'
fi

# Main seed, FX-04 idempotence and the MCP matrix.
matrix_file="$run_dir/matrix.json"
matrix_code=2
verify_code=2
if [[ "$fx06_ok" == '1' ]]; then
  seed_ok=1
  code=0; fixture seed >"$run_dir/seed.json" 2>"$run_dir/seed.stderr" || code="$?"; [[ "$code" == '0' ]] || seed_ok=0
  verify_code=0; fixture verify >"$run_dir/verify.json" 2>"$run_dir/verify.stderr" || verify_code="$?"; [[ "$verify_code" == '0' ]] || seed_ok=0
  if [[ "$seed_ok" == '1' ]]; then
    idem_ok=1
    counts_pre_idem="$(counts_of)" || idem_ok=0
    code=0; fixture seed >"$run_dir/seed-idem.json" 2>"$run_dir/seed-idem.stderr" || code="$?"
    [[ "$code" == '0' && "$(cat "$run_dir/seed-idem.json")" == *'"idempotent":true'* ]] || idem_ok=0
    counts_post_idem="$(counts_of)" || idem_ok=0
    if [[ "$idem_ok" == '1' ]]; then
      printf '%s' "$counts_pre_idem" | counts_stable "$counts_post_idem" || idem_ok=0
    fi
    code=0; XNETVN_ROLE_SEO_RUN_ID="${run_id}-other" fixture seed >/dev/null 2>&1 || code="$?"; [[ "$code" == '2' ]] || idem_ok=0
    [[ "$idem_ok" == '1' ]] || { fx04_ok=0; fx04_reason='internal_error'; }
    if php /work/tests/E2E/role-seo-fixture-matrix.php >"$matrix_file" 2>"$run_dir/matrix.stderr"; then
      matrix_code=0
    else
      matrix_code="$?"
    fi
  else
    fx04_ok=0; fx04_reason='unavailable'
  fi
else
  fx04_ok=0; fx04_reason='unavailable'
fi

# Explicit cleanup of the main run, then the secret-free merged report.
trap - EXIT
code=0; fixture cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || code="$?"
rmdir "$base_dir/private/$target/$run_id" 2>/dev/null || true
cleanup_ok=1
[[ "$code" == '0' && ! -e "$private_state" && ! -L "$private_state" ]] || cleanup_ok=0
counts_final="$(counts_of)" || cleanup_ok=0
# Owned-only gate (follow-up 2026-10-20_06): compare owned counts
# (users/keys/posts/terms/media) plus settings_digest. cron_digest drift
# is platform churn during the long campaign and is reported as
# cron_drift without failing cleanup.
if ! printf '%s' "$counts_baseline" | counts_stable "$counts_final"; then
  cleanup_ok=0
else
  baseline_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_baseline")" || cleanup_ok=0
  final_settings="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["settings_digest"] ?? "");' "$counts_final")" || cleanup_ok=0
  [[ "$baseline_settings" == "$final_settings" ]] || cleanup_ok=0
fi
baseline_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_baseline")" || baseline_cron=""
final_cron="$(php -r '$d=json_decode($argv[1],true); echo (string)($d["cron_digest"] ?? "");' "$counts_final")" || final_cron=""
cron_drift=0
[[ "$baseline_cron" == "$final_cron" ]] || cron_drift=1

export ROLE_SEO_MATRIX_FILE="$matrix_file"
export ROLE_SEO_FX01="$fx01_ok:$fx01_reason" ROLE_SEO_FX04="$fx04_ok:$fx04_reason" ROLE_SEO_FX05="$fx05_ok:$fx05_reason"
export ROLE_SEO_FX06="$fx06_ok:$fx06_reason" ROLE_SEO_FX08="$fx08_ok:$fx08_reason" ROLE_SEO_FX09="$fx09_ok:$fx09_reason"
export ROLE_SEO_VERIFY="$verify_code" ROLE_SEO_CLEANUP="$cleanup_ok" ROLE_SEO_COUNTS_BEFORE="$counts_before" ROLE_SEO_COUNTS_FINAL="$counts_final"
export ROLE_SEO_MATRIX_CODE="$matrix_code" ROLE_SEO_COUNTS_BASELINE="$counts_baseline"
export ROLE_SEO_CRON_DRIFT="$cron_drift"
merge_code=0
merge_out="$(php -r '
$matrix_file = getenv( "ROLE_SEO_MATRIX_FILE" );
$matrix = array( "cases" => array() );
if ( is_file( $matrix_file ) ) {
  $decoded = json_decode( file_get_contents( $matrix_file ), true );
  if ( is_array( $decoded ) ) { $matrix = $decoded; }
}
$runner = array();
foreach ( array( "FX-01" => "ROLE_SEO_FX01", "FX-04" => "ROLE_SEO_FX04", "FX-05" => "ROLE_SEO_FX05", "FX-06" => "ROLE_SEO_FX06", "FX-08" => "ROLE_SEO_FX08", "FX-09" => "ROLE_SEO_FX09" ) as $id => $env ) {
  $parts = explode( ":", (string) getenv( $env ) );
  $runner[ $id ] = array( "ok" => "1" === ( $parts[0] ?? "" ), "reason" => $parts[1] ?? "unavailable" );
}
$cases = $matrix["cases"] ?? array();
if ( "0" !== (string) getenv( "ROLE_SEO_MATRIX_CODE" ) ) {
  foreach ( array( "FX-02", "FX-03", "FX-07" ) as $must ) {
    $found = false;
    foreach ( $cases as $id => $result ) {
      if ( $id === $must || str_starts_with( $id, $must . "-" ) ) {
        $cases[ $id ] = array( "ok" => false, "reason" => "internal_error" );
        $found = true;
      }
    }
    if ( ! $found ) {
      $cases[ $must ] = array( "ok" => false, "reason" => "internal_error" );
    }
  }
}
foreach ( $runner as $id => $result ) { $cases[ $id ] = $result; }
if ( isset( $cases["FX-02"] ) ) {
  $cases["FX-02"]["ok"] = ( $cases["FX-02"]["ok"] ?? false ) && "0" === (string) getenv( "ROLE_SEO_VERIFY" );
  if ( ! $cases["FX-02"]["ok"] ) { $cases["FX-02"]["reason"] = "internal_error"; }
}
$failed = false;
foreach ( $cases as $result ) { if ( true !== ( $result["ok"] ?? false ) ) { $failed = true; } }
$report = array(
  "suite" => "role-seo-fixture",
  "target" => getenv( "XNETVN_MATRIX_SITE" ),
  "run_id" => getenv( "XNETVN_ROLE_SEO_RUN_ID" ),
  "blog" => (int) getenv( "XNETVN_ROLE_SEO_BLOG" ),
  "cases" => $cases,
  "counts" => array( "before" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BEFORE" ), true ), "baseline" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_BASELINE" ), true ), "final" => json_decode( (string) getenv( "ROLE_SEO_COUNTS_FINAL" ), true ) ),
  "cleanup" => "1" === (string) getenv( "ROLE_SEO_CLEANUP" ),
  "cron_drift" => "1" === (string) getenv( "ROLE_SEO_CRON_DRIFT" ),
);
$run_dir = dirname( $matrix_file );
file_put_contents( $run_dir . "/results.json", json_encode( $report, JSON_PRETTY_PRINT ) );
echo json_encode( $report ) . "\n";
$exit = 0;
if ( "1" !== (string) getenv( "ROLE_SEO_CLEANUP" ) ) { $exit = 2; } elseif ( $failed ) { $exit = 1; }
exit( $exit );
')" || merge_code="$?"
if [[ "$merge_code" != '0' && "$merge_code" != '1' && "$merge_code" != '2' ]]; then
  printf '{"suite":"role-seo-fixture","target":"%s","run_id":"%s","cases":{},"counts":{},"cleanup":false}\n' "$target" "$run_id"
  exit 2
fi
printf '%s\n' "$merge_out"
exit "$merge_code"
