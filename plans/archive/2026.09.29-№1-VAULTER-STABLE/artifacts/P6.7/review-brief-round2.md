# Re-review brief (round 2) — PLAN2.P6.7

You are an independent, read-only reviewer. Do not modify files. This round checks only the findings of
round 1 (`plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P6.7/review-round1.md`) and the correction made for
them; it is not a new audit of the whole item.

## Round-1 findings and the executor's disposition

1. (major) A child id under an invisible drive answered `node_not_found` while an unknown drive answered
   `drive_not_found`. **Corrected:** `packages/drive/src/Http/Resources/NodeResponses.php::withinDrive()` — on a
   `NodeNotFoundException` inside the call it answers `drive_not_found` when the caller cannot see the drive
   root, otherwise rethrows; used by `Nodes/ListChildrenController`, `Folders/CreateFolderController`,
   `Files/UploadFileController`, `Uploads/CreateUploadController`.
2. (minor) IM routes answered 428/412 before 403. **Corrected:** `NodeResponses::expectedSequence()` now takes
   the route's `Ability` and throws `403 permission_denied` when the caller's abilities lack it, before
   `Preconditions::matchNode()`; callers pass Edit/Move/Trash/Restore/Delete (uploads with `node_id`: Edit).
3. (minor) tus `HEAD` 204 vs design 200. **Rejected** (recorded deviation in `log.jsonl`): tus 1.0.0 allows
   200 or 204; the existing 204 contract is kept.

New tests: `packages/drive/tests/Http/UniformNotFoundDriveV1Test.php` (child-id dataset + visible-drive
node_not_found), `packages/drive/tests/Http/IfMatchRequiredTest.php` (403-before-precondition dataset).

## What to check

- Do the corrections close findings 1 and 2 on every route the finding named, without opening a new
  difference (e.g. a caller granted only a deep folder, a visible drive with a missing child, a deleted drive)?
- Does the ability pre-check ever let a mutation run without the expected sequence, or refuse a caller the
  operation itself would allow?
- Is the rejection of finding 3 sound?

    git diff HEAD -- packages/drive/src/Http packages/drive/tests/Http

    cd packages/drive && env APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: LOG_CHANNEL=stderr ../../scripts/php84 vendor/bin/pest --ci tests/Http

(`LOG_CHANNEL=stderr` avoids the unwritable log file you hit in round 1.)

## Report

First line `VERDICT: PASS` or `VERDICT: FINDINGS`; per finding severity, file:line, defect and a reproduction
(test or command output). Without a reproduction a finding is at most "plausible".
