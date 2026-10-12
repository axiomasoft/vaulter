#!/usr/bin/env bash
set -euo pipefail
cd /home/vostrikov/projects/packages/vaulter
A="plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P6.8"
python3 -c "
import json,subprocess,sys
a=json.load(open('$A/review-launch.json'))['argv']
sys.exit(subprocess.run(a, stdin=open('$A/review-brief.md'), stdout=open('$A/review-grok.jsonl','w'), stderr=open('$A/review-grok.stderr','w')).returncode)
"
