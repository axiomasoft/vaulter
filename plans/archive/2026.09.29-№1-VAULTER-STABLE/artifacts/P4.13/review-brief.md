You review the UNCOMMITTED working tree of /home/vostrikov/projects/packages/vaulter against HEAD (`git diff HEAD`, plus untracked files from `git status --short`) for plan item PLAN2.P4.13 "Public API on snapshot-DTO and Page<T>; Eloquent internal; WriteScope". You are read-only: report findings, change nothing.

Normative spec: plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P4-design.json section "Snapshots" (read it with python3 json), D44/D38; recorded deviations: `plan.py` log of the item (plans/.../log.jsonl, grep P4.13) and artifacts/P4.13/completion-notes.md.

Review axes (find real defects, not style):
1. Authorization: no check weakened by the signature change (OperationGuard/locator use only the snapshot id; a snapshot's other fields are never trusted).
2. WriteScope: any path that writes a Vaulter model outside VaulterDatabase::transaction()/write() in production code (commands, jobs, listeners, repositories), and any path where the guard can be bypassed by accident or breaks production (e.g. scoped service vs singleton, Octane, queue workers).
3. Snapshot mapping: wrong/missing data (viewer star for system actor, file head, document head seam, trashRoot, UTC dates, ids as strings), N+1 queries on listings.
4. REST resources/controllers: JSON keys unchanged; ids now strings; abilities/handler/breadcrumb/children behaviour.
5. Public contract leaks: Eloquent models, builders, collections or paginators still reachable from public (non-@internal) Operations/handle signatures.
DO NOT RUN ANYTHING that executes the test suites, PHP, Pest, PHPStan, Pint, artisan or composer: the owner forbids further runs. Review by reading code and `git diff HEAD` only (read-only file and grep tools, git read commands). Because you cannot run anything, give each finding the exact code path (file:line chain) that demonstrates it instead of a reproduction; the executor will confirm or reject it by reading.
Return: a JSON list of findings {file, line, severity(high|medium|low), summary, code_path, verdict(PLAUSIBLE|CONFIRMED_BY_READING)} and a one-line overall verdict.
