#!/usr/bin/env bash
# Build the admin asset minified siblings from their readable sources.
set -euo pipefail
cd "$(dirname "$0")/.."
npx --yes terser@5.51.2 assets/admin.js --compress --mangle --comments '/^!/' --output assets/admin.min.js
npx --yes clean-css-cli@5.6.3 -o assets/admin.min.css assets/admin.css
node --check assets/admin.min.js
printf 'Minified assets updated: assets/admin.min.js, assets/admin.min.css\n'
