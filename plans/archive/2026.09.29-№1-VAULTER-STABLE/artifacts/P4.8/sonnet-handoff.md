# P4.8 — check-and-close prompt for Sonnet 5.5 (medium)

Paste everything below the line into a fresh Claude Code session started in
`/home/vostrikov/projects/packages/vaulter` on **Sonnet 5.5, effort medium**.

---

You finish plan item **P4.8** of plan `2026.09.29-№1-VAULTER-STABLE` ("Documents Operations: authorized
public API of documents and comments"). The code, tests and docs are already written (uncommitted) by an
Opus 5.5/high session, which ran only `php -l` and Pint (owner instruction: no long runs there). Your job:
run the checks, fix **small** defects, run a short grok review, finish and commit. Do not redesign.

`plan.py` = `python3 /home/vostrikov/.claude/plugins/cache/swissknifeman/task/0.28.3/scripts/plan.py`;
`PL=2026.09.29-№1-VAULTER-STABLE`; artifacts dir `A=plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P4.8`.
Your actor for every `plan.py log|finish|commit`: `--actor anthropic/claude-sonnet-5-5/medium`.
Do **not** run `plan.py start` (the item is already started; your route is below the item's and would be
refused). Never `git add -A`, `--no-verify`, amend or push. Leave the owner's dirty files alone:
`.swissknife/analyst/**` and `plans/…/views/**`.

## 0. Read first (short)

- `$A/completion-notes.md`, `$A/check-profile.json`, `$A/negative-controls.md`, `$A/review-brief.md`
- `grep '"P4.8"' plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl` (recorded deviations/assumptions)
- `git status --short` and, when a test fails, the related part of `git diff`.

Key intended behaviour (do not "fix" these back): Operations return snapshots; HTTP keeps URLs/JSON keys
but ids are strings (D38); a node the user cannot see / trashed / other tenant → `404 node_not_found`
(was 403); export needs `Download`; GET content needs `View`; comment resolve = `Comment` AND (author OR
`Edit`), delete = author OR `Manage`; comment of another node → `404 comment_not_found`; Actions are
`execute(OperationContext, Input)` with the actor from the context.

## 1. Golden (once, after reading)

```bash
cd packages/documents
UPDATE_SNAPSHOT=1 env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Arch/PublicApiSnapshotTest.php
git diff tests/Arch/__snapshots__/public-api.txt
```
Expect only ADDED blocks (FACADE Documents with `in`/`comments`, DocumentsManager, DocumentOperations,
CommentOperations, DocumentSnapshot, DocumentRevisionSnapshot, CommentSnapshot, AddCommentInput,
CollaborationSession, DocumentExport). Existing blocks must be unchanged. If an existing block changed,
stop and report.

## 2. Acceptance checks (keep full output in `$A/receipts/<id>.txt`, report counts only)

| id | cwd | command |
|---|---|---|
| V1 | packages/documents | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Operations tests/Arch tests/Document tests/Comment tests/Collaboration` |
| V1x | packages/documents | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Feature tests/Profiles tests/Providers tests/Unit tests/Configuration tests/Schema` |
| V2 | packages/drive | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: ../../scripts/php84 vendor/bin/pest --ci tests/Arch/OperationsBoundaryTest.php` |
| V3 | . | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/Arch packages/core/tests/Feature` |
| V3x | . | `env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: scripts/php84 vendor/bin/pest --ci packages/core/tests/ObservabilityDocuments packages/core/tests/E2E` |
| V80 | . / packages/documents / packages/drive | `scripts/php84 vendor/bin/pint --test packages/core && scripts/php84 vendor/bin/phpstan analyse -c phpstan.neon --memory-limit=1G --no-progress`; in packages/documents and packages/drive: `../../scripts/php84 vendor/bin/pint --test && ../../scripts/php84 vendor/bin/phpstan analyse --memory-limit=512M --no-progress` |
| V81 | . | `python3 plans/2026.09.29-№1-VAULTER-STABLE/artifacts/coverage/opus-coverage.py check` |

V1x/V3x are impact checks of edited callers (not plan acceptance ids) — report them in the notes.
V4 (PG tenant) is NOT run here: it is deferred to the P4.7 barrier (D51).

Likely small failures and how to treat them:
- PHPStan generics/paginator types (`cursorPaginate()` contract vs `Illuminate\Pagination\CursorPaginator`,
  `through()` return, `$model::query()` on a config class-string) → add precise `@var`/`@return` or adjust the
  type; no `@phpstan-ignore` unless the same pattern is already ignored in that package.
- A test still expecting `403` where the node is not visible, an int id in JSON, or `abort(404)` without code →
  update the expectation to the intended behaviour above.
- A test helper or call site missed by the mechanical rewrite (`execute(` without a context) → pass
  `DocumentTestHelpers::context($driveOrNode[, $userId])`.
- Pint on a file you edited → `pint <file>`.
Anything that needs a change in authorization semantics, a public signature, or the design → do NOT improvise:
`plan.py log $PL P4.8 --type blocker --summary "…" --actor …` and stop with a report.
Every fix you make: one line in `plan.py log $PL P4.8 --type difficulty --summary "…" --actor …`.
Re-run only the checks whose inputs your fix touched.

## 3. Negative controls (V80 part)

Execute NC1–NC5 from `$A/negative-controls.md` exactly: break → run only the named test file → record the red
output line → restore the exact bytes (`git diff` of that file back to the pre-control state) → rerun it green.
Fill the table in that file.

## 4. Short grok review (owner: grok 4.7, no long passes)

```bash
plan.py bridge orch launch reviewer grok --model grok-4.7 --effort high --cwd "$PWD"
```
It prints the argv (JSON). If it fails with `BRIDGE_PARTNER_UNAVAILABLE`/`SKM_ORCH_ROOT`, retry once with
`SKM_ORCH_ROOT=/home/vostrikov/projects/packages/swissknifeman/packages/orch`. Run the printed argv unchanged with
the brief on stdin and a hard cap:
```bash
timeout 900 <argv…> < "$A/review-brief.md" > "$A/review-grok-stream.jsonl"
```
Save the argv as `$A/review-launch.json`. Prove the session from grok's own record
(`~/.grok/sessions/<encoded cwd>/<session>/updates.jsonl`: model, effort; note `contextWindowTokens` if present).
If the launch is impossible or times out without an answer: write `$A/review-verdict.md` saying so, log
`--type blocker` "review pending", and still finish only if the owner allows; otherwise stop and report. Never
invent a verdict.

For each finding: confirm or reject it by reading and, where possible, a focused test. At most ONE owning
correction for confirmed findings (rerun the affected checks), no second review unless a correction changed
authorization code (then one short re-review with the same brief limited to the delta). Write
`$A/review-verdict.md` (reviewer, session id, findings, your confirm/reject with evidence, what was fixed) and
`plan.py log $PL P4.8 --type note --summary "grok review: <verdict>, session <id>, artifact review-verdict.md" --actor …`.

## 5. Finish and commit

Append the results (commands, exit codes, test counts, fixes) to `$A/completion-notes.md` under
"Check session results". Then:

```bash
plan.py finish $PL P4.8 --status done_with_deviations \
  --check V1=0 --check V2=0 --check V3=0 --check V80=0 --check V81=0 \
  --deviation "See P4.8 log: CollaborationHub seam, DocumentOperations collaborators, AddCommentAction node arg, snapshot fields, CommentNotFoundException, files beyond task_files" \
  --pending "V4 (PG tenant rows of DocumentAbilityMatrixTest) → P4.7 barrier V90 (D51)" \
  --notes "Documents::in/comments operations on snapshots; controllers via Operations; TrashGuard removed; matrix + HTTP parity; review: <verdict>" \
  --actor anthropic/claude-sonnet-5-5/medium
plan.py commit $PL P4.8 --role executor --actor anthropic/claude-sonnet-5-5/medium --leave-outside --dry-run
```
In the dry run, every path of `git status` below must be selected; add each missing one with `--path`
(expected extras: `docs/guide/{collaboration,comments,errors,export}.md`, `docs/ru/guide/{documents,comments,errors,export}.md`,
`packages/core/tests/E2E/E2eServiceProvider.php`, `packages/core/tests/ObservabilityDocuments/P83DocumentsMetricsTest.php`,
`packages/core/src/Http/Support/TrashGuard.php` (deleted, staged), `packages/documents/src/Collaboration/CollaborationHub.php`,
`packages/documents/src/Data/Snapshots/Mapping/DocumentSnapshotMapper.php`, `packages/documents/src/Exceptions/CommentNotFoundException.php`,
`packages/documents/src/Export/NodeExportService.php`, `packages/documents/tests/Pest.php`,
`packages/documents/tests/Operations/OperationsTestCase.php`, `packages/documents/tests/Feature/MiddlewareStacksTest.php`,
`packages/documents/tests/Document/{DocumentTestHelpers,DomainExceptionStatusTest,…}.php`, the golden snapshot,
`$A/**`). Leave `.swissknife/analyst/**` and `plans/…/views/**` out. Then run the same command without
`--dry-run` and report the printed sha, then `plan.py next $PL`.
