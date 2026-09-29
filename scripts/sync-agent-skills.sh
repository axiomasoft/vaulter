#!/usr/bin/env bash
# Сжимает .agents/skills до CORE-набора (как CoreX): marketplace покрывает php/quality/task.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SKILLS="$ROOT/.agents/skills"
SKM="${SKM_ROOT:-$HOME/projects/packages/swissknifeman}"
SKILLER="$SKM/packages/skiller/bin/skiller"

KEEP=(
  compact-responses
  db-test-preflight
  gh-review
  mcp-advisor
  node-pnpm-preflight
  plan-protocol
  query-craft
  ecosystem-mcp
  ecosystem-setup
)

declare -A TARGET=(
  [compact-responses]="$SKM/packages/skiller/skills/general/compact-responses"
  [db-test-preflight]="$SKM/packages/skiller/skills/devops/db-test-preflight"
  [gh-review]="$SKM/packages/skiller/skills/oss-dev/gh-review"
  [mcp-advisor]="$SKM/packages/skiller/skills/general/mcp-advisor"
  [node-pnpm-preflight]="$SKM/packages/skiller/skills/devops/node-pnpm-preflight"
  [plan-protocol]="$SKM/packages/skiller/skills/general/plan-protocol"
  [query-craft]="$SKM/packages/skiller/skills/research/query-craft"
)

mkdir -p "$SKILLS"
shopt -s nullglob
for entry in "$SKILLS"/*; do
  base="$(basename "$entry")"
  keep=0
  for k in "${KEEP[@]}"; do
    if [[ "$base" == "$k" ]]; then keep=1; break; fi
  done
  if (( keep )); then continue; fi
  if [[ -L "$entry" ]]; then
    unlink "$entry"
  elif [[ -d "$entry" ]]; then
    rm -rf "$entry"
  else
    rm -f "$entry"
  fi
done

for name in compact-responses db-test-preflight gh-review mcp-advisor node-pnpm-preflight plan-protocol query-craft; do
  ln -sfn "${TARGET[$name]}" "$SKILLS/$name"
done

if [[ -x "$SKILLER" ]]; then
  (cd "$ROOT" && "$SKILLER" budget --first-turn --provider cursor 2>/dev/null | tail -8) || true
fi

echo "OK: $(ls -1 "$SKILLS" | wc -l) entries in .agents/skills (expected 9)."
