#!/usr/bin/env bash
# Build isolated CLI runtimes inside the existing budgeted remote toolbox only.
set -euo pipefail
[[ "${XNETVN_RTL:-}" == '1' && -d /work/.local ]] || exit 2
matrix_dir=/work/.local/php-matrix
mkdir -p "$matrix_dir"
[[ ! -L "$matrix_dir" ]] || exit 2
# Dependencies are installed inside this disposable project container, never the host.
apt-get update > "$matrix_dir/dependencies.log" 2>&1
apt-get install --yes --no-install-recommends libcurl4-openssl-dev libssl-dev pkg-config >> "$matrix_dir/dependencies.log" 2>&1
for version in 8.3.35 8.4.26; do
  case "$version" in
    8.3.35) checksum=ff4630fbbbd94359134b7d3c223db59329905bdc4f5a9ef93d257b48e358619a;;
    8.4.26) checksum=32a2de53862ad44ed4a5005244ce4f1b50c271e74dced215449a4443b40569f1;;
  esac
  if [[ ! -x "$matrix_dir/php-$version/sapi/cli/php" ]]; then
    curl --fail --location --proto '=https' --tlsv1.2 --max-time 120 \
      "https://www.php.net/distributions/php-$version.tar.xz" -o "$matrix_dir/php-$version.tar.xz"
    printf '%s  %s\n' "$checksum" "$matrix_dir/php-$version.tar.xz" | sha256sum --check
    tar --extract --xz --file "$matrix_dir/php-$version.tar.xz" --directory "$matrix_dir"
    (
      cd "$matrix_dir/php-$version"
      ./configure --disable-all --enable-cli --disable-cgi --disable-phpdbg \
        --with-curl --with-openssl --enable-filter > configure.log 2>&1
      make -j1 > build.log 2>&1
    )
  fi
  "$matrix_dir/php-$version/sapi/cli/php" -n /work/tests/Network/runtime-smoke.php
done
