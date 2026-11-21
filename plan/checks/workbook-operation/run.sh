#!/usr/bin/env bash
# One command from the repository root: bash plan/checks/workbook-operation/run.sh
# Installs the reference from its shrinkwrap (Univer 1.5.2, TypeScript 5.9.3), type-checks it,
# lints the PHP owner and runs both vectors in separate Node/PHP processes.
set -euo pipefail
plan=$(cd "$(dirname "$0")/../.." && pwd)
ref="$plan/examples/reference/workbook-operation"
npm ci --prefix "$ref" --ignore-scripts --no-audit --no-fund --prefer-offline --loglevel=error
printf 'node %s, %s\n' "$(node -v)" "$(php -r 'echo "PHP ", PHP_VERSION;')"
(cd "$ref" && ./node_modules/.bin/tsc -p .)
for file in "$ref"/php/*.php; do php -l "$file" >/dev/null; done
node "$plan/checks/workbook-operation/check.mjs"
