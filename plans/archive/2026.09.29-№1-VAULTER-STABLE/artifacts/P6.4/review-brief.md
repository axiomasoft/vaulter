# Read-only review brief — PLAN2.P6.4 (`@vaulter/vue` on REST v1)

You are an independent, read-only reviewer. Do not modify any file (the sandbox refuses writes to the
repository). Inspect, run read-only commands and tests, report findings, stop.

## What to review

The uncommitted working tree of `/home/vostrikov/projects/packages/vaulter` against `HEAD` (d8d631bc).
Ignore everything under `plans/` except the artifacts named below (owner work in `plans/**/views/` is not part of
this item). New files are untracked, so use `git status --short` and read them directly:

    git status --short -- . ':!plans'
    git diff HEAD -- . ':!plans'

Item contract (JSON, source of truth): `plans/2026.09.29-№1-VAULTER-STABLE/phases/P6/P6.4.json`.
Normative design: `plans/2026.09.29-№1-VAULTER-STABLE/knowledge/P6-design.json` sections `Vue`, `Resources`,
`Validators`, `Errors`, `OpenAPI`; OPUS input `knowledge/artifacts-P6-design-opus.json` (`F11-1-3`, `F11-4-6`);
decisions D45, D53, D62, D64, D65. Executor notes and deviations: `plans/2026.09.29-№1-VAULTER-STABLE/log.jsonl`
(entries for P6.4), `artifacts/P6.4/{check-profile.json,tool-pins.md,changelog-entry.md}`.
The contract the client follows: `openapi/vaulter-v1.yaml` and `openapi/examples/*.json`.

## Substantive changes (review closely)

- `packages/vue/src/api/` — `schema.d.ts` is GENERATED (`npm run api:generate`; do not review its content, check
  that it equals the generation — `npm run api:check`), `types.ts` (aliases; an `Open<>` helper widens the
  `Record<string, never>` that openapi-typescript renders for open objects — a recorded deviation), `transport.ts`
  (fetch, headers, version guard), `errors.ts` (problem+json), `client.ts` (resources, `If-Match`),
  `uploads/{tus,s3,shared}.ts`.
- `packages/vue/src/viewers/` + `components/VaulterUnsupportedNode.vue` + `src/i18n.ts` — per-app viewer registry,
  `VaulterViewer`, built-in viewers, fallback (V37).
- `packages/vue/src/composables/*`, `src/collaboration/{transport,session}.ts` — composables on the new client
  (412 → re-read + `conflict`, 428 rethrown), collaboration (`epoch_changed` → new Y.Doc + `onEpochChanged`).
- `packages/vue/src/components/*` — ports to v1; `VaulterNodeList` keyboard/listbox model; `VaulterNameDialog` +
  `src/naming.ts` (portable-v1 mirror of `packages/core/src/Naming/PortableNamePolicy.php` and
  `packages/core/src/Support/NodeName.php`); `VaulterUploader` live region; `VaulterDocumentNode`/`VaulterSpreadsheet`.
- `packages/vue/tests/**`, `package.json` (exact pins), `scripts/api-check.mjs`, `.github/workflows/frontend.yml`,
  `docs/guide/vue.md` + `docs/ru/guide/vue.md`, `.changeset/vue-rest-v1.md`, `CHANGELOG.md`, `UPGRADE.md`.

## Risky seams to probe

1. Client vs contract: a method whose path, verb, query/body field names, `If-Match` use, or return shape differs
   from `openapi/vaulter-v1.yaml` (check every namespace against the operations; e.g. share/grant bodies, upload
   session fields, `changes.list`, revision endpoints, `files.upload` multipart, tus headers, s3 part numbering).
2. Preconditions: any mutation that can go out without `If-Match`, or with a made-up/parsed tag; any place that
   builds, parses or compares an ETag; `etag === null` paths; wildcard `*`.
3. Lost update in the composables: `useNodes` / `useDocument` conflict handling — can a stale response overwrite
   newer state, can an autosave race (timer + manual save) send an old tag, does a 412 leave `head`/`content`
   inconsistent, is an unhandled promise rejection possible?
4. Version guard and errors: `transport.ts` ordering (first response decides, later calls refuse without fetching),
   problem+json parsing edge cases, `http_<status>` for non-JSON, a 304/204 body, a redirect on download.
5. Viewer registry/fallback: per-app isolation (module-level state anywhere?), loader detection (`typeof entry ===
   'function'`), SSR safety, `VaulterViewer` precedence (`type_available`, null key, presentation null), whether
   the download button can appear for a caller without `abilities.download` or for a non-file, and whether
   server `viewer.props` can override `node`/`readonly`.
6. Collaboration: `useCollaboration` reopen logic (4409 / 4001 / 4403 / 4422), double reopen, leaked providers or
   watchers, `onEpochChanged` ordering, `schemaOutdated` read-only.
7. Accessibility: `VaulterNodeList` listbox semantics (roles, one tab stop, focus after removal, keys on inner
   buttons), `VaulterUploader` live region, `VaulterNameDialog` — compare with the WAI-ARIA listbox pattern.
8. `naming.ts` vs the PHP policy: any name the two judge differently (order of checks, `\s`/`\p{Z}`, UTF-8 bytes vs
   UTF-16 units, NFC, lone surrogates, `split('.', 1)` stem). Prove with a failing input.
9. Public surface: `src/index.ts` exports vs the snapshot, removed legacy names, type leaks, `dist` contents of
   `npm run build`.
10. Anything that contradicts P6-design#Vue, the item rules (no `types.ts` by hand, no component deciding
    permissions itself, lists only by cursor) or the scope `exclude` without a recorded deviation.

## Checks you may run (read-only)

    cd packages/vue && npm run api:check
    cd packages/vue && npm run typecheck
    cd packages/vue && npm test
    cd packages/vue && npx vitest run tests/api tests/contract
    cd packages/vue && npx vitest run tests/viewers

The executor's own results (all exit 0, 257 tests) are in
`/tmp/claude-1000/-home-vostrikov-projects-packages-vaulter/6f9c7d90-289b-4b43-a538-813d6a87f78e/scratchpad/checks/V*.log`.
Do not run `npm run build` if it needs to write inside the repository, anything with network, docker, oasdiff or PHP
suites. Scratch scripts and files you need go to a temporary directory outside the repository; if a tool refuses to
run because it wants to write inside the repository, say so instead of working around it.

## Report

Start with a one-line verdict: `VERDICT: PASS` or `VERDICT: FINDINGS`. Then, per finding: severity
(blocker / major / minor), file:line, what is wrong, and a **reproduction** — a test you ran or a command and its
output. A finding without a reproduction is at most "plausible"; say so. Keep style nits out unless they hide a
defect.
