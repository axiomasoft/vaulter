# Review round 2 (re-review of round-1 findings) — PLAN2.P6.7

- Reviewer: grok/grok-4.7, effort high, context window 500000 — proven from the session record
  `~/.grok/sessions/<cwd>/01a12794-9d6c-76c0-99d6-2d6290e55b69/` (updates.jsonl modelId, summary.json
  reasoning_effort, signals.json contextWindowTokens). Brief `review-brief-round2.md`, stream
  `review-grok-round2.jsonl`, 56 turns, 1208 s.
- Budget: this is the second and last completed round; one owning correction was made after round 1.

## Executor disposition

- Finding 1 (new, from the correction): not corrected — review budget exhausted. Not an information leak: the
  caller who gets `drive_not_found` for a missing child id can already see nodes of that drive, and an
  unknown id still answers 404. Residual inconsistency recorded with a recommendation (decide "drive visible"
  as root visible OR any visible node in the drive, then map misses accordingly) for a follow-up item.
- Finding 2: closed by the reviewer. Finding 3: rejection accepted by the reviewer.

## Verbatim result

VERDICT: FINDINGS

## 1. major — `packages/drive/src/Http/Resources/NodeResponses.php:76`

`withinDrive()` treats "cannot View the drive root" as "the drive does not exist." A caller granted only a folder inside the drive can list that folder, search the drive, and create a child there, and a missing `parent` or `parent_id` on every route from finding 1 still answers `404 drive_not_found`. The same missing id on a drive whose root the caller can see answers `404 node_not_found`. A drive in `deleting` status answers `drive_not_found` on those routes, matching an unknown drive. A stranger's child id, including a real folder id and resumable uploads turned off, also matches an unknown drive.

The root check is what rewrites the original `NodeNotFoundException` (`NodeResponses.php:76–78`). `ListChildrenController`, `CreateFolderController`, `UploadFileController`, and `CreateUploadController` all go through it. `UniformNotFoundDriveV1Test` only covers a stranger and the drive owner.

**Reproduction.** User 1 owns the drive. User 2 has `writer` on folder `deep` only (`GrantScope::Subtree`). SQLite in memory, `permission_driver=database`.

| Request | User 2 (deep folder) | User 1 (root) |
|---|---|---|
| `GET drives/{id}/nodes?parent={deep}` | 200 | |
| `GET drives/{id}/search?q=inside` | 200 | |
| `POST drives/{id}/folders` `{name, parent_id: deep}` | 201 | |
| `GET drives/{id}/nodes?parent=no-such` | 404 `drive_not_found` | 404 `node_not_found` |
| `POST drives/{id}/folders` `{name, parent_id: no-such}` | 404 `drive_not_found` | 404 `node_not_found` |
| `POST drives/{id}/files` with `parent_id=no-such` | 404 `drive_not_found` | 404 `node_not_found` |
| `POST drives/{id}/uploads` with `parent_id=no-such` | 404 `drive_not_found` | 404 `node_not_found` |
| same upload, `vaulter-drive.uploads.resumable.enabled=false` | 404 `drive_not_found` | 404 `node_not_found` |
| that upload with `parent_id={deep}` and resumable off | 403 `profile_restriction` | |

After user 2 trashed their own file with a matching `If-Match` (200), `POST drives/{id}/uploads` with that `node_id` answered `404 drive_not_found`. User 1's missing `node_id` answered `404 node_not_found`.

## Finding 2 — closed

`expectedSequence()` checks the route ability and then `Preconditions::matchNode()`. Rename, properties, move, trash, restore, purge, and both revision routes pass that sequence into the operation. The ability flags use the same `Ability` the operation authorizes (`Edit`, `Move`, `Trash`, `Restore`, `Delete`). Profile policy allows those abilities for the metadata flag and for the mutation.

A writer who only has the folder gets `428 precondition_required` with no `If-Match`, `412 node_sequence_mismatch` for a stale tag, and `200` for the current tag, on rename and on trash. `IfMatchRequiredTest` passed, including the reader dataset (`403` with no header and with a stale tag). `POST uploads` with `node_id` calls `expectedSequence(Edit)` before the session is created. The returned sequence is not stored on the session; `uploads.md` already says the commit does not compare it again.

## Finding 3 — rejection is sound

The tus 1.0.0 HEAD rule says the server should acknowledge a successful HEAD with `200 OK` or `204 No Content`. The design route table says `200`. `log.jsonl` at `2026-10-10T20:39:19Z` records keeping the existing `204` contract, and `docs/reference/rest-api.md` documents that `204`.

`tests/Http` with PHP 8.4: 136 passed (773 assertions), including `UniformNotFoundDriveV1Test` and `IfMatchRequiredTest`. `scripts/php84` could not relink `build/php84/bin/php` in this sandbox, and the vendor storage directory is not writable, so this run used `php8.4` and `TEST_TOKEN=1` (suite storage under `/tmp`).
