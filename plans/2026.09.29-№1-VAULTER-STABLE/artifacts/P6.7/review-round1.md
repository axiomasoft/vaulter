# Review round 1 — PLAN2.P6.7

- Reviewer: grok/grok-4.7, effort high, context window 500000 (proven from the session record:
  `~/.grok/sessions/<cwd>/01a12772-958f-7113-b5d7-c5aa1f26b931/` — `updates.jsonl` modelId grok-4.7,
  `chat_history.jsonl`/`summary.json` reasoning_effort high, `signals.json` contextWindowTokens 500000).
- Launch: orch bridge `launch reviewer grok --model grok-4.7 --effort high` (ro-run read-only), argv in
  `review-launch.json`, stream `review-grok.jsonl`, brief `review-brief.md`.
- Session: 01a12772-958f-7113-b5d7-c5aa1f26b931, 66 turns, 1319 s, result success.

## Verbatim result

VERDICT: FINDINGS

## 1. major — `packages/drive/src/Http/Controllers/Nodes/ListChildrenController.php:31`

A drive the caller cannot see is distinguishable from a drive that does not exist, as soon as the route carries a child id (`parent`, `parent_id`, or `node_id`). `HttpResourceResolver::drive()` returns the row before any visibility check. The child lookup then fails as `node_not_found`. An unknown drive id fails earlier as `drive_not_found`. P6-I2 requires those two answers to match except for `request_id`. `UniformNotFoundDriveV1Test` only covers the no-parent routes, which do match.

The same split is in `CreateFolderController.php:26`, `UploadFileController.php:31`, and `CreateUploadController.php:49`. Root-relative calls (no child id) stay uniform. Turning resumable uploads off does not close it: the drive is resolved before the feature gate. Search and `DELETE …/trash` returning 200 to a stranger are the recorded P6.7 deviation and are separate from this.

**Reproduction.** User 1 owns the drive; user 2 has no grant. SQLite in memory, `permission_driver=database`.

| Request | Existing drive | Unknown drive |
|---|---|---|
| `GET drives/{id}/nodes?parent=no-such` | 404 `node_not_found` | 404 `drive_not_found` |
| `POST drives/{id}/folders` `{name, parent_id: no-such}` | 404 `node_not_found` | 404 `drive_not_found` |
| `POST drives/{id}/files` with `parent_id` | 404 `node_not_found` | 404 `drive_not_found` |
| `POST drives/{id}/uploads` with `parent_id` or `node_id` | 404 `node_not_found` | 404 `drive_not_found` |
| same upload with `vaulter-drive.uploads.resumable.enabled=false` | 404 `node_not_found` | 404 `drive_not_found` |
| `GET drives/{id}/nodes` (no parent) | 404 `drive_not_found` | 404 `drive_not_found` |
| `POST` folder / file / upload with no child id | 404 `drive_not_found` | 404 `drive_not_found` |

A caller who can see the named parent still has to get that page. Only the miss (unknown or invisible child) must not use a different code from an unknown drive.

## 2. minor — `packages/drive/src/Http/Controllers/Nodes/UpdateNodeController.php:21`

On every IM route, `expectedSequence()` runs after View and before the mutation ability. P6-design#Validators requires the missing ability to be 403 before If-Match. A reader (View, no Edit) is told about the precondition first:

- no `If-Match` → 428 `precondition_required`
- `If-Match: *` → 428 `precondition_required`
- `If-Match: "not-the-tag"` → 412 `node_sequence_mismatch` (`meta` is `node_id` only; the current tag is not in the body)
- `If-Match` equal to the GET tag → 403 `permission_denied`

The name stayed `r.txt`. The same 428 happens for `POST nodes/{id}/trash`. View still precedes 428, so an invisible node stays 404.

## 3. minor — `packages/drive/src/Http/Controllers/Uploads/TusProtocolController.php:96`

`HEAD uploads/{upload}` returns 204. The P6.7 route table and the tus 1.0.0 HEAD rule both say 200. No deviation is recorded. `tests/Feature/TusResponseContractsTest.php` passed here, including `assertNoContent()` on that HEAD (204), with `Tus-Resumable`, `Upload-Length`, and `Cache-Control: no-store, private`.

---

Checked and holding: IM routes call `expectedSequence()`; purge compares the sequence on the locked row before `preparePurge` and quota; trash-list tags purge with 204 (`TrashV1Test` success path); `NodeEtagRepresentationTest` passed; downloads go only through `ContentResponder`. Exception-path tests in the suite aborted in this sandbox because `vendor/.../storage/logs/laravel.log` is not writable. The probe above redirected logging and is the reproduction for findings 1 and 2.
