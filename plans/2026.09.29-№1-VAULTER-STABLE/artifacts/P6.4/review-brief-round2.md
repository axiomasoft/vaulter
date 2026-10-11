# Read-only re-review brief — PLAN2.P6.4, round 2 (`@vaulter/vue` on REST v1)

You are an independent, read-only reviewer. Do not modify any file. Inspect, run read-only commands, report, stop.
The working tree of `/home/vostrikov/projects/packages/vaulter` against `HEAD` is the candidate (same as round 1 plus
the corrections below). Round 1 findings and verdict: `plans/2026.09.29-№1-VAULTER-STABLE/artifacts/P6.4/review-round1.md`.
The executor reproduced each finding with a failing test first (now green):

1. `useDocument` overlapping saves → `src/composables/useDocument.ts`: saves are serialised through a promise queue
   (`save()` → `queue.then(persist, persist)`), an explicit save clears the autosave timer. Tests: `tests/drive-composables.test.ts`
   ("useDocument — overlapping saves").
2. `useNodes` late list rolls a row back → `src/composables/useNodes.ts`: `clock`/`touched`/`reconcile()` and a `listSeq`
   guard on `refresh()`/`loadMore()`. Tests: "useNodes — a late response never rolls a row back".
3. `useCollaboration` connect during reopen → `src/composables/useCollaboration.ts`: `generation` token,
   `resolveSession(mine)` returns null when superseded, `connect()`/`reopen()`/`disconnect()` bump it. Tests in
   `tests/node-collaboration.test.ts`.
4. `portableNameReason` whitespace → `src/naming.ts` `EDGE_SPACE = /^[\t-\r\p{Z}᠎]|[\t-\r\p{Z}᠎]$/u`
   (PHP 8.4 `preg_match('/^[\s\p{Z}]|[\s\p{Z}]$/u')` matches U+180E, not U+FEFF). Tests: `tests/naming.test.ts`.
5. S3 zero-byte upload → `src/api/uploads/s3.ts` sends one empty part (`do … while`). Test: `tests/api/uploads.test.ts`.

## Scope of this round (bounded)

(a) Is each correction right, complete and free of a new defect? Prove a defect with a failing input.
(b) The changed dependency closure: `VaulterDocumentNode.vue`, `VaulterSpreadsheet.vue`, `VaulterDrive.vue`,
`VaulterNameDialog.vue` and every caller of `useDocument().save()/flush()`, `useNodes()`, `useCollaboration()`.
(c) The finding class repeated three times (ordering of async results against newer local state). Check the shared
invariant — "a response older than a later local action never overwrites it, and a superseded async operation never
leaves a resource behind" — across ALL composables and components (`useSharedNodes`, `useComments`, `useRevisions`,
`useGrants`, `useShares`, `useActivity`, `useUpload`, `VaulterFolderPicker`, `VaulterDrive` search), not only the
three that were patched. Do not re-review unrelated code.

## Checks you may run (read-only)

    cd packages/vue && npm run typecheck && npx vitest run
(the executor's results: typecheck exit 0, 22 files / 270 tests passed). If a tool needs to write inside the
repository (Vite's config bundle in `node_modules/.vite-temp`), say so rather than working around it.

## Report

First line `VERDICT: PASS` or `VERDICT: FINDINGS`; per finding severity (blocker/major/minor), file:line, what is wrong
and a **reproduction** (a test you ran or command output). A finding without a reproduction is at most "plausible".
