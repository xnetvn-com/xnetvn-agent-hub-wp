#!/usr/bin/env bash
# Retained comment lifecycle review with controlled disposable deletion.
#
# wp69 keeps every review state, including spam and trash, and never calls
# purge. wp71 restores review spam/trash before purge, re-audits immediately
# before every purge call, and only allows disposable comments to be deleted.
# The runner never deletes a review comment, post, user or option.
set -euo pipefail

[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }

target="${1:-}"
case "$target" in
  wp69)
    site_alias="${RTL_ALIAS_WP69:-}"
    retention='all'
    ;;
  wp71)
    site_alias="${RTL_ALIAS_WP71:-}"
    retention='purge-safe'
    ;;
  *)
    printf 'Select wp69 or wp71.\n' >&2
    exit 2
    ;;
esac
[[ -n "$site_alias" ]] || { printf 'Site alias is missing.\n' >&2; exit 2; }

export XNETVN_MATRIX_SITE="$target"
export COMMENTS_REVIEW_RETENTION="$retention"
run_id="${XNETVN_COMMENTS_REVIEW_RUN_ID:-run-$(date -u +%Y%m%dT%H%M%SZ)-$$}"
[[ "$run_id" =~ ^[A-Za-z0-9._-]{1,80}$ ]] || { printf 'Run id is invalid.\n' >&2; exit 2; }
export XNETVN_COMMENTS_REVIEW_RUN_ID="$run_id"
export COMMENTS_REVIEW_RUN_ID="$run_id"

base_dir='/work/.local/comments-review'
run_dir="$base_dir/$target/$run_id"
private_dir="$base_dir/private/$target"
state_path="$private_dir/state.json"
mkdir -p "$base_dir"
if [[ -d "$run_dir" ]]; then
  existing="$(find "$run_dir" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null || true)"
  [[ -z "$existing" ]] || { printf 'Run directory already holds evidence.\n' >&2; exit 2; }
fi
mkdir -p "$run_dir" "$private_dir"
chmod 0700 "$base_dir" "$run_dir" "$private_dir"
export COMMENTS_REVIEW_RUN_DIR="$run_dir"
export COMMENTS_REVIEW_STATE="$state_path"

exec 9>"$base_dir/$target.lock"
flock -n 9 || { printf 'Another comments review run holds this target.\n' >&2; exit 2; }
if [[ -e "$state_path" || -L "$state_path" ]]; then
  printf 'Reconcile the existing comments review state at %s first.\n' "$state_path" >&2
  exit 2
fi

revision="${XNETVN_REVISION:-unavailable}"
if [[ "$revision" == 'unavailable' ]] && git -C /work rev-parse HEAD >/dev/null 2>&1; then
  revision="$(git -C /work rev-parse HEAD)"
fi
fingerprint='unavailable'
if [[ -f /work/tests/E2E/comments-review.php && -f /work/tests/E2E/comments-review-fixture.php && -f /work/infra/remote/run-comments-review.sh ]]; then
  fingerprint="$(sha256sum /work/tests/E2E/comments-review.php /work/tests/E2E/comments-review-fixture.php /work/infra/remote/run-comments-review.sh | sha256sum | cut -d' ' -f1)"
fi
export XNETVN_REVISION="$revision"
export XNETVN_FINGERPRINT="$fingerprint"

setup_file="$run_dir/setup.json"
restore_file="$run_dir/restore.json"
audit_spam_file="$run_dir/audit-spam.json"
audit_trash_file="$run_dir/audit-trash.json"
results_file="$run_dir/results.json"
status_before="$run_dir/retained-before.json"
status_after="$run_dir/retained-after.json"
cleanup_file="$run_dir/cleanup.json"
settings_file="$run_dir/settings.json"
state_delete_file="$run_dir/state-delete.json"
report_file="$run_dir/report.json"

fixture() {
  bash /work/infra/remote/run-wp.sh "$target" --url="https://$site_alias" eval-file /work/tests/E2E/comments-review-fixture.php "$@" --use-include
}

fixture_status() {
  fixture status
}

audit_safe() {
  php -r '
$d = json_decode( file_get_contents( $argv[1] ), true );
if ( ! is_array( $d ) ) { exit( 1 ); }
exit( 0 === (int) ( $d["foreign"] ?? -1 ) && 0 === (int) ( $d["review"] ?? -1 ) ? 0 : 1 );
' "$1"
}

restore_clean() {
  php -r '
$d = json_decode( file_get_contents( $argv[1] ), true );
if ( ! is_array( $d ) ) { exit( 1 ); }
$ok = empty( $d["failed"] ) && empty( $d["remaining_review"] ) && empty( $d["missing_review"] );
exit( $ok ? 0 : 1 );
' "$1"
}

status_equal() {
  php -r '
$a = json_decode( file_get_contents( $argv[1] ), true );
$b = json_decode( file_get_contents( $argv[2] ), true );
if ( ! is_array( $a ) || ! is_array( $b ) ) { exit( 1 ); }
$same = ( $a["review_ids"] ?? null ) === ( $b["review_ids"] ?? null )
  && ( $a["review_statuses"] ?? null ) === ( $b["review_statuses"] ?? null )
  && ( $a["review_counts"] ?? null ) === ( $b["review_counts"] ?? null );
exit( $same ? 0 : 1 );
' "$1" "$2"
}

restore_settings_safe() {
  fixture restore-settings >"$settings_file" 2>"$run_dir/settings.stderr" || return 1
  php -r '
$d = json_decode( file_get_contents( $argv[1] ), true );
if ( ! is_array( $d ) ) { exit( 1 ); }
$v = (string) ( $d["rate_limit"] ?? "" );
exit( in_array( $v, array( "restored", "skipped_changed", "absent" ), true ) ? 0 : 1 );
' "$settings_file"
}

cleanup_known_safe() {
  fixture_status >"$status_before" 2>"$run_dir/retained-before.stderr" || return 1
  fixture cleanup-keys >"$cleanup_file" 2>"$run_dir/cleanup.stderr" || return 1
  fixture_status >"$status_after" 2>"$run_dir/retained-after.stderr" || return 1
  status_equal "$status_before" "$status_after" || return 1
  fixture delete-state >"$state_delete_file" 2>"$run_dir/state-delete.stderr" || return 1
}

write_settings_state() {
  if [[ -f "$settings_file" ]]; then
    settings_state="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo is_array($d)?(string)($d["rate_limit"]??"failed"):"failed";' "$settings_file" 2>/dev/null || printf 'failed')"
  else
    settings_state='absent'
  fi
  case "$settings_state" in
    restored|skipped_changed|absent) ;;
    *) settings_state='failed' ;;
  esac
}

write_retained_cleanup() {
  write_settings_state
  printf '{"state":"keys_retained","settings":"%s"}\n' "$settings_state" >"$cleanup_file"
}

write_report() {
  export REPORT_REVISION="$revision"
  export REPORT_FINGERPRINT="$fingerprint"
  export REPORT_SETUP="$setup_file"
  export REPORT_RESTORE="$restore_file"
  export REPORT_AUDIT_SPAM="$audit_spam_file"
  export REPORT_AUDIT_TRASH="$audit_trash_file"
  export REPORT_RESULTS="$results_file"
  export REPORT_STATUS_BEFORE="$status_before"
  export REPORT_STATUS_AFTER="$status_after"
  export REPORT_CLEANUP="$cleanup_file"
  export REPORT_SETTINGS="$settings_file"
  export REPORT_STATE_DELETE="$state_delete_file"
  export REPORT_OUT="$report_file"
  php -r '
$read = static function ( string $path ): ?array {
    if ( ! is_file( $path ) ) { return null; }
    $raw = file_get_contents( $path );
    $data = json_decode( $raw, true );
    return is_array( $data ) ? $data : null;
};
$report = array(
    "suite"        => "comments-review",
    "site"         => getenv( "XNETVN_MATRIX_SITE" ),
    "run_id"       => getenv( "COMMENTS_REVIEW_RUN_ID" ),
    "retention"    => getenv( "COMMENTS_REVIEW_RETENTION" ),
    "revision"     => getenv( "REPORT_REVISION" ),
    "fingerprint"  => getenv( "REPORT_FINGERPRINT" ),
    "result"       => getenv( "COMMENTS_REVIEW_RESULT" ),
    "exit_code"    => (int) getenv( "COMMENTS_REVIEW_EXIT_CODE" ),
    "setup"        => $read( getenv( "REPORT_SETUP" ) ),
    "restore"      => $read( getenv( "REPORT_RESTORE" ) ),
    "audit_spam"   => $read( getenv( "REPORT_AUDIT_SPAM" ) ),
    "audit_trash"  => $read( getenv( "REPORT_AUDIT_TRASH" ) ),
    "results"      => $read( getenv( "REPORT_RESULTS" ) ),
    "retained"     => array(
        "before" => $read( getenv( "REPORT_STATUS_BEFORE" ) ),
        "after"  => $read( getenv( "REPORT_STATUS_AFTER" ) ),
    ),
    "cleanup"      => $read( getenv( "REPORT_CLEANUP" ) ),
    "settings"     => $read( getenv( "REPORT_SETTINGS" ) ),
    "state_delete" => $read( getenv( "REPORT_STATE_DELETE" ) ),
);
file_put_contents( getenv( "REPORT_OUT" ), json_encode( $report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR ), LOCK_EX );
' >/dev/null
}

finish() {
  local result="$1"
  local code="$2"
  export COMMENTS_REVIEW_RESULT="$result"
  export COMMENTS_REVIEW_EXIT_CODE="$code"
  if ! write_report; then
    printf 'comments-review report_failed\n' >&2
    result='failed'
    code=1
  fi
  printf 'comments-review site=%s run_id=%s result=%s retention=%s report=%s\n' "$target" "$run_id" "$result" "$retention" "$report_file"
  if [[ -f "$results_file" ]]; then
    php -r '
$d = json_decode( file_get_contents( $argv[1] ), true );
if ( ! is_array( $d ) ) { exit( 0 ); }
printf( "comments-review passed=%d failed=%d blocked=%d retained=%d deleted=%d\n", (int) ( $d["passed"] ?? 0 ), (int) ( $d["failed"] ?? 0 ), (int) ( $d["blocked"] ?? 0 ), (int) ( $d["retention_state"]["review_total"] ?? 0 ), (int) ( $d["retention_state"]["intentionally_deleted"] ?? 0 ) );
' "$results_file" || true
  fi
  if [[ -f "$state_path" ]]; then
    printf 'comments-review state=retained_for_reconciliation path=%s\n' "$state_path"
  fi
  exit "$code"
}

if ! fixture setup >"$setup_file" 2>"$run_dir/setup.stderr"; then
  if [[ -f "$state_path" ]]; then
    restore_settings_safe || true
  fi
  write_retained_cleanup
  finish 'failed' 1
fi

if [[ "$retention" == 'purge-safe' ]]; then
  if ! fixture restore-review >"$restore_file" 2>"$run_dir/restore.stderr"; then
    restore_settings_safe || true
    write_retained_cleanup
    finish 'failed' 1
  fi
  if ! restore_clean "$restore_file"; then
    if cleanup_known_safe; then
      finish 'blocked' 2
    fi
    restore_settings_safe || true
    write_retained_cleanup
    finish 'failed' 1
  fi
  for status in spam trash; do
    audit_file="$audit_spam_file"
    [[ "$status" == 'trash' ]] && audit_file="$audit_trash_file"
    if ! fixture purge-audit "$status" >"$audit_file" 2>"$run_dir/audit-$status.stderr"; then
      if cleanup_known_safe; then
        finish 'blocked' 2
      fi
      restore_settings_safe || true
      write_retained_cleanup
      finish 'failed' 1
    fi
    if ! audit_safe "$audit_file"; then
      if cleanup_known_safe; then
        finish 'blocked' 2
      fi
      restore_settings_safe || true
      write_retained_cleanup
      finish 'failed' 1
    fi
  done
fi

matrix_code=0
php /work/tests/E2E/comments-review.php >"$run_dir/matrix.stdout" 2>"$run_dir/matrix.stderr" || matrix_code="$?"

matrix_result='failed'
cleanup_safe='0'
if [[ -f "$results_file" ]]; then
  matrix_result="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo is_array($d)?(string)($d["result"]??"failed"):"failed";' "$results_file")"
  cleanup_safe="$(php -r '$d=json_decode(file_get_contents($argv[1]),true); echo is_array($d)&&!empty($d["cleanup_safe"])?"1":"0";' "$results_file")"
fi

if [[ "$matrix_code" == '2' && "$matrix_result" == 'blocked' && "$cleanup_safe" == '1' ]]; then
  matrix_result='blocked'
elif [[ "$matrix_code" == '0' && "$matrix_result" == 'pass' && "$cleanup_safe" == '1' ]]; then
  matrix_result='pass'
else
  matrix_result='failed'
  cleanup_safe='0'
fi

if [[ "$cleanup_safe" == '1' ]]; then
  if ! cleanup_known_safe; then
    restore_settings_safe || true
    write_retained_cleanup
    finish 'failed' 1
  fi
else
  restore_settings_safe || true
  write_retained_cleanup
  if [[ "$settings_state" == 'failed' ]]; then
    finish 'failed' 1
  fi
fi

if [[ "$matrix_result" == 'pass' ]]; then
  finish 'pass' 0
fi
if [[ "$matrix_result" == 'blocked' ]]; then
  finish 'blocked' 2
fi
finish 'failed' 1
