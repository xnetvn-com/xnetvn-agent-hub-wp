#!/usr/bin/env bash
# Acceptance campaign runner for the MCP role/SEO evidence.
#
# The runner executes one phase of a campaign inside the lab namespace and
# prints exactly one sanitized JSON block between the ROLE_SEO_REPORT_BEGIN
# and ROLE_SEO_REPORT_END markers. The block carries case ids, roles,
# targets, allowlisted reasons, counts, timings and boolean checks only; the
# raw group reports stay in the private run directory of the lab and are
# never fetched.
#
# The two phases are sequential modes of the same namespace: `single` runs
# the owned groups on wp69 and then wp71, `network` runs the network group
# on the main site and the child site. The runner never starts, stops or
# converts a stack; the reviewed transition owns that.
#
# Usage: run-role-seo-acceptance.sh single|network role-seo-YYYYMMDDTHHMMSSZ
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' ]] || { printf 'Remote lab required.\n' >&2; exit 2; }

phase="${1:-}"
campaign="${2:-}"
[[ "$phase" == 'single' || "$phase" == 'network' ]] || { printf 'Select single or network.\n' >&2; exit 2; }
[[ "$campaign" =~ ^role-seo-[0-9]{8}T[0-9]{6}Z$ ]] || { printf 'Campaign must match role-seo-YYYYMMDDTHHMMSSZ.\n' >&2; exit 2; }

acceptance_root="/work/.local/role-seo-acceptance"
run_dir="$acceptance_root/$campaign"
input_dir="$run_dir/input"
mkdir -p "$input_dir"

# The run id of the owned fixture stays short: the stability matrix derives
# phase run ids from it and every derived id has to stay inside the
# fixture guard pattern.
stamp="$(printf '%s' "${campaign#role-seo-}" | tr '[:upper:]' '[:lower:]')"
date_part="${stamp:0:8}"
time_part="${stamp:9:6}"
run_prefix="acc-${date_part}-${time_part}"

group_code() {
  case "$1" in
    fixture) printf 'fx' ;;
    permissions) printf 'pe' ;;
    content) printf 'co' ;;
    media) printf 'me' ;;
    lifecycle) printf 'li' ;;
    stability) printf 'st' ;;
    network) printf 'nw' ;;
    *) printf 'xx' ;;
  esac
}

blog_code() {
  case "$1" in
    network-main) printf 'm' ;;
    network-child) printf 'c' ;;
    *) printf 'x' ;;
  esac
}

report_built=0
cleanup_acceptance() {
  trap - EXIT
  # The owned fixtures of every group are cleaned by the group runner. A
  # phase that ends before its report exists leaves a partial input
  # directory behind; that directory is removed so that the next run never
  # mistakes it for evidence. A finished phase keeps its private input on
  # the lab, so that no private state has to travel to the receiving
  # machine.
  if [[ "$report_built" != '1' ]]; then
    rm -rf "$input_dir" 2>/dev/null || true
  fi
}
trap cleanup_acceptance EXIT

run_single_group() {
  local target="$1" group="$2" code=0 matrix=''
  export XNETVN_ROLE_SEO_RUN_ID="${run_prefix}-${target}$( group_code "$group" )"
  bash /work/infra/remote/run-role-seo.sh "$target" "$group" >"$input_dir/${group}__${target}.json" 2>"$run_dir/${group}__${target}.stderr" || code="$?"
  printf '%s\n' "$code" >"$input_dir/${group}__${target}.exit"
  # The merged report of a group does not repeat its declaration; the
  # matrix file of the same run does. The declaration is metadata only and
  # lets the verifier notice a drift between the group and the manifest.
  matrix="/work/.local/role-seo/${target}/${XNETVN_ROLE_SEO_RUN_ID}/matrix.json"
  if [[ -f "$matrix" ]]; then
    cp "$matrix" "$input_dir/${group}__${target}.matrix.json"
  fi
  return 0
}

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

run_network_matrix_group() {
  local group="$1" blog="$2" code=0 matrix=''
  # The B-E matrices run one blog at a time on the network: the two blogs
  # share the network tables, so the runner of the network matrix guards the
  # whole network with a lock. The run id stays inside the fixture guard
  # pattern and inside the 26 character limit.
  export XNETVN_ROLE_SEO_RUN_ID="nb-${stamp}-$( group_code "$group" )$( blog_code "$blog" )"
  XNETVN_RTL=1 bash /work/infra/remote/run-role-seo-network-matrix.sh "$group" "$blog" >"$input_dir/${group}__${blog}.json" 2>"$run_dir/${group}__${blog}.stderr" || code="$?"
  printf '%s\n' "$code" >"$input_dir/${group}__${blog}.exit"
  # The matrix file of the same run carries the declaration of the group; the
  # merged report of the group does not repeat it.
  matrix="/work/.local/role-seo/${blog}/${XNETVN_ROLE_SEO_RUN_ID}/matrix.json"
  if [[ -f "$matrix" ]]; then
    cp "$matrix" "$input_dir/${group}__${blog}.matrix.json"
  fi
  return 0
}

run_network_group() {
  local code=0 matrix='' part='' run_id=''
  # The network matrix runs in two parts: one part holds the per-blog
  # isolation groups, the other the cross-blog policy groups. Each part
  # seeds and cleans up on its own, owns its own run id and keeps the pause
  # inside the second part, so the shared lab auth-failure budget of the
  # plan stays intact.
  for part in a b; do
    run_id="${run_prefix}-nw${part}"
    export XNETVN_ROLE_SEO_RUN_ID="$run_id"
    XNETVN_ROLE_SEO_NET_PART="$part" bash /work/infra/remote/run-role-seo-network.sh "$part" >"$input_dir/network__network-$part.json" 2>"$run_dir/network__network-$part.stderr" || code="$?"
    printf '%s\n' "$code" >"$input_dir/network__network-$part.exit"
    matrix="/work/.local/role-seo-network/${run_id}/matrix.json"
    if [[ -f "$matrix" ]]; then
      cp "$matrix" "$input_dir/network__network-$part.matrix.json"
    fi
  done
  php -r '
$cases = array();
$declared = array();
$cleanup = true;
$counts = array();
foreach ( array( $argv[1], $argv[2] ) as $index => $file ) {
  if ( ! is_file( $file ) ) { $cleanup = false; continue; }
  $decoded = json_decode( file_get_contents( $file ), true );
  if ( ! is_array( $decoded ) || ! isset( $decoded["cases"] ) || ! is_array( $decoded["cases"] ) ) { $cleanup = false; continue; }
  foreach ( $decoded["cases"] as $id => $result ) { $cases[ $id ] = $result; }
  if ( array() === $declared && isset( $decoded["declared"] ) && is_array( $decoded["declared"] ) ) { $declared = $decoded["declared"]; }
  if ( true !== ( $decoded["cleanup"] ?? false ) ) { $cleanup = false; }
  // The measured counts of every part and every blog: the baseline and the
  // final state with the settings and cron digests. An empty counts block
  // would leave the owned restore of the network group unmeasured.
  if ( isset( $decoded["counts"] ) && is_array( $decoded["counts"] ) && array() !== $decoded["counts"] ) {
    $counts[ 0 === $index ? "part_a" : "part_b" ] = $decoded["counts"];
  }
}
$report = array(
  "suite"    => "role-seo-network",
  "target"   => "network",
  "run_id"   => getenv( "XNETVN_ROLE_SEO_RUN_ID" ),
  "cases"    => $cases,
  "declared" => $declared,
  "counts"   => $counts,
  "cleanup"  => $cleanup,
);
file_put_contents( $argv[3], json_encode( $report, JSON_UNESCAPED_SLASHES ) );
' "$input_dir/network__network-a.json" "$input_dir/network__network-b.json" "$input_dir/network__network.json" 2>>"$run_dir/network__network.stderr" || code=2
  if [[ -f "$input_dir/network__network-a.matrix.json" ]]; then
    cp "$input_dir/network__network-a.matrix.json" "$input_dir/network__network.matrix.json"
  fi
  return 0
}

if [[ "$phase" == 'single' ]]; then
  for target in wp69 wp71; do
    for group in fixture permissions content media lifecycle stability; do
      run_single_group "$target" "$group"
    done
  done
else
  if ! ensure_canonical_dns; then
    printf 'The canonical network domain does not resolve in the toolbox.\n' >&2
    exit 2
  fi
  run_network_group
  # The B-E matrices of the two network blogs follow the network group, one
  # blog at a time, so the blogs never run at the same time.
  for blog in network-main network-child; do
    for group in permissions content media lifecycle; do
      run_network_matrix_group "$group" "$blog"
    done
  done
fi

# The environment of the phase: real versions only, never a secret.
wp69_version=unavailable
wp71_version=unavailable
if [[ "$phase" == 'single' ]]; then
  wp69_version="$(bash /work/infra/remote/run-wp.sh wp69 core version 2>/dev/null || printf 'unavailable')"
  wp71_version="$(bash /work/infra/remote/run-wp.sh wp71 core version 2>/dev/null || printf 'unavailable')"
fi
cat >"$input_dir/environment.json" <<EOF
{"client":"harness-mcp-client","protocol":"2026-07-28","php":"$( php -r 'printf("%s", PHP_VERSION);' )","wordpress":{"wp69":"${wp69_version}","wp71":"${wp71_version}"},"adapter":"mcp-adapter"}
EOF

phase_report="$run_dir/phase.json"
php /work/tests/E2E/role-seo-acceptance.php phase --phase "$phase" --campaign "$campaign" --input "$input_dir" --root /work --output "$phase_report" >"$run_dir/phase.summary.json" 2>"$run_dir/phase.stderr" || {
  printf 'Acceptance phase %s could not build its report.\n' "$phase" >&2
  exit 2
}

report_built=1
printf '%s\n' 'ROLE_SEO_REPORT_BEGIN'
cat "$phase_report"
printf '%s\n' 'ROLE_SEO_REPORT_END'

fail_count="$( php -r '$d = json_decode( file_get_contents( $argv[1] ), true ); echo (int) ( $d["counts"]["fail"] ?? 0 );' "$phase_report" )"
skip_count="$( php -r '$d = json_decode( file_get_contents( $argv[1] ), true ); echo (int) ( $d["counts"]["skip"] ?? 0 );' "$phase_report" )"
if [[ "$skip_count" != '0' ]]; then
  exit 2
fi
if [[ "$fail_count" != '0' ]]; then
  exit 1
fi
exit 0
