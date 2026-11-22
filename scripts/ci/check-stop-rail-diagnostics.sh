#!/usr/bin/env bash
set -euo pipefail

report=$(mktemp)
trap 'rm -f "$report"' EXIT
if vendor/bin/phpstan analyse --error-format=json --no-progress -v tests/Repo/Fixtures/engineering/ForbiddenCalls.php > "$report"; then
    echo 'PHPStan accepted the forbidden engineering calls fixture.' >&2
    exit 1
fi
python3 - "$report" <<'PY'
import json
import sys

with open(sys.argv[1]) as report:
    data = next((json.loads(line) for line in report if line.startswith('{')), {})
findings = [message for messages in data.get('error_details', {}).values() for message in messages]
findings.extend(message for file in data.get('files', {}).values() for message in file['messages'])
for expected in ('DB::transaction()', 'ZipArchive::extractTo()', 'parse_url()', 'env()', 'VerifyCsrfToken',
                 'vaulter.advisoryLock', 'vaulter.tenantContext', 'vaulter.unsafeDdl'):
    if not any(expected in message['message'] or expected == message.get('identifier') for message in findings):
        sys.exit(f'PHPStan did not diagnose {expected}')
PY
