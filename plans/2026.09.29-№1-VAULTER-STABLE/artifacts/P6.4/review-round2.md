VERDICT: FINDINGS

`npm run typecheck` in `packages/vue` exited 0. `npx vitest run` did not run: Vite could not write `packages/vue/node_modules/.vite-temp/vite.config.ts.timestamp-….mjs` (`EACCES`). The reproductions below execute the package sources under Node and happy-dom.

The name-policy correction matches the server on every scalar. `portableNameReason` and a PHP 8.3.35 run of the `NodeName::normalize` + `PortableNamePolicy` checks (same regexes as the source; Composer’s autoload refuses this PHP) agreed on all 1,112,064 non-surrogate code points as a prefix of `a`, a suffix of `a`, and the character alone: 0 mismatches. U+180E is `leading_or_trailing_space` on both sides. U+FEFF is accepted on both sides. `VaulterFolderPicker` keeps the newer folder when an older `children` response arrives last.

## 1. Major — `useCollaboration.ts:150`

`connect()` during an epoch reopen opens the new room on the pre-restore `Y.Doc`. `reopen()` replaces the document only after its own session returns. A `connect()` that wins during that wait makes `resolveSession` return null, and the reopen returns at line 150 before the `doc` swap and before `onEpochChanged`. `connect()` (line 178) then calls `open()` on the document that is already there.

Reproduction: edit the doc to `edit-before-restore`, fire `epoch_changed`, hold the reopen session, `connect()` to room `…42.2`, then release the reopen session.

```text
{"text":"edit-before-restore","sameDoc":true,"epochCalls":0,"room":"vaulter.documents.7.42.2","providers":2}
```

`VaulterDocumentNode.load()` reaches this through `collab.connect()`. The slot binds `ydoc`, so the editor keeps the pre-restore text and the provider syncs it into the restored room.

## 2. Major — `VaulterDocumentNode.vue:138`

A superseded `connect()` rejects, and the component stores that as a failed session. `resolveSession` still throws after a newer `connect()` has taken the generation (`useCollaboration.ts:100`). If the winner has not opened yet, `connect()` throws `The collaboration connection was cancelled.` `load()`’s catch sets `sessionFailed` and emits `collaboration-failed`. The winner then sets `collaborative` and does not clear `sessionFailed`. `isReadonly` stays true (`VaulterDocumentNode.vue:97`) while one provider is live.

Reproduction: two overlapping `load()` calls; the older session resolves first.

```text
{"collaborative":true,"sessionFailed":true,"providers":1}
```

## 3. Major — `useNodes.ts:136`

`loadMore()` appends `reconcile()`’s output, and `reconcile()` also returns locally touched rows that are not on that page (`useNodes.ts:94`). A rename or create that lands while the next page is in flight is appended again.

`loadMore()` also shares `listSeq` with `refresh()` and never clears `loading`. A `loadMore()` started during `refresh()` makes the refresh drop its page (`seq !== listSeq`) and skip the `loading = false` in the `finally` (`useNodes.ts:119`).

Reproduction, rename during `loadMore`:

```text
["1:new","2:page2","1:new"]
```

Reproduction, `refresh()` in flight, then `loadMore()`; the refresh body was the row `9`:

```text
{"loading":true,"ids":["1","2"]}
```

## 4. Major — `useDocument.ts:82` and `:129`

An autosave armed before a 412 uses the re-read ETag and clears `conflict`. The queued `persist()` runs after the failed save’s re-read, so it sends the newer body with the other author’s tag. Success assigns `head` and sets `conflict` to null. The banner in `VaulterDocumentNode.vue:206` (“save again to overwrite, or reload”) is up only until the debounce that was already scheduled. `VaulterSpreadsheet.update` and `VaulterDocumentNode.onChange` both get there through `setContent`.

Reproduction (`debounceMs: 30`): save of `C2` held, `setContent(C3)`, reject the first update with 412, let the timer fire.

```text
{"midConflict":true,"updates":2,"conflict":null,"etag":"\"E3\"","content":{"v":"C3"}}
```

The second request is the overwrite. `conflict` is null afterward.

## 5. Major — `useDocument.ts:64` and `:128`

A save response that arrives after `load()` overwrites `head`. `load()` is outside the save queue. It sets `content` and `head` to the loaded snapshot; the in-flight `persist()` then sets `head` to the save it started earlier and clears `conflict`, leaving `content` on the loaded snapshot.

Reproduction: save of `{v:'local'}` held, `load()` resolves `{v:'server-now', etag:'E9'}`, then the save resolves `{v:'local', etag:'E2'}`.

```text
{"content":{"v":"server-now"},"head":{"v":"local"},"etag":"\"E2\""}
```

The next save sends the loaded body with the etag of the save that just landed.

## 6. Major — late list responses drop newer local rows

These reads assign the page with no generation check, so a response that started before a local add replaces the list the add just updated. `loadMore` also has no in-flight guard, so two calls append the same page.

| Site | What the late response did |
|---|---|
| `useComments.ts:32` and `:49` | dropped `c-new`; appended `c2` twice |
| `useShares.ts:37` | list became `["s-old"]` while `lastCreated` stayed `s-new` |
| `useGrants.ts:26` | list became `["g-old"]` |
| `useRevisions.ts:35` | list became `["r-old"]` |
| `useActivity.ts:38` | the older refresh left `["stale"]` after a newer refresh had applied `fresh` |

```text
COMMENTS {"afterLate":["c1"],"afterDouble":["c1","c2","c2"]}
SHARES   {"ids":["s-old"],"last":"s-new"}
GRANTS   ["g-old"]
REVISIONS ["r-old"]
ACTIVITY {"afterRefresh":["stale"],"afterDouble":["stale"]}
```

`useSharedNodes` already ignores a stale page (`loadSeq`) and ignores `loadMore` while `loading` is set. These five do not.

## 7. Major — `VaulterDrive.vue:250`

An empty search clears `searchResults` and returns without incrementing `searchSeq`. The in-flight search still matches the sequence and writes its hits back. `goTo` (`VaulterDrive.vue:129`) clears the results the same way.

Reproduction: Enter on `report` (held), Enter on a blank query, then release the held search.

```text
{"afterClear":["root"],"afterLate":["hit"]}
```

## 8. Major — `VaulterDrive.vue:114`

`refreshBreadcrumb()` applies whichever `getWithIncludes` returns, with no sequence check. A slower breadcrumb for folder A lands after the user has opened folder B and overwrites the trail.

Reproduction: `goTo('A')` held on the breadcrumb request, `goTo('B')` completed, then A’s response resolved.

```text
["Drive","Folder A"]
```

## 9. Minor — `src/api/client.ts:300`

`abort()` sends the tus `DELETE` only when `uploadId` is already set. `uploadId` is assigned after the create `POST` returns (`client.ts:283`). Cancel during that `POST` sets `aborted` and returns. The create then finishes, `runTus` reads the offset, and the loop throws `Resumable upload aborted.` The session `u1` is never deleted.

```text
{"result":"[Vaulter] Resumable upload aborted.","calls":["POST /api/vaulter/v1/drives/7/uploads","GET /api/vaulter/v1/uploads/u1"],"deleted":false}
```

## Plausible — zero-byte S3 still fails `isComplete`

`runS3` does send one empty part, so `S3MultipartHandler::complete` no longer throws “no parts were recorded”. `isComplete` (`packages/drive/src/Uploads/S3MultipartHandler.php:298`) still requires `staged->size($session) > 0`. A declared total of 0 assembles to size 0, so `UploadOperations::complete` throws `UploadConflictException` after the client’s `POST …/complete`. I did not execute the Laravel handler.
