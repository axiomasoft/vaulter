# Review brief — PLAN3 P2.5 «p2-findings» (read-only)

You are a fresh, independent reviewer. You may read everything and run read-only commands; the repository is
read-only, do NOT edit or create files. Report findings; the executor repairs.

## What was built (uncommitted, repo root = cwd)
- `plans/2026.10.13-№1-VAULTER-KERNEL/knowledge/p2-findings.json` — 6 sections: code-facts-verification,
  type-map-summary, identities-summary, scan-baseline, open-questions, inputs-for-p3-p4.
- Open decisions (status open, NOT decided): `plans/…/decisions/D32.json`, `D33.json`, `D34.json`.
Item contract: `plans/…/phases/P2/P2.5.json` (rules, guidance) and `plans/…/knowledge/p2-design.json` section
`findings-contract`. Sources being verified: `plans/…/knowledge/source-review.json` sections `code-facts` and
`audit-verification` (line numbers there refer to old commit c64bba25 — compare by symbol).

## What to check (correctness only)
1. Every row of code-facts-verification: is the status (подтверждено|изменилось|опровергнуто) true for the
   current tree (product == commit 35718fd2), and does the cited file+symbol exist and say what the row claims?
   Is any code-facts statement or audit item A3/A5/A6/A7/A16/A17/A21/A23 missing?
2. Every number in type-map-summary, identities-summary, scan-baseline: recompute from
   `tests/Repo/Arch/Fixtures/{target-map.json,identities/*.json,allowlists/i1..i4.json,fk-map.json}`
   (e.g. with python3 -c). Report any mismatch with the exact recomputation.
3. open-questions: is anything closed "by evidence" actually a product/architecture fork that needed an open D#?
   Is any D32–D34 actually answered by code or an existing accepted decision (D21–D29, D31)? Are D32–D34 options
   real alternatives with a correct context?
4. Rule: fixture data not copied (summary = numbers, named exceptions, paths).

Allowed commands: reading files, grep, python3 one-liners over fixtures, and
`scripts/php84 php scripts/inventory/inventory.php --check` (fast). No test suites, no full matrix, no mutation,
no coverage, no load tests — the owner forbade long runs.

## Output (your final message, nothing else after it)
`VERDICT: PASS` or `VERDICT: FINDINGS`, then for each finding: location (section + row/line), severity
(high|medium|low), one-sentence defect, and a REPRODUCTION (command and its output). A finding without a
reproduction is at most `plausible` — say so. No style nits, no rewrites.
