VERDICT: FINDINGS

## 1. Major — overlapping saves drop the newer edit and clear `conflict`

`packages/vue/src/composables/useDocument.ts:99` and `:110`

`save()` reads `head.etag` and sends it with no in-flight lock. A second save started before the first returns repeats that same tag. On success the first response does `head.value = saved` and `conflict.value = null`. If that success is applied after the newer save has already handled a 412, the conflict banner is cleared while `content` is still the edit the server rejected.

`setContent` (`useDocument.ts:78`) and `VaulterSpreadsheet` (`VaulterSpreadsheet.vue:50`, which calls `save()` and leaves the debounce timer armed) both start that second save. The autosave timer swallows the rejection (`useDocument.ts:81`), so nothing is left waiting to write the newer body.

Reproduction (composable mounted with a deferred `documents.update`): both calls sent `etag` `"n.0.1"`, bodies `{v:C2}` then `{v:C3}`. The C3 call rejected with 412 and set `conflict`. Resolving the C2 call afterwards printed:

```text
DOC race after older success: {"content":{"v":"C3"},"head":{"v":"C2"},"conflict":null,"error":"document_state_mismatch"}
```

`VaulterDocumentNode.vue:206` shows the conflict copy only while `conflict` is set, and the generic “Could not load this document.” region when `error` is set and `conflict` is not (`:212`). After this race the editor still shows C3, the server has C2, and the conflict instruction to save again is gone.

## 2. Major — a late list response rolls the row back, including its ETag

`packages/vue/src/composables/useNodes.ts:73` and `:142`

`refresh()` assigns `nodes.value = page.data` with no generation token. `rename()` then `replace()`s by id. A children response the server built before the rename, arriving after the rename response, restores the previous name and the previous `etag`. The next `If-Match` is taken from that row (`useNodes.ts:141`). `VaulterDrive.vue:254` already drops a stale search; the folder list does not.

Reproduction: first `children` returned `{name:'old', etag:'"E1"'}`; a second `children` was held, `rename` completed, then the held page (still the pre-rename body) was applied.

```text
after rename new "E2"
after late list old "E1"
```

`star()` / `unstar()` (`useNodes.ts:184`) write through the same `replace()`, so a delayed star snapshot does the same thing.

## 3. Major — `connect()` during an epoch reopen leaves an extra provider

`packages/vue/src/composables/useCollaboration.ts:125` and `:149`

`reopen()` and `connect()` each call `resolveSession()` and then `open()` with no shared generation. `connect()`’s `teardown()` only destroys the provider that exists at that moment. If it runs while `reopen()` is awaiting the new session, `reopen()` later calls `open()` again and does not destroy the provider `connect()` just created. `disconnect()` only destroys `handle.value`, so the extra Hocuspocus connection stays up. `VaulterDocumentNode.vue:124` reaches this by calling `collab.connect()` from `load()`.

Reproduction: epoch close started a reopen, `connect()` finished first (`room-2`, provider `p2`), then the reopen’s session resolved (`room-1`) and opened `p3`.

```text
COLLAB alive providers: ["p2","p3"] calls 3 room room-1
```

## 4. Major — `portableNameReason` disagrees with `PortableNamePolicy` on two code points

`packages/vue/src/naming.ts:69` and `packages/core/src/Naming/PortableNamePolicy.php:38`

Both use `/^[\s\p{Z}]|[\s\p{Z}]$/u`, but the engines’ `\s` sets differ. A scan of every non-surrogate BMP character alone, as a prefix of `a`, and as a suffix of `a` (190,470 rows) produced 6 mismatches, all of these two characters:

| Name | JS `portableNameReason` | PHP `PortableNamePolicy::apply` |
|---|---|---|
| U+180E + `file`, and `file` + U+180E | `null` (accepted) | `leading_or_trailing_space` |
| U+FEFF + `file`, and `file` + U+FEFF | `leading_or_trailing_space` | accepted |

U+180E is the one that gets submitted. `String.prototype.trim` does not remove it (`"᠎file"` stays), and `VaulterNameDialog.vue:68` validates and emits the trimmed string. `portableNameReason` returns null, so the dialog submits a name the server answers `422 invalid_node_name` / `leading_or_trailing_space` for. The dialog comment says that is the case it exists to catch before the caller closes it.

U+FEFF is the other way: the exported function rejects a name PHP accepts. The dialog’s `trim` strips U+FEFF before the check, so that path submits the remainder.

PHP 8.4, same strings:

```text
"\u180efile" => leading_or_trailing_space
"file\u180e" => leading_or_trailing_space
"\ufefffile" => ACCEPT
"file\ufeff" => ACCEPT
180E regex match
FEFF regex no
```

## 5. Minor — an empty S3 upload never sends a part

`packages/vue/src/api/uploads/s3.ts:14`

`runS3` loops while `sent < run.total`. A zero-byte blob skips the loop and `POST`s `complete`. The server rejects that: `S3MultipartHandler::complete` throws when no parts were recorded (`packages/drive/src/Uploads/S3MultipartHandler.php:199`). The tus runner sends one empty PATCH for this case (`tus.ts:16`).

Reproduction with `protocol: 's3'` and `new Blob([])`:

```text
S3 zero-byte requests: ["POST /api/vaulter/v1/drives/7/uploads","POST /api/vaulter/v1/uploads/u1/complete"]
```

No `/parts` request. Part numbers for a non-empty file start at 1, which matches `uploads.parts.store`.

## Checked, no finding

`node scripts/api-check.mjs` in `packages/vue` exited 0 (`openapi-typescript` 7.13.0, generated file matches `src/api/schema.d.ts`). IM mutations send the resource `etag` through as `If-Match` without parsing it; `*` is not invented. A single 412 re-reads and sets `conflict`; a 428 is rethrown. `VaulterViewer` spreads server `viewer.props` first and then forces `node` and `readonly`. The fallback download control is `abilities.download && kind === 'file'` (`VaulterUnsupportedNode.vue:24`). The full Vitest run was not repeated: Vite’s config bundle needs to write `packages/vue/node_modules/.vite-temp`, and that write is denied here.
