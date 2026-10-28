#!/usr/bin/env bash
# Execute only from the approved complete tool closure T. Candidate paths are data.
set -euo pipefail
: "${BC_PYTHON:?externally approved absolute Python required}"
[[ "$BC_PYTHON" = /* ]] || exit 2
exec "$BC_PYTHON" -I "$(dirname "$0")/Contract/openapi-check.py" "$@"
