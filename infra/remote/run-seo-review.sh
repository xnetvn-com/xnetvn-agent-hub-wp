#!/usr/bin/env bash
# Retained SEO review campaign: prepare, publish/verify/reconcile, hand off.
#
# Lab-only wrapper around the MCP publishing flow. It prepares the owned review
# actors and run-scoped keys, runs exactly one action, and always cleans only
# the run-scoped credentials and bounded temporary files. The article, its
# three attachments, its terms and its author stay for the project owner after
# every outcome, including a failed or interrupted run.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }

target="${1:-}"
campaign="${2:-}"
action="${3:-}"
[[ "$target" == 'wp69' || "$target" == 'wp71' ]] || { printf 'Select wp69 or wp71.\n' >&2; exit 2; }
[[ "$campaign" =~ ^review-[0-9]{8}T[0-9]{6}Z$ ]] || { printf 'Select a review campaign like review-20261026T101112Z.\n' >&2; exit 2; }
case "$action" in
  publish|verify|reconcile) ;;
  *) printf 'Select publish, verify or reconcile.\n' >&2; exit 2;;
esac
case "$target" in
  wp69) site_alias="$RTL_ALIAS_WP69";;
  wp71) site_alias="$RTL_ALIAS_WP71";;
esac
[[ -n "$site_alias" ]] || { printf 'The target alias is unavailable.\n' >&2; exit 2; }

export XNETVN_SEO_REVIEW_TARGET="$target"
export XNETVN_SEO_REVIEW_CAMPAIGN="$campaign"
export XNETVN_SEO_REVIEW_ACTION="$action"
export XNETVN_SEO_REVIEW_HOME="${XNETVN_SEO_REVIEW_HOME:-/work/.local/seo-review}"
base_dir="$XNETVN_SEO_REVIEW_HOME"
private_dir="$base_dir/private/$target/$campaign"
handoff="$base_dir/$target/$campaign/handoff.json"
mkdir -p "$base_dir"
exec 9>"$base_dir/$target.lock"
flock -n 9 || { printf 'Another SEO review run holds this target.\n' >&2; exit 2; }

run_id="${XNETVN_SEO_REVIEW_RUN_ID:-run-$(date -u +%Y%m%dT%H%M%SZ)-$$}"
run_dir="$base_dir/$target/$campaign/runner"
mkdir -p "$run_dir" "$private_dir"
revision="${XNETVN_SEO_REVIEW_REVISION:-$(git -C /work rev-parse HEAD 2>/dev/null || printf 'unknown')}"
fingerprint="$(cd /work && sha256sum tests/E2E/seo-review-fixture.php tests/E2E/seo-review.php infra/remote/run-seo-review.sh tests/Integration/SeoReviewRetentionTest.php tests/E2E/seo-review.spec.js 2>/dev/null | sha256sum | cut -d' ' -f1)"
[[ -n "$fingerprint" ]] || fingerprint='unavailable'
export XNETVN_SEO_REVIEW_REVISION="$revision"
export XNETVN_SEO_REVIEW_FINGERPRINT="$fingerprint"

fixture() {
  bash /work/infra/remote/run-wp.sh "$target" --url="https://$site_alias" eval-file /work/tests/E2E/seo-review-fixture.php "$@" --use-include
}

cleanup_done=0
cleanup_ok=0
interrupted=''
cleanup() {
  if [[ "$cleanup_done" == '1' ]]; then
    return
  fi
  cleanup_done=1
  local code=0
  fixture credentials-cleanup >"$run_dir/cleanup.json" 2>"$run_dir/cleanup.stderr" || code="$?"
  cleanup_ok=0
  if [[ "$code" == '0' && ! -e "$private_dir/credentials.json" && ! -L "$private_dir/credentials.json" ]]; then
    cleanup_ok=1
  fi
  return 0
}
on_signal() {
  interrupted="$1"
  trap - EXIT INT TERM
  cleanup
  exit 2
}
trap 'on_signal INT' INT
trap 'on_signal TERM' TERM
trap cleanup EXIT

prepare_code=0
fixture prepare >"$run_dir/prepare.json" 2>"$run_dir/prepare.stderr" || prepare_code="$?"
flow_code=2
if [[ "$prepare_code" == '0' ]]; then
  if php /work/tests/E2E/seo-review.php >"$run_dir/flow.json" 2>"$run_dir/flow.stderr"; then
    flow_code=0
  else
    flow_code="$?"
  fi
fi
trap - EXIT INT TERM
cleanup

# The handoff records the actual cleanup state after the trap, never a promise
# from before it ran. Only booleans and paths change here.
if [[ -f "$handoff" && ! -L "$handoff" ]]; then
  HANDOFF_FILE="$handoff" CLEANUP_FILE="$run_dir/cleanup.json" php -r '
$handoff = (string) getenv( "HANDOFF_FILE" );
$data = json_decode( (string) file_get_contents( $handoff ), true );
$cleanup = json_decode( (string) @file_get_contents( (string) getenv( "CLEANUP_FILE" ) ), true );
if ( is_array( $data ) ) {
  $data["credential_cleanup"] = true === ( $cleanup["credentials_removed"] ?? false );
  $data["temp_cleanup"] = true === ( $cleanup["temp_removed"] ?? false );
  $data["cleanup"] = array( "keys" => $data["credential_cleanup"] ? "revoked" : "pending", "temp" => $data["temp_cleanup"] ? "removed" : "pending", "article" => "retained" );
  $tmp = $handoff . ".tmp-" . getmypid();
  file_put_contents( $tmp, (string) json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
  chmod( $tmp, 0600 );
  rename( $tmp, $handoff );
}
' || true
fi

final_code=2
if [[ "$prepare_code" == '0' && "$cleanup_ok" == '1' ]]; then
  case "$flow_code" in
    0) final_code=0;;
    1) final_code=1;;
    *) final_code=2;;
  esac
fi
export SEO_REVIEW_FINAL="$final_code" SEO_REVIEW_FLOW="$flow_code" SEO_REVIEW_PREPARE="$prepare_code" SEO_REVIEW_CLEANUP="$cleanup_ok"
export SEO_REVIEW_INTERRUPTED="$interrupted" SEO_REVIEW_TARGET="$target" SEO_REVIEW_CAMPAIGN="$campaign" SEO_REVIEW_ACTION="$action"
export SEO_REVIEW_RUN_ID="$run_id" SEO_REVIEW_REVISION="$revision" SEO_REVIEW_FINGERPRINT="$fingerprint"
export SEO_REVIEW_FLOW_FILE="$run_dir/flow.json" SEO_REVIEW_PREPARE_FILE="$run_dir/prepare.json" SEO_REVIEW_CLEANUP_FILE="$run_dir/cleanup.json"
export SEO_REVIEW_HANDOFF="$handoff" SEO_REVIEW_JOURNAL="$private_dir/journal.json" SEO_REVIEW_RUN_DIR="$run_dir"
php -r '
$read = static function ( string $file ): mixed {
  if ( ! is_file( $file ) ) {
    return array();
  }
  $decoded = json_decode( (string) file_get_contents( $file ), true );
  return is_array( $decoded ) ? $decoded : array();
};
$report = array(
  "suite"       => "seo-review-runner",
  "target"      => (string) getenv( "SEO_REVIEW_TARGET" ),
  "campaign"    => (string) getenv( "SEO_REVIEW_CAMPAIGN" ),
  "action"      => (string) getenv( "SEO_REVIEW_ACTION" ),
  "run_id"      => (string) getenv( "SEO_REVIEW_RUN_ID" ),
  "revision"    => (string) getenv( "SEO_REVIEW_REVISION" ),
  "fingerprint" => (string) getenv( "SEO_REVIEW_FINGERPRINT" ),
  "exit"        => (int) getenv( "SEO_REVIEW_FINAL" ),
  "flow_exit"   => (int) getenv( "SEO_REVIEW_FLOW" ),
  "prepare_exit" => (int) getenv( "SEO_REVIEW_PREPARE" ),
  "cleanup_ok"  => "1" === (string) getenv( "SEO_REVIEW_CLEANUP" ),
  "interrupted" => (string) getenv( "SEO_REVIEW_INTERRUPTED" ),
  "journal"     => (string) getenv( "SEO_REVIEW_JOURNAL" ),
  "handoff"     => (string) getenv( "SEO_REVIEW_HANDOFF" ),
  "evidence"    => (string) getenv( "SEO_REVIEW_RUN_DIR" ),
  "prepare"     => $read( (string) getenv( "SEO_REVIEW_PREPARE_FILE" ) ),
  "flow"        => $read( (string) getenv( "SEO_REVIEW_FLOW_FILE" ) ),
  "cleanup"     => $read( (string) getenv( "SEO_REVIEW_CLEANUP_FILE" ) ),
);
echo json_encode( $report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "\n";
'
exit "$final_code"
