#!/usr/bin/env bash
#
# Remove stale wp-env state left behind by an interrupted CI job.
#
# The shared self-hosted runner keeps the wp-env cache between jobs. A job that
# is killed while wp-env updates WordPress can leave a Git `index.lock` behind
# and leave its containers running, which makes the next `wp-env start` fail
# with "Unable to create ... index.lock" or a closed Docker socket.
#
# This script is deliberately narrow:
#   - it only looks at wp-env cache directories named
#     `wp-env-xnetvn-agent-hub-wp-*` in the wp-env home;
#   - it only deletes files named exactly `index.lock` below a `.git`
#     directory inside those cache directories;
#   - it only stops containers that belong to that wp-env project.
#   - with --reset, it resets the WordPress checkouts inside those cache
#     directories (`WordPress`, `tests-WordPress`, `WordPress-PHPUnit` and
#     `tests-WordPress-PHPUnit`) to the recorded revision and removes untracked
#     files, so a previous job's local changes cannot block the next
#     `wp-env start`.
#   - with --volumes, it removes the project's Docker containers, volumes and
#     networks but keeps images and local files, so a stale database volume
#     cannot block the next start and no image has to be pulled again.
#
# It never removes a cache directory, an image or a network outside the project,
# and it never touches the shared lab namespace.
#
# Usage:
#   bash bin/cleanup-wp-env.sh [--reset] [--volumes] [--dry-run] [--no-docker]
#
# Options:
#   --reset      Reset the managed WordPress checkouts in the project cache.
#   --volumes    Remove the project's Docker containers, volumes and networks.
#   --dry-run    Print what would be removed, stopped or reset; change nothing.
#   --no-docker  Skip the container and volume steps (service-free self-check).
#
# Exit codes:
#   0  Nothing to clean, or everything requested was cleaned.
#   1  A stale lock, a cache reset or a volume removal failed.
#   2  Invalid command-line usage.

set -uo pipefail

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
REPO_ROOT="$(cd -- "$SCRIPT_DIR/.." && pwd)"
readonly REPO_ROOT
readonly PROJECT_PREFIX='wp-env-xnetvn-agent-hub-wp-'

DRY_RUN=0
NO_DOCKER=0
RESET=0
VOLUMES=0

usage() {
  printf 'Usage: bash bin/cleanup-wp-env.sh [--reset] [--volumes] [--dry-run] [--no-docker]\n'
}

while [ "$#" -gt 0 ]; do
  case "$1" in
    --reset) RESET=1 ;;
    --volumes) VOLUMES=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --no-docker) NO_DOCKER=1 ;;
    -h|--help) usage; exit 0 ;;
    *) printf 'Unknown argument: %s\n' "$1" >&2; usage >&2; exit 2 ;;
  esac
  shift
done

# Resolve the wp-env home the same way @wordpress/env does: an explicit
# WP_ENV_HOME wins; otherwise probe both candidate homes so the script does not
# silently do nothing on a machine without /snap.
homes=()
if [ -n "${WP_ENV_HOME:-}" ]; then
  homes+=("$WP_ENV_HOME")
else
  for candidate in "$HOME/wp-env" "$HOME/.wp-env"; do
    if [ -d "$candidate" ]; then
      homes+=("$candidate")
    fi
  done
fi

exit_code=0
project_dirs=()
for home in "${homes[@]}"; do
  [ -d "$home" ] || continue
  if ! candidates="$(find -P "$home" -mindepth 1 -maxdepth 1 -type d \
      -name "${PROJECT_PREFIX}*" -print 2>/dev/null | sort)"; then
    printf 'ERROR: could not scan wp-env home: %s\n' "$home" >&2
    exit_code=1
    continue
  fi
  while IFS= read -r dir; do
    [ -n "$dir" ] || continue
    case "$(basename -- "$dir")" in
      "${PROJECT_PREFIX}"*) project_dirs+=("$dir") ;;
    esac
  done <<< "$candidates"
done

if [ "${#project_dirs[@]}" -eq 0 ]; then
  printf 'No wp-env state found for %s; nothing to clean.\n' "${PROJECT_PREFIX}\*"
  exit "$exit_code"
fi

printf 'Found %d wp-env cache director%s for %s.\n' \
  "${#project_dirs[@]}" \
  "$([ "${#project_dirs[@]}" -eq 1 ] && printf 'y' || printf 'ies')" \
  "${PROJECT_PREFIX}\*"

# Remove only `index.lock` files below `.git` inside the cache directory. The
# four known layouts (WordPress, tests-WordPress, WordPress-PHPUnit and
# tests-WordPress-PHPUnit) are all within three levels.
for dir in "${project_dirs[@]}"; do
  if ! lock_list="$(find -P "$dir" -maxdepth 3 -type f -path '*/.git/index.lock' -print 2>/dev/null)"; then
    printf 'ERROR: could not scan %s for stale locks.\n' "$dir" >&2
    exit_code=1
    continue
  fi
  while IFS= read -r lock; do
    [ -n "$lock" ] || continue
    mtime="$(date -u -r "$lock" '+%Y-%m-%dT%H:%M:%SZ' 2>/dev/null || printf 'unknown')"
    if [ "$DRY_RUN" -eq 1 ]; then
      printf 'DRY-RUN: would remove stale lock %s (mtime %s)\n' "$lock" "$mtime"
      continue
    fi
    if rm -- "$lock"; then
      printf 'Removed stale lock: %s (mtime %s)\n' "$lock" "$mtime"
    else
      printf 'ERROR: could not remove stale lock: %s\n' "$lock" >&2
      exit_code=1
    fi
  done <<< "$lock_list"
done

# Stop the containers of this wp-env project only, and only when a cache
# directory actually holds a docker-compose.yml. Stopping unconditionally makes
# `wp-env stop` exit 1 on a runner without that cache directory.
if [ "$NO_DOCKER" -eq 1 ]; then
  printf 'Docker step skipped (--no-docker).\n'
else
  compose_dirs=()
  for dir in "${project_dirs[@]}"; do
    if [ -f "$dir/docker-compose.yml" ]; then
      compose_dirs+=("$dir")
    fi
  done

  if [ "${#compose_dirs[@]}" -eq 0 ]; then
    printf 'No docker-compose.yml in the wp-env cache; no containers to stop.\n'
  elif [ -x "$REPO_ROOT/node_modules/.bin/wp-env" ]; then
    if [ "$DRY_RUN" -eq 1 ]; then
      printf 'DRY-RUN: would run "npx --no-install wp-env stop" from %s\n' "$REPO_ROOT"
    else
      printf 'Stopping wp-env containers with the repository CLI.\n'
      if ! ( cd "$REPO_ROOT" && npx --no-install wp-env stop ); then
        printf 'WARNING: "wp-env stop" failed; continuing so the start step can report the real state.\n' >&2
      fi
    fi
  else
    for dir in "${compose_dirs[@]}"; do
      project="$(basename -- "$dir")"
      if [ "$DRY_RUN" -eq 1 ]; then
        printf 'DRY-RUN: would stop containers labelled com.docker.compose.project=%s\n' "$project"
        continue
      fi
      mapfile -t container_ids < <(docker ps -aq \
        --filter "label=com.docker.compose.project=${project}" 2>/dev/null)
      if [ "${#container_ids[@]}" -eq 0 ]; then
        printf 'No running containers for compose project %s.\n' "$project"
        continue
      fi
      printf 'Stopping %d container(s) for compose project %s.\n' \
        "${#container_ids[@]}" "$project"
      if ! docker stop "${container_ids[@]}" >/dev/null; then
        printf 'WARNING: could not stop containers for compose project %s; continuing.\n' "$project" >&2
      fi
    done
  fi
fi

if [ "$VOLUMES" -eq 1 ]; then
  if [ "$NO_DOCKER" -eq 1 ]; then
    printf 'Volume step skipped (--no-docker).\n'
  else
    for dir in "${project_dirs[@]}"; do
      if [ ! -f "$dir/docker-compose.yml" ]; then
        continue
      fi
      project="$(basename -- "$dir")"
      if [ "$DRY_RUN" -eq 1 ]; then
        printf 'DRY-RUN: would remove the Docker volumes for %s\n' "$project"
        continue
      fi
      if volume_output="$(docker compose -f "$dir/docker-compose.yml" -p "$project" down --volumes --remove-orphans 2>&1)"; then
        printf 'Removed the Docker containers and volumes for %s.\n' "$project"
      else
        printf 'ERROR: could not remove the Docker volumes for %s: %s\n' "$project" "$volume_output" >&2
        exit_code=1
      fi
    done
  fi
fi

if [ "$RESET" -eq 1 ]; then
  # @wordpress/env keeps the core and PHPUnit sources in these four managed
  # checkouts below the project cache. Reset only a real worktree whose
  # top-level is exactly that checkout; never discover arbitrary repositories.
  for dir in "${project_dirs[@]}"; do
    for checkout in \
      "$dir/WordPress" \
      "$dir/tests-WordPress" \
      "$dir/WordPress-PHPUnit" \
      "$dir/tests-WordPress-PHPUnit"; do
      if [ -L "$checkout" ] || [ ! -e "$checkout/.git" ] || [ -L "$checkout/.git" ]; then
        continue
      fi
      toplevel="$(git -C "$checkout" rev-parse --show-toplevel 2>/dev/null || true)"
      if [ -z "$toplevel" ]; then
        printf 'WARNING: could not inspect the WordPress checkout in %s; skipping.\n' "$checkout" >&2
        continue
      fi
      if [ "$(cd "$toplevel" && pwd -P)" != "$(cd "$checkout" && pwd -P)" ]; then
        continue
      fi
      if [ "$DRY_RUN" -eq 1 ]; then
        printf 'DRY-RUN: would reset the WordPress checkout in %s\n' "$checkout"
        continue
      fi
      if ! reset_output="$(git -C "$checkout" reset --hard 2>&1)"; then
        printf 'ERROR: could not reset the WordPress checkout in %s: %s\n' "$checkout" "$reset_output" >&2
        exit_code=1
        continue
      fi
      if ! clean_output="$(git -C "$checkout" clean -ffd 2>&1)"; then
        printf 'ERROR: could not clean untracked files in %s: %s\n' "$checkout" "$clean_output" >&2
        exit_code=1
        continue
      fi
      printf 'Reset the WordPress checkout in %s.\n' "$checkout"
    done
  done
fi

if [ "$DRY_RUN" -eq 1 ]; then
  printf 'Dry run complete; nothing was changed.\n'
elif [ "$exit_code" -eq 0 ]; then
  printf 'wp-env cleanup complete.\n'
fi

exit "$exit_code"
